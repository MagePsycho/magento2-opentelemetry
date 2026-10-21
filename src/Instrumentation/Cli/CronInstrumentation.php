<?php
/**
 * This file is part of the MagePsycho_OpenTelemetry package.
 *
 * @author    Raj KB <rajkb@magepsycho.com>
 * @copyright Copyright (c) 2025 MumzWorld (https://www.mumzworld.com) - original work
 * @copyright Copyright (c) 2025 MagePsycho (https://www.magepsycho.com) - modifications
 */
declare(strict_types=1);

namespace MagePsycho\OpenTelemetry\Instrumentation\Cli;

use Magento\Cron\Model\Schedule;
use Magento\Cron\Observer\ProcessCronQueueObserver;
use MagePsycho\OpenTelemetry\Instrumentation\AbstractInstrumentation;
use OpenTelemetry\API\Trace\StatusCode;
use Throwable;

/**
 * Cron jobs, one trace per job.
 *
 * cron:run works through every due job of a group in one process. Nested under the process span they
 * would share one trace, so each job is the root of its own, linked back to the cron:run span.
 */
class CronInstrumentation extends AbstractInstrumentation
{
    public const INSTRUMENTATION_NAME = 'io.opentelemetry.contrib.php.magento.cron';
    private const SPAN_NAME_PREFIX = 'Cron:';

    /**
     * Per open job: the startUnitOfWork() state, or false when it started no span.
     *
     * @var array<int, array|false>
     */
    private static array $started = [];

    /**
     * @inheritdoc
     * phpcs:disable Magento2.Functions.StaticFunction
     */
    protected static function getInstrumentationName(): string
    {
        return self::INSTRUMENTATION_NAME;
    }

    /**
     * @inheritdoc
     */
    public static function register(): void
    {
        self::instrumentCronRun();
    }

    /**
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private static function instrumentCronRun(): void
    {
        self::hookOutermost(
            ProcessCronQueueObserver::class,
            'tryRunJob',
            static function (
                ProcessCronQueueObserver $subject,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) {
                $schedule = $params[3] ?? null;
                if (!$schedule instanceof Schedule) {
                    self::$started[] = false;
                    return;
                }
                $groupId = (string)($params[4] ?? '');
                $jobCode = (string)$schedule->getJobCode();

                $builder = self::createSpanBuilder(
                    sprintf('%s %s', self::SPAN_NAME_PREFIX, $jobCode),
                    $function,
                    $class,
                    $filename,
                    $lineno,
                )
                    ->setAttribute('magento.cron.job_code', $jobCode)
                    ->setAttribute('magento.cron.group', $groupId);
                if ($schedule->getId()) {
                    $builder->setAttribute('magento.cron.schedule_id', (int)$schedule->getId());
                }

                self::$started[] = self::startUnitOfWork($builder);
            },
            static function (
                ProcessCronQueueObserver $subject,
                array $params,
                mixed $returnValue,
                ?Throwable $exception,
            ) {
                $state = array_pop(self::$started);
                if (!is_array($state)) {
                    return;
                }

                $schedule = $params[3] ?? null;
                $status = $schedule instanceof Schedule ? (string)$schedule->getStatus() : '';
                // A failed job is recorded on the schedule, whether or not the exception gets this far.
                if ($exception === null && $status === Schedule::STATUS_ERROR) {
                    self::getActiveSpan()?->setStatus(StatusCode::STATUS_ERROR, (string)$schedule->getMessages());
                }

                self::endUnitOfWork($state, $exception, $status !== '' ? ['magento.cron.status' => $status] : []);
            },
        );
    }
    //phpcs:enable Magento2.Functions.StaticFunction
}

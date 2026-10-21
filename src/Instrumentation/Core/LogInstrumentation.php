<?php
/**
 * This file is part of the MagePsycho_OpenTelemetry package.
 *
 * @author    Raj KB <rajkb@magepsycho.com>
 * @copyright Copyright (c) 2025 MagePsycho (https://www.magepsycho.com)
 */
declare(strict_types=1);

namespace MagePsycho\OpenTelemetry\Instrumentation\Core;

use MagePsycho\OpenTelemetry\Instrumentation\AbstractInstrumentation;
use Monolog\Level;
use Monolog\Logger;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\StatusCode;
use Throwable;

/**
 * Log and trace correlation, opt-in with OTEL_MAGENTO_LOG_INSTRUMENTATION.
 *
 * - Every log record written while a span is open gets trace_id and span_id in its context, so a line in
 *   var/log/system.log or exception.log leads straight to its trace.
 * - Records at ERROR and above are added to the open span as a "log" event, with the exception when one
 *   is passed in the context - so a trace shows the errors Magento caught and logged, not only the ones
 *   it threw.
 */
class LogInstrumentation extends AbstractInstrumentation
{
    public const INSTRUMENTATION_NAME = 'io.opentelemetry.contrib.php.magento.log';
    private const MAX_MESSAGE_LENGTH = 1024;

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
        // Monolog 3 (Magento 2.4.7+); the Level enum does not exist before it.
        if (!enum_exists(Level::class)) {
            return;
        }
        self::instrumentAddRecord();
    }

    /**
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private static function instrumentAddRecord(): void
    {
        // Magento\Framework\Logger\Monolog extends Logger and calls parent::addRecord(): outermost only.
        self::hookOutermost(
            Logger::class,
            'addRecord',
            static function (Logger $subject, array $params) {
                $spanContext = Span::getCurrent()->getContext();
                if (!$spanContext->isValid() || !is_array($params[2] ?? [])) {
                    return null;
                }

                $params[2] = ($params[2] ?? []) + [
                    'trace_id' => $spanContext->getTraceId(),
                    'span_id' => $spanContext->getSpanId(),
                ];

                return $params;
            },
            static function (Logger $subject, array $params, mixed $returnValue, ?Throwable $exception) {
                $level = self::levelValue($params[0] ?? null);
                if ($level < Level::Error->value) {
                    return;
                }

                $span = Span::getCurrent();
                if (!$span->getContext()->isValid()) {
                    return;
                }

                $span->addEvent('log', [
                    'log.severity' => Level::from($level)->getName(),
                    'log.channel' => $subject->getName(),
                    'log.message' => self::truncateString((string)($params[1] ?? ''), self::MAX_MESSAGE_LENGTH),
                ]);

                $logged = $params[2]['exception'] ?? null;
                if ($logged instanceof Throwable) {
                    $span->recordException($logged);
                    $span->setStatus(StatusCode::STATUS_ERROR, $logged->getMessage());
                }
            },
        );
    }

    /**
     * @param mixed $level
     * @return int
     */
    private static function levelValue(mixed $level): int
    {
        if ($level instanceof Level) {
            return $level->value;
        }

        return is_int($level) && Level::tryFrom($level) ? $level : 0;
    }
    //phpcs:enable Magento2.Functions.StaticFunction
}

<?php
/**
 * This file is part of the MagePsycho_OpenTelemetry package.
 *
 * @author    Raj KB <rajkb@magepsycho.com>
 * @copyright Copyright (c) 2025 MagePsycho (https://www.magepsycho.com)
 */
declare(strict_types=1);

namespace MagePsycho\OpenTelemetry\Instrumentation\Core;

use Magento\Framework\Cache\LockGuardedCacheLoader;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\View\Element\AbstractBlock;
use MagePsycho\OpenTelemetry\Instrumentation\AbstractInstrumentation;
use MagePsycho\OpenTelemetry\Instrumentation\Http\LayoutInstrumentation;
use MagePsycho\OpenTelemetry\Instrumentation\Util\RequestStats;
use MagePsycho\OpenTelemetry\Instrumentation\Util\SpanLimiter;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\StatusCode;
use stdClass;
use Throwable;
use function OpenTelemetry\Instrumentation\hook;

/**
 * Named locks (cron, indexers, cache generation) and lock-guarded cache loads.
 *
 * LockGuardedCacheLoader is what turns a cache miss under load into a queue: the first request
 * regenerates the entry while every other one polls and sleeps until it is there. Hits make up nearly
 * all of its calls and cost nothing, so a span is only recorded, after the fact, for a load that
 * regenerated the data or had to wait for it.
 */
class LockInstrumentation extends AbstractInstrumentation
{
    public const INSTRUMENTATION_NAME = 'io.opentelemetry.contrib.php.magento.lock';
    private const SPAN_NAME_PREFIX = 'Lock:';
    private const LOCK_SPAN_LIMIT = 100;
    private const LIMITER_KEY = 'lock';
    private const MAX_NAME_LENGTH = 256;

    /**
     * One entry per open lockedLoadData() call, the innermost last.
     *
     * @var stdClass[]
     */
    private static array $loads = [];

    /**
     * Open lock()/unlock() calls, counting the Proxy and the backend it calls separately.
     *
     * @var int
     */
    private static int $lockDepth = 0;

    /**
     * Per open lock()/unlock(): whether it started a span.
     *
     * @var bool[]
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
        SpanLimiter::getInstance()->setLimit(self::LIMITER_KEY, self::LOCK_SPAN_LIMIT);
        self::instrumentLock('lock', 'acquire');
        self::instrumentLock('unlock', 'release');
        self::instrumentLockedLoadData();
    }

    /**
     * @param string $method
     * @param string $operation
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private static function instrumentLock(string $method, string $operation): void
    {
        self::hookOutermost(
            LockManagerInterface::class,
            $method,
            static function (
                LockManagerInterface $subject,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($method, $operation) {
                $name = (string)($params[0] ?? '');

                // Lock\Proxy hands the call to the configured backend: one span, and one attempt, per call.
                if (self::$lockDepth++ > 0) {
                    self::$started[] = false;
                    return;
                }

                // The loader's own lock attempts are counted on its span instead.
                $load = end(self::$loads);
                if ($load instanceof stdClass && $load->lockName === $name) {
                    if ($method === 'lock') {
                        $load->lockAttempts++;
                    }
                    self::$started[] = false;
                    return;
                }

                if (!SpanLimiter::getInstance()->shouldStart(self::LIMITER_KEY)) {
                    RequestStats::recordDropped(self::LIMITER_KEY);
                    self::$started[] = false;
                    return;
                }

                $builder = self::createSpanBuilder(
                    sprintf('%s %s', self::SPAN_NAME_PREFIX, $operation),
                    $function,
                    $class,
                    $filename,
                    $lineno,
                )->setAttribute('magento.lock.name', self::truncateString($name, self::MAX_NAME_LENGTH));
                if ($method === 'lock') {
                    $builder->setAttribute('magento.lock.timeout', (int)($params[1] ?? -1));
                }

                self::startSpanAndAttachToContext($builder);
                self::$started[] = true;
            },
            static function (
                LockManagerInterface $subject,
                array $params,
                mixed $returnValue,
                ?Throwable $exception,
            ) use ($method) {
                $outermost = --self::$lockDepth === 0;
                if (!array_pop(self::$started)) {
                    $load = end(self::$loads);
                    if ($outermost && $method === 'lock' && $returnValue === true && $load instanceof stdClass
                        && $load->lockName === (string)($params[0] ?? '')
                    ) {
                        $load->lockAcquired = true;
                    }
                    return;
                }

                $attribute = $method === 'lock' ? 'magento.lock.acquired' : 'magento.lock.released';
                self::endSpan($exception, [$attribute => $returnValue === true]);
            },
        );
    }

    /**
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private static function instrumentLockedLoadData(): void
    {
        /*
         * A plain hook, not hookOutermost(): the loader is a shared instance, and a block's render calls
         * it again for every cached child block - those nested calls are real, not Interceptor frames.
         */
        hook(
            LockGuardedCacheLoader::class,
            'lockedLoadData',
            static function (
                LockGuardedCacheLoader $subject,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) {
                $load = new stdClass();
                $load->lockName = (string)($params[0] ?? '');
                $load->start = Clock::getDefault()->now();
                $load->loads = 0;
                $load->collected = false;
                $load->lockAttempts = 0;
                $load->lockAcquired = false;
                $load->forBlock = self::calledFromBlockCache();
                $load->code = [$function, $class, $filename, $lineno];
                self::$loads[] = $load;

                // Wrapped to see, from outside, whether the data came from cache or was regenerated.
                $loader = $params[1] ?? null;
                $collector = $params[2] ?? null;
                if (!is_callable($loader) || !is_callable($collector)) {
                    return null;
                }
                $params[1] = static function () use ($loader, $load) {
                    $load->loads++;
                    return $loader();
                };
                $params[2] = static function () use ($collector, $load) {
                    $load->collected = true;
                    return $collector();
                };

                return $params;
            },
            static function (
                LockGuardedCacheLoader $subject,
                array $params,
                mixed $returnValue,
                ?Throwable $exception,
            ) {
                $load = array_pop(self::$loads);
                if (!$load instanceof stdClass) {
                    return;
                }

                if ($load->forBlock) {
                    LayoutInstrumentation::recordBlockCacheResult(!$load->collected);
                }

                // The loader runs again after every sleep, so more than one load means it waited.
                $waited = $load->loads > 1;
                if (!$load->collected && !$waited && $exception === null) {
                    return;
                }

                [$function, $class, $filename, $lineno] = $load->code;
                $span = self::createSpanBuilder('Cache: lockedLoadData', $function, $class, $filename, $lineno)
                    ->setStartTimestamp($load->start)
                    ->setAttribute('magento.cache.lock.name', self::truncateString($load->lockName, self::MAX_NAME_LENGTH))
                    ->setAttribute('magento.cache.hit', !$load->collected)
                    ->setAttribute('magento.cache.lock.waited', $waited)
                    ->setAttribute('magento.cache.load_attempts', $load->loads)
                    ->setAttribute('magento.cache.lock.attempts', $load->lockAttempts)
                    ->setAttribute('magento.cache.lock.acquired', $load->lockAcquired)
                    ->startSpan();
                if ($exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }
                $span->end();
            },
        );
    }

    /**
     * Whether lockedLoadData() was called by AbstractBlock::_loadCache(), i.e. for block_html.
     *
     * @return bool
     */
    private static function calledFromBlockCache(): bool
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8);
        foreach ($frames as $i => $frame) {
            if (($frame['function'] ?? '') === 'lockedLoadData') {
                $caller = $frames[$i + 1] ?? [];
                return ($caller['function'] ?? '') === '_loadCache'
                    && is_a($caller['class'] ?? '', AbstractBlock::class, true);
            }
        }

        return false;
    }
    //phpcs:enable Magento2.Functions.StaticFunction
}

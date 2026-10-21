<?php
/**
 * This file is part of the MagePsycho_OpenTelemetry package.
 *
 * @author    Raj KB <rajkb@magepsycho.com>
 * @copyright Copyright (c) 2025 MumzWorld (https://www.mumzworld.com) - original work
 * @copyright Copyright (c) 2025 MagePsycho (https://www.magepsycho.com) - modifications
 */
declare(strict_types=1);

namespace MagePsycho\OpenTelemetry\Instrumentation\Cache;

use Magento\Framework\Cache\Backend\Redis;
use Magento\Framework\Cache\Frontend\Adapter\Symfony as SymfonyCacheAdapter;
use MagePsycho\OpenTelemetry\Instrumentation\AbstractInstrumentation;
use MagePsycho\OpenTelemetry\Instrumentation\Util\RequestStats;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\TraceAttributeValues;
use Throwable;

class RedisInstrumentation extends AbstractInstrumentation
{
    public const INSTRUMENTATION_NAME = 'io.opentelemetry.contrib.php.magento.redis';
    private const SPAN_NAME_PREFIX = 'Cache: Redis:';

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
        // Note: only uncomment if required
        #self::instrumentLoad();
        self::countLoads();
        self::instrumentTest();
        self::instrumentSave();
        self::instrumentClean();
        self::instrumentRemove();
    }

    /**
     * @return void
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private static function instrumentLoad(): void
    {
        self::hookOutermost(
            Redis::class,
            'load',
            static function (
                Redis     $subject,
                array       $params,
                string      $class,
                string      $function,
                ?string     $filename,
                ?int        $lineno,
            ) {
                $cacheId = $params[0] ?? 'NA';
                $spanName = sprintf('%s load', self::SPAN_NAME_PREFIX);
                $builder = self::createSpanBuilder(
                    $spanName,
                    $function,
                    $class,
                    $filename,
                    $lineno,
                )
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(TraceAttributes::DB_SYSTEM_NAME, TraceAttributeValues::DB_SYSTEM_REDIS)
                    ->setAttribute(TraceAttributes::DB_OPERATION_NAME, 'load')
                    ->setAttribute('db.redis.save.cacheId', $cacheId);

                self::startSpanAndAttachToContext($builder);
            },
            static function (
                Redis     $subject,
                array       $params,
                mixed       $returnValue,
                ?Throwable  $exception,
            ) {
                // Only uncomment if required. Data might be too big or might have sensitive information
                /*$spanAttributes = [
                    'db.redis.load.cacheData' => self::saferStringEncode($returnValue)
                ];*/
                self::endSpan($exception, ['magento.cache.hit' => $returnValue !== false]);
            },
        );
    }

    /**
     * Count loads and hits for the request totals, without a span per load.
     *
     * A page makes hundreds of cache loads; spans for each are what instrumentLoad() is kept off for,
     * but the hit ratio is still worth having on the root span. Magento 2.4.9 replaced the Zend cache
     * backends with Symfony Cache, so the loads are counted on whichever of the two this version uses.
     *
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private static function countLoads(): void
    {
        foreach ([Redis::class, SymfonyCacheAdapter::class] as $cacheClass) {
            self::hookOutermost(
                $cacheClass,
                'load',
                null,
                // No return type, so the cached data is passed through untouched.
                static function (object $subject, array $params, mixed $returnValue, ?Throwable $exception) {
                    RequestStats::recordCacheLoad($exception === null && $returnValue !== false);
                },
            );
        }
    }

    /**
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private static function instrumentTest(): void
    {
        self::hookOutermost(
            Redis::class,
            'test',
            static function (
                Redis       $subject,
                array       $params,
                string      $class,
                string      $function,
                ?string     $filename,
                ?int        $lineno,
            ) {
                $cacheId = $params[0] ?? 'NA';
                $spanName = sprintf('%s test', self::SPAN_NAME_PREFIX);
                $builder = self::createSpanBuilder(
                    $spanName,
                    $function,
                    $class,
                    $filename,
                    $lineno,
                )
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(TraceAttributes::DB_SYSTEM_NAME, TraceAttributeValues::DB_SYSTEM_REDIS)
                    ->setAttribute(TraceAttributes::DB_OPERATION_NAME, 'test')
                    ->setAttribute('db.redis.save.cacheId', $cacheId);

                self::startSpanAndAttachToContext($builder);
            },
            static function (
                Redis       $subject,
                array       $params,
                mixed       $returnValue,
                ?Throwable  $exception,
            ) {
                self::endSpan($exception);
            },
        );
    }

    /**
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private static function instrumentSave(): void
    {
        self::hookOutermost(
            Redis::class,
            'save',
            static function (
                Redis       $subject,
                array       $params,
                string      $class,
                string      $function,
                ?string     $filename,
                ?int        $lineno,
            ) {
                #$cacheData = $params[0] ?? '';
                $cacheId = $params[1] ?? '';
                $cacheTags = $params[2] ?? [];
                $spanName = sprintf('%s save', self::SPAN_NAME_PREFIX);
                $builder = self::createSpanBuilder(
                    $spanName,
                    $function,
                    $class,
                    $filename,
                    $lineno,
                );
                $builder->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(TraceAttributes::DB_SYSTEM_NAME, TraceAttributeValues::DB_SYSTEM_REDIS)
                    ->setAttribute(TraceAttributes::DB_OPERATION_NAME, 'save')
                    ->setAttribute('db.redis.save.cacheId', $cacheId)
                    // Note: Only uncomment if required. It might be too big or have sensitive information,
                    // Also can cause errors: OpenTelemetry: [error] Export failure [exception] Bad Request
                    //->setAttribute('db.redis.save.cacheData', self::saferStringEncode($cacheData))
                    ->setAttribute('db.redis.save.cacheTags', implode(',', $cacheTags));

                self::startSpanAndAttachToContext($builder);
            },
            static function (
                Redis       $subject,
                array       $params,
                mixed       $returnValue,
                ?Throwable  $exception,
            ) {
                self::endSpan($exception);
            },
        );
    }

    /**
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private static function instrumentRemove(): void
    {
        self::hookOutermost(
            Redis::class,
            'remove',
            static function (
                Redis       $subject,
                array       $params,
                string      $class,
                string      $function,
                ?string     $filename,
                ?int        $lineno,
            ) {
                $cacheId = $params[0] ?? 'NA';
                $spanName = sprintf('%s remove', self::SPAN_NAME_PREFIX);
                $builder = self::createSpanBuilder(
                    $spanName,
                    $function,
                    $class,
                    $filename,
                    $lineno,
                )
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(TraceAttributes::DB_SYSTEM_NAME, TraceAttributeValues::DB_SYSTEM_REDIS)
                    ->setAttribute(TraceAttributes::DB_OPERATION_NAME, 'remove')
                    ->setAttribute('db.redis.save.cacheId', $cacheId);

                self::startSpanAndAttachToContext($builder);
            },
            static function (
                Redis       $subject,
                array       $params,
                mixed       $returnValue,
                ?Throwable  $exception,
            ) {
                self::endSpan($exception);
            },
        );
    }

    /**
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private static function instrumentClean(): void
    {
        self::hookOutermost(
            Redis::class,
            'clean',
            static function (
                Redis       $subject,
                array       $params,
                string      $class,
                string      $function,
                ?string     $filename,
                ?int        $lineno,
            ) {
                $mode = $params[0] ?? 'NA';
                $tags = $params[1] ?? [];
                $spanName = sprintf('%s clean', self::SPAN_NAME_PREFIX);
                $builder = self::createSpanBuilder(
                    $spanName,
                    $function,
                    $class,
                    $filename,
                    $lineno,
                )
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(TraceAttributes::DB_SYSTEM_NAME, TraceAttributeValues::DB_SYSTEM_REDIS)
                    ->setAttribute(TraceAttributes::DB_OPERATION_NAME, 'clean')
                    ->setAttribute('db.redis.clean.mode', $mode)
                    ->setAttribute('db.redis.clean.tags', implode(',', $tags));

                self::startSpanAndAttachToContext($builder);
            },
            static function (
                Redis       $subject,
                array       $params,
                mixed       $returnValue,
                ?Throwable  $exception,
            ) {
                self::endSpan($exception);
            },
        );
    }
    //phpcs:enable Magento2.Functions.StaticFunction
}

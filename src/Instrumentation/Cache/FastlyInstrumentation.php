<?php
/**
 * This file is part of the MagePsycho_OpenTelemetry package.
 *
 * @author    Raj KB <rajkb@magepsycho.com>
 * @copyright Copyright (c) 2025 MagePsycho (https://www.magepsycho.com)
 */
declare(strict_types=1);

namespace MagePsycho\OpenTelemetry\Instrumentation\Cache;

use Fastly\Cdn\Model\PurgeCache;
use MagePsycho\OpenTelemetry\Instrumentation\AbstractInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use Throwable;

/**
 * Fastly CDN purges (fastly/magento2), the Fastly counterpart of VarnishInstrumentation.
 *
 * The API calls themselves go through the Laminas client, so their HTTP spans nest under this one.
 */
class FastlyInstrumentation extends AbstractInstrumentation
{
    public const INSTRUMENTATION_NAME = 'io.opentelemetry.contrib.php.magento.fastly';
    private const SPAN_NAME_PREFIX = 'Cache: Fastly:';

    /**
     * A full-catalogue save can purge thousands of keys; the count is always recorded, the keys only up to this.
     */
    private const MAX_RECORDED_KEYS = 100;

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
        self::instrumentFastlyPurgeCache();
    }

    /**
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private static function instrumentFastlyPurgeCache(): void
    {
        self::hookOutermost(
            PurgeCache::class,
            'sendPurgeRequest',
            static function (
                PurgeCache $subject,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) {
                $pattern = $params[0] ?? '';
                $type = self::purgeType($pattern);

                // Named by purge type only, so every key purge groups together however many keys it carries.
                $builder = self::createSpanBuilder(
                    sprintf('%s Purge %s', self::SPAN_NAME_PREFIX, $type),
                    $function,
                    $class,
                    $filename,
                    $lineno,
                )->setAttribute('magento.cache.fastly.purge.type', $type);

                if ($type === 'surrogate_key') {
                    $keys = array_values(array_filter($pattern));
                    $builder
                        ->setAttribute('magento.cache.fastly.purge.key_count', count($keys))
                        ->setAttribute(
                            'magento.cache.fastly.purge.keys',
                            self::truncateString(self::saferJsonEncode(array_slice($keys, 0, self::MAX_RECORDED_KEYS)))
                        );
                } elseif ($type === 'url') {
                    // Host and path only, as for outbound HTTP spans.
                    $builder->setAttribute('magento.cache.fastly.purge.url', strtok($pattern, '?'));
                }

                self::startSpanAndAttachToContext($builder);
            },
            static function (
                PurgeCache $subject,
                array $params,
                mixed $returnValue,
                ?Throwable $exception,
            ) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $scope->detach();

                $span = Span::fromContext($scope->context());
                if ($exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                } elseif (!$returnValue) {
                    // Fastly\Cdn\Model\Api logs and swallows API errors, returning false.
                    $span->setStatus(StatusCode::STATUS_ERROR, 'Fastly purge failed');
                }
                $span->setAttribute('magento.cache.fastly.purge.result', $returnValue ? 'success' : 'failed');
                $span->end();
            },
        );
    }

    /**
     * Mirrors the branches of PurgeCache::sendPurgeRequest().
     *
     * @param mixed $pattern
     * @return string
     */
    private static function purgeType(mixed $pattern): string
    {
        if (empty($pattern)) {
            return 'all';
        }
        if (is_array($pattern)) {
            return 'surrogate_key';
        }
        if (is_string($pattern) && str_starts_with($pattern, 'http')) {
            return 'url';
        }

        return 'invalid';
    }
    //phpcs:enable Magento2.Functions.StaticFunction
}

<?php
/**
 * This file is part of the MagePsycho_OpenTelemetry package.
 *
 * @author    Raj KB <rajkb@magepsycho.com>
 * @copyright Copyright (c) 2025 MagePsycho (https://www.magepsycho.com)
 */
declare(strict_types=1);

namespace MagePsycho\OpenTelemetry\Instrumentation\Http;

use Magento\Framework\Session\SaveHandler;
use MagePsycho\OpenTelemetry\Instrumentation\AbstractInstrumentation;
use Throwable;

/**
 * Session reads and writes, through Magento's save handler whatever the backend (Redis, database, files).
 *
 * The session is read at the start of nearly every request and written at the end, so its size is a cost
 * every request pays - a session bloated by a big quote or a module storing whole objects in it shows up
 * here as magento.session.size_bytes long before it hits session max size.
 */
class SessionInstrumentation extends AbstractInstrumentation
{
    public const INSTRUMENTATION_NAME = 'io.opentelemetry.contrib.php.magento.session';
    private const SPAN_NAME_PREFIX = 'Session:';

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
        self::instrument('read');
        self::instrument('write');
    }

    /**
     * @param string $operation
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private static function instrument(string $operation): void
    {
        self::hookOutermost(
            SaveHandler::class,
            $operation,
            static function (
                SaveHandler $subject,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($operation) {
                $builder = self::createSpanBuilder(
                    sprintf('%s %s', self::SPAN_NAME_PREFIX, $operation),
                    $function,
                    $class,
                    $filename,
                    $lineno
                );
                if ($operation === 'write' && is_string($params[1] ?? null)) {
                    $builder->setAttribute('magento.session.size_bytes', strlen($params[1]));
                }

                self::startSpanAndAttachToContext($builder);
            },
            static function (SaveHandler $subject, array $params, mixed $returnValue, ?Throwable $exception) use (
                $operation
            ) {
                $attributes = [];
                if ($operation === 'read' && is_string($returnValue)) {
                    $attributes['magento.session.size_bytes'] = strlen($returnValue);
                }
                self::endSpan($exception, $attributes);
            },
        );
    }
    //phpcs:enable Magento2.Functions.StaticFunction
}

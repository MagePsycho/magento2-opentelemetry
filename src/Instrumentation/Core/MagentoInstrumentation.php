<?php
/**
 * This file is part of the MagePsycho_OpenTelemetry package.
 *
 * @author    Raj KB <rajkb@magepsycho.com>
 * @copyright Copyright (c) 2025 MumzWorld (https://www.mumzworld.com) - original work
 * @copyright Copyright (c) 2025 MagePsycho (https://www.magepsycho.com) - modifications
 */
declare(strict_types=1);

namespace MagePsycho\OpenTelemetry\Instrumentation\Core;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Http;
use MagePsycho\OpenTelemetry\Instrumentation\AbstractInstrumentation;
use MagePsycho\OpenTelemetry\Instrumentation\Util\Http\ServerPropagationGetter;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\TraceAttributes;
use Throwable;
use function OpenTelemetry\Instrumentation\hook;

class MagentoInstrumentation extends AbstractInstrumentation
{
    public const INSTRUMENTATION_NAME = 'io.opentelemetry.contrib.php.magento';

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
        self::instrumentBootstrapRun();
        self::instrumentAppTerminate();
        self::instrumentHttpCatchException();
    }

    /**
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public static function instrumentBootstrapRun(): void
    {
        hook(
            Bootstrap::class,
            'run',
            static function (
                Bootstrap   $subject,
                array       $params,
                string      $class,
                string      $function,
                ?string     $filename,
                ?int        $lineno,
            ) {
                $spanName = 'Bootstrap::run()';
                $builder = self::createSpanBuilder(
                    $spanName,
                    $function,
                    $class,
                    $filename,
                    $lineno,
                )->setSpanKind(SpanKind::KIND_INTERNAL);

                /*
                 * Join the caller's trace. This is the only place it can be done: every other span in
                 * the request nests below this one, so extracting further down - in the REST or GraphQL
                 * hook, say - would leave this unparented local root sitting above a remote-parented
                 * child and split the trace in two.
                 *
                 * extract() hands back the context it was given when no trace headers are present, so
                 * CLI runs and uninstrumented callers are unaffected.
                 */
                self::startSpanAndAttachToContext(
                    $builder,
                    Globals::propagator()->extract($_SERVER, ServerPropagationGetter::instance())
                );
            },
            static function (
                Bootstrap   $subject,
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
    private static function instrumentAppTerminate(): void
    {
        hook(
            Bootstrap::class,
            'terminate',
            static function (
                Bootstrap $bootstrap,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno
            ) {
                $scope = Context::storage()->scope();
                $span = Span::getCurrent();
                $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, "500");
                $span->recordException($params[0]);
                $span->setStatus(StatusCode::STATUS_ERROR, $params[0]->getMessage());
                $scope->detach();
                $span->end();
            }
        );
    }

    /**
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private static function instrumentHttpCatchException(): void
    {

        hook(
            Http::class,
            'catchException',
            null,
            static function (
                Http $subject,
                array $params,
                mixed $returnValue,
                ?Throwable $exception,
            ) {
                $httpException = $params[1] ?? $exception;
                self::endSpan($httpException);
            },
        );
    }
    //phpcs:enable Magento2.Functions.StaticFunction
}

<?php
/**
 * This file is part of the MagePsycho_OpenTelemetry package.
 *
 * @author    Raj KB <rajkb@magepsycho.com>
 * @copyright Copyright (c) 2025 MagePsycho (https://www.magepsycho.com)
 */
declare(strict_types=1);

namespace MagePsycho\OpenTelemetry\Instrumentation\Http;

use Magento\Framework\App\Area;
use Magento\Framework\App\FrontController;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\App\State;
use MagePsycho\OpenTelemetry\Instrumentation\AbstractInstrumentation;
use MagePsycho\OpenTelemetry\Instrumentation\Util\Http\RequestHandler;
use MagePsycho\OpenTelemetry\Instrumentation\Util\Http\ResponseHandler;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use Throwable;
use function OpenTelemetry\Instrumentation\hook;

/**
 * Storefront requests (PLP, PDP, CMS, checkout pages, customer sections and other AJAX controllers).
 *
 * REST and GraphQL have their own front controllers, so they never reach this hook. Admin requests
 * do pass through FrontController, and are left to BackendInstrumentation.
 */
class FrontendInstrumentation extends AbstractInstrumentation
{
    public const INSTRUMENTATION_NAME = 'io.opentelemetry.contrib.php.magento.frontend';
    private const SPAN_NAME_PREFIX = 'Frontend:';

    /**
     * Whether each open dispatch started a span, so the post hook only ends its own.
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
        self::instrumentFrontControllerDispatch();
    }

    /**
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private static function instrumentFrontControllerDispatch(): void
    {
        hook(
            FrontController::class,
            'dispatch',
            static function (
                FrontController $subject,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) {
                $request = $params[0] ?? null;

                // The hook fires on both FrontController\Interceptor::dispatch and the parent it
                // calls, so only the outermost dispatch opens a span.
                if (!$request instanceof HttpRequest || in_array(true, self::$started, true) || self::isAdminArea()) {
                    self::$started[] = false;
                    return;
                }

                $builder = self::createSpanBuilder(
                    sprintf('%s dispatch', self::SPAN_NAME_PREFIX),
                    $function,
                    $class,
                    $filename,
                    $lineno
                )->setSpanKind(SpanKind::KIND_SERVER);

                // Provisional name; replaced by the route once the request has been routed.
                self::updateRootSpanName(RequestHandler::generateRootSpanName(self::SPAN_NAME_PREFIX, $request));
                $builder = RequestHandler::addRequestAttributesToSpan($builder, $request);

                self::startSpanAndAttachToContext($builder);
                self::$started[] = true;
            },
            static function (
                FrontController $subject,
                array $params,
                mixed $response,
                ?Throwable $exception,
            ) {
                if (!array_pop(self::$started)) {
                    return;
                }

                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $scope->detach();
                $span = Span::fromContext($scope->context());

                $request = $params[0] ?? null;
                if ($request instanceof HttpRequest && $route = self::routePath($request)) {
                    /*
                     * Low cardinality, so every PLP groups as "Frontend: GET /catalog/category/view".
                     * The URL itself stays on url.path, and the entity behind it on magento.entity.id.
                     */
                    $spanName = sprintf(
                        '%s %s %s',
                        self::SPAN_NAME_PREFIX,
                        $request->getMethod() ?: 'UNKNOWN',
                        $route
                    );
                    self::updateRootSpanName($spanName);
                    $span->updateName($spanName);
                    $span->setAttribute('http.route', $route);
                    $span->setAttribute('magento.full_action_name', $request->getFullActionName());
                    if ($entityId = self::entityId($request)) {
                        $span->setAttribute('magento.entity.id', $entityId);
                    }
                }

                if ($exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                } elseif ($response instanceof ResponseInterface) {
                    $span = ResponseHandler::addResponseAttributesToSpan($span, $response);
                }
                $span->end();
            },
        );
    }

    /**
     * The routed controller as a path - cms_index_index becomes /cms/index/index.
     *
     * Read after routing, so a URL rewrite such as /women/dresses.html reports the controller that
     * served it rather than its own path. Null when the request was never matched to a controller.
     *
     * @param HttpRequest $request
     * @return string|null
     */
    private static function routePath(HttpRequest $request): ?string
    {
        $parts = [$request->getRouteName(), $request->getControllerName(), $request->getActionName()];
        foreach ($parts as $part) {
            if (!is_string($part) || $part === '') {
                return null;
            }
        }

        return '/' . implode('/', $parts);
    }

    /**
     * The id of the entity the controller served - product, category or CMS page.
     *
     * Read from the routed parameters, which is where a URL rewrite leaves it: the product behind
     * /mens-plantsman-organic-cotton-corduroy-blazer arrives as id=1234 on catalog/product/view.
     * Only numeric values, so a stray non-id "id" parameter cannot inflate the attribute.
     *
     * @param HttpRequest $request
     * @return string|null
     */
    private static function entityId(HttpRequest $request): ?string
    {
        foreach (['id', 'page_id'] as $param) {
            $value = $request->getParam($param);
            if (is_scalar($value) && ctype_digit((string)$value)) {
                return (string)$value;
            }
        }

        return null;
    }

    /**
     * Whether the current request runs in the admin area.
     *
     * @return bool
     */
    private static function isAdminArea(): bool
    {
        try {
            return ObjectManager::getInstance()->get(State::class)->getAreaCode() === Area::AREA_ADMINHTML;
        } catch (Throwable $e) {
            return false;
        }
    }
    //phpcs:enable Magento2.Functions.StaticFunction
}

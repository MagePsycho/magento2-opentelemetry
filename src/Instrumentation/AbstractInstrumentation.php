<?php
/**
 * This file is part of the MagePsycho_OpenTelemetry package.
 *
 * Provides an abstract class for OpenTelemetry instrumentation in Magento.
 * It includes utility functions for tracing spans, processing exceptions,
 * handling URL segments, safely encoding JSON, etc.
 *
 * @author    Raj KB <rajkb@magepsycho.com>
 * @copyright Copyright (c) 2025 MumzWorld (https://www.mumzworld.com) - original work
 * @copyright Copyright (c) 2025 MagePsycho (https://www.magepsycho.com) - modifications
 */
declare(strict_types=1);

namespace MagePsycho\OpenTelemetry\Instrumentation;

use MagePsycho\OpenTelemetry\Instrumentation\Util\RequestStats;
use MagePsycho\OpenTelemetry\Instrumentation\Util\SpanLimiter;
use OpenTelemetry\API\Trace\LocalRootSpan;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\Context;
use MagePsycho\OpenTelemetry\Instrumentation\Util\Traits\SpanBuilderTrait;
use MagePsycho\OpenTelemetry\Instrumentation\Util\Traits\AttributeSanitizerTrait;
use function OpenTelemetry\Instrumentation\hook;

/**
 * AbstractInstrumentation provides core tracing functionalities for OpenTelemetry integration in Magento.
 * @SuppressWarnings(PHPMD.NumberOfChildren)
 */
abstract class AbstractInstrumentation
{
    use AttributeSanitizerTrait;
    use SpanBuilderTrait;

    public const INSTRUMENTATION_NAME = 'io.opentelemetry.contrib.php.magento';

    /**
     * Get the name of the instrumentation.
     *
     * @return string
     * phpcs:disable Magento2.Functions.StaticFunction
     */
    abstract protected static function getInstrumentationName(): string;

    /**
     * Register the instrumentation hooks.
     *
     * @return void
     */
    abstract public static function register(): void;

    /**
     * Open calls per hook and subject, so a hook fires its callbacks once per call however many frames it spans.
     *
     * @var array<string, int>
     */
    private static array $callDepth = [];

    /**
     * Hooks registered through hookOutermost(), so two on the same method keep separate depths.
     *
     * @var int
     */
    private static int $hookCount = 0;

    /**
     * Hook a method, running the callbacks only for the outermost frame of each call.
     *
     * A hook matches the hooked class and every subclass. Magento generates an Interceptor subclass
     * for any class carrying a plugin, and the Interceptor overrides the method only to call
     * parent::method() - so a plain hook fires twice per call, once in each frame, and every span
     * comes out doubled with the copy nested inside the original.
     *
     * The outer frame is the one kept: its span covers the plugins, which is what the caller waited for.
     *
     * Not for methods that legitimately re-enter on the same object, such as event dispatch: the
     * nested calls would be swallowed along with the Interceptor frame.
     *
     * @param string $class
     * @param string $method
     * @param \Closure|null $pre
     * @param \Closure|null $post
     * @return void
     */
    protected static function hookOutermost(
        string $class,
        string $method,
        ?\Closure $pre = null,
        ?\Closure $post = null
    ): void {
        /*
         * No return types on either wrapper: the extension only replaces the hooked method's return
         * value when the post callback declares one, and these must stay transparent.
         */
        $hookId = ++self::$hookCount;
        hook(
            $class,
            $method,
            static function (?object $subject, array $params, ...$rest) use ($hookId, $class, $method, $pre) {
                $key = self::callKey($hookId, $subject, $class, $method);
                self::$callDepth[$key] = (self::$callDepth[$key] ?? 0) + 1;
                if (self::$callDepth[$key] > 1 || $pre === null) {
                    return null;
                }

                return $pre($subject, $params, ...$rest);
            },
            static function (?object $subject, array $params, ...$rest) use ($hookId, $class, $method, $post) {
                $key = self::callKey($hookId, $subject, $class, $method);
                $depth = (self::$callDepth[$key] ?? 1) - 1;
                if ($depth > 0) {
                    self::$callDepth[$key] = $depth;
                    return null;
                }
                unset(self::$callDepth[$key]);

                return $post === null ? null : $post($subject, $params, ...$rest);
            },
        );
    }

    /**
     * @param int $hookId
     * @param object|null $subject
     * @param string $class
     * @param string $method
     * @return string
     */
    private static function callKey(int $hookId, ?object $subject, string $class, string $method): string
    {
        return $hookId . '|' . ($subject === null ? '' : spl_object_id($subject)) . '|' . $class . '::' . $method;
    }

    /**
     * Start a unit of work - a queue message, a cron job - as the root of a trace of its own.
     *
     * A long-running process does many of these one after another. Nested under the process span they
     * would all land in one trace that is only exported when the process exits, and the span limits
     * would be spent on the first few. Instead each gets its own trace, a link back to the process span,
     * and fresh span limits and request totals; the process gets its own back in endUnitOfWork().
     *
     * @param SpanBuilderInterface $builder
     * @return array State to hand to endUnitOfWork().
     */
    protected static function startUnitOfWork(SpanBuilderInterface $builder): array
    {
        $processContext = Span::getCurrent()->getContext();
        if ($processContext->isValid()) {
            $builder->addLink($processContext);
        }

        $limiter = SpanLimiter::getInstance();
        $state = ['limiter' => $limiter->snapshot(), 'stats' => RequestStats::begin()];
        $limiter->reset();

        self::startSpanAndAttachToContext($builder, Context::getRoot());

        return $state;
    }

    /**
     * End a span opened by startUnitOfWork(), with its totals, and go back to the process's own.
     *
     * @param array $state What startUnitOfWork() returned.
     * @param \Throwable|null $exception
     * @param array $spanAttributes
     * @return void
     */
    protected static function endUnitOfWork(array $state, ?\Throwable $exception, array $spanAttributes = []): void
    {
        $span = self::getActiveSpan();
        if ($span) {
            RequestStats::applyTo($span);
        }
        self::endSpan($exception, $spanAttributes);

        RequestStats::end($state['stats']);
        SpanLimiter::getInstance()->restore($state['limiter']);
    }

    /**
     * Updates the name of the root span.
     *
     * @param string $name
     * @return SpanInterface|null
     */
    protected static function updateRootSpanName(string $name): ?SpanInterface
    {
        $rootSpan = LocalRootSpan::current();
        if ($rootSpan && !empty($name)) {
            $rootSpan->updateName($name);
        }
        return $rootSpan;
    }

    /**
     * Extracts the class name from a fully qualified class name (FQCN).
     * Also removes any "Interceptor" suffix if present.
     *
     * @param string $classNameString The fully qualified class name.
     *
     * @return string The extracted class name.
     */
    protected static function extractClassName(string $classNameString): string
    {
        $classNameString = str_replace("\Interceptor", "", $classNameString);
        $parts = explode("\\", $classNameString);
        return end($parts);
    }

    /**
     * Extracts a name like ProductCollection from a class name ending in
     * ...\Collection or ...\Collection\Interceptor
     *
     * @param string $className Fully qualified class name
     * @return string|null The extracted name or null if it doesn't match pattern
     */
    protected static function extractCollectionName(string $className): ?string
    {
        // Normalize: trim trailing \Interceptor if exists
        if (str_ends_with($className, "\\Interceptor")) {
            $className = substr($className, 0, -strlen("\\Interceptor"));
        }

        $parts = explode("\\", $className);

        // Must end with 'Collection' and have at least 2 parts to extract entity
        $last = array_pop($parts);
        if ($last !== "Collection" || empty($parts)) {
            return null;
        }

        $entity = array_pop($parts);
        return $entity . "Collection";
    }
    //phpcs:enable Magento2.Functions.StaticFunction
}

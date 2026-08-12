<?php
/**
 * This file is part of the MagePsycho_OpenTelemetry package.
 *
 * @author    Raj KB <rajkb@magepsycho.com>
 * @copyright Copyright (c) 2025 MumzWorld (https://www.mumzworld.com) - original work
 * @copyright Copyright (c) 2025 MagePsycho (https://www.magepsycho.com) - modifications
 */
declare(strict_types=1);

namespace MagePsycho\OpenTelemetry\Instrumentation\Http;

use Closure;
use GuzzleHttp\Client;
use Magento\Framework\HTTP\Adapter\Curl as CurlAdapter;
use Magento\Framework\HTTP\AsyncClient\Request as AsyncRequest;
use Magento\Framework\HTTP\AsyncClientInterface;
use Magento\Framework\HTTP\ClientInterface as MagentoHttpClient;
use Magento\Framework\HTTP\LaminasClient;
use MagePsycho\OpenTelemetry\Instrumentation\AbstractInstrumentation;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\ArrayAccessGetterSetter;
use OpenTelemetry\SemConv\TraceAttributes;
use Throwable;
use function OpenTelemetry\Instrumentation\hook;

/**
 * Spans for every outbound HTTP call Magento can make.
 *
 * Guzzle alone is not enough: Magento's own traffic never touches it. Client\Curl and Client\Socket
 * drive the curl extension directly, and LaminasClient - which is what PayPal Payflow, USPS, DHL, the
 * currency imports and Payment\Gateway\Http\Client\Zend all use - goes through Laminas on top of
 * Adapter\Curl. Each is hooked on its own, so the slowest call in a checkout is no longer invisible.
 *
 * Only the host and path are recorded, never the full URL: query strings routinely carry API keys and
 * tokens, and spans outlive the request that produced them.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class HttpClientInstrumentation extends AbstractInstrumentation
{
    public const INSTRUMENTATION_NAME = 'io.opentelemetry.contrib.php.magento.http.client';
    private const SPAN_NAME_PREFIX = 'External:';

    private const UNKNOWN_METHOD = 'UNKNOWN';
    private const UNKNOWN_HOST = 'NA';

    /**
     * Requests handed to Adapter\Curl::write(), keyed by object id, awaiting their read().
     *
     * The adapter splits one call across two methods and only write() is told where the request is
     * going, so the target has to be carried across to read(), which is where the network wait is.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private static array $pendingCurlRequests = [];

    /**
     * Object ids whose read() opened no span, because no write() preceded it.
     *
     * Without this the post callback would call endSpan() for a span that was never started, and
     * detach a scope belonging to somebody else.
     *
     * @var array<int, true>
     */
    private static array $skippedCurlReads = [];

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
        self::instrumentGuzzleClient();
        self::instrumentMagentoClient();
        self::instrumentLaminasClient();
        self::instrumentAsyncClient();
        self::instrumentCurlAdapter();
    }

    /**
     * GuzzleHttp\Client::send - used by the SaaS and services-connector modules, not by Magento core.
     *
     * @return void
     */
    private static function instrumentGuzzleClient(): void
    {
        self::hookOutboundCall(
            Client::class,
            'send',
            static function (object $subject, array $params): array {
                $request = $params[0] ?? null;
                if (!is_object($request)
                    || !method_exists($request, 'getMethod')
                    || !method_exists($request, 'getUri')
                ) {
                    return [self::UNKNOWN_METHOD, ''];
                }

                return [(string)$request->getMethod(), (string)$request->getUri()];
            },
            static function (object $subject, array $params): ?array {
                $request = $params[0] ?? null;
                /* PSR-7 requests are immutable, so the parameter has to be replaced wholesale. */
                if (!is_object($request) || !method_exists($request, 'withHeader')) {
                    return null;
                }

                foreach (self::traceHeaders() as $name => $value) {
                    $request = $request->withHeader($name, $value);
                }

                return [$request, $params[1] ?? []];
            }
        );
    }

    /**
     * Framework\HTTP\ClientInterface::get()/post().
     *
     * Hooked on the interface, so Client\Curl, Client\Socket and any third-party implementation are
     * all covered. makeRequest() would be the single hook point and is reachable here even though it
     * is protected - but it exists only on Client\Curl, so the interface is the better seam and costs
     * nothing: get() and post() are the whole request-issuing surface it declares.
     *
     * @return void
     */
    private static function instrumentMagentoClient(): void
    {
        foreach (['get' => 'GET', 'post' => 'POST'] as $methodName => $method) {
            self::hookOutboundCall(
                MagentoHttpClient::class,
                $methodName,
                static function (object $subject, array $params) use ($method): array {
                    return [$method, (string)($params[0] ?? '')];
                },
                static function (object $subject, array $params): ?array {
                    /*
                     * Headers live on the client here, not in the call, so the parameters are left
                     * alone. addHeader() is on Client\Curl and Client\Socket but not on the interface
                     * itself, hence the guard - a third-party implementation without it simply goes
                     * uninjected rather than fatal.
                     */
                    if (method_exists($subject, 'addHeader')) {
                        foreach (self::traceHeaders() as $name => $value) {
                            $subject->addHeader($name, $value);
                        }
                    }

                    return null;
                }
            );
        }
    }

    /**
     * Framework\HTTP\LaminasClient::send().
     *
     * The client that carries the calls worth seeing, and the one nothing else reaches: it extends
     * Laminas\Http\Client, so it implements no part of the interface hooked above.
     *
     * send() takes an optional request that overrides the one held on the client, so the argument
     * wins when it is there and the client's own state answers otherwise.
     *
     * @return void
     */
    private static function instrumentLaminasClient(): void
    {
        self::hookOutboundCall(
            LaminasClient::class,
            'send',
            static function (object $subject, array $params): array {
                $request = $params[0] ?? null;
                if (is_object($request)
                    && method_exists($request, 'getMethod')
                    && method_exists($request, 'getUriString')
                ) {
                    return [(string)$request->getMethod(), (string)$request->getUriString()];
                }

                $method = method_exists($subject, 'getMethod') ? (string)$subject->getMethod() : self::UNKNOWN_METHOD;
                $uri = method_exists($subject, 'getUri') ? (string)$subject->getUri() : '';

                return [$method, $uri];
            },
            static function (object $subject, array $params): ?array {
                /* Whichever request is about to be sent is the one that must carry the headers. */
                $request = $params[0] ?? null;
                if (!is_object($request) && method_exists($subject, 'getRequest')) {
                    $request = $subject->getRequest();
                }

                if (!is_object($request) || !method_exists($request, 'getHeaders')) {
                    return null;
                }

                $headers = $request->getHeaders();
                if (!is_object($headers) || !method_exists($headers, 'addHeaderLine')) {
                    return null;
                }

                foreach (self::traceHeaders() as $name => $value) {
                    /* Laminas appends rather than replaces, so an existing header must go first. */
                    if (method_exists($headers, 'has')
                        && method_exists($headers, 'get')
                        && method_exists($headers, 'removeHeader')
                        && $headers->has($name)
                    ) {
                        $headers->removeHeader($headers->get($name));
                    }

                    $headers->addHeaderLine($name, $value);
                }

                return null;
            }
        );
    }

    /**
     * Framework\HTTP\AsyncClientInterface::request().
     *
     * Hooked on the Magento interface rather than on the Guzzle call underneath it: GuzzleAsyncClient
     * issues requestAsync(), which the send() hook above never sees.
     *
     * The span covers dispatch only. The response is deferred, so the wait belongs to whoever calls
     * get() on the returned object, not here.
     *
     * @return void
     */
    private static function instrumentAsyncClient(): void
    {
        self::hookOutboundCall(
            AsyncClientInterface::class,
            'request',
            static function (object $subject, array $params): array {
                $request = $params[0] ?? null;
                if (!is_object($request)
                    || !method_exists($request, 'getMethod')
                    || !method_exists($request, 'getUrl')
                ) {
                    return [self::UNKNOWN_METHOD, ''];
                }

                return [(string)$request->getMethod(), (string)$request->getUrl()];
            },
            static function (object $subject, array $params): ?array {
                $request = $params[0] ?? null;
                /* AsyncClient\Request has no setters, so a replacement carrying the headers is built. */
                if (!$request instanceof AsyncRequest) {
                    return null;
                }

                return [
                    new AsyncRequest(
                        $request->getUrl(),
                        $request->getMethod(),
                        array_merge($request->getHeaders(), self::traceHeaders()),
                        $request->getBody()
                    ),
                ];
            }
        );
    }

    /**
     * Framework\HTTP\Adapter\Curl, the transport one level below a client.
     *
     * Timed on read(), where the network wait is - write() only hands the request over, and is hooked
     * purely to learn where the call is going.
     *
     * Note this fires for Laminas traffic too, nested inside the LaminasClient span above, because
     * Laminas\Http\Client::setAdapter() builds this class directly. That nesting is the useful kind:
     * the client span carries the semantic call, this one carries the transport.
     *
     * @return void
     */
    private static function instrumentCurlAdapter(): void
    {
        hook(
            CurlAdapter::class,
            'write',
            static function (object $subject, array $params): ?array {
                self::$pendingCurlRequests[spl_object_id($subject)] = [
                    (string)($params[0] ?? self::UNKNOWN_METHOD),
                    (string)($params[1] ?? ''),
                ];

                $headers = $params[3] ?? [];
                /*
                 * Only inject when nobody upstream did. Laminas prepares its own headers and hands
                 * them straight to this method, so a client that already injected would otherwise get
                 * a second traceparent appended here - normalizeHeaders() accepts both an assoc entry
                 * and a "Name: value" line, so duplicates would survive all the way to the wire.
                 */
                if (!is_array($headers) || self::hasTraceHeader($headers)) {
                    return null;
                }

                $params[3] = array_merge($headers, self::traceHeaders());

                return $params;
            },
            null,
        );

        hook(
            CurlAdapter::class,
            'read',
            static function (
                object  $subject,
                array   $params,
                string  $class,
                string  $function,
                ?string $filename,
                ?int    $lineno,
            ): void {
                $objectId = spl_object_id($subject);
                if (!isset(self::$pendingCurlRequests[$objectId])) {
                    /* A read with no write in front of it - there is no request to describe. */
                    self::$skippedCurlReads[$objectId] = true;

                    return;
                }

                [$method, $url] = self::$pendingCurlRequests[$objectId];
                /* Consumed, so a second read() on the same adapter is not attributed to it. */
                unset(self::$pendingCurlRequests[$objectId]);

                self::startOutboundSpan($method, $url, $function, $class, $filename, $lineno);
            },
            static function (
                object     $subject,
                array      $params,
                mixed      $returnValue,
                ?Throwable $exception,
            ): void {
                $objectId = spl_object_id($subject);
                if (isset(self::$skippedCurlReads[$objectId])) {
                    unset(self::$skippedCurlReads[$objectId]);

                    return;
                }

                self::endSpan($exception);
            },
        );
    }

    /**
     * Register the usual pre/post pair around a call that goes out over the network.
     *
     * @param string $className Class or interface to hook.
     * @param string $methodName Method to hook.
     * @param Closure $describe Receives ($subject, $params), returns [method, url].
     * @param Closure|null $inject Receives ($subject, $params) after the span is open and returns the
     *                             call's parameters carrying the trace headers, or null to leave them
     *                             untouched (for clients whose headers live on the subject).
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private static function hookOutboundCall(
        string $className,
        string $methodName,
        Closure $describe,
        ?Closure $inject = null
    ): void {
        hook(
            $className,
            $methodName,
            static function (
                object  $subject,
                array   $params,
                string  $class,
                string  $function,
                ?string $filename,
                ?int    $lineno,
            ) use ($describe, $inject): ?array {
                [$method, $url] = $describe($subject, $params);

                self::startOutboundSpan((string)$method, (string)$url, $function, $class, $filename, $lineno);

                /* Must run after the span is open - it is that span the downstream service continues. */
                return $inject === null ? null : $inject($subject, $params);
            },
            static function (
                object     $subject,
                array      $params,
                mixed      $returnValue,
                ?Throwable $exception,
            ): void {
                self::endSpan($exception);
            },
        );
    }

    /**
     * Start a client span named "External: GET api.example.com/v1/orders".
     *
     * @param string $method
     * @param string $url
     * @param string $function
     * @param string $class
     * @param string|null $filename
     * @param int|null $lineno
     * @return void
     */
    private static function startOutboundSpan(
        string $method,
        string $url,
        string $function,
        string $class,
        ?string $filename,
        ?int $lineno,
    ): void {
        [$host, $path] = self::splitUrl($url);
        $method = $method === '' ? self::UNKNOWN_METHOD : strtoupper($method);

        $builder = self::createSpanBuilder(
            sprintf('%s %s %s%s', self::SPAN_NAME_PREFIX, $method, $host, $path),
            $function,
            $class,
            $filename,
            $lineno,
        )
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $method)
            ->setAttribute(TraceAttributes::SERVER_ADDRESS, $host)
            ->setAttribute(TraceAttributes::URL_PATH, $path);

        self::startSpanAndAttachToContext($builder);
    }

    /**
     * The trace context of the span that is currently open, as headers to put on the wire.
     *
     * This is what makes an outbound call part of the same distributed trace instead of the start of
     * a new one on the far side. Which headers appear is the propagator's business, not ours -
     * OTEL_PROPAGATORS decides between tracecontext, baggage, b3 and the rest.
     *
     * @return array<string, string>
     */
    private static function traceHeaders(): array
    {
        $carrier = [];
        Globals::propagator()->inject($carrier, ArrayAccessGetterSetter::getInstance(), Context::getCurrent());

        return array_filter($carrier, 'is_string');
    }

    /**
     * Whether a carrier already carries any header the propagator would write.
     *
     * @param array<mixed> $headers
     * @return bool
     */
    private static function hasTraceHeader(array $headers): bool
    {
        $fields = array_map('strtolower', Globals::propagator()->fields());
        if (!$fields) {
            return false;
        }

        foreach ($headers as $key => $value) {
            /* Assoc entries key by name; list entries are raw "Name: value" lines. */
            $name = is_int($key) ? strtok((string)$value, ':') : (string)$key;
            if (is_string($name) && in_array(strtolower(trim($name)), $fields, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Host and path of a URL, and nothing else.
     *
     * The query string is dropped rather than truncated: it is where API keys, tokens and signatures
     * live, and a span is exported to a backend that keeps it.
     *
     * @param string $url
     * @return array{0: string, 1: string}
     */
    private static function splitUrl(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            return [self::UNKNOWN_HOST, ''];
        }

        //phpcs:ignore Magento2.Functions.DiscouragedFunction
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return [self::UNKNOWN_HOST, ''];
        }

        $host = (string)($parts['host'] ?? '');
        $path = (string)($parts['path'] ?? '');

        if ($host === '') {
            /* A relative target: the path is all there is, and it must not carry the query. */
            return [self::UNKNOWN_HOST, $path];
        }

        return [$host, $path];
    }
    //phpcs:enable Magento2.Functions.StaticFunction
}

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
use Magento\Framework\HTTP\AsyncClientInterface;
use Magento\Framework\HTTP\ClientInterface as MagentoHttpClient;
use Magento\Framework\HTTP\LaminasClient;
use MagePsycho\OpenTelemetry\Instrumentation\AbstractInstrumentation;
use OpenTelemetry\API\Trace\SpanKind;
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
        self::hookOutboundCall(Client::class, 'send', static function (object $subject, array $params): array {
            $request = $params[0] ?? null;
            if (!is_object($request)
                || !method_exists($request, 'getMethod')
                || !method_exists($request, 'getUri')
            ) {
                return [self::UNKNOWN_METHOD, ''];
            }

            return [(string)$request->getMethod(), (string)$request->getUri()];
        });
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
        self::hookOutboundCall(LaminasClient::class, 'send', static function (object $subject, array $params): array {
            $request = $params[0] ?? null;
            if (is_object($request) && method_exists($request, 'getMethod') && method_exists($request, 'getUriString')) {
                return [(string)$request->getMethod(), (string)$request->getUriString()];
            }

            $method = method_exists($subject, 'getMethod') ? (string)$subject->getMethod() : self::UNKNOWN_METHOD;
            $uri = method_exists($subject, 'getUri') ? (string)$subject->getUri() : '';

            return [$method, $uri];
        });
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
                if (!is_object($request) || !method_exists($request, 'getMethod') || !method_exists($request, 'getUrl')) {
                    return [self::UNKNOWN_METHOD, ''];
                }

                return [(string)$request->getMethod(), (string)$request->getUrl()];
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
            static function (object $subject, array $params): void {
                self::$pendingCurlRequests[spl_object_id($subject)] = [
                    (string)($params[0] ?? self::UNKNOWN_METHOD),
                    (string)($params[1] ?? ''),
                ];
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
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private static function hookOutboundCall(string $className, string $methodName, Closure $describe): void
    {
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
            ) use ($describe): void {
                [$method, $url] = $describe($subject, $params);

                self::startOutboundSpan((string)$method, (string)$url, $function, $class, $filename, $lineno);
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

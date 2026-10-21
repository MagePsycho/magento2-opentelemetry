<?php
/**
 * This file is part of the MagePsycho_OpenTelemetry package.
 *
 * @author    Raj KB <rajkb@magepsycho.com>
 * @copyright Copyright (c) 2025 MagePsycho (https://www.magepsycho.com)
 */
declare(strict_types=1);

namespace MagePsycho\OpenTelemetry\Instrumentation\Cli;

use Magento\Framework\Amqp\Bulk\Exchange as AmqpBulkExchange;
use Magento\Framework\Amqp\Exchange as AmqpExchange;
use Magento\Framework\MessageQueue\Envelope;
use Magento\Framework\MessageQueue\EnvelopeInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use MagePsycho\OpenTelemetry\Instrumentation\AbstractInstrumentation;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

/**
 * Publishing to the message queue, and carrying the trace across to the consumer.
 *
 * Every publish gets a PRODUCER span. On RabbitMQ the trace context also travels with the message, in its
 * application_headers, so the consumer's trace links back to the request that queued the work - an async
 * REST call to the bulk operation that ran it later. The MySQL queue stores only the topic and the body,
 * so there the publish span is all there is.
 */
class QueuePublisherInstrumentation extends AbstractInstrumentation
{
    public const INSTRUMENTATION_NAME = 'io.opentelemetry.contrib.php.magento.queue';
    private const SPAN_NAME_PREFIX = 'Queue:';

    /**
     * Open publish() calls: PublisherPool hands each one to the publisher for its connection.
     *
     * @var int
     */
    private static int $publishDepth = 0;

    /**
     * Per open publish(): whether it started a span.
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
        self::instrumentPublish();
        if (class_exists(AMQPTable::class)) {
            self::instrumentAmqpEnqueue(AmqpExchange::class);
            self::instrumentAmqpEnqueue(AmqpBulkExchange::class);
        }
    }

    /**
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private static function instrumentPublish(): void
    {
        self::hookOutermost(
            PublisherInterface::class,
            'publish',
            static function (
                PublisherInterface $subject,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) {
                if (self::$publishDepth++ > 0) {
                    self::$started[] = false;
                    return;
                }

                $topic = (string)($params[0] ?? 'unknown');
                $builder = self::createSpanBuilder(
                    sprintf('%s publish %s', self::SPAN_NAME_PREFIX, $topic),
                    $function,
                    $class,
                    $filename,
                    $lineno
                )
                    ->setSpanKind(SpanKind::KIND_PRODUCER)
                    ->setAttribute('messaging.operation.type', 'send')
                    ->setAttribute('messaging.destination.name', $topic);
                // Bulk publishers take a list of messages for one topic.
                if (is_array($params[1] ?? null) && array_is_list($params[1])) {
                    $builder->setAttribute('messaging.batch.message_count', count($params[1]));
                }

                self::startSpanAndAttachToContext($builder);
                self::$started[] = true;
            },
            static function (PublisherInterface $subject, array $params, mixed $returnValue, ?Throwable $exception) {
                self::$publishDepth--;
                if (array_pop(self::$started)) {
                    self::endSpan($exception);
                }
            },
        );
    }

    /**
     * Add the trace context to each envelope's AMQP application_headers, replacing the envelope argument.
     *
     * @param string $exchangeClass
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private static function instrumentAmqpEnqueue(string $exchangeClass): void
    {
        self::hookOutermost(
            $exchangeClass,
            'enqueue',
            static function (object $subject, array $params) {
                $envelopes = $params[1] ?? null;
                if ($envelopes instanceof EnvelopeInterface) {
                    $params[1] = self::withTraceContext($envelopes);
                    return $params;
                }
                if (is_array($envelopes)) {
                    $params[1] = array_map(
                        static fn ($envelope) => $envelope instanceof EnvelopeInterface
                            ? self::withTraceContext($envelope)
                            : $envelope,
                        $envelopes
                    );
                    return $params;
                }

                return null;
            },
        );
    }

    /**
     * @param EnvelopeInterface $envelope
     * @return EnvelopeInterface
     */
    private static function withTraceContext(EnvelopeInterface $envelope): EnvelopeInterface
    {
        $carrier = [];
        Globals::propagator()->inject($carrier);
        if (!$carrier) {
            return $envelope;
        }

        $properties = $envelope->getProperties();
        $headers = $properties['application_headers'] ?? null;
        if (!$headers instanceof AMQPTable) {
            $headers = new AMQPTable(is_array($headers) ? $headers : []);
        }
        foreach ($carrier as $key => $value) {
            $headers->set($key, $value);
        }
        $properties['application_headers'] = $headers;

        return new Envelope($envelope->getBody(), $properties);
    }
    //phpcs:enable Magento2.Functions.StaticFunction
}

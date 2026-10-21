<?php
/**
 * This file is part of the MagePsycho_OpenTelemetry package.
 *
 * @author    Raj KB <rajkb@magepsycho.com>
 * @copyright Copyright (c) 2025 MagePsycho (https://www.magepsycho.com)
 */
declare(strict_types=1);

namespace MagePsycho\OpenTelemetry\Instrumentation\Cli;

use Magento\Framework\MessageQueue\Consumer;
use Magento\Framework\MessageQueue\ConsumerConfigurationInterface;
use Magento\Framework\MessageQueue\ConsumerFactory;
use Magento\Framework\MessageQueue\EnvelopeInterface;
use Magento\Framework\MessageQueue\MessageProcessorInterface;
use MagePsycho\OpenTelemetry\Instrumentation\AbstractInstrumentation;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanKind;
use Throwable;

/**
 * Message queue consumers (queue:consumers:start), one trace per message.
 *
 * A consumer process runs for minutes or hours and works through thousands of messages. Nested
 * under the CLI span, every message would land in one trace that is only exported when the
 * process exits - if it ever does - and the per-process span limits would be spent on the first
 * few messages. Each message therefore starts a new root span, linked back to the process span.
 */
class QueueConsumerInstrumentation extends AbstractInstrumentation
{
    public const INSTRUMENTATION_NAME = 'io.opentelemetry.contrib.php.magento.queue';
    private const SPAN_NAME_PREFIX = 'Queue:';

    /**
     * Consumer names by consumer object, since a Consumer does not expose its own.
     *
     * @var array<int, string>
     */
    private static array $consumerNames = [];

    /**
     * Per open dispatch: the startUnitOfWork() state, or false when it started no span.
     *
     * @var array<int, array|false>
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
        self::instrumentConsumerFactory();
        self::instrumentConsumerDispatch();
        self::instrumentBatchProcess();
    }

    /**
     * Remember the name each consumer was created under.
     *
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private static function instrumentConsumerFactory(): void
    {
        self::hookOutermost(
            ConsumerFactory::class,
            'get',
            null,
            // No return type, so the consumer is passed through untouched.
            static function (ConsumerFactory $subject, array $params, mixed $consumer, ?Throwable $exception) {
                if (is_object($consumer) && isset($params[0]) && is_string($params[0])) {
                    self::$consumerNames[spl_object_id($consumer)] = $params[0];
                }
            },
        );
    }

    /**
     * One root span per message handled by the standard consumer.
     *
     * Consumer::dispatchMessage() is private, but it is the one place both the subscribe (daemon) and
     * the --max-messages loop pass through with a single decoded message, for every connection type.
     *
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private static function instrumentConsumerDispatch(): void
    {
        self::hookOutermost(
            Consumer::class,
            'dispatchMessage',
            static function (
                Consumer $subject,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) {
                $envelope = $params[0] ?? null;
                if (!$envelope instanceof EnvelopeInterface) {
                    self::$started[] = false;
                    return;
                }

                $properties = $envelope->getProperties();
                $topic = (string)($properties['topic_name'] ?? 'unknown');
                $builder = self::createSpanBuilder(
                    sprintf('%s process %s', self::SPAN_NAME_PREFIX, $topic),
                    $function,
                    $class,
                    $filename,
                    $lineno
                )
                    ->setAttribute('messaging.system', self::messagingSystem($properties))
                    ->setAttribute('messaging.destination.name', $topic);

                if (isset($properties['message_id']) && is_scalar($properties['message_id'])) {
                    $builder->setAttribute('messaging.message.id', (string)$properties['message_id']);
                }
                if (isset($properties['retries']) && is_numeric($properties['retries'])) {
                    $builder->setAttribute('magento.queue.retries', (int)$properties['retries']);
                }

                self::startMessageSpan($builder, self::$consumerNames[spl_object_id($subject)] ?? null);
            },
            static function (Consumer $subject, array $params, mixed $returnValue, ?Throwable $exception) {
                self::endMessageSpan($exception);
            },
        );
    }

    /**
     * One root span per batch handled by a BatchConsumer, which merges messages before dispatch.
     *
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private static function instrumentBatchProcess(): void
    {
        self::hookOutermost(
            MessageProcessorInterface::class,
            'process',
            static function (
                MessageProcessorInterface $subject,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) {
                $configuration = $params[1] ?? null;
                $messages = $params[2] ?? [];
                $mergedMessages = $params[4] ?? [];

                // Merged messages are grouped by topic.
                $topics = is_array($mergedMessages) ? array_map('strval', array_keys($mergedMessages)) : [];
                $topic = $topics ? implode(',', $topics) : 'unknown';

                $builder = self::createSpanBuilder(
                    sprintf('%s process %s', self::SPAN_NAME_PREFIX, $topic),
                    $function,
                    $class,
                    $filename,
                    $lineno
                )
                    ->setAttribute('messaging.destination.name', $topic)
                    ->setAttribute('messaging.batch.message_count', is_array($messages) ? count($messages) : 0);

                $consumerName = $configuration instanceof ConsumerConfigurationInterface
                    ? $configuration->getConsumerName()
                    : null;
                self::startMessageSpan($builder, $consumerName);
            },
            static function (
                MessageProcessorInterface $subject,
                array $params,
                mixed $returnValue,
                ?Throwable $exception
            ) {
                self::endMessageSpan($exception);
            },
        );
    }

    /**
     * Start the span as the root of a new trace, on a fresh span budget.
     *
     * @param SpanBuilderInterface $builder
     * @param string|null $consumerName
     * @return void
     */
    private static function startMessageSpan(SpanBuilderInterface $builder, ?string $consumerName): void
    {
        // A message handled inside another message's span (a synchronous RPC call, say) stays in its trace.
        if (in_array(true, array_map('is_array', self::$started), true)) {
            self::$started[] = false;
            return;
        }

        $builder->setSpanKind(SpanKind::KIND_CONSUMER)
            ->setAttribute('messaging.operation.type', 'process');
        if ($consumerName) {
            $builder->setAttribute('messaging.consumer.group.name', $consumerName);
        }

        // Each message its own budget; polling queries between messages stay capped by the process's.
        self::$started[] = self::startUnitOfWork($builder);
    }

    /**
     * End the span opened by startMessageSpan(), if it opened one.
     *
     * @param Throwable|null $exception
     * @return void
     */
    private static function endMessageSpan(?Throwable $exception): void
    {
        $state = array_pop(self::$started);
        if (!is_array($state)) {
            return;
        }

        self::endUnitOfWork($state, $exception);
    }

    /**
     * The broker, told apart by the properties each driver puts on the envelope.
     *
     * @param array $properties
     * @return string
     */
    private static function messagingSystem(array $properties): string
    {
        if (isset($properties['delivery_tag'])) {
            return 'rabbitmq';
        }
        if (isset($properties['relation_id'])) {
            return 'mysql';
        }

        return 'magento';
    }
    //phpcs:enable Magento2.Functions.StaticFunction
}

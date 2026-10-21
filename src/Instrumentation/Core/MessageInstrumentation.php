<?php
/**
 * This file is part of the MagePsycho_OpenTelemetry package.
 *
 * @author    Raj KB <rajkb@magepsycho.com>
 * @copyright Copyright (c) 2025 MagePsycho (https://www.magepsycho.com)
 */
declare(strict_types=1);

namespace MagePsycho\OpenTelemetry\Instrumentation\Core;

use Magento\Framework\Message\Manager;
use Magento\Framework\Message\MessageInterface;
use MagePsycho\OpenTelemetry\Instrumentation\AbstractInstrumentation;
use OpenTelemetry\API\Trace\Span;
use Throwable;

/**
 * Error messages shown to the customer or admin user ("The requested qty is not available", "The payment
 * could not be processed", ...), as an event on the span that raised them.
 *
 * Magento turns many failures into a message and a redirect rather than an exception, so without this the
 * trace of a failed add-to-cart or checkout step looks like a success.
 */
class MessageInstrumentation extends AbstractInstrumentation
{
    public const INSTRUMENTATION_NAME = 'io.opentelemetry.contrib.php.magento.message';
    private const MAX_TEXT_LENGTH = 512;

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
        self::instrumentAddMessage();
    }

    /**
     * addError(), addErrorMessage(), addException() and addExceptionMessage() all end in addMessage().
     *
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private static function instrumentAddMessage(): void
    {
        self::hookOutermost(
            Manager::class,
            'addMessage',
            static function (Manager $subject, array $params) {
                $message = $params[0] ?? null;
                if (!$message instanceof MessageInterface || $message->getType() !== MessageInterface::TYPE_ERROR) {
                    return null;
                }

                $span = Span::getCurrent();
                if ($span->getContext()->isValid()) {
                    $span->addEvent('magento.message', [
                        'magento.message.type' => $message->getType(),
                        'magento.message.text' => self::truncateString(
                            strip_tags((string)$message->getText()),
                            self::MAX_TEXT_LENGTH
                        ),
                    ]);
                }

                return null;
            },
        );
    }
    //phpcs:enable Magento2.Functions.StaticFunction
}

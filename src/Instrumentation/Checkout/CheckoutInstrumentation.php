<?php
/**
 * This file is part of the MagePsycho_OpenTelemetry package.
 *
 * @author    Raj KB <rajkb@magepsycho.com>
 * @copyright Copyright (c) 2025 MagePsycho (https://www.magepsycho.com)
 */
declare(strict_types=1);

namespace MagePsycho\OpenTelemetry\Instrumentation\Checkout;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteManagement;
use Magento\Sales\Api\Data\OrderInterface;
use MagePsycho\OpenTelemetry\Instrumentation\AbstractInstrumentation;
use Throwable;

/**
 * Order placement, for every channel: the storefront checkout, REST and GraphQL all end in
 * QuoteManagement::placeOrder() and submit().
 *
 * A failed placement - payment declined, stock gone, a total that no longer validates - throws, so it
 * shows as an error span with the exception, searchable by payment method. No customer data is recorded.
 */
class CheckoutInstrumentation extends AbstractInstrumentation
{
    public const INSTRUMENTATION_NAME = 'io.opentelemetry.contrib.php.magento.checkout';
    private const SPAN_NAME_PREFIX = 'Checkout:';

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
        self::instrumentPlaceOrder();
        self::instrumentSubmit();
    }

    /**
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private static function instrumentPlaceOrder(): void
    {
        self::hookOutermost(
            QuoteManagement::class,
            'placeOrder',
            static function (
                QuoteManagement $subject,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) {
                $builder = self::createSpanBuilder(
                    sprintf('%s placeOrder', self::SPAN_NAME_PREFIX),
                    $function,
                    $class,
                    $filename,
                    $lineno
                );
                if (is_numeric($params[0] ?? null)) {
                    $builder->setAttribute('magento.quote.id', (int)$params[0]);
                }
                $paymentMethod = $params[1] ?? null;
                if (is_object($paymentMethod) && method_exists($paymentMethod, 'getMethod')
                    && $paymentMethod->getMethod()
                ) {
                    $builder->setAttribute('magento.payment.method', (string)$paymentMethod->getMethod());
                }

                self::startSpanAndAttachToContext($builder);
            },
            static function (QuoteManagement $subject, array $params, mixed $orderId, ?Throwable $exception) {
                self::endSpan($exception, is_numeric($orderId) ? ['magento.order.id' => (int)$orderId] : []);
            },
        );
    }

    /**
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private static function instrumentSubmit(): void
    {
        self::hookOutermost(
            QuoteManagement::class,
            'submit',
            static function (
                QuoteManagement $subject,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) {
                $builder = self::createSpanBuilder(
                    sprintf('%s submit', self::SPAN_NAME_PREFIX),
                    $function,
                    $class,
                    $filename,
                    $lineno
                );

                $quote = $params[0] ?? null;
                if ($quote instanceof Quote) {
                    $builder
                        ->setAttribute('magento.quote.id', (int)$quote->getId())
                        ->setAttribute('magento.quote.items_count', (int)$quote->getItemsCount())
                        ->setAttribute('magento.quote.is_guest', (bool)$quote->getCustomerIsGuest());
                    $method = $quote->getPayment()->getMethod();
                    if ($method) {
                        $builder->setAttribute('magento.payment.method', (string)$method);
                    }
                }

                self::startSpanAndAttachToContext($builder);
            },
            static function (QuoteManagement $subject, array $params, mixed $order, ?Throwable $exception) {
                $attributes = [];
                if ($order instanceof OrderInterface) {
                    $attributes['magento.order.id'] = (int)$order->getEntityId();
                    $attributes['magento.order.increment_id'] = (string)$order->getIncrementId();
                }
                self::endSpan($exception, $attributes);
            },
        );
    }
    //phpcs:enable Magento2.Functions.StaticFunction
}

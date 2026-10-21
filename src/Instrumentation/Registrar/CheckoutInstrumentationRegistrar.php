<?php
/**
 * This file is part of the MagePsycho_OpenTelemetry package.
 *
 * @author    Raj KB <rajkb@magepsycho.com>
 * @copyright Copyright (c) 2025 MagePsycho (https://www.magepsycho.com)
 */
declare(strict_types=1);

namespace MagePsycho\OpenTelemetry\Instrumentation\Registrar;

use MagePsycho\OpenTelemetry\Instrumentation\Checkout\CheckoutInstrumentation;
use MagePsycho\OpenTelemetry\Instrumentation\InstrumentationRegistrarInterface;

class CheckoutInstrumentationRegistrar implements InstrumentationRegistrarInterface
{
    /**
     * @inheritdoc
     * phpcs:disable Magento2.Functions.StaticFunction
     */
    public static function register(): void
    {
        CheckoutInstrumentation::register();
    }
    //phpcs:enable Magento2.Functions.StaticFunction
}

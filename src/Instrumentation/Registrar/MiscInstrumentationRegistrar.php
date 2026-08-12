<?php
/**
 * This file is part of the MagePsycho_OpenTelemetry package.
 *
 * @author    Raj KB <rajkb@magepsycho.com>
 * @copyright Copyright (c) 2025 MumzWorld (https://www.mumzworld.com) - original work
 * @copyright Copyright (c) 2025 MagePsycho (https://www.magepsycho.com) - modifications
 */
declare(strict_types=1);

namespace MagePsycho\OpenTelemetry\Instrumentation\Registrar;

use MagePsycho\OpenTelemetry\Instrumentation\Misc\AbstractDbInstrumentation;
use MagePsycho\OpenTelemetry\Instrumentation\Misc\HttpClientInstrumentation;
use MagePsycho\OpenTelemetry\Instrumentation\Misc\InventoryInstrumentation;
use MagePsycho\OpenTelemetry\Instrumentation\Misc\PricingInstrumentation;
use MagePsycho\OpenTelemetry\Instrumentation\Misc\RepositoryInstrumentation;
use MagePsycho\OpenTelemetry\Instrumentation\Misc\SalesRuleInstrumentation;
use MagePsycho\OpenTelemetry\Instrumentation\Misc\ShippingInstrumentation;
use MagePsycho\OpenTelemetry\Instrumentation\InstrumentationRegistrarInterface;
use MagePsycho\OpenTelemetry\Instrumentation\Misc\TotalCollectorInstrumentation;

class MiscInstrumentationRegistrar implements InstrumentationRegistrarInterface
{
    /**
     * @inheirtdoc
     * phpcs:disable Magento2.Functions.StaticFunction
     */
    public static function register(): void
    {
        SalesRuleInstrumentation::register();
        InventoryInstrumentation::register();
        ShippingInstrumentation::register();
        PricingInstrumentation::register();
        TotalCollectorInstrumentation::register();
        AbstractDbInstrumentation::register();
        RepositoryInstrumentation::register();
        HttpClientInstrumentation::register();
    }
    //phpcs:enable Magento2.Functions.StaticFunction
}

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

use MagePsycho\OpenTelemetry\Instrumentation\Cache\CacheInstrumentation;
use MagePsycho\OpenTelemetry\Instrumentation\Cache\RedisInstrumentation;
use MagePsycho\OpenTelemetry\Instrumentation\Cache\VarnishInstrumentation;
use MagePsycho\OpenTelemetry\Instrumentation\InstrumentationRegistrarInterface;

class CacheInstrumentationRegistrar implements InstrumentationRegistrarInterface
{
    /**
     * @inheirtdoc
     * phpcs:disable Magento2.Functions.StaticFunction
     */
    public static function register(): void
    {
        CacheInstrumentation::register();
        RedisInstrumentation::register();
        VarnishInstrumentation::register();
    }
    //phpcs:enable Magento2.Functions.StaticFunction
}

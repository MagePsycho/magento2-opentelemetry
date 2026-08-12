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

use MagePsycho\OpenTelemetry\Instrumentation\Entity\EavEntityInstrumentation;
use MagePsycho\OpenTelemetry\Instrumentation\Entity\FlatEntityInstrumentation;
use MagePsycho\OpenTelemetry\Instrumentation\InstrumentationRegistrarInterface;

class EntityInstrumentationRegistrar implements InstrumentationRegistrarInterface
{
    /**
     * @inheritdoc
     * phpcs:disable Magento2.Functions.StaticFunction
     */
    public static function register(): void
    {
        EavEntityInstrumentation::register();
        FlatEntityInstrumentation::register();
    }
    //phpcs:enable Magento2.Functions.StaticFunction
}

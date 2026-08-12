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

use MagePsycho\OpenTelemetry\Instrumentation\Cli\CliInstrumentation;
use MagePsycho\OpenTelemetry\Instrumentation\Cli\CronInstrumentation;
use MagePsycho\OpenTelemetry\Instrumentation\Cli\IndexerInstrumentation;
use MagePsycho\OpenTelemetry\Instrumentation\InstrumentationRegistrarInterface;

class CliInstrumentationRegistrar implements InstrumentationRegistrarInterface
{
    /**
     * @inheirtdoc
     * phpcs:disable Magento2.Functions.StaticFunction
     */
    public static function register(): void
    {
        CliInstrumentation::register();
        CronInstrumentation::register();
        IndexerInstrumentation::register();
    }
    //phpcs:enable Magento2.Functions.StaticFunction
}

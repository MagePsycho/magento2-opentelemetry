<?php
/**
 * This file is part of the MagePsycho_OpenTelemetry package.
 *
 * @author    Raj KB <rajkb@magepsycho.com>
 * @copyright Copyright (c) 2025 MumzWorld (https://www.mumzworld.com) - original work
 * @copyright Copyright (c) 2025 MagePsycho (https://www.magepsycho.com) - modifications
 */
namespace MagePsycho\OpenTelemetry\Instrumentation;

/**
 * Interface for instrumentation registrar classes.
 *
 * Each registrar class is responsible for grouping and registering
 * related OpenTelemetry instrumentations (e.g., Cache, CLI, HTTP).
 */
interface InstrumentationRegistrarInterface
{
    /**
     * Register all instrumentations for a given domain
     *
     * @return void
     * phpcs:disable Magento2.Functions.StaticFunction
     */
    public static function register(): void;
    //phpcs:enable Magento2.Functions.StaticFunction
}

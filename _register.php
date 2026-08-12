<?php

declare(strict_types=1);

use MagePsycho\OpenTelemetry\Instrumentation\InstrumentationGuard;
use MagePsycho\OpenTelemetry\Instrumentation\Registrar\CacheInstrumentationRegistrar;
use MagePsycho\OpenTelemetry\Instrumentation\Registrar\CliInstrumentationRegistrar;
use MagePsycho\OpenTelemetry\Instrumentation\Registrar\CoreInstrumentationRegistrar;
use MagePsycho\OpenTelemetry\Instrumentation\Registrar\DatabaseInstrumentationRegistrar;
use MagePsycho\OpenTelemetry\Instrumentation\Registrar\EntityInstrumentationRegistrar;
use MagePsycho\OpenTelemetry\Instrumentation\Registrar\HttpInstrumentationRegistrar;
use MagePsycho\OpenTelemetry\Instrumentation\Registrar\MiscInstrumentationRegistrar;

if (!InstrumentationGuard::isInstrumentationEligible()) {
    return;
}

CoreInstrumentationRegistrar::register();
CacheInstrumentationRegistrar::register();
DatabaseInstrumentationRegistrar::register();
CliInstrumentationRegistrar::register();
HttpInstrumentationRegistrar::register();
EntityInstrumentationRegistrar::register();

// Optional: enable extended instrumentation (pricing, inventory, shipping, sales rules, repositories, etc.)
// Set OTEL_MAGENTO_MISC_INSTRUMENTATION=true in your environment to activate.
if (getenv('OTEL_MAGENTO_MISC_INSTRUMENTATION') === 'true') {
    MiscInstrumentationRegistrar::register();
}

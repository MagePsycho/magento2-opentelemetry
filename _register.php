<?php

declare(strict_types=1);

use MagePsycho\OpenTelemetry\Instrumentation\Core\LogInstrumentation;
use MagePsycho\OpenTelemetry\Instrumentation\InstrumentationGuard;
use MagePsycho\OpenTelemetry\Instrumentation\Registrar\CacheInstrumentationRegistrar;
use MagePsycho\OpenTelemetry\Instrumentation\Registrar\CheckoutInstrumentationRegistrar;
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
CheckoutInstrumentationRegistrar::register();

// Optional: enable extended instrumentation (pricing, inventory, shipping, sales rules, repositories, etc.)
// Set OTEL_MAGENTO_MISC_INSTRUMENTATION=true in your environment or php.ini to activate.
if (InstrumentationGuard::isFlagEnabled(InstrumentationGuard::MISC_INSTRUMENTATION_FLAG)) {
    MiscInstrumentationRegistrar::register();
}

// Optional: trace ids in log records, and logged errors as span events.
// Set OTEL_MAGENTO_LOG_INSTRUMENTATION=true in your environment or php.ini to activate.
if (InstrumentationGuard::isFlagEnabled(InstrumentationGuard::LOG_INSTRUMENTATION_FLAG)) {
    LogInstrumentation::register();
}

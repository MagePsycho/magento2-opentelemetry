<?php
/**
 * This file is part of the MagePsycho_OpenTelemetry package.
 *
 * @author    Raj KB <rajkb@magepsycho.com>
 * @copyright Copyright (c) 2025 MumzWorld (https://www.mumzworld.com) - original work
 * @copyright Copyright (c) 2025 MagePsycho (https://www.magepsycho.com) - modifications
 */
declare(strict_types=1);

namespace MagePsycho\OpenTelemetry\Instrumentation;

use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\SdkAutoloader;
use Throwable;

/**
 * Guards against inappropriate OpenTelemetry instrumentation in Magento
 */
class InstrumentationGuard
{
    /**
     * Instrumentation name used for SDK checks
     */
    private const INSTRUMENTATION_NAME = 'magento2';

    /**
     * Opt-in for storefront requests, read from the environment or php.ini
     */
    public const FRONTEND_INSTRUMENTATION_FLAG = 'OTEL_MAGENTO_FRONTEND_INSTRUMENTATION';

    /**
     * Opt-in for extended instrumentation (pricing, inventory, shipping, ...), read from the environment or php.ini
     */
    public const MISC_INSTRUMENTATION_FLAG = 'OTEL_MAGENTO_MISC_INSTRUMENTATION';

    /**
     * Opt-in for log and trace correlation (trace ids in log records, errors as span events), read from
     * the environment or php.ini
     */
    public const LOG_INSTRUMENTATION_FLAG = 'OTEL_MAGENTO_LOG_INSTRUMENTATION';

    /**
     * List of URLs that should be excluded from instrumentation
     */
    private const EXCLUDED_URLS = [
        'health_check.php',
        'get.php',
        'static.php',
        'cron.php'
    ];

    /**
     * Scripts that should be excluded, matched against SCRIPT_NAME
     *
     * The entry points are listed here as well as in EXCLUDED_URLS because the SDK matches those against
     * REQUEST_URI, and a rewritten request never shows its script there: a missing image reaches get.php
     * as /media/catalog/product/...jpg. get.php also does its database work in createApplication(),
     * before Bootstrap::run() opens the root span, so every query it makes would export as a trace of its own.
     */
    private const EXCLUDED_SCRIPTS = [
        'health_check.php',
        'get.php',
        'static.php',
        'cron.php',
        'phpstan',
        'phpunit'
    ];

    /**
     * Paths that are allowed to be instrumented in web context, unless storefront instrumentation is enabled
     */
    private const ALLOWED_PATHS = [
        '/rest/',
        '/admin/',
        '/backend/',
        '/graphql'
    ];

    /**
     * Determines if the current context is eligible for OpenTelemetry instrumentation
     *
     * @return bool True if instrumentation should be applied, false otherwise
     * phpcs:disable Magento2.Functions.StaticFunction
     */
    public static function isInstrumentationEligible(): bool
    {
        try {
            self::setExcludedUrls();

            if (!self::isInstrumentationEnabled() || self::isExcludedScript() || !self::isAllowedRequest()) {
                return false;
            }

            return true;
        } catch (Throwable $e) {

            return false;
        }
    }

    /**
     * Sets excluded URLs as an environment variable for OpenTelemetry.
     *
     * @return void
     */
    private static function setExcludedUrls(): void
    {
        $urlsString = implode(',', self::EXCLUDED_URLS);
        //phpcs:ignore Magento2.Functions.DiscouragedFunction
        putenv(
            sprintf('OTEL_PHP_EXCLUDED_URLS=%s', $urlsString)
        );
    }

    /**
     * Whether an opt-in flag is set to true, in the environment or in php.ini
     *
     * php.ini is checked as well as the environment because it is the usual home for the rest of the
     * OTEL_* settings, and getenv() alone never sees it. Read leniently rather than through the SDK's
     * Configuration::getBoolean(): php.ini turns an unquoted `true` into "1", which the SDK rejects.
     *
     * @param string $flag
     * @return bool
     */
    public static function isFlagEnabled(string $flag): bool
    {
        //phpcs:ignore Magento2.Functions.DiscouragedFunction
        $value = getenv($flag);
        if ($value === false) {
            $value = get_cfg_var($flag);
        }

        return is_string($value) && filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Checks if instrumentation is enabled and available
     *
     * @return bool
     */
    private static function isInstrumentationEnabled(): bool
    {
        return class_exists(Sdk::class)
            && !Sdk::isInstrumentationDisabled(self::INSTRUMENTATION_NAME)
            && \extension_loaded('opentelemetry')
            && SdkAutoloader::isEnabled()
            && !SdkAutoloader::isExcludedUrl();
    }

    /**
     * Checks if the current script is in the excluded list
     *
     * @return bool
     */
    private static function isExcludedScript(): bool
    {
        //phpcs:ignore
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        foreach (self::EXCLUDED_SCRIPTS as $script) {
            // Entry points by exact file name, so get.php does not also catch widget.php.
            $matches = str_ends_with($script, '.php')
                ? basename($scriptName) === $script
                : str_contains($scriptName, $script);
            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if the current request should be instrumented
     *
     * @return bool
     */
    private static function isAllowedRequest(): bool
    {
        if (PHP_SAPI === 'cli' || self::isFlagEnabled(self::FRONTEND_INSTRUMENTATION_FLAG)) {
            return true;
        }
        //phpcs:ignore
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        foreach (self::ALLOWED_PATHS as $path) {
            if (self::matchesAllowedPath($requestUri, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determines if the given request URI matches an allowed path.
     *
     * - If the path is "/rest/", it checks whether "/rest/" appears anywhere in the request URI.
     * - For other paths, it checks if the request URI starts with the specified path.
     *
     * @param string $requestUri The current request URI.
     * @param string $path The allowed path to check against.
     * @return bool True if the request URI matches the path, false otherwise.
     */
    private static function matchesAllowedPath(string $requestUri, string $path): bool
    {
        if ($path === '/rest/') {
            return str_contains($requestUri, $path);
        }

        return str_starts_with($requestUri, $path);
    }
    //phpcs:enable Magento2.Functions.StaticFunction
}

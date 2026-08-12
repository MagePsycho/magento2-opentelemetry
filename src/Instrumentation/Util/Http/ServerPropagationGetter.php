<?php
/**
 * This file is part of the MagePsycho_OpenTelemetry package.
 *
 * @author    Raj KB <rajkb@magepsycho.com>
 * @copyright Copyright (c) 2025 MagePsycho (https://www.magepsycho.com)
 */
declare(strict_types=1);

namespace MagePsycho\OpenTelemetry\Instrumentation\Util\Http;

use OpenTelemetry\Context\Propagation\PropagationGetterInterface;

/**
 * Reads inbound trace headers out of $_SERVER.
 *
 * The equivalent classes in the Symfony, Laravel and Yii instrumentations read the framework's own
 * header bag. Magento cannot: the context has to be extracted in Bootstrap::run(), which is where the
 * root span opens, and at that point no request object exists yet. $_SERVER is the only carrier
 * available that early, and it is the one PHP populates from the actual request headers.
 *
 * Header names arrive as HTTP_TRACEPARENT / HTTP_X_FOO; Content-Type and Content-Length are the two
 * that PHP does not prefix.
 */
class ServerPropagationGetter implements PropagationGetterInterface
{
    private const PREFIX = 'HTTP_';

    /**
     * Headers PHP exposes without the HTTP_ prefix.
     */
    private const UNPREFIXED = ['CONTENT_TYPE', 'CONTENT_LENGTH'];

    /**
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * @return self
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @inheritdoc
     */
    public function keys($carrier): array
    {
        if (!is_array($carrier)) {
            return [];
        }

        $keys = [];
        foreach (array_keys($carrier) as $key) {
            $key = (string)$key;
            if (str_starts_with($key, self::PREFIX)) {
                $keys[] = self::toHeaderName(substr($key, strlen(self::PREFIX)));
            } elseif (in_array($key, self::UNPREFIXED, true)) {
                $keys[] = self::toHeaderName($key);
            }
        }

        return $keys;
    }

    /**
     * @inheritdoc
     */
    public function get($carrier, string $key): ?string
    {
        if (!is_array($carrier)) {
            return null;
        }

        $name = strtoupper(str_replace('-', '_', $key));

        foreach ([self::PREFIX . $name, $name] as $candidate) {
            if (isset($carrier[$candidate]) && is_scalar($carrier[$candidate])) {
                return (string)$carrier[$candidate];
            }
        }

        return null;
    }

    /**
     * TRACEPARENT -> traceparent, X_FOO -> x-foo.
     *
     * @param string $serverKey
     * @return string
     */
    private static function toHeaderName(string $serverKey): string
    {
        return strtolower(str_replace('_', '-', $serverKey));
    }
}

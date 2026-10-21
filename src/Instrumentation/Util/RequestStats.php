<?php
/**
 * This file is part of the MagePsycho_OpenTelemetry package.
 *
 * @author    Raj KB <rajkb@magepsycho.com>
 * @copyright Copyright (c) 2025 MagePsycho (https://www.magepsycho.com)
 */
declare(strict_types=1);

namespace MagePsycho\OpenTelemetry\Instrumentation\Util;

use OpenTelemetry\API\Trace\SpanInterface;

/**
 * Totals for one unit of work - an HTTP request, a CLI command, a queue message or a cron job - written
 * onto its root span when it ends.
 *
 * Counted whether or not a span was recorded, so the totals stay true after a span limit is reached:
 * a request with 3,000 queries shows magento.sql.count=3000 even though only the first 2,048 have spans.
 * Repeated statement shapes (the same query run again with different values) are what an N+1 looks like.
 */
class RequestStats
{
    /**
     * Distinct statement shapes tracked per unit; the rest are still counted, just not told apart.
     */
    private const MAX_FINGERPRINTS = 5000;

    /**
     * Only the start of a statement is fingerprinted - enough to tell shapes apart, cheap on bulk INSERTs.
     */
    private const FINGERPRINT_LENGTH = 1000;

    private const TOP_DUPLICATE_LENGTH = 300;

    /**
     * @var array{sql: int, fingerprints: array<string, array{0: int, 1: string}>, cacheLoads: int, cacheHits: int, dropped: array<string, int>}
     */
    private static array $current = [
        'sql' => 0,
        'fingerprints' => [],
        'cacheLoads' => 0,
        'cacheHits' => 0,
        'dropped' => [],
    ];

    /**
     * Start counting a nested unit of work from zero.
     *
     * @return array The enclosing unit's totals, to hand back to end().
     */
    public static function begin(): array
    {
        $saved = self::$current;
        self::$current = [
            'sql' => 0,
            'fingerprints' => [],
            'cacheLoads' => 0,
            'cacheHits' => 0,
            'dropped' => [],
        ];

        return $saved;
    }

    /**
     * Go back to counting the enclosing unit of work.
     *
     * @param array $saved What begin() returned.
     * @return void
     */
    public static function end(array $saved): void
    {
        self::$current = $saved;
    }

    /**
     * @param string $sql
     * @return void
     */
    public static function recordSql(string $sql): void
    {
        self::$current['sql']++;

        $fingerprint = self::fingerprint($sql);
        $key = md5($fingerprint);
        if (isset(self::$current['fingerprints'][$key])) {
            self::$current['fingerprints'][$key][0]++;
        } elseif (count(self::$current['fingerprints']) < self::MAX_FINGERPRINTS) {
            self::$current['fingerprints'][$key] = [1, $fingerprint];
        }
    }

    /**
     * @param bool $hit
     * @return void
     */
    public static function recordCacheLoad(bool $hit): void
    {
        self::$current['cacheLoads']++;
        if ($hit) {
            self::$current['cacheHits']++;
        }
    }

    /**
     * A span not recorded because its limit was reached.
     *
     * @param string $kind Short and fixed, e.g. "sql" or "block" - it becomes part of an attribute name.
     * @return void
     */
    public static function recordDropped(string $kind): void
    {
        self::$current['dropped'][$kind] = (self::$current['dropped'][$kind] ?? 0) + 1;
    }

    /**
     * Write the current unit's totals onto its root span.
     *
     * @param SpanInterface $span
     * @return void
     */
    public static function applyTo(SpanInterface $span): void
    {
        $stats = self::$current;

        $span->setAttribute('magento.sql.count', $stats['sql']);
        if ($stats['sql'] > 0) {
            $distinct = count($stats['fingerprints']);
            $span->setAttribute('magento.sql.distinct_count', $distinct);
            $span->setAttribute('magento.sql.duplicate_count', $stats['sql'] - $distinct);

            [$count, $fingerprint] = self::topDuplicate($stats['fingerprints']);
            if ($count > 1) {
                $span->setAttribute('magento.sql.top_duplicate_count', $count);
                $span->setAttribute('magento.sql.top_duplicate', $fingerprint);
            }
        }

        if ($stats['cacheLoads'] > 0) {
            $span->setAttribute('magento.cache.load_count', $stats['cacheLoads']);
            $span->setAttribute('magento.cache.hit_count', $stats['cacheHits']);
        }

        foreach ($stats['dropped'] as $kind => $count) {
            $span->setAttribute(sprintf('magento.spans.dropped.%s', $kind), $count);
        }

        $span->setAttribute('magento.memory.peak_bytes', memory_get_peak_usage(true));
    }

    /**
     * The statement shape: literals, numbers and IN-lists replaced, whitespace collapsed.
     *
     * Magento binds most values as ? placeholders already; this also folds the ones built into the SQL.
     *
     * @param string $sql
     * @return string
     */
    private static function fingerprint(string $sql): string
    {
        return trim((string)preg_replace(
            [
                "/'(?:[^'\\\\]|\\\\.)*'/s",
                '/\b\d+(?:\.\d+)?\b/',
                '/\(\s*\?(?:\s*,\s*\?)*\s*\)/',
                '/\s+/',
            ],
            ['?', '?', '(?+)', ' '],
            substr($sql, 0, self::FINGERPRINT_LENGTH)
        ));
    }

    /**
     * @param array<string, array{0: int, 1: string}> $fingerprints
     * @return array{0: int, 1: string}
     */
    private static function topDuplicate(array $fingerprints): array
    {
        $top = [0, ''];
        foreach ($fingerprints as $entry) {
            if ($entry[0] > $top[0]) {
                $top = $entry;
            }
        }

        return [$top[0], substr($top[1], 0, self::TOP_DUPLICATE_LENGTH)];
    }
}

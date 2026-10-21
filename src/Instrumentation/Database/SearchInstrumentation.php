<?php
/**
 * This file is part of the MagePsycho_OpenTelemetry package.
 *
 * @author    Raj KB <rajkb@magepsycho.com>
 * @copyright Copyright (c) 2025 MagePsycho (https://www.magepsycho.com)
 */
declare(strict_types=1);

namespace MagePsycho\OpenTelemetry\Instrumentation\Database;

use Magento\Elasticsearch8\Model\Client\Elasticsearch;
use Magento\OpenSearch\Model\SearchClient;
use MagePsycho\OpenTelemetry\Instrumentation\AbstractInstrumentation;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SemConv\TraceAttributes;
use Throwable;

/**
 * Search engine calls: catalog search and layered navigation queries, and indexing bulk writes.
 *
 * The query body is never recorded - it can carry customer search terms. What is recorded is enough to
 * tell a slow engine from a heavy query: the index, the engine's own "took", the hit total and how many
 * aggregations (layered navigation filters) were asked for.
 */
class SearchInstrumentation extends AbstractInstrumentation
{
    public const INSTRUMENTATION_NAME = 'io.opentelemetry.contrib.php.magento.search';
    private const SPAN_NAME_PREFIX = 'Search:';

    /**
     * Search client class => db.system.name
     */
    private const CLIENTS = [
        SearchClient::class => 'opensearch',
        Elasticsearch::class => 'elasticsearch',
    ];

    /**
     * @inheritdoc
     * phpcs:disable Magento2.Functions.StaticFunction
     */
    protected static function getInstrumentationName(): string
    {
        return self::INSTRUMENTATION_NAME;
    }

    /**
     * @inheritdoc
     */
    public static function register(): void
    {
        foreach (self::CLIENTS as $client => $system) {
            self::instrumentQuery($client, $system);
            self::instrumentBulkQuery($client, $system);
        }
    }

    /**
     * @param string $client
     * @param string $system
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private static function instrumentQuery(string $client, string $system): void
    {
        self::hookOutermost(
            $client,
            'query',
            static function (
                object $subject,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($system) {
                $query = is_array($params[0] ?? null) ? $params[0] : [];
                $builder = self::createSpanBuilder(
                    sprintf('%s query', self::SPAN_NAME_PREFIX),
                    $function,
                    $class,
                    $filename,
                    $lineno
                )
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(TraceAttributes::DB_SYSTEM_NAME, $system)
                    ->setAttribute(TraceAttributes::DB_OPERATION_NAME, 'search');
                if (is_string($query['index'] ?? null)) {
                    $builder->setAttribute(TraceAttributes::DB_COLLECTION_NAME, $query['index']);
                }
                $aggregations = $query['body']['aggregations'] ?? $query['body']['aggs'] ?? null;
                if (is_array($aggregations)) {
                    $builder->setAttribute('magento.search.aggregation_count', count($aggregations));
                }
                if (isset($query['body']['size']) && is_numeric($query['body']['size'])) {
                    $builder->setAttribute('magento.search.size', (int)$query['body']['size']);
                }

                self::startSpanAndAttachToContext($builder);
            },
            static function (object $subject, array $params, mixed $result, ?Throwable $exception) {
                $attributes = [];
                if (is_array($result) || $result instanceof \ArrayAccess) {
                    if (isset($result['took'])) {
                        $attributes['magento.search.took_ms'] = (int)$result['took'];
                    }
                    $total = $result['hits']['total'] ?? null;
                    $total = is_array($total) ? ($total['value'] ?? null) : $total;
                    if (is_numeric($total)) {
                        $attributes['magento.search.hits'] = (int)$total;
                    }
                    if (!empty($result['timed_out'])) {
                        $attributes['magento.search.timed_out'] = true;
                    }
                }
                self::endSpan($exception, $attributes);
            },
        );
    }

    /**
     * Indexing writes, from the catalogsearch_fulltext indexer and partial reindexes on save.
     *
     * @param string $client
     * @param string $system
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private static function instrumentBulkQuery(string $client, string $system): void
    {
        self::hookOutermost(
            $client,
            'bulkQuery',
            static function (
                object $subject,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($system) {
                $query = is_array($params[0] ?? null) ? $params[0] : [];
                $builder = self::createSpanBuilder(
                    sprintf('%s bulk', self::SPAN_NAME_PREFIX),
                    $function,
                    $class,
                    $filename,
                    $lineno
                )
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(TraceAttributes::DB_SYSTEM_NAME, $system)
                    ->setAttribute(TraceAttributes::DB_OPERATION_NAME, 'bulk');
                if (is_string($query['index'] ?? null)) {
                    $builder->setAttribute(TraceAttributes::DB_COLLECTION_NAME, $query['index']);
                }
                // An action line and a document line per item.
                if (is_array($query['body'] ?? null)) {
                    $builder->setAttribute('magento.search.bulk.items', intdiv(count($query['body']), 2));
                }

                self::startSpanAndAttachToContext($builder);
            },
            static function (object $subject, array $params, mixed $result, ?Throwable $exception) {
                $attributes = [];
                if ((is_array($result) || $result instanceof \ArrayAccess)) {
                    if (isset($result['took'])) {
                        $attributes['magento.search.took_ms'] = (int)$result['took'];
                    }
                    if (!empty($result['errors'])) {
                        $attributes['magento.search.bulk.errors'] = true;
                    }
                }
                self::endSpan($exception, $attributes);
            },
        );
    }
    //phpcs:enable Magento2.Functions.StaticFunction
}

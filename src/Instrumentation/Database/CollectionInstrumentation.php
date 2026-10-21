<?php
/**
 * This file is part of the MagePsycho_OpenTelemetry package.
 *
 * @author    Raj KB <rajkb@magepsycho.com>
 * @copyright Copyright (c) 2025 MagePsycho (https://www.magepsycho.com)
 */
declare(strict_types=1);

namespace MagePsycho\OpenTelemetry\Instrumentation\Database;

use Magento\Framework\Data\Collection\AbstractDb;
use MagePsycho\OpenTelemetry\Instrumentation\AbstractInstrumentation;
use MagePsycho\OpenTelemetry\Instrumentation\Util\RequestStats;
use MagePsycho\OpenTelemetry\Instrumentation\Util\SpanLimiter;
use Throwable;

/**
 * Collection loads, any database-backed collection (catalog, EAV, flat).
 *
 * The same collection class loaded many times in one request - one per product on a listing, say - is the
 * N+1 at the model level; the SQL beneath it only shows the symptom. Loads of an already loaded collection
 * do nothing and get no span.
 */
class CollectionInstrumentation extends AbstractInstrumentation
{
    public const INSTRUMENTATION_NAME = 'io.opentelemetry.contrib.php.magento.collection';
    private const SPAN_NAME_PREFIX = 'Collection:';
    private const COLLECTION_SPAN_LIMIT = 300;
    private const LIMITER_KEY = 'collection';

    /**
     * Per open load: whether it started a span.
     *
     * @var bool[]
     */
    private static array $started = [];

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
        SpanLimiter::getInstance()->setLimit(self::LIMITER_KEY, self::COLLECTION_SPAN_LIMIT);
        self::instrumentLoad();
    }

    /**
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private static function instrumentLoad(): void
    {
        // Subclasses override load() and call parent::load(): outermost frame only.
        self::hookOutermost(
            AbstractDb::class,
            'load',
            static function (
                AbstractDb $subject,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) {
                if ($subject->isLoaded()) {
                    self::$started[] = false;
                    return;
                }
                if (!SpanLimiter::getInstance()->shouldStart(self::LIMITER_KEY)) {
                    RequestStats::recordDropped(self::LIMITER_KEY);
                    self::$started[] = false;
                    return;
                }

                $collectionClass = preg_replace('/\\\\Interceptor$/', '', get_class($subject));
                $builder = self::createSpanBuilder(
                    sprintf('%s %s', self::SPAN_NAME_PREFIX, self::collectionName($collectionClass)),
                    $function,
                    $class,
                    $filename,
                    $lineno
                )->setAttribute('magento.collection.class', $collectionClass);

                $table = self::mainTable($subject);
                if ($table !== null) {
                    $builder->setAttribute('magento.collection.table', $table);
                }
                if ($subject->getPageSize()) {
                    $builder->setAttribute('magento.collection.page_size', (int)$subject->getPageSize());
                }

                self::startSpanAndAttachToContext($builder);
                self::$started[] = true;
            },
            static function (AbstractDb $subject, array $params, mixed $returnValue, ?Throwable $exception) {
                if (!array_pop(self::$started)) {
                    return;
                }

                $attributes = [];
                if ($exception === null && $subject->isLoaded()) {
                    $attributes['magento.collection.items'] = count($subject->getItems());
                }
                self::endSpan($exception, $attributes);
            },
        );
    }

    /**
     * Magento\Catalog\Model\ResourceModel\Product\Collection becomes ProductCollection.
     *
     * @param string $class
     * @return string
     */
    private static function collectionName(string $class): string
    {
        return self::extractCollectionName($class) ?? self::extractClassName($class);
    }

    /**
     * The table the collection reads from - flat collections have a main table, EAV ones an entity table.
     *
     * @param AbstractDb $collection
     * @return string|null
     */
    private static function mainTable(AbstractDb $collection): ?string
    {
        try {
            if (method_exists($collection, 'getMainTable')) {
                return (string)$collection->getMainTable();
            }
            if (method_exists($collection, 'getEntity')) {
                return (string)$collection->getEntity()->getEntityTable();
            }
        } catch (Throwable $e) {
            return null;
        }

        return null;
    }
    //phpcs:enable Magento2.Functions.StaticFunction
}

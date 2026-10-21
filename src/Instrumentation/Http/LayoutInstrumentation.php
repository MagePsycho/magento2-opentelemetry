<?php
/**
 * This file is part of the MagePsycho_OpenTelemetry package.
 *
 * @author    Raj KB <rajkb@magepsycho.com>
 * @copyright Copyright (c) 2025 MagePsycho (https://www.magepsycho.com)
 */
declare(strict_types=1);

namespace MagePsycho\OpenTelemetry\Instrumentation\Http;

use Magento\Framework\View\Element\AbstractBlock;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Layout;
use Magento\Framework\View\Model\Layout\Merge;
use Magento\Framework\View\Result\Layout as LayoutResult;
use MagePsycho\OpenTelemetry\Instrumentation\AbstractInstrumentation;
use MagePsycho\OpenTelemetry\Instrumentation\Util\RequestStats;
use MagePsycho\OpenTelemetry\Instrumentation\Util\SpanLimiter;
use Throwable;

/**
 * Page rendering: layout XML merge, layout generation, result rendering, and each block's toHtml().
 *
 * Block spans answer "which block is the page's time going into", and magento.block.cache.hit tells a
 * block served from block_html from one that was rendered - the cost a warm cache hides.
 */
class LayoutInstrumentation extends AbstractInstrumentation
{
    public const INSTRUMENTATION_NAME = 'io.opentelemetry.contrib.php.magento.layout';
    private const SPAN_NAME_PREFIX = 'Layout:';
    private const BLOCK_SPAN_PREFIX = 'Block:';
    private const BLOCK_SPAN_LIMIT = 500;
    private const LIMITER_KEY = 'block';
    private const MAX_HANDLES_LENGTH = 1024;

    /**
     * One frame per block being rendered, the innermost last: whether it has a span, and its cache result.
     *
     * @var array<int, array{span: bool, hit: bool|null}>
     */
    private static array $blocks = [];

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
        SpanLimiter::getInstance()->setLimit(self::LIMITER_KEY, self::BLOCK_SPAN_LIMIT);
        self::instrumentLayoutMerge();
        self::instrumentSimple(Layout::class, 'generateElements', 'generateElements');
        self::instrumentSimple(LayoutResult::class, 'renderResult', 'renderResult');
        self::instrumentBlockToHtml();
    }

    /**
     * Record whether the block being rendered came from block_html. Called by LockInstrumentation,
     * which sees the lock-guarded load AbstractBlock::_loadCache() goes through.
     *
     * @param bool $hit
     * @return void
     */
    public static function recordBlockCacheResult(bool $hit): void
    {
        $top = array_key_last(self::$blocks);
        if ($top !== null) {
            self::$blocks[$top]['hit'] = $hit;
        }
    }

    /**
     * Merging the layout XML for the page's handles - cheap from cache, expensive when it is cold.
     *
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private static function instrumentLayoutMerge(): void
    {
        self::hookOutermost(
            Merge::class,
            'load',
            static function (
                Merge $subject,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) {
                $builder = self::createSpanBuilder(
                    sprintf('%s load', self::SPAN_NAME_PREFIX),
                    $function,
                    $class,
                    $filename,
                    $lineno
                );
                self::startSpanAndAttachToContext($builder);
            },
            static function (Merge $subject, array $params, mixed $returnValue, ?Throwable $exception) {
                self::endSpan($exception, [
                    'magento.layout.handles' => self::truncateString(
                        implode(',', $subject->getHandles()),
                        self::MAX_HANDLES_LENGTH
                    ),
                ]);
            },
        );
    }

    /**
     * @param string $class
     * @param string $method
     * @param string $operation
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private static function instrumentSimple(string $class, string $method, string $operation): void
    {
        self::hookOutermost(
            $class,
            $method,
            static function (
                object $subject,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($operation) {
                $builder = self::createSpanBuilder(
                    sprintf('%s %s', self::SPAN_NAME_PREFIX, $operation),
                    $function,
                    $class,
                    $filename,
                    $lineno
                );
                self::startSpanAndAttachToContext($builder);
            },
            static function (object $subject, array $params, mixed $returnValue, ?Throwable $exception) {
                self::endSpan($exception);
            },
        );
    }

    /**
     * One span per rendered block, up to BLOCK_SPAN_LIMIT per request.
     *
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private static function instrumentBlockToHtml(): void
    {
        self::hookOutermost(
            AbstractBlock::class,
            'toHtml',
            static function (
                AbstractBlock $subject,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) {
                // Every block gets a frame, recorded or not, so cache results land on the right one.
                if (!SpanLimiter::getInstance()->shouldStart(self::LIMITER_KEY)) {
                    RequestStats::recordDropped(self::LIMITER_KEY);
                    self::$blocks[] = ['span' => false, 'hit' => null];
                    return;
                }

                $blockClass = preg_replace('/\\\\Interceptor$/', '', get_class($subject));
                $name = $subject->getNameInLayout() ?: self::extractClassName($blockClass);
                $builder = self::createSpanBuilder(
                    sprintf('%s %s', self::BLOCK_SPAN_PREFIX, $name),
                    $function,
                    $class,
                    $filename,
                    $lineno
                )
                    ->setAttribute('magento.block.name', (string)$subject->getNameInLayout())
                    ->setAttribute('magento.block.class', $blockClass);
                if ($subject instanceof Template && $subject->getTemplate()) {
                    $builder->setAttribute('magento.block.template', (string)$subject->getTemplate());
                }

                self::startSpanAndAttachToContext($builder);
                self::$blocks[] = ['span' => true, 'hit' => null];
            },
            static function (AbstractBlock $subject, array $params, mixed $returnValue, ?Throwable $exception) {
                $frame = array_pop(self::$blocks);
                if (!$frame || !$frame['span']) {
                    return;
                }

                // Only set for blocks with a cache lifetime; the rest never touch block_html.
                self::endSpan(
                    $exception,
                    $frame['hit'] === null ? [] : ['magento.block.cache.hit' => $frame['hit']]
                );
            },
        );
    }
    //phpcs:enable Magento2.Functions.StaticFunction
}

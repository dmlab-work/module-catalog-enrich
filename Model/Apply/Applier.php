<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Apply;

use DmLab\CatalogEnrich\Exception\IneligibleAttributeException;
use DmLab\CatalogEnrich\Model\Attribute\AttributeCatalog;
use DmLab\CatalogEnrich\Model\Attribute\AttributeDescriptor;
use DmLab\CatalogEnrich\Model\ResourceModel\Suggestion as SuggestionResource;
use DmLab\CatalogEnrich\Model\Rule;
use DmLab\CatalogEnrich\Model\RuleRepository;
use DmLab\CatalogEnrich\Model\State\ProductDataLoader;
use DmLab\CatalogEnrich\Model\Suggestion;
use DmLab\CatalogEnrich\Observer\CacheTagRecorder;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Action as ProductAction;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Indexer\Model\Indexer\DeferredCacheContext;
use Psr\Log\LoggerInterface;

/**
 * Writes a suggestion to the product (store 0) and reverts it.
 */
class Applier
{
    private const APPLICABLE = [Suggestion::STATUS_PENDING, Suggestion::STATUS_SHADOW];
    private const FULLTEXT_INDEXER = 'catalogsearch_fulltext';

    /**
     * Whether the current transaction reached the product write.
     *
     * @var bool
     */
    private bool $written = false;

    /**
     * Cache tags the realtime indexers cleaned during the current transaction.
     *
     * @var string[]
     */
    private array $tags = [];

    /**
     * @param ProductAction $productAction
     * @param ProductDataLoader $productDataLoader
     * @param AttributeCatalog $attributeCatalog
     * @param RuleRepository $ruleRepository
     * @param SuggestionResource $suggestionResource
     * @param IndexerRegistry $indexerRegistry
     * @param CacheTagRecorder $cacheTagRecorder
     * @param DeferredCacheContext $deferredCacheContext
     * @param EventManager $eventManager
     * @param CacheInterface $cache
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ProductAction $productAction,
        private readonly ProductDataLoader $productDataLoader,
        private readonly AttributeCatalog $attributeCatalog,
        private readonly RuleRepository $ruleRepository,
        private readonly SuggestionResource $suggestionResource,
        private readonly IndexerRegistry $indexerRegistry,
        private readonly CacheTagRecorder $cacheTagRecorder,
        private readonly DeferredCacheContext $deferredCacheContext,
        private readonly EventManager $eventManager,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Write the suggested value against the live value; on success the row becomes `applied`.
     *
     * @param Suggestion $suggestion
     * @return ApplyResult
     */
    public function apply(Suggestion $suggestion): ApplyResult
    {
        return $this->atomically($suggestion, fn (): ApplyResult => $this->applyLocked($suggestion));
    }

    /**
     * Restore the previous value while the live value is still the one applied; the row becomes `reverted`.
     *
     * @param Suggestion $suggestion
     * @return ApplyResult
     */
    public function revert(Suggestion $suggestion): ApplyResult
    {
        return $this->atomically($suggestion, fn (): ApplyResult => $this->revertLocked($suggestion));
    }

    /**
     * Run under a row lock with the stored status; the product write and the row update commit together.
     *
     * @param Suggestion $suggestion
     * @param callable $action returns ApplyResult
     * @return ApplyResult
     */
    private function atomically(Suggestion $suggestion, callable $action): ApplyResult
    {
        $snapshot = $suggestion->getData();
        $this->written = false;
        $this->tags = [];
        $this->suggestionResource->beginTransaction();
        try {
            $status = $this->suggestionResource->lockStatus((int)$suggestion->getId());
            if ($status === null) {
                $result = new ApplyResult(false, (string)__('The suggestion no longer exists.'));
            } else {
                $suggestion->setData('status', $status);
                $result = $action();
            }
            if ($result->isOk()) {
                $this->suggestionResource->commit();
                $this->cleanProductCache($suggestion->getProductId());

                return $result;
            }
        } catch (\Throwable $e) {
            $result = new ApplyResult(false, (string)__('Could not save the change: %1', $e->getMessage()));
        }
        $this->suggestionResource->rollBack();
        $suggestion->setData($snapshot);
        if ($this->written) {
            $this->resyncSearch($suggestion->getProductId());
        }

        return $result;
    }

    /**
     * Realtime fulltext reindex pushed the rolled-back value to the search engine; push the restored one.
     *
     * @param int $productId
     * @return void
     */
    private function resyncSearch(int $productId): void
    {
        $deferred = $this->deferredCacheContext->isActive();
        try {
            $indexer = $this->indexerRegistry->get(self::FULLTEXT_INDEXER);
            if (!$indexer->isScheduled()) {
                $indexer->reindexList([$productId]);
            }
        } catch (\Throwable $e) {
            if (!$deferred) {
                $this->endCacheDeferral();
            }
            $this->logger->warning('DmLab_CatalogEnrich: search reindex after rollback failed.', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Realtime indexers clean caches before the commit, so a concurrent request may re-cache the old value.
     *
     * Repeats their tags (parents, categories) plus the product's own.
     *
     * @param int $productId
     * @return void
     */
    private function cleanProductCache(int $productId): void
    {
        $tags = array_values(array_unique([Product::CACHE_TAG . '_' . $productId, ...$this->tags]));
        try {
            $this->eventManager->dispatch('clean_cache_by_tags', ['object' => new CacheTags($tags)]);
            $this->cache->clean($tags);
        } catch (\Throwable $e) {
            $this->logger->warning('DmLab_CatalogEnrich: product cache clean after commit failed.', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Apply body; runs inside the transaction.
     *
     * @param Suggestion $suggestion
     * @return ApplyResult
     */
    private function applyLocked(Suggestion $suggestion): ApplyResult
    {
        if (!in_array($suggestion->getStatus(), self::APPLICABLE, true)) {
            return new ApplyResult(false, (string)__('Only pending suggestions can be applied.'));
        }
        $value = trim($suggestion->getValue());
        if ($value === '') {
            return new ApplyResult(false, (string)__('The suggestion has no value.'));
        }
        $descriptor = $this->descriptor($suggestion);
        if ($descriptor === null) {
            return new ApplyResult(
                false,
                (string)__('Attribute "%1" can no longer be enriched.', $suggestion->getAttributeCode())
            );
        }
        if (!$this->optionsExist($descriptor, $value)) {
            return new ApplyResult(false, (string)__('The suggested option no longer exists.'));
        }
        $live = $this->liveValues($suggestion);
        if ($live === false) {
            return new ApplyResult(false, (string)__('The product no longer exists.'));
        }
        [$stored, $storeViewValues] = $live;
        $rule = $this->ruleRepository->getByAttributeCode($suggestion->getAttributeCode());

        $filled = $stored !== null && !$rule?->isEmptyValue($stored);
        if ($filled && ($descriptor->getInput() !== AttributeDescriptor::INPUT_MULTISELECT
            || $rule?->getFillMode() !== Rule::FILL_ADD_MISSING)
        ) {
            return new ApplyResult(false, (string)__('The attribute already has a value.'));
        }
        if ($this->hasStoreViewValue($storeViewValues)) {
            // A store-0 write (an add_missing union too) would stay hidden behind the store-view value.
            return new ApplyResult(false, (string)__('The attribute already has a store view value.'));
        }
        if ($filled) {
            $value = $this->union((string)$stored, $value);
        }

        $result = $this->write($suggestion, $value);
        if ($result->isOk()) {
            // A boolean 0 the rule treats as empty is kept, so a revert restores it.
            $suggestion->setData('previous_value', $stored);
            $suggestion->setData('status', Suggestion::STATUS_APPLIED);
            // A loaded updated_at would be written back and stop ON UPDATE from refreshing it.
            $suggestion->unsetData('updated_at');
            $this->suggestionResource->save($suggestion);
        }

        return $result;
    }

    /**
     * Revert body; runs inside the transaction.
     *
     * @param Suggestion $suggestion
     * @return ApplyResult
     */
    private function revertLocked(Suggestion $suggestion): ApplyResult
    {
        if ($suggestion->getStatus() !== Suggestion::STATUS_APPLIED) {
            return new ApplyResult(false, (string)__('Only applied suggestions can be reverted.'));
        }
        $live = $this->liveValues($suggestion);
        if ($live === false) {
            return new ApplyResult(false, (string)__('The product no longer exists.'));
        }
        if ($live[0] === null || !$this->sameValue($live[0], $this->appliedValue($suggestion))) {
            return new ApplyResult(false, (string)__('The value was changed after it was applied.'));
        }

        $previous = $suggestion->getPreviousValue();
        $result = $this->write($suggestion, $previous === null || trim($previous) === '' ? null : $previous);
        if ($result->isOk()) {
            $suggestion->setData('status', Suggestion::STATUS_REVERTED);
            $suggestion->unsetData('updated_at');
            $this->suggestionResource->save($suggestion);
        }

        return $result;
    }

    /**
     * Value that `apply` wrote: previous ∪ suggested for a filled multiselect, else the suggested value.
     *
     * A boolean's previous value is a 0 the rule treated as empty, never merged.
     *
     * @param Suggestion $suggestion
     * @return string
     */
    private function appliedValue(Suggestion $suggestion): string
    {
        $previous = $suggestion->getPreviousValue();
        if ($previous === null || trim($previous) === ''
            || $this->descriptor($suggestion)?->getInput() === AttributeDescriptor::INPUT_BOOLEAN
        ) {
            return trim($suggestion->getValue());
        }

        return $this->union($previous, $suggestion->getValue());
    }

    /**
     * Compare raw values; comma lists are compared as id sets.
     *
     * @param string $left
     * @param string $right
     * @return bool
     */
    private function sameValue(string $left, string $right): bool
    {
        if (str_contains($left, ',') || str_contains($right, ',')) {
            return $this->ids($left) === $this->ids($right);
        }

        return trim($left) === trim($right);
    }

    /**
     * Live store-0 raw value (null when empty) and the store-view values; false when the product is missing.
     *
     * @param Suggestion $suggestion
     * @return array{0: string|null, 1: string[]}|false
     */
    private function liveValues(Suggestion $suggestion): array|false
    {
        $code = $suggestion->getAttributeCode();
        $data = $this->productDataLoader->load($suggestion->getProductId(), [], [$code]);
        if ($data === null) {
            return false;
        }
        $value = $data[$code] ?? null;
        $value = is_scalar($value) && trim((string)$value) !== '' ? trim((string)$value) : null;

        return [$value, $data[ProductDataLoader::STORE_VIEW_VALUES][$code] ?? []];
    }

    /**
     * Whether any store-view value is non-empty; "treat 0 as empty" applies to store 0 only.
     *
     * @param string[] $values
     * @return bool
     */
    private function hasStoreViewValue(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string)$value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Write one value of one product at store 0.
     *
     * @param Suggestion $suggestion
     * @param string|null $value
     * @return ApplyResult
     */
    private function write(Suggestion $suggestion, ?string $value): ApplyResult
    {
        $this->written = true;
        $deferred = $this->deferredCacheContext->isActive();
        $this->cacheTagRecorder->start();
        try {
            $this->productAction->updateAttributes(
                [$suggestion->getProductId()],
                [$suggestion->getAttributeCode() => $value],
                0
            );
        } catch (\Throwable $e) {
            if (!$deferred) {
                $this->endCacheDeferral();
            }

            return new ApplyResult(false, (string)__('Could not save the attribute: %1', $e->getMessage()));
        } finally {
            $this->tags = [...$this->tags, ...$this->cacheTagRecorder->stop()];
        }

        return new ApplyResult(true);
    }

    /**
     * An indexer that threw skipped its after-plugin flush, leaving cache tags deferred for the rest of the process.
     *
     * @return void
     */
    private function endCacheDeferral(): void
    {
        while ($this->deferredCacheContext->isActive()) {
            $this->deferredCacheContext->commit();
        }
    }

    /**
     * Eligible descriptor of the suggestion's attribute, or null.
     *
     * @param Suggestion $suggestion
     * @return AttributeDescriptor|null
     */
    private function descriptor(Suggestion $suggestion): ?AttributeDescriptor
    {
        try {
            return $this->attributeCatalog->get($suggestion->getAttributeCode());
        } catch (IneligibleAttributeException $e) {
            return null;
        }
    }

    /**
     * Whether every id of the comma list is a current option of the attribute.
     *
     * @param AttributeDescriptor $descriptor
     * @param string $value
     * @return bool
     */
    private function optionsExist(AttributeDescriptor $descriptor, string $value): bool
    {
        $options = $descriptor->getOptions();
        foreach (explode(',', $value) as $part) {
            $part = trim($part);
            if (!ctype_digit($part) || !isset($options[(int)$part])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Comma-joined ascending union of two id lists.
     *
     * @param string $left
     * @param string $right
     * @return string
     */
    private function union(string $left, string $right): string
    {
        $ids = array_values(array_unique([...$this->ids($left), ...$this->ids($right)]));
        sort($ids);

        return implode(',', $ids);
    }

    /**
     * Sorted unique option ids of a comma list.
     *
     * @param string $value
     * @return int[]
     */
    private function ids(string $value): array
    {
        $ids = [];
        foreach (explode(',', $value) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                $ids[] = (int)$part;
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}

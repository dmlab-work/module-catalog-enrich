<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\Apply;

use DmLab\CatalogEnrich\Exception\IneligibleAttributeException;
use DmLab\CatalogEnrich\Model\Apply\Applier;
use DmLab\CatalogEnrich\Model\Attribute\AttributeCatalog;
use DmLab\CatalogEnrich\Model\Attribute\AttributeDescriptor;
use DmLab\CatalogEnrich\Model\ResourceModel\Rule as RuleResource;
use DmLab\CatalogEnrich\Model\ResourceModel\Suggestion as SuggestionResource;
use DmLab\CatalogEnrich\Model\Rule;
use DmLab\CatalogEnrich\Model\RuleRepository;
use DmLab\CatalogEnrich\Model\State\ProductDataLoader;
use DmLab\CatalogEnrich\Model\Suggestion;
use DmLab\CatalogEnrich\Observer\CacheTagRecorder;
use Magento\Catalog\Model\Product\Action as ProductAction;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Event\Observer;
use Magento\Framework\Indexer\CacheContext;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\Db\Context as DbContext;
use Magento\Framework\Registry;
use Magento\Indexer\Model\Indexer\DeferredCacheContext;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ApplierTest extends TestCase
{
    private const ID = 5;

    /**
     * @var ProductAction&MockObject
     */
    private ProductAction $productAction;

    /**
     * @var ProductDataLoader&MockObject
     */
    private ProductDataLoader $loader;

    /**
     * @var SuggestionResource&MockObject
     */
    private SuggestionResource $suggestionResource;

    /**
     * @var RuleRepository&Stub
     */
    private RuleRepository $ruleRepository;

    /**
     * @var AttributeCatalog&Stub
     */
    private AttributeCatalog $attributeCatalog;

    /**
     * @var IndexerInterface&MockObject
     */
    private IndexerInterface $fulltext;

    /**
     * @var CacheInterface&MockObject
     */
    private CacheInterface $cache;

    /**
     * @var EventManager&MockObject
     */
    private EventManager $eventManager;

    /**
     * @var LoggerInterface&Stub
     */
    private LoggerInterface $logger;

    /**
     * @var CacheTagRecorder
     */
    private CacheTagRecorder $recorder;

    /**
     * @var DeferredCacheContext
     */
    private DeferredCacheContext $deferredCacheContext;

    /**
     * @var Applier
     */
    private Applier $applier;

    /**
     * Status the row lock reads; null = row deleted.
     *
     * @var string|null
     */
    private ?string $storedStatus = null;

    protected function setUp(): void
    {
        $this->productAction = $this->createMock(ProductAction::class);
        $this->loader = $this->createMock(ProductDataLoader::class);
        $this->suggestionResource = $this->createMock(SuggestionResource::class);
        $this->suggestionResource->method('lockStatus')->willReturnCallback(fn (): ?string => $this->storedStatus);
        $this->ruleRepository = $this->createStub(RuleRepository::class);
        $this->attributeCatalog = $this->createStub(AttributeCatalog::class);
        $this->attributeCatalog->method('get')->willReturnCallback(
            static fn (string $code): AttributeDescriptor => match ($code) {
                'color' => new AttributeDescriptor(1, 'color', 'Color', 'select', 'catalog_product_entity_int', [
                    12 => 'Red',
                    13 => 'Blue',
                ]),
                'includes' => new AttributeDescriptor(2, 'includes', 'Includes', 'multiselect', 'x_varchar', [
                    1 => 'A',
                    3 => 'B',
                    5 => 'C',
                    9 => 'D',
                ]),
                'is_assembled' => new AttributeDescriptor(3, 'is_assembled', 'Is assembled', 'boolean', 'x_int'),
                default => throw new IneligibleAttributeException(__('Attribute "%1" does not exist.', $code)),
            }
        );
        $this->fulltext = $this->createMock(IndexerInterface::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->eventManager = $this->createMock(EventManager::class);
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->recorder = new CacheTagRecorder();
        $this->deferredCacheContext = new DeferredCacheContext(new CacheContext());
        $this->applier = $this->applier();
    }

    private function applier(): Applier
    {
        $indexerRegistry = $this->createStub(IndexerRegistry::class);
        $indexerRegistry->method('get')->willReturnMap([['catalogsearch_fulltext', $this->fulltext]]);

        return new Applier(
            $this->productAction,
            $this->loader,
            $this->attributeCatalog,
            $this->ruleRepository,
            $this->suggestionResource,
            $indexerRegistry,
            $this->recorder,
            $this->deferredCacheContext,
            $this->eventManager,
            $this->cache,
            $this->logger
        );
    }

    /**
     * @param array<string,mixed> $data
     */
    private function suggestion(array $data): Suggestion
    {
        $data += ['suggestion_id' => 7, 'product_id' => self::ID, 'status' => Suggestion::STATUS_PENDING];
        $this->storedStatus = $data['status'];

        return new Suggestion(
            $this->createStub(Context::class),
            $this->createStub(Registry::class),
            new SuggestionResource($this->createStub(DbContext::class)),
            null,
            $data
        );
    }

    /**
     * @param string $code
     * @param string|null $value null = no typed value stored
     * @param string[] $storeViewValues
     */
    private function live(string $code, ?string $value, array $storeViewValues = []): void
    {
        $this->loader->expects(self::once())->method('load')
            ->with(self::ID, [], [$code])
            ->willReturn([
                'sku' => 'SKU-5',
                'attribute_set_id' => '4',
                $code => $value,
                ProductDataLoader::STORE_VIEW_VALUES => [$code => $storeViewValues],
            ]);
    }

    private function expectWrite(string $code, ?string $value): void
    {
        $this->productAction->expects(self::once())->method('updateAttributes')
            ->with([self::ID], [$code => $value], 0);
        $this->suggestionResource->expects(self::once())->method('save');
        $this->fulltext->expects(self::never())->method('reindexList');
        $this->expectCommit(true);
    }

    private function expectNoWrite(): void
    {
        $this->productAction->expects(self::never())->method('updateAttributes');
        $this->fulltext->expects(self::never())->method('reindexList');
        $this->suggestionResource->expects(self::never())->method('save');
        $this->expectCommit(false);
    }

    /**
     * @param string[] $tags
     */
    private function expectCommit(bool $commit, array $tags = ['cat_p_' . self::ID]): void
    {
        $this->suggestionResource->expects(self::once())->method('beginTransaction');
        $this->suggestionResource->expects($commit ? self::once() : self::never())->method('commit');
        $this->suggestionResource->expects($commit ? self::never() : self::once())->method('rollBack');
        $this->cache->expects($commit ? self::once() : self::never())->method('clean')
            ->with($tags);
        $this->eventManager->expects($commit ? self::once() : self::never())->method('dispatch')
            ->with('clean_cache_by_tags', self::callback(
                static fn (array $data): bool => $data['object'] instanceof IdentityInterface
                    && $data['object']->getIdentities() === $tags
            ));
    }

    private function fillMode(string $fillMode): void
    {
        $this->rule(['attribute_code' => 'includes', 'fill_mode' => $fillMode]);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function rule(array $data): void
    {
        $rule = new Rule(
            $this->createStub(Context::class),
            $this->createStub(Registry::class),
            new RuleResource($this->createStub(DbContext::class)),
            null,
            $data + ['rule_id' => 1]
        );
        $this->ruleRepository->method('getByAttributeCode')->willReturn($rule);
    }

    public function testStoreViewValueCountsAsFilled(): void
    {
        $this->live('color', null, ['13']);
        $this->expectNoWrite();

        $result = $this->applier->apply($this->suggestion(['attribute_code' => 'color', 'value' => '12']));

        self::assertFalse($result->isOk());
        self::assertSame('The attribute already has a store view value.', $result->getReason());
    }

    public function testAddMissingRefusedWhenOnlyAStoreViewHasValues(): void
    {
        $this->fillMode(Rule::FILL_ADD_MISSING);
        $this->live('includes', null, ['3,9']);
        $this->expectNoWrite();

        self::assertFalse($this->applier->apply($this->suggestion(['attribute_code' => 'includes', 'value' => '5']))
            ->isOk());
    }

    public function testTreatZeroAsEmptyOverwritesZeroAndKeepsItForRevert(): void
    {
        $this->rule(['attribute_code' => 'is_assembled', 'treat_zero_as_empty' => 1]);
        $suggestion = $this->suggestion(['attribute_code' => 'is_assembled', 'value' => '1']);
        $this->live('is_assembled', '0');
        $this->expectWrite('is_assembled', '1');

        self::assertTrue($this->applier->apply($suggestion)->isOk());
        self::assertSame('0', $suggestion->getPreviousValue());
        self::assertSame(Suggestion::STATUS_APPLIED, $suggestion->getStatus());
    }

    public function testTreatZeroAsEmptyIgnoresStoreViewZero(): void
    {
        $this->rule(['attribute_code' => 'is_assembled', 'treat_zero_as_empty' => 1]);
        $this->live('is_assembled', '0', ['0']);
        $this->expectNoWrite();

        $result = $this->applier->apply($this->suggestion(['attribute_code' => 'is_assembled', 'value' => '1']));

        self::assertFalse($result->isOk());
        self::assertSame('The attribute already has a store view value.', $result->getReason());
    }

    public function testAddMissingRefusedWhenAStoreViewHasValuesBesidesStoreZero(): void
    {
        $this->fillMode(Rule::FILL_ADD_MISSING);
        $this->live('includes', '9', ['3']);
        $this->expectNoWrite();

        $result = $this->applier->apply($this->suggestion(['attribute_code' => 'includes', 'value' => '5']));

        self::assertFalse($result->isOk());
        self::assertSame('The attribute already has a store view value.', $result->getReason());
    }

    public function testTreatZeroAsEmptyStillRefusesYes(): void
    {
        $this->rule(['attribute_code' => 'is_assembled', 'treat_zero_as_empty' => 1]);
        $this->live('is_assembled', null, ['1']);
        $this->expectNoWrite();

        self::assertFalse(
            $this->applier->apply($this->suggestion(['attribute_code' => 'is_assembled', 'value' => '0']))->isOk()
        );
    }

    public function testRevertRestoresTheZeroThatWasTreatedAsEmpty(): void
    {
        $suggestion = $this->suggestion([
            'attribute_code' => 'is_assembled',
            'value' => '1',
            'previous_value' => '0',
            'status' => Suggestion::STATUS_APPLIED,
        ]);
        $this->live('is_assembled', '1');
        $this->expectWrite('is_assembled', '0');

        self::assertTrue($this->applier->revert($suggestion)->isOk());
        self::assertSame(Suggestion::STATUS_REVERTED, $suggestion->getStatus());
    }

    public function testSelectAppliedOnEmptyAtStoreZero(): void
    {
        $suggestion = $this->suggestion(
            ['attribute_code' => 'color', 'value' => '12', 'updated_at' => '2026-10-01 00:00:00']
        );
        $this->live('color', null);
        $this->expectWrite('color', '12');

        $result = $this->applier->apply($suggestion);

        self::assertTrue($result->isOk());
        self::assertNull($result->getReason());
        self::assertSame(Suggestion::STATUS_APPLIED, $suggestion->getStatus());
        self::assertNull($suggestion->getPreviousValue());
        // Not written back, so the column's ON UPDATE refreshes it.
        self::assertFalse($suggestion->hasData('updated_at'));
    }

    public function testShadowRowCanBeApplied(): void
    {
        $suggestion = $this->suggestion([
            'attribute_code' => 'color',
            'value' => '12',
            'status' => Suggestion::STATUS_SHADOW,
        ]);
        $this->live('color', '');
        $this->expectWrite('color', '12');

        self::assertTrue($this->applier->apply($suggestion)->isOk());
        self::assertSame(Suggestion::STATUS_APPLIED, $suggestion->getStatus());
    }

    public function testSelectRefusedWhenFilled(): void
    {
        $suggestion = $this->suggestion(['attribute_code' => 'color', 'value' => '12']);
        $this->live('color', '13');
        $this->expectNoWrite();

        $result = $this->applier->apply($suggestion);

        self::assertFalse($result->isOk());
        self::assertSame('The attribute already has a value.', $result->getReason());
        self::assertSame(Suggestion::STATUS_PENDING, $suggestion->getStatus());
    }

    public function testMultiselectEmptyOnlyRefusedWhenFilled(): void
    {
        $this->fillMode(Rule::FILL_EMPTY_ONLY);
        $this->live('includes', '3');
        $this->expectNoWrite();

        $result = $this->applier->apply($this->suggestion(['attribute_code' => 'includes', 'value' => '5']));

        self::assertFalse($result->isOk());
    }

    public function testMultiselectWithoutRuleIsEmptyOnly(): void
    {
        $this->ruleRepository->method('getByAttributeCode')->willReturn(null);
        $this->live('includes', '3');
        $this->expectNoWrite();

        $result = $this->applier->apply($this->suggestion(['attribute_code' => 'includes', 'value' => '5']));

        self::assertFalse($result->isOk());
    }

    public function testAddMissingWritesAscendingUnionAndKeepsPrevious(): void
    {
        $this->fillMode(Rule::FILL_ADD_MISSING);
        $suggestion = $this->suggestion(['attribute_code' => 'includes', 'value' => '5,1,3']);
        $this->live('includes', '9,3');
        $this->expectWrite('includes', '1,3,5,9');

        self::assertTrue($this->applier->apply($suggestion)->isOk());
        self::assertSame('9,3', $suggestion->getPreviousValue());
        self::assertSame('5,1,3', $suggestion->getValue());
        self::assertSame(Suggestion::STATUS_APPLIED, $suggestion->getStatus());
    }

    public function testBooleanZeroApplied(): void
    {
        $suggestion = $this->suggestion(['attribute_code' => 'is_assembled', 'value' => '0']);
        $this->live('is_assembled', null);
        $this->expectWrite('is_assembled', '0');

        self::assertTrue($this->applier->apply($suggestion)->isOk());
    }

    public function testBooleanZeroCountsAsFilled(): void
    {
        $this->live('is_assembled', '0');
        $this->expectNoWrite();

        self::assertFalse(
            $this->applier->apply($this->suggestion(['attribute_code' => 'is_assembled', 'value' => '1']))->isOk()
        );
    }

    public function testApplyRefusedForNonPendingStatus(): void
    {
        $this->loader->expects(self::never())->method('load');
        $this->expectNoWrite();

        $result = $this->applier->apply($this->suggestion([
            'attribute_code' => 'color',
            'value' => '12',
            'status' => Suggestion::STATUS_REJECTED,
        ]));

        self::assertFalse($result->isOk());
        self::assertSame('Only pending suggestions can be applied.', $result->getReason());
    }

    public function testApplyRefusedForEmptyValue(): void
    {
        $this->loader->expects(self::never())->method('load');
        $this->expectNoWrite();

        $result = $this->applier->apply($this->suggestion(['attribute_code' => 'color', 'value' => ' ']));

        self::assertFalse($result->isOk());
    }

    public function testApplyRefusedForIneligibleAttribute(): void
    {
        $this->loader->expects(self::never())->method('load');
        $this->expectNoWrite();

        $result = $this->applier->apply($this->suggestion(['attribute_code' => 'gone', 'value' => '1']));

        self::assertSame('Attribute "gone" can no longer be enriched.', $result->getReason());
    }

    public function testApplyRefusedForDeletedOption(): void
    {
        $this->loader->expects(self::never())->method('load');
        $this->expectNoWrite();

        $result = $this->applier->apply($this->suggestion(['attribute_code' => 'includes', 'value' => '3,42']));

        self::assertFalse($result->isOk());
        self::assertSame('The suggested option no longer exists.', $result->getReason());
    }

    public function testApplyRefusedWhenProductMissing(): void
    {
        $this->loader->expects(self::once())->method('load')->willReturn(null);
        $this->expectNoWrite();

        $result = $this->applier->apply($this->suggestion(['attribute_code' => 'color', 'value' => '12']));

        self::assertSame('The product no longer exists.', $result->getReason());
    }

    public function testWriteFailureIsRefusalAndKeepsStatus(): void
    {
        $suggestion = $this->suggestion(['attribute_code' => 'color', 'value' => '12']);
        $this->live('color', null);
        $this->productAction->expects(self::once())->method('updateAttributes')
            ->willThrowException(new \RuntimeException('deadlock'));
        $this->suggestionResource->expects(self::never())->method('save');
        $this->expectCommit(false);
        $this->fulltext->expects(self::once())->method('reindexList')->with([self::ID]);

        $result = $this->applier->apply($suggestion);

        self::assertFalse($result->isOk());
        self::assertSame('Could not save the attribute: deadlock', $result->getReason());
        self::assertSame(Suggestion::STATUS_PENDING, $suggestion->getStatus());
    }

    public function testSuggestionSaveFailureRollsBackTheProductWrite(): void
    {
        $suggestion = $this->suggestion(['attribute_code' => 'color', 'value' => '12', 'previous_value' => null]);
        $this->live('color', null);
        $this->productAction->expects(self::once())->method('updateAttributes');
        $this->suggestionResource->expects(self::once())->method('save')
            ->willThrowException(new \RuntimeException('lost connection'));
        $this->expectCommit(false);
        $this->fulltext->method('isScheduled')->willReturn(false);
        $this->fulltext->expects(self::once())->method('reindexList')->with([self::ID]);

        $result = $this->applier->apply($suggestion);

        self::assertFalse($result->isOk());
        self::assertSame('Could not save the change: lost connection', $result->getReason());
        self::assertSame(Suggestion::STATUS_PENDING, $suggestion->getStatus());
    }

    public function testWriteErrorIsRefusalAndRollsBack(): void
    {
        $suggestion = $this->suggestion(['attribute_code' => 'color', 'value' => '12']);
        $this->live('color', null);
        $this->productAction->expects(self::once())->method('updateAttributes')
            ->willThrowException(new \TypeError('bad observer'));
        $this->expectCommit(false);
        $this->fulltext->expects(self::once())->method('reindexList')->with([self::ID]);

        $result = $this->applier->apply($suggestion);

        self::assertFalse($result->isOk());
        self::assertSame('Could not save the attribute: bad observer', $result->getReason());
    }

    public function testSuggestionSaveErrorRollsBack(): void
    {
        $suggestion = $this->suggestion(['attribute_code' => 'color', 'value' => '12', 'previous_value' => null]);
        $this->live('color', null);
        $this->productAction->expects(self::once())->method('updateAttributes');
        $this->suggestionResource->method('save')->willThrowException(new \Error('broken'));
        $this->expectCommit(false);
        $this->fulltext->expects(self::once())->method('reindexList')->with([self::ID]);

        $result = $this->applier->apply($suggestion);

        self::assertFalse($result->isOk());
        self::assertSame('Could not save the change: broken', $result->getReason());
        self::assertSame(Suggestion::STATUS_PENDING, $suggestion->getStatus());
    }

    public function testRollbackSkipsSearchResyncWhenFulltextIsScheduled(): void
    {
        $suggestion = $this->suggestion(['attribute_code' => 'color', 'value' => '12']);
        $this->live('color', null);
        $this->productAction->expects(self::once())->method('updateAttributes');
        $this->suggestionResource->method('save')->willThrowException(new \RuntimeException('lost connection'));
        $this->expectCommit(false);
        $this->fulltext->method('isScheduled')->willReturn(true);
        $this->fulltext->expects(self::never())->method('reindexList');

        self::assertFalse($this->applier->apply($suggestion)->isOk());
    }

    public function testSearchResyncFailureIsLogged(): void
    {
        $suggestion = $this->suggestion(['attribute_code' => 'color', 'value' => '12']);
        $this->live('color', null);
        $this->productAction->expects(self::once())->method('updateAttributes')
            ->willThrowException(new \RuntimeException('deadlock'));
        $this->expectCommit(false);
        $this->fulltext->expects(self::once())->method('reindexList')
            ->willThrowException(new \RuntimeException('search down'));
        $logger = $this->createMock(LoggerInterface::class);
        $this->logger = $logger;
        $this->applier = $this->applier();
        $logger->expects(self::once())->method('warning')
            ->with('DmLab_CatalogEnrich: search reindex after rollback failed.', [
                'product_id' => self::ID,
                'error' => 'search down',
            ]);

        self::assertSame('Could not save the attribute: deadlock', $this->applier->apply($suggestion)->getReason());
    }

    public function testCacheCleanFailureAfterCommitIsLoggedAndKeepsTheResult(): void
    {
        $suggestion = $this->suggestion(['attribute_code' => 'color', 'value' => '12']);
        $this->live('color', null);
        $this->productAction->expects(self::once())->method('updateAttributes');
        $this->suggestionResource->expects(self::once())->method('commit');
        $this->suggestionResource->expects(self::never())->method('rollBack');
        $this->fulltext->expects(self::never())->method('reindexList');
        $this->eventManager->expects(self::once())->method('dispatch')->with('clean_cache_by_tags');
        $this->cache->expects(self::once())->method('clean')->willThrowException(new \RuntimeException('valkey down'));
        $logger = $this->createMock(LoggerInterface::class);
        $this->logger = $logger;
        $this->applier = $this->applier();
        $logger->expects(self::once())->method('warning')
            ->with('DmLab_CatalogEnrich: product cache clean after commit failed.', [
                'product_id' => self::ID,
                'error' => 'valkey down',
            ]);

        self::assertTrue($this->applier->apply($suggestion)->isOk());
        self::assertSame(Suggestion::STATUS_APPLIED, $suggestion->getStatus());
    }

    public function testCommitRepeatsTheTagsTheIndexersCleaned(): void
    {
        $suggestion = $this->suggestion(['attribute_code' => 'color', 'value' => '12']);
        $this->live('color', null);
        $this->productAction->expects(self::once())->method('updateAttributes')->willReturnCallback(
            function (): ProductAction {
                $this->indexerCleaned(['cat_p_' . self::ID, 'cat_p_42', 'cat_c_3']);

                return $this->productAction;
            }
        );
        $this->suggestionResource->expects(self::once())->method('save');
        $this->fulltext->expects(self::never())->method('reindexList');
        $this->expectCommit(true, ['cat_p_' . self::ID, 'cat_p_42', 'cat_c_3']);

        self::assertTrue($this->applier->apply($suggestion)->isOk());
        $this->indexerCleaned(['cat_p_99']);
        self::assertSame([], $this->recorder->stop());
    }

    public function testFailedWriteEndsTheCacheDeferralItLeaked(): void
    {
        $suggestion = $this->suggestion(['attribute_code' => 'color', 'value' => '12']);
        $this->live('color', null);
        $this->productAction->expects(self::once())->method('updateAttributes')->willReturnCallback(
            function (): never {
                $this->deferredCacheContext->start();
                throw new \RuntimeException('indexer failed');
            }
        );
        $this->expectCommit(false);
        $this->fulltext->expects(self::once())->method('reindexList')->with([self::ID]);

        self::assertFalse($this->applier->apply($suggestion)->isOk());
        self::assertFalse($this->deferredCacheContext->isActive());
    }

    public function testFailedWriteKeepsAnOuterCacheDeferral(): void
    {
        $suggestion = $this->suggestion(['attribute_code' => 'color', 'value' => '12']);
        $this->live('color', null);
        $this->deferredCacheContext->start();
        $this->productAction->expects(self::once())->method('updateAttributes')
            ->willThrowException(new \RuntimeException('deadlock'));
        $this->expectCommit(false);
        $this->fulltext->expects(self::once())->method('reindexList')->with([self::ID]);

        self::assertFalse($this->applier->apply($suggestion)->isOk());
        self::assertTrue($this->deferredCacheContext->isActive());
    }

    public function testFailedSearchResyncEndsTheCacheDeferralItLeaked(): void
    {
        $this->loader->expects(self::exactly(2))->method('load')
            ->willReturn(['sku' => 'SKU-5', 'attribute_set_id' => '4']);
        $writes = 0;
        $this->productAction->expects(self::exactly(2))->method('updateAttributes')->willReturnCallback(
            function () use (&$writes): ProductAction {
                if (++$writes === 1) {
                    throw new \RuntimeException('deadlock');
                }
                if ($this->deferredCacheContext->isActive()) {
                    $this->deferredCacheContext->registerTags(['cat_p_42']);
                } else {
                    $this->indexerCleaned(['cat_p_42']);
                }

                return $this->productAction;
            }
        );
        $this->fulltext->expects(self::once())->method('reindexList')->willReturnCallback(
            function (): never {
                $this->deferredCacheContext->start();
                throw new \RuntimeException('search down');
            }
        );
        $this->suggestionResource->expects(self::exactly(2))->method('beginTransaction');
        $this->suggestionResource->expects(self::once())->method('rollBack');
        $this->suggestionResource->expects(self::once())->method('commit');
        $this->suggestionResource->expects(self::once())->method('save');
        $this->cache->expects(self::once())->method('clean')->with(['cat_p_' . self::ID, 'cat_p_42']);
        $this->eventManager->expects(self::once())->method('dispatch')->with('clean_cache_by_tags');

        self::assertFalse($this->applier->apply($this->suggestion(['attribute_code' => 'color', 'value' => '12']))
            ->isOk());
        self::assertFalse($this->deferredCacheContext->isActive());
        self::assertTrue($this->applier->apply($this->suggestion(['attribute_code' => 'color', 'value' => '12']))
            ->isOk());
    }

    /**
     * @param string[] $tags
     */
    private function indexerCleaned(array $tags): void
    {
        $context = new CacheContext();
        $context->registerTags($tags);
        $this->recorder->execute(new Observer(['event' => new Event(['object' => $context])]));
    }

    public function testApplyUsesTheLockedStatus(): void
    {
        $suggestion = $this->suggestion(['attribute_code' => 'includes', 'value' => '5']);
        $this->storedStatus = Suggestion::STATUS_APPLIED;
        $this->loader->expects(self::never())->method('load');
        $this->expectNoWrite();

        $result = $this->applier->apply($suggestion);

        self::assertSame('Only pending suggestions can be applied.', $result->getReason());
        self::assertSame(Suggestion::STATUS_PENDING, $suggestion->getStatus());
    }

    public function testRevertUsesTheLockedStatus(): void
    {
        $suggestion = $this->suggestion([
            'attribute_code' => 'color',
            'value' => '12',
            'status' => Suggestion::STATUS_APPLIED,
        ]);
        $this->storedStatus = Suggestion::STATUS_REVERTED;
        $this->loader->expects(self::never())->method('load');
        $this->expectNoWrite();

        self::assertSame(
            'Only applied suggestions can be reverted.',
            $this->applier->revert($suggestion)->getReason()
        );
    }

    public function testApplyRefusedWhenTheRowIsGone(): void
    {
        $suggestion = $this->suggestion(['attribute_code' => 'color', 'value' => '12']);
        $this->storedStatus = null;
        $this->loader->expects(self::never())->method('load');
        $this->expectNoWrite();

        self::assertSame('The suggestion no longer exists.', $this->applier->apply($suggestion)->getReason());
    }

    public function testRevertToEmptyWritesNull(): void
    {
        $suggestion = $this->suggestion([
            'attribute_code' => 'color',
            'value' => '12',
            'previous_value' => null,
            'status' => Suggestion::STATUS_APPLIED,
            'updated_at' => '2026-10-01 00:00:00',
        ]);
        $this->live('color', '12');
        $this->expectWrite('color', null);

        self::assertTrue($this->applier->revert($suggestion)->isOk());
        self::assertSame(Suggestion::STATUS_REVERTED, $suggestion->getStatus());
        self::assertFalse($suggestion->hasData('updated_at'));
    }

    public function testRevertWriteFailureIsRefusalAndKeepsStatus(): void
    {
        $suggestion = $this->suggestion([
            'attribute_code' => 'color',
            'value' => '12',
            'previous_value' => null,
            'status' => Suggestion::STATUS_APPLIED,
        ]);
        $this->live('color', '12');
        $this->productAction->expects(self::once())->method('updateAttributes')
            ->willThrowException(new \RuntimeException('deadlock'));
        $this->suggestionResource->expects(self::never())->method('save');
        $this->expectCommit(false);
        $this->fulltext->expects(self::once())->method('reindexList')->with([self::ID]);

        $result = $this->applier->revert($suggestion);

        self::assertFalse($result->isOk());
        self::assertSame('Could not save the attribute: deadlock', $result->getReason());
        self::assertSame(Suggestion::STATUS_APPLIED, $suggestion->getStatus());
    }

    public function testRevertAddMissingRestoresPrevious(): void
    {
        $suggestion = $this->suggestion([
            'attribute_code' => 'includes',
            'value' => '5,1',
            'previous_value' => '9,3',
            'status' => Suggestion::STATUS_APPLIED,
        ]);
        $this->live('includes', '9,5,3,1');
        $this->expectWrite('includes', '9,3');

        self::assertTrue($this->applier->revert($suggestion)->isOk());
        self::assertSame(Suggestion::STATUS_REVERTED, $suggestion->getStatus());
    }

    public function testRevertRefusedAfterManualEdit(): void
    {
        $suggestion = $this->suggestion([
            'attribute_code' => 'color',
            'value' => '12',
            'status' => Suggestion::STATUS_APPLIED,
        ]);
        $this->live('color', '13');
        $this->expectNoWrite();

        $result = $this->applier->revert($suggestion);

        self::assertFalse($result->isOk());
        self::assertSame('The value was changed after it was applied.', $result->getReason());
        self::assertSame(Suggestion::STATUS_APPLIED, $suggestion->getStatus());
    }

    public function testRevertRefusedWhenValueWasCleared(): void
    {
        $this->live('color', null);
        $this->expectNoWrite();

        self::assertFalse($this->applier->revert($this->suggestion([
            'attribute_code' => 'color',
            'value' => '12',
            'status' => Suggestion::STATUS_APPLIED,
        ]))->isOk());
    }

    public function testRevertRefusedWhenNotApplied(): void
    {
        $this->loader->expects(self::never())->method('load');
        $this->expectNoWrite();

        $result = $this->applier->revert($this->suggestion(['attribute_code' => 'color', 'value' => '12']));

        self::assertSame('Only applied suggestions can be reverted.', $result->getReason());
    }

    public function testRevertRefusedWhenProductMissing(): void
    {
        $this->loader->expects(self::once())->method('load')->willReturn(null);
        $this->expectNoWrite();

        self::assertFalse($this->applier->revert($this->suggestion([
            'attribute_code' => 'color',
            'value' => '12',
            'status' => Suggestion::STATUS_APPLIED,
        ]))->isOk());
    }
}

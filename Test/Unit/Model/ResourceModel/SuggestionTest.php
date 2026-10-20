<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\ResourceModel;

use DmLab\CatalogEnrich\Model\ResourceModel\Suggestion;
use DmLab\CatalogEnrich\Model\Suggestion as SuggestionModel;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SuggestionTest extends TestCase
{
    use RecordsSelect;

    /**
     * @var Suggestion
     */
    private Suggestion $resource;

    protected function setUp(): void
    {
        $this->resource = new Suggestion($this->dbContext());
    }

    public function testMainTableAndIdField(): void
    {
        $this->adapter->expects(self::never())->method('select');

        self::assertSame('pfx_dmlab_catalog_enrich_suggestion', $this->resource->getMainTable());
        self::assertSame('suggestion_id', $this->resource->getIdFieldName());
    }

    public function testFindForNewestFirst(): void
    {
        $rows = [['suggestion_id' => '9'], ['suggestion_id' => '4']];
        $this->adapter->expects(self::once())->method('fetchAll')->willReturn($rows);

        self::assertSame($rows, $this->resource->findFor(5, 'color'));
        self::assertSame([['product_id = ?', 5], ['attribute_code = ?', 'color']], $this->callsOf('where'));
        self::assertSame([[['created_at DESC', 'suggestion_id DESC']]], $this->callsOf('order'));
    }

    public function testFindForProductsGroupsByProductAndCodeInOneQuery(): void
    {
        $this->adapter->expects(self::once())->method('fetchAll')->willReturn([
            ['suggestion_id' => '9', 'product_id' => '5', 'attribute_code' => 'color'],
            ['suggestion_id' => '4', 'product_id' => '5', 'attribute_code' => 'color'],
            ['suggestion_id' => '3', 'product_id' => '6', 'attribute_code' => 'size'],
        ]);

        $rows = $this->resource->findForProducts([5, '6'], ['color', 'size']);

        self::assertSame(['9', '4'], array_column($rows[5]['color'], 'suggestion_id'));
        self::assertSame(['3'], array_column($rows[6]['size'], 'suggestion_id'));
        self::assertSame(
            [['product_id IN (?)', [5, 6]], ['attribute_code IN (?)', ['color', 'size']]],
            $this->callsOf('where')
        );
        self::assertSame([[['created_at DESC', 'suggestion_id DESC']]], $this->callsOf('order'));
    }

    public function testFindForProductsWithoutProductsOrCodesQueriesNothing(): void
    {
        $this->adapter->expects(self::never())->method('fetchAll');

        self::assertSame([], $this->resource->findForProducts([], ['color']));
        self::assertSame([], $this->resource->findForProducts([5], []));
    }

    public function testDeleteStaleOnlyPendingAndShadowWithAnotherFingerprint(): void
    {
        $this->adapter->expects(self::once())->method('delete')->with(
            'pfx_dmlab_catalog_enrich_suggestion',
            [
                'product_id = ?' => 5,
                'attribute_code = ?' => 'color',
                'status IN (?)' => ['pending', 'shadow'],
                'fingerprint <> ?' => 'fp-new',
            ]
        )->willReturn(2);

        self::assertSame(2, $this->resource->deleteStale(5, 'color', 'fp-new'));
    }

    public function testDeleteOpenRemovesPendingAndShadowOfThePair(): void
    {
        $this->adapter->expects(self::once())->method('delete')->with(
            'pfx_dmlab_catalog_enrich_suggestion',
            ['product_id = ?' => 5, 'attribute_code = ?' => 'color', 'status IN (?)' => ['pending', 'shadow']]
        )->willReturn(1);

        self::assertSame(1, $this->resource->deleteOpen(5, 'color'));
    }

    public function testDeleteOpenForProductsRemovesPendingAndShadowOfTheAttribute(): void
    {
        $this->adapter->expects(self::once())->method('delete')->with(
            'pfx_dmlab_catalog_enrich_suggestion',
            ['product_id IN (?)' => [5, 6], 'attribute_code = ?' => 'color', 'status IN (?)' => ['pending', 'shadow']]
        )->willReturn(3);

        self::assertSame(3, $this->resource->deleteOpenForProducts('color', [5, '6']));
    }

    public function testDeleteOpenForNoProductsDeletesNothing(): void
    {
        $this->adapter->expects(self::never())->method('delete');

        self::assertSame(0, $this->resource->deleteOpenForProducts('color', []));
    }

    public function testLockStatusReadsTheRowForUpdate(): void
    {
        $this->adapter->expects(self::once())->method('fetchOne')->willReturn('pending');

        self::assertSame('pending', $this->resource->lockStatus(7));
        self::assertSame([['pfx_dmlab_catalog_enrich_suggestion', ['status']]], $this->callsOf('from'));
        self::assertSame([['suggestion_id = ?', 7]], $this->callsOf('where'));
        self::assertSame([[]], $this->callsOf('forUpdate'));
    }

    public function testLockStatusOfAMissingRowIsNull(): void
    {
        $this->adapter->expects(self::once())->method('fetchOne')->willReturn(false);

        self::assertNull($this->resource->lockStatus(7));
    }

    /**
     * @return array<string, array{0: int, 1: bool}>
     */
    public static function affectedRows(): array
    {
        return ['updated' => [1, true], 'changed concurrently' => [0, false]];
    }

    #[DataProvider('affectedRows')]
    public function testSaveIfStatusUpdatesOnlyTheExpectedStatus(int $affected, bool $saved): void
    {
        $suggestion = new SuggestionModel(
            $this->createStub(Context::class),
            $this->createStub(Registry::class),
            $this->resource,
            null,
            ['suggestion_id' => '8', 'value' => '12', 'probability' => 0.93, 'status' => 'pending', 'model' => 'x']
        );
        $this->adapter->expects(self::once())->method('update')->with(
            'pfx_dmlab_catalog_enrich_suggestion',
            ['value' => '12', 'probability' => 0.93, 'status' => 'pending'],
            ['suggestion_id = ?' => 8, 'status = ?' => 'shadow']
        )->willReturn($affected);

        self::assertSame($saved, $this->resource->saveIfStatus($suggestion, 'shadow'));
    }

    #[DataProvider('affectedRows')]
    public function testTransitionStatusOnlyFromTheExpectedStatus(int $affected, bool $moved): void
    {
        $this->adapter->expects(self::once())->method('update')->with(
            'pfx_dmlab_catalog_enrich_suggestion',
            ['status' => 'rejected'],
            ['suggestion_id = ?' => 8, 'status = ?' => 'pending']
        )->willReturn($affected);

        self::assertSame($moved, $this->resource->transitionStatus(8, 'pending', 'rejected'));
    }

    public function testCountAppliedToEmptyOnProductsOfTheSets(): void
    {
        $this->adapter->expects(self::once())->method('fetchOne')->willReturn('4');

        self::assertSame(4, $this->resource->countAppliedToEmpty('color', [4, 9]));
        self::assertSame(
            [['e' => 'pfx_catalog_product_entity'], 'e.entity_id = s.product_id', []],
            $this->callsOf('join')[0]
        );
        self::assertSame(
            [
                ['s.attribute_code = ?', 'color'],
                ['s.status = ?', 'applied'],
                ['s.previous_value IS NULL'],
                ['e.attribute_set_id IN (?)', [4, 9]],
            ],
            $this->callsOf('where')
        );
    }

    public function testCountAppliedToEmptyCountsZeroWhenItIsEmpty(): void
    {
        $this->adapter->expects(self::once())->method('fetchOne')->willReturn('5');

        self::assertSame(5, $this->resource->countAppliedToEmpty('is_assembled', [4], true));
        self::assertContains(["s.previous_value IS NULL OR s.previous_value = '0'"], $this->callsOf('where'));
        // An applied "No" over a 0 left the product empty: countFilled does not count it either.
        self::assertContains(["s.value <> '0'"], $this->callsOf('where'));
    }

    public function testCountAppliedToEmptyWithoutSetsIsZero(): void
    {
        $this->adapter->expects(self::never())->method('fetchOne');

        self::assertSame(0, $this->resource->countAppliedToEmpty('color', []));
    }

    public function testCountByStatus(): void
    {
        $this->adapter->expects(self::once())->method('fetchPairs')->willReturn(['pending' => '3', 'applied' => '10']);

        self::assertSame(['pending' => 3, 'applied' => 10], $this->resource->countByStatus('color'));
        $from = $this->callsOf('from');
        self::assertSame('status', $from[0][1][0]);
        self::assertInstanceOf(Expression::class, $from[0][1]['cnt']);
        self::assertSame([['attribute_code = ?', 'color']], $this->callsOf('where'));
        self::assertSame([['status']], $this->callsOf('group'));
    }
}

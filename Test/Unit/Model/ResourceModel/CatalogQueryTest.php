<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\ResourceModel;

use DmLab\CatalogEnrich\Model\Attribute\AttributeDescriptor;
use DmLab\CatalogEnrich\Model\ResourceModel\CatalogQuery;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\DB\Sql\Expression;
use PHPUnit\Framework\TestCase;

class CatalogQueryTest extends TestCase
{
    use RecordsSelect;

    /**
     * @var CatalogQuery
     */
    private CatalogQuery $query;

    /**
     * @var bool
     */
    private bool $configurableInstalled = true;

    protected function setUp(): void
    {
        $visibility = $this->createStub(AbstractAttribute::class);
        $visibility->method('getAttributeId')->willReturn('99');
        $eavConfig = $this->createStub(EavConfig::class);
        $eavConfig->method('getAttribute')->willReturnMap([['catalog_product', 'visibility', $visibility]]);
        $this->query = new CatalogQuery($this->resourceConnection(), $eavConfig);
        $this->adapter->method('isTableExists')->willReturnCallback(fn (): bool => $this->configurableInstalled);
    }

    private function quoteInto(): void
    {
        $this->adapter->method('quoteInto')->willReturnCallback(
            static fn (string $text, mixed $value): string => str_replace(
                '?',
                is_array($value) ? implode(',', $value) : "'" . $value . "'",
                $text
            )
        );
    }

    private static function select(): AttributeDescriptor
    {
        return new AttributeDescriptor(93, 'color', 'Color', 'select', 'catalog_product_entity_int', [1 => 'Red']);
    }

    private static function multiselect(): AttributeDescriptor
    {
        return new AttributeDescriptor(94, 'style', 'Style', 'multiselect', 'catalog_product_entity_varchar', [
            5 => 'Modern',
        ]);
    }

    public function testSetIdsForAttribute(): void
    {
        $this->adapter->expects(self::once())->method('fetchCol')->willReturn(['4', '9']);

        self::assertSame([4, 9], $this->query->setIdsForAttribute(93));
        self::assertSame([[]], $this->callsOf('distinct'));
        self::assertSame([['pfx_eav_entity_attribute', ['attribute_set_id']]], $this->callsOf('from'));
        self::assertSame([['attribute_id = ?', 93]], $this->callsOf('where'));
    }

    public function testFetchIdsOrderedAfterCursorWithSetsSkusAndLimit(): void
    {
        $this->adapter->expects(self::once())->method('fetchCol')->willReturn(['11', '12']);

        self::assertSame([11, 12], $this->query->fetchIds(10, 50, [4, '9'], ['A', 'B']));
        self::assertSame([[['e' => 'pfx_catalog_product_entity'], ['entity_id']]], $this->callsOf('from'));
        self::assertSame([
            ['e.entity_id > ?', 10],
            ['e.attribute_set_id IN (?)', [4, 9]],
            ['e.sku IN (?)', ['A', 'B']],
        ], $this->callsOf('where'));
        self::assertSame([['e.entity_id ASC']], $this->callsOf('order'));
        self::assertSame([[50]], $this->callsOf('limit'));
        self::assertSame([], $this->callsOf('joinLeft'));
    }

    public function testFetchIdsVisibleOnlyDropsNotVisibleIndividually(): void
    {
        $this->quoteInto();
        $this->adapter->expects(self::once())->method('fetchCol')->willReturn(['11']);

        self::assertSame([11], $this->query->fetchIds(0, null, [4], [], true));
        self::assertSame([[
            ['vis' => 'pfx_catalog_product_entity_int'],
            "vis.entity_id = e.entity_id AND vis.store_id = 0 AND vis.attribute_id = '99'",
            [],
        ]], $this->callsOf('joinLeft'));
        self::assertContains(['vis.value IS NULL OR vis.value <> ?', 1], $this->callsOf('where'));
    }

    public function testFetchSuperAttributeIdsGroupedByProduct(): void
    {
        $this->adapter->expects(self::once())->method('fetchAll')->willReturn([
            ['product_id' => '5', 'attribute_id' => '93'],
            ['product_id' => '5', 'attribute_id' => '144'],
            ['product_id' => '8', 'attribute_id' => '93'],
        ]);

        self::assertSame([5 => [93, 144], 8 => [93]], $this->query->fetchSuperAttributeIds([5, '8', 9]));
        self::assertSame(
            [['pfx_catalog_product_super_attribute', ['product_id', 'attribute_id']]],
            $this->callsOf('from')
        );
        self::assertSame([['product_id IN (?)', [5, 8, 9]]], $this->callsOf('where'));
    }

    public function testWithoutConfigurableProductModuleSuperAttributesAreSkippedAndCheckedOnce(): void
    {
        $this->configurableInstalled = false;
        $this->quoteInto();
        $this->adapter->expects(self::never())->method('fetchAll');
        $this->adapter->expects(self::once())->method('isTableExists')
            ->with('pfx_catalog_product_super_attribute')->willReturn(false);
        $this->adapter->expects(self::exactly(2))->method('fetchCol')->willReturn([]);

        self::assertSame([], $this->query->fetchSuperAttributeIds([5]));
        $this->query->fetchFilledIds(self::select(), 5);
        $this->query->fetchFilledIds(self::select(), 5);

        self::assertCount(2, $this->callsOf('joinLeft'));
        self::assertSame(['s' => 'pfx_dmlab_catalog_enrich_suggestion'], $this->callsOf('joinLeft')[0][0]);
        self::assertNotContains(['sa.product_super_attribute_id IS NULL'], $this->callsOf('where'));
    }

    public function testFetchSuperAttributeIdsWithoutProductsQueriesNothing(): void
    {
        $this->adapter->expects(self::never())->method('fetchAll');

        self::assertSame([], $this->query->fetchSuperAttributeIds([]));
    }

    public function testFetchIdsWithoutLimitOrSkus(): void
    {
        $this->adapter->expects(self::once())->method('fetchCol')->willReturn([]);

        self::assertSame([], $this->query->fetchIds(0, null, [4], []));
        self::assertSame([], $this->callsOf('limit'));
        self::assertCount(2, $this->callsOf('where'));
    }

    public function testFetchIdsWithoutSetsSelectsNothing(): void
    {
        $this->adapter->expects(self::never())->method('fetchCol');

        self::assertSame([], $this->query->fetchIds(0, 10, [], []));
        self::assertSame([], $this->query->fetchIds(0, 0, [4], []));
    }

    public function testFetchFilledIdsSelectNewestFirstStoreZeroNotNull(): void
    {
        $this->quoteInto();
        $this->adapter->expects(self::once())->method('fetchCol')->willReturn(['30', '20']);

        self::assertSame([30, 20], $this->query->fetchFilledIds(self::select(), 200));
        self::assertSame([[['v' => 'pfx_catalog_product_entity_int'], []]], $this->callsOf('from'));
        self::assertSame([['entity_id' => 'v.entity_id']], array_column($this->callsOf('columns'), 0));
        self::assertSame([
            [
                ['s' => 'pfx_dmlab_catalog_enrich_suggestion'],
                "s.product_id = v.entity_id AND s.attribute_code = 'color' AND s.status = 'applied'",
                [],
            ],
            [
                ['sa' => 'pfx_catalog_product_super_attribute'],
                "sa.product_id = v.entity_id AND sa.attribute_id = '93'",
                [],
            ],
        ], $this->callsOf('joinLeft'));
        self::assertSame([['v.entity_id']], $this->callsOf('group'));
        self::assertSame([
            ['s.suggestion_id IS NULL'],
            ['sa.product_super_attribute_id IS NULL'],
            ['v.attribute_id = ?', 93],
            ['v.store_id = ?', 0],
            ['v.value IS NOT NULL'],
        ], $this->callsOf('where'));
        self::assertSame([['v.entity_id DESC']], $this->callsOf('order'));
        self::assertSame([[200]], $this->callsOf('limit'));
    }

    public function testFetchFilledIdsVisibleOnlyAndWithoutZero(): void
    {
        $this->quoteInto();
        $this->adapter->expects(self::once())->method('fetchCol')->willReturn([]);

        $this->query->fetchFilledIds(self::select(), 5, true, true);

        self::assertCount(3, $this->callsOf('joinLeft'));
        self::assertSame(['vis' => 'pfx_catalog_product_entity_int'], $this->callsOf('joinLeft')[2][0]);
        self::assertContains(['vis.value IS NULL OR vis.value <> ?', 1], $this->callsOf('where'));
        self::assertContains(['v.value <> ?', 0], $this->callsOf('where'));
    }

    public function testFetchFilledIdsMultiselectAlsoExcludesEmptyString(): void
    {
        $this->quoteInto();
        $this->adapter->expects(self::once())->method('fetchCol')->willReturn([]);

        $this->query->fetchFilledIds(self::multiselect(), 5);

        self::assertSame([[['v' => 'pfx_catalog_product_entity_varchar'], []]], $this->callsOf('from'));
        self::assertContains(["v.value <> ''"], $this->callsOf('where'));
        self::assertNotContains(['v.value <> ?', 0], $this->callsOf('where'));
    }

    public function testCountZeroGoldUsesTheSampleFilters(): void
    {
        $this->quoteInto();
        $this->adapter->expects(self::once())->method('fetchOne')->willReturn('12');

        self::assertSame(12, $this->query->countZeroGold(self::select(), true));
        $columns = $this->callsOf('columns');
        self::assertInstanceOf(Expression::class, $columns[0][0][0]);
        self::assertSame('COUNT(DISTINCT v.entity_id)', (string)$columns[0][0][0]);
        self::assertContains(['v.value = ?', 0], $this->callsOf('where'));
        self::assertContains(['sa.product_super_attribute_id IS NULL'], $this->callsOf('where'));
        self::assertCount(3, $this->callsOf('joinLeft'));
    }

    public function testFetchValuesByStoreKeyedByProductAndStore(): void
    {
        $this->adapter->expects(self::once())->method('fetchAll')->willReturn([
            ['entity_id' => '7', 'store_id' => '0', 'value' => '5,6'],
            ['entity_id' => '9', 'store_id' => '1', 'value' => '6'],
            ['entity_id' => '9', 'store_id' => '2', 'value' => '5'],
        ]);

        self::assertSame(
            [7 => [0 => '5,6'], 9 => [1 => '6', 2 => '5']],
            $this->query->fetchValuesByStore(self::multiselect(), [7, '9'])
        );
        self::assertSame(
            [[['v' => 'pfx_catalog_product_entity_varchar'], ['entity_id', 'store_id', 'value']]],
            $this->callsOf('from')
        );
        self::assertSame([
            ['v.entity_id IN (?)', [7, 9]],
            ['v.attribute_id = ?', 94],
            ['v.value IS NOT NULL'],
            ["v.value <> ''"],
        ], $this->callsOf('where'));
        self::assertSame([['v.store_id ASC']], $this->callsOf('order'));
    }

    public function testFetchValuesByStoreWithoutProductsQueriesNothing(): void
    {
        $this->adapter->expects(self::never())->method('fetchAll');

        self::assertSame([], $this->query->fetchValuesByStore(self::select(), []));
    }

    public function testCountInSets(): void
    {
        $this->adapter->expects(self::once())->method('fetchOne')->willReturn('42');

        self::assertSame(42, $this->query->countInSets([4, 9]));
        $from = $this->callsOf('from');
        self::assertSame('pfx_catalog_product_entity', $from[0][0]);
        self::assertInstanceOf(Expression::class, $from[0][1][0]);
        self::assertSame([['attribute_set_id IN (?)', [4, 9]]], $this->callsOf('where'));
    }

    public function testCountFilledJoinsEntityForSets(): void
    {
        $this->adapter->expects(self::once())->method('fetchOne')->willReturn('7');

        self::assertSame(7, $this->query->countFilled(self::select(), [4]));
        self::assertSame(
            [[['e' => 'pfx_catalog_product_entity'], 'e.entity_id = v.entity_id', []]],
            $this->callsOf('join')
        );
        self::assertSame([
            ['e.attribute_set_id IN (?)', [4]],
            ['v.attribute_id = ?', 93],
            ['v.store_id = ?', 0],
            ['v.value IS NOT NULL'],
        ], $this->callsOf('where'));
    }

    public function testCountFilledCanExcludeZero(): void
    {
        $this->adapter->expects(self::once())->method('fetchOne')->willReturn('3');

        self::assertSame(3, $this->query->countFilled(self::select(), [4], true));
        self::assertContains(['v.value <> ?', 0], $this->callsOf('where'));
    }

    public function testCountsWithoutSetsAreZero(): void
    {
        $this->adapter->expects(self::never())->method('fetchOne');

        self::assertSame(0, $this->query->countInSets([]));
        self::assertSame(0, $this->query->countFilled(self::select(), []));
    }
}

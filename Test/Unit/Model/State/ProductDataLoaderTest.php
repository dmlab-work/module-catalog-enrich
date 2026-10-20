<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\State;

use DmLab\CatalogEnrich\Model\State\ProductDataLoader;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;

class ProductDataLoaderTest extends TestCase
{
    /**
     * Table => rows the adapter returns for it.
     *
     * @var array<string, array<int, array<string,mixed>>>
     */
    private array $tables = [];

    /**
     * Per select: [table, columns, where conditions].
     *
     * @var array<int, array{0: string, 1: array, 2: array}>
     */
    private array $queries = [];

    protected function setUp(): void
    {
        $this->tables = [
            'cpe' => [
                ['entity_id' => '1', 'sku' => 'A', 'attribute_set_id' => '4', 'type_id' => 'simple'],
                ['entity_id' => '2', 'sku' => 'B', 'attribute_set_id' => '9', 'type_id' => 'configurable'],
            ],
            'cpe_varchar' => [
                ['entity_id' => '1', 'attribute_id' => '73', 'store_id' => '0', 'value' => 'Chair'],
                ['entity_id' => '2', 'attribute_id' => '73', 'store_id' => '0', 'value' => 'Table'],
            ],
            'cpe_int' => [
                ['entity_id' => '1', 'attribute_id' => '93', 'store_id' => '0', 'value' => '12'],
                ['entity_id' => '1', 'attribute_id' => '93', 'store_id' => '1', 'value' => '13'],
                ['entity_id' => '2', 'attribute_id' => '93', 'store_id' => '1', 'value' => '14'],
                ['entity_id' => '2', 'attribute_id' => '93', 'store_id' => '2', 'value' => '14'],
                ['entity_id' => '2', 'attribute_id' => '93', 'store_id' => '3', 'value' => null],
                ['entity_id' => '2', 'attribute_id' => '99', 'store_id' => '0', 'value' => '4'],
            ],
        ];
    }

    private function loader(): ProductDataLoader
    {
        $adapter = $this->createStub(AdapterInterface::class);
        $adapter->method('select')->willReturnCallback(fn (): Select => $this->select());
        $adapter->method('quoteInto')->willReturnCallback(
            static fn (string $text, $value): string => str_replace(
                '?',
                is_array($value) ? implode(',', $value) : (string)$value,
                $text
            )
        );
        $adapter->method('fetchAll')->willReturnCallback(function (Select $select): array {
            $query = array_pop($this->queries);
            $this->queries[] = $query;

            return $this->tables[$query[0]] ?? [];
        });

        $attributes = [
            'name' => $this->attribute(73, 'cpe_varchar'),
            'color' => $this->attribute(93, 'cpe_int'),
            'visibility' => $this->attribute(99, 'cpe_int'),
            'created_at' => $this->attribute(0, 'cpe', true),
        ];
        $resource = $this->createStub(ProductResource::class);
        $resource->method('getConnection')->willReturn($adapter);
        $resource->method('getEntityTable')->willReturn('cpe');
        $resource->method('getAttribute')->willReturnCallback(
            static fn (string $code) => $attributes[$code] ?? false
        );

        return new ProductDataLoader($resource);
    }

    private function attribute(int $id, string $table, bool $static = false): AbstractAttribute
    {
        $attribute = $this->createStub(AbstractAttribute::class);
        $attribute->method('getAttributeId')->willReturn($id);
        $attribute->method('getBackendTable')->willReturn($table);
        $attribute->method('isStatic')->willReturn($static);

        return $attribute;
    }

    private function select(): Select
    {
        $index = count($this->queries);
        $this->queries[] = ['', [], []];
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnCallback(function ($table, $columns) use ($index, $select): Select {
            $this->queries[$index][0] = $table;
            $this->queries[$index][1] = $columns;

            return $select;
        });
        $select->method('where')->willReturnCallback(function ($cond, $value = null) use ($index, $select): Select {
            $this->queries[$index][2][] = $value === null ? $cond : [$cond, $value];

            return $select;
        });

        return $select;
    }

    public function testLoadsABatchWithOneQueryPerTable(): void
    {
        $data = $this->loader()->loadMany([1, 2, 3, 1], ['name', 'color', 'unknown', 'created_at']);

        self::assertSame([
            1 => [
                'sku' => 'A', 'attribute_set_id' => '4', 'type_id' => 'simple',
                'name' => 'Chair', 'color' => '12', 'unknown' => null, 'created_at' => null,
            ],
            2 => [
                'sku' => 'B', 'attribute_set_id' => '9', 'type_id' => 'configurable',
                'name' => 'Table', 'color' => null, 'unknown' => null, 'created_at' => null,
            ],
        ], $data);
        self::assertCount(3, $this->queries);
        self::assertSame(['cpe', ['entity_id', 'sku', 'attribute_set_id', 'type_id', 'created_at']], [
            $this->queries[0][0],
            $this->queries[0][1],
        ]);
        self::assertSame([['entity_id IN (?)', [1, 2, 3]]], $this->queries[0][2]);
        self::assertSame(
            [['entity_id IN (?)', [1, 2]], ['attribute_id IN (?)', [93]], ['store_id = ?', 0]],
            $this->queries[2][2]
        );
    }

    public function testScopedCodesCollectDistinctNonEmptyStoreViewValues(): void
    {
        $data = $this->loader()->loadMany([1, 2], ['visibility'], ['color']);

        self::assertSame(['color' => ['13']], $data[1][ProductDataLoader::STORE_VIEW_VALUES]);
        self::assertSame(['color' => ['14']], $data[2][ProductDataLoader::STORE_VIEW_VALUES]);
        self::assertSame('12', $data[1]['color']);
        self::assertNull($data[2]['color']);
        self::assertSame('4', $data[2]['visibility']);
        self::assertSame(
            [['entity_id IN (?)', [1, 2]], ['attribute_id IN (?)', [99, 93]], 'store_id = 0 OR attribute_id IN (93)'],
            $this->queries[1][2]
        );
    }

    public function testLoadReturnsOneProduct(): void
    {
        $data = $this->loader()->load(1, ['color']);

        self::assertSame(['sku' => 'A', 'attribute_set_id' => '4', 'type_id' => 'simple', 'color' => '12'], $data);
    }

    public function testMissingProductsAreAbsentWithoutValueQueries(): void
    {
        $loader = $this->loader();

        self::assertNull($loader->load(999, ['name']));
        self::assertSame([], $loader->loadMany([0, -1], ['name']));
        self::assertCount(1, $this->queries);
    }
}

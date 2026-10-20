<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\ResourceModel;

use DmLab\CatalogEnrich\Model\ResourceModel\Attempt;
use PHPUnit\Framework\TestCase;

class AttemptTest extends TestCase
{
    use RecordsSelect;

    /**
     * @var Attempt
     */
    private Attempt $resource;

    protected function setUp(): void
    {
        $this->resource = new Attempt($this->dbContext());
    }

    public function testMainTableAndIdField(): void
    {
        $this->adapter->expects(self::never())->method('select');

        self::assertSame('pfx_dmlab_catalog_enrich_attempt', $this->resource->getMainTable());
        self::assertSame('attempt_id', $this->resource->getIdFieldName());
    }

    public function testLoadForProductsIsOneQueryKeyedByProductAndCode(): void
    {
        $rows = [
            ['product_id' => '5', 'attribute_code' => 'color', 'outcome' => 'accepted'],
            ['product_id' => '5', 'attribute_code' => 'style', 'outcome' => 'error'],
            ['product_id' => '7', 'attribute_code' => 'color', 'outcome' => 'uncertain'],
        ];
        $this->adapter->expects(self::once())->method('fetchAll')->willReturn($rows);

        $result = $this->resource->loadForProducts([5, '7', 5], ['color', 'style']);

        self::assertSame([5 => ['color' => $rows[0], 'style' => $rows[1]], 7 => ['color' => $rows[2]]], $result);
        self::assertSame([['pfx_dmlab_catalog_enrich_attempt']], $this->callsOf('from'));
        self::assertSame([
            ['product_id IN (?)', [5, 7]],
            ['attribute_code IN (?)', ['color', 'style']],
        ], $this->callsOf('where'));
    }

    public function testLoadForProductsWithEmptyInputSkipsQuery(): void
    {
        $this->adapter->expects(self::never())->method('fetchAll');

        self::assertSame([], $this->resource->loadForProducts([], ['color']));
        self::assertSame([], $this->resource->loadForProducts([1], []));
    }

    public function testCountOutcomesIsOneGroupedQuery(): void
    {
        $this->adapter->expects(self::once())->method('fetchAll')->willReturn([
            ['attribute_code' => 'color', 'outcome' => 'accepted', 'total' => '4'],
            ['attribute_code' => 'color', 'outcome' => 'error', 'total' => '1'],
            ['attribute_code' => 'style', 'outcome' => 'not_stated', 'total' => '2'],
        ]);

        self::assertSame(
            ['color' => ['accepted' => 4, 'error' => 1], 'style' => ['not_stated' => 2]],
            $this->resource->countOutcomes(['color', 'style', 'color'])
        );
        self::assertSame(
            [['pfx_dmlab_catalog_enrich_attempt', ['attribute_code', 'outcome', 'total' => 'COUNT(*)']]],
            $this->callsOf('from')
        );
        self::assertSame([['attribute_code IN (?)', ['color', 'style']]], $this->callsOf('where'));
        self::assertSame([[['attribute_code', 'outcome']]], $this->callsOf('group'));
    }

    public function testCountOutcomesWithoutCodesSkipsQuery(): void
    {
        $this->adapter->expects(self::never())->method('fetchAll');

        self::assertSame([], $this->resource->countOutcomes([]));
    }

    public function testDeleteByIds(): void
    {
        $this->adapter->expects(self::once())->method('delete')
            ->with('pfx_dmlab_catalog_enrich_attempt', ['attempt_id IN (?)' => [3, 5]])
            ->willReturn(2);

        self::assertSame(2, $this->resource->deleteByIds(['3', 5, 3]));
        self::assertSame(0, $this->resource->deleteByIds([]));
    }

    public function testDeleteByIdsInChunksOfAThousand(): void
    {
        $chunks = [];
        $this->adapter->expects(self::exactly(3))->method('delete')
            ->willReturnCallback(function (string $table, array $where) use (&$chunks): int {
                $chunks[] = $where['attempt_id IN (?)'];

                return count($where['attempt_id IN (?)']);
            });

        self::assertSame(2500, $this->resource->deleteByIds(range(1, 2500)));
        self::assertSame([1000, 1000, 500], array_map('count', $chunks));
        self::assertSame(2001, $chunks[2][0]);
    }

    public function testUpsertUpdatesEveryColumnButTheKey(): void
    {
        $row = [
            'attempt_id' => 3,
            'product_id' => 5,
            'attribute_code' => 'color',
            'fingerprint' => str_repeat('a', 64),
            'outcome' => 'accepted',
            'answer' => '{}',
            'model' => 'jev-1.13.0',
            'error_message' => null,
            'attempts' => 1,
            'attempted_at' => '2026-10-19 10:00:00',
            'next_attempt_at' => null,
        ];
        $expectedRow = $row;
        unset($expectedRow['attempt_id']);
        $this->adapter->expects(self::once())->method('insertOnDuplicate')->with(
            'pfx_dmlab_catalog_enrich_attempt',
            $expectedRow,
            [
                'fingerprint', 'outcome', 'answer', 'model', 'error_message',
                'attempts', 'attempted_at', 'next_attempt_at',
            ]
        );

        $this->resource->upsert($row);
    }

    public function testUpsertRequiresTheUniqueKey(): void
    {
        $this->adapter->expects(self::never())->method('insertOnDuplicate');
        $this->expectException(\InvalidArgumentException::class);

        $this->resource->upsert(['product_id' => 5, 'outcome' => 'error']);
    }
}

<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\ResourceModel\Suggestion\Grid;

use DmLab\CatalogEnrich\Model\ResourceModel\Suggestion\Grid\SelectJoiner;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;

class SelectJoinerTest extends TestCase
{
    public function testJoinsProductAndAttributeTables(): void
    {
        $joins = [];
        $select = $this->createStub(Select::class);
        $select->method('joinLeft')->willReturnCallback(
            function (array $table, string $condition, array $columns) use (&$joins, $select): Select {
                $joins[] = [$table, $condition, $columns];
                return $select;
            }
        );
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getTableName')->willReturnCallback(static fn (string $t): string => 'pfx_' . $t);

        self::assertSame($select, (new SelectJoiner($resource))->join($select));

        self::assertSame([
            [['cpe' => 'pfx_catalog_product_entity'], 'cpe.entity_id = main_table.product_id', ['sku' => 'sku']],
            [['et' => 'pfx_eav_entity_type'], "et.entity_type_code = 'catalog_product'", []],
            [
                ['ea_name' => 'pfx_eav_attribute'],
                "ea_name.entity_type_id = et.entity_type_id AND ea_name.attribute_code = 'name'",
                [],
            ],
            [
                ['pn' => 'pfx_catalog_product_entity_varchar'],
                'pn.entity_id = main_table.product_id AND pn.attribute_id = ea_name.attribute_id AND pn.store_id = 0',
                ['product_name' => 'value'],
            ],
            [
                ['ea' => 'pfx_eav_attribute'],
                'ea.entity_type_id = et.entity_type_id AND ea.attribute_code = main_table.attribute_code',
                ['attribute_label' => 'frontend_label'],
            ],
        ], $joins);
    }

    public function testFilterMapQualifiesJoinedAndAmbiguousFields(): void
    {
        self::assertSame('cpe.sku', SelectJoiner::FILTER_MAP['sku']);
        self::assertSame('pn.value', SelectJoiner::FILTER_MAP['product_name']);
        self::assertSame('ea.frontend_label', SelectJoiner::FILTER_MAP['attribute_label']);
        foreach (['value', 'attribute_code', 'created_at', 'updated_at', 'status'] as $field) {
            self::assertSame('main_table.' . $field, SelectJoiner::FILTER_MAP[$field]);
        }
    }
}

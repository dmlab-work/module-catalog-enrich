<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\ResourceModel\Suggestion\Grid;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;

/**
 * Adds SKU, store-0 product name and attribute label to the suggestion grid select.
 */
class SelectJoiner
{
    /**
     * Grid field => qualified column; main-table fields are qualified because joined tables share their names.
     */
    public const FILTER_MAP = [
        'suggestion_id' => 'main_table.suggestion_id',
        'product_id' => 'main_table.product_id',
        'attribute_code' => 'main_table.attribute_code',
        'value' => 'main_table.value',
        'previous_value' => 'main_table.previous_value',
        'probability' => 'main_table.probability',
        'status' => 'main_table.status',
        'model' => 'main_table.model',
        'created_at' => 'main_table.created_at',
        'updated_at' => 'main_table.updated_at',
        'sku' => 'cpe.sku',
        'product_name' => 'pn.value',
        'attribute_label' => 'ea.frontend_label',
    ];

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Left-join the product and attribute tables onto a select of `main_table`.
     *
     * @param Select $select
     * @return Select
     */
    public function join(Select $select): Select
    {
        $select->joinLeft(
            ['cpe' => $this->table('catalog_product_entity')],
            'cpe.entity_id = main_table.product_id',
            ['sku' => 'sku']
        )->joinLeft(
            ['et' => $this->table('eav_entity_type')],
            "et.entity_type_code = 'catalog_product'",
            []
        )->joinLeft(
            ['ea_name' => $this->table('eav_attribute')],
            "ea_name.entity_type_id = et.entity_type_id AND ea_name.attribute_code = 'name'",
            []
        )->joinLeft(
            ['pn' => $this->table('catalog_product_entity_varchar')],
            'pn.entity_id = main_table.product_id AND pn.attribute_id = ea_name.attribute_id AND pn.store_id = 0',
            ['product_name' => 'value']
        )->joinLeft(
            ['ea' => $this->table('eav_attribute')],
            'ea.entity_type_id = et.entity_type_id AND ea.attribute_code = main_table.attribute_code',
            ['attribute_label' => 'frontend_label']
        );

        return $select;
    }

    /**
     * Prefixed table name.
     *
     * @param string $name
     * @return string
     */
    private function table(string $name): string
    {
        return $this->resourceConnection->getTableName($name);
    }
}

<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\ResourceModel;

use DmLab\CatalogEnrich\Model\Attribute\AttributeDescriptor;
use DmLab\CatalogEnrich\Model\Suggestion;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;

/**
 * Product id / count queries over the catalog tables (store 0).
 */
class CatalogQuery
{
    private const SUPER_ATTRIBUTE_TABLE = 'catalog_product_super_attribute';

    /**
     * Super attribute table name; '' without Magento_ConfigurableProduct, null until checked.
     *
     * @var string|null
     */
    private ?string $superAttributeTable = null;

    /**
     * @param ResourceConnection $resource
     * @param EavConfig $eavConfig
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EavConfig $eavConfig
    ) {
    }

    /**
     * Attribute sets containing the attribute.
     *
     * @param int $attributeId
     * @return int[]
     */
    public function setIdsForAttribute(int $attributeId): array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->distinct()
            ->from($this->resource->getTableName('eav_entity_attribute'), ['attribute_set_id'])
            ->where('attribute_id = ?', $attributeId)
            ->order('attribute_set_id ASC');

        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * Product ids after a cursor, ascending; empty set ids select nothing.
     *
     * @param int $afterId
     * @param int|null $limit
     * @param int[] $setIds
     * @param string[] $skus empty = any
     * @param bool $visibleOnly skip products whose store-0 visibility is "Not Visible Individually"
     * @return int[]
     */
    public function fetchIds(int $afterId, ?int $limit, array $setIds, array $skus, bool $visibleOnly = false): array
    {
        if ($setIds === [] || ($limit !== null && $limit < 1)) {
            return [];
        }
        $connection = $this->connection();
        $select = $connection->select()
            ->from(['e' => $this->resource->getTableName('catalog_product_entity')], ['entity_id'])
            ->where('e.entity_id > ?', $afterId)
            ->where('e.attribute_set_id IN (?)', array_map('intval', $setIds))
            ->order('e.entity_id ASC');
        if ($skus !== []) {
            $select->where('e.sku IN (?)', array_values(array_map('strval', $skus)));
        }
        if ($visibleOnly) {
            $this->filterVisible($select, 'e.entity_id');
        }
        if ($limit !== null) {
            $select->limit($limit);
        }

        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * Configurable attribute ids of the products (only configurable parents have rows).
     *
     * @param int[] $productIds
     * @return array<int, int[]>
     */
    public function fetchSuperAttributeIds(array $productIds): array
    {
        $table = $this->superAttributeTable();
        if ($productIds === [] || $table === null) {
            return [];
        }
        $connection = $this->connection();
        $select = $connection->select()
            ->from($table, ['product_id', 'attribute_id'])
            ->where('product_id IN (?)', array_map('intval', $productIds));

        $ids = [];
        foreach ($connection->fetchAll($select) as $row) {
            $ids[(int)$row['product_id']][] = (int)$row['attribute_id'];
        }

        return $ids;
    }

    /**
     * Calibration sample: ids with a store-0 value, newest first.
     *
     * Values this module applied and configurable parents of the attribute are excluded.
     *
     * @param AttributeDescriptor $descriptor
     * @param int $limit
     * @param bool $visibleOnly skip products whose store-0 visibility is "Not Visible Individually"
     * @param bool $excludeZero skip value 0 (a rule that treats boolean 0 as empty)
     * @return int[]
     */
    public function fetchFilledIds(
        AttributeDescriptor $descriptor,
        int $limit,
        bool $visibleOnly = false,
        bool $excludeZero = false
    ): array {
        if ($limit < 1) {
            return [];
        }
        $connection = $this->connection();
        $select = $this->goldSelect($descriptor, $visibleOnly)
            ->columns(['entity_id' => 'v.entity_id'])
            ->group('v.entity_id')
            ->order('v.entity_id DESC')
            ->limit($limit);
        if ($excludeZero) {
            $select->where('v.value <> ?', 0);
        }

        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * Products the calibration sample would hold with value 0.
     *
     * @param AttributeDescriptor $descriptor
     * @param bool $visibleOnly
     * @return int
     */
    public function countZeroGold(AttributeDescriptor $descriptor, bool $visibleOnly = false): int
    {
        $select = $this->goldSelect($descriptor, $visibleOnly)
            ->columns([new Expression('COUNT(DISTINCT v.entity_id)')])
            ->where('v.value = ?', 0);

        return (int)$this->connection()->fetchOne($select);
    }

    /**
     * Raw values of the attribute in every store for the products: product id => store id => value.
     *
     * @param AttributeDescriptor $descriptor
     * @param int[] $productIds
     * @return array<int, array<int,string>>
     */
    public function fetchValuesByStore(AttributeDescriptor $descriptor, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $connection = $this->connection();
        $select = $connection->select()
            ->from(
                ['v' => $this->resource->getTableName($descriptor->getBackendTable())],
                ['entity_id', 'store_id', 'value']
            )
            ->where('v.entity_id IN (?)', array_map('intval', $productIds))
            ->where('v.attribute_id = ?', $descriptor->getAttributeId())
            ->where('v.value IS NOT NULL')
            ->order('v.store_id ASC');
        if ($descriptor->getInput() === AttributeDescriptor::INPUT_MULTISELECT) {
            $select->where("v.value <> ''");
        }

        $values = [];
        foreach ($connection->fetchAll($select) as $row) {
            $values[(int)$row['entity_id']][(int)$row['store_id']] = (string)$row['value'];
        }

        return $values;
    }

    /**
     * Number of products in the attribute sets.
     *
     * @param int[] $setIds
     * @return int
     */
    public function countInSets(array $setIds): int
    {
        if ($setIds === []) {
            return 0;
        }
        $connection = $this->connection();
        $select = $connection->select()
            ->from($this->resource->getTableName('catalog_product_entity'), [new Expression('COUNT(*)')])
            ->where('attribute_set_id IN (?)', array_map('intval', $setIds));

        return (int)$connection->fetchOne($select);
    }

    /**
     * Number of products in the sets with a store-0 value for the attribute.
     *
     * @param AttributeDescriptor $descriptor
     * @param int[] $setIds
     * @param bool $excludeZero value 0 counts as empty
     * @return int
     */
    public function countFilled(AttributeDescriptor $descriptor, array $setIds, bool $excludeZero = false): int
    {
        if ($setIds === []) {
            return 0;
        }
        $connection = $this->connection();
        $select = $connection->select()
            ->from(['v' => $this->resource->getTableName($descriptor->getBackendTable())], [new Expression('COUNT(*)')])
            ->join(
                ['e' => $this->resource->getTableName('catalog_product_entity')],
                'e.entity_id = v.entity_id',
                []
            )
            ->where('e.attribute_set_id IN (?)', array_map('intval', $setIds));
        $this->filterFilled($select, $descriptor);
        if ($excludeZero) {
            $select->where('v.value <> ?', 0);
        }

        return (int)$connection->fetchOne($select);
    }

    /**
     * Store-0 value rows usable as calibration gold, without columns.
     *
     * @param AttributeDescriptor $descriptor
     * @param bool $visibleOnly
     * @return Select
     */
    private function goldSelect(AttributeDescriptor $descriptor, bool $visibleOnly): Select
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from(['v' => $this->resource->getTableName($descriptor->getBackendTable())], [])
            ->joinLeft(
                ['s' => $this->resource->getTableName('dmlab_catalog_enrich_suggestion')],
                $connection->quoteInto('s.product_id = v.entity_id AND s.attribute_code = ?', $descriptor->getCode())
                . $connection->quoteInto(' AND s.status = ?', Suggestion::STATUS_APPLIED),
                []
            )
            ->where('s.suggestion_id IS NULL');
        $superAttributeTable = $this->superAttributeTable();
        if ($superAttributeTable !== null) {
            $select->joinLeft(
                ['sa' => $superAttributeTable],
                $connection->quoteInto(
                    'sa.product_id = v.entity_id AND sa.attribute_id = ?',
                    $descriptor->getAttributeId()
                ),
                []
            )->where('sa.product_super_attribute_id IS NULL');
        }
        $this->filterFilled($select, $descriptor);
        if ($visibleOnly) {
            $this->filterVisible($select, 'v.entity_id');
        }

        return $select;
    }

    /**
     * Drop products whose store-0 visibility is "Not Visible Individually"; no row means visible.
     *
     * @param Select $select
     * @param string $entityColumn qualified product id column of the select
     * @return void
     */
    private function filterVisible(Select $select, string $entityColumn): void
    {
        $connection = $this->connection();
        $attribute = $this->eavConfig->getAttribute(Product::ENTITY, 'visibility');
        $select->joinLeft(
            ['vis' => $this->resource->getTableName('catalog_product_entity_int')],
            'vis.entity_id = ' . $entityColumn . ' AND vis.store_id = 0'
            . $connection->quoteInto(' AND vis.attribute_id = ?', (int)$attribute->getAttributeId()),
            []
        )->where('vis.value IS NULL OR vis.value <> ?', Visibility::VISIBILITY_NOT_VISIBLE);
    }

    /**
     * Store-0 rows of the attribute with a value.
     *
     * @param Select $select
     * @param AttributeDescriptor $descriptor
     * @return void
     */
    private function filterFilled(Select $select, AttributeDescriptor $descriptor): void
    {
        $select->where('v.attribute_id = ?', $descriptor->getAttributeId())
            ->where('v.store_id = ?', 0)
            ->where('v.value IS NOT NULL');
        // On int tables `value <> ''` would also drop boolean 0.
        if ($descriptor->getInput() === AttributeDescriptor::INPUT_MULTISELECT) {
            $select->where("v.value <> ''");
        }
    }

    /**
     * Super attribute table name, checked once; null without Magento_ConfigurableProduct.
     *
     * @return string|null
     */
    private function superAttributeTable(): ?string
    {
        if ($this->superAttributeTable === null) {
            $table = $this->resource->getTableName(self::SUPER_ATTRIBUTE_TABLE);
            $this->superAttributeTable = $this->connection()->isTableExists($table) ? $table : '';
        }

        return $this->superAttributeTable === '' ? null : $this->superAttributeTable;
    }

    /**
     * Default connection.
     *
     * @return AdapterInterface
     */
    private function connection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }
}

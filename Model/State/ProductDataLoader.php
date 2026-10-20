<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\State;

use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;

/**
 * Reads raw store-0 attribute values of products: one query per backend table for a whole batch.
 */
class ProductDataLoader
{
    public const STATIC_CODES = ['sku', 'attribute_set_id', 'type_id'];

    /** Key of the store-view values (code => distinct non-empty raw values); attribute codes start with a letter. */
    public const STORE_VIEW_VALUES = '_store_view_values';

    /**
     * @param ProductResource $productResource
     */
    public function __construct(
        private readonly ProductResource $productResource
    ) {
    }

    /**
     * Raw values of one product keyed by code (absent → null); null when the product does not exist.
     *
     * @param int $productId
     * @param string[] $codes
     * @param string[] $scopedCodes codes whose store-view values are collected too
     * @return array<string,mixed>|null
     */
    public function load(int $productId, array $codes, array $scopedCodes = []): ?array
    {
        return $this->loadMany([$productId], $codes, $scopedCodes)[$productId] ?? null;
    }

    /**
     * Raw values of many products, keyed by product id then code; missing products are absent.
     *
     * Rows carry STORE_VIEW_VALUES only when scoped codes are given.
     *
     * @param int[] $productIds
     * @param string[] $codes
     * @param string[] $scopedCodes codes whose store-view values are collected too
     * @return array<int, array<string,mixed>>
     */
    public function loadMany(array $productIds, array $codes, array $scopedCodes = []): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return [];
        }
        $codes = array_values(array_unique([...self::STATIC_CODES, ...$codes, ...$scopedCodes]));
        [$static, $tables] = $this->resolve($codes);

        $rows = $this->loadStatic($ids, $static);
        $blank = array_fill_keys($codes, null);
        $data = [];
        foreach ($ids as $id) {
            if (!isset($rows[$id])) {
                continue;
            }
            $data[$id] = array_replace($blank, array_intersect_key($rows[$id], $blank));
            if ($scopedCodes !== []) {
                $data[$id][self::STORE_VIEW_VALUES] = array_fill_keys($scopedCodes, []);
            }
        }
        if ($data === []) {
            return [];
        }

        $scopedCodes = array_flip($scopedCodes);
        foreach ($tables as $table => $attributes) {
            $scopedIds = array_keys(array_filter(
                $attributes,
                static fn (string $code): bool => isset($scopedCodes[$code])
            ));
            foreach ($this->loadValues($table, array_keys($data), array_keys($attributes), $scopedIds) as $row) {
                $id = (int)$row['entity_id'];
                $code = $attributes[(int)$row['attribute_id']] ?? null;
                if ($code === null || !isset($data[$id])) {
                    continue;
                }
                if ((int)$row['store_id'] === 0) {
                    $data[$id][$code] = $row['value'];
                    continue;
                }
                $value = trim((string)$row['value']);
                if (isset($scopedCodes[$code]) && $value !== ''
                    && !in_array($value, $data[$id][self::STORE_VIEW_VALUES][$code], true)
                ) {
                    $data[$id][self::STORE_VIEW_VALUES][$code][] = $value;
                }
            }
        }

        return $data;
    }

    /**
     * Split codes into entity-table columns and backend table => attribute id => code; unknown codes are dropped.
     *
     * @param string[] $codes
     * @return array{0: string[], 1: array<string, array<int,string>>}
     */
    private function resolve(array $codes): array
    {
        $static = [];
        $tables = [];
        foreach ($codes as $code) {
            if (in_array($code, self::STATIC_CODES, true)) {
                $static[] = $code;
                continue;
            }
            $attribute = $this->productResource->getAttribute($code);
            if (!$attribute instanceof AbstractAttribute) {
                continue;
            }
            if ($attribute->isStatic()) {
                $static[] = $code;
                continue;
            }
            $tables[(string)$attribute->getBackendTable()][(int)$attribute->getAttributeId()] = $code;
        }

        return [$static, $tables];
    }

    /**
     * Entity-table columns of the existing products.
     *
     * @param int[] $ids
     * @param string[] $columns
     * @return array<int, array<string,mixed>>
     */
    private function loadStatic(array $ids, array $columns): array
    {
        $connection = $this->productResource->getConnection();
        $select = $connection->select()
            ->from($this->productResource->getEntityTable(), ['entity_id', ...$columns])
            ->where('entity_id IN (?)', $ids);

        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $rows[(int)$row['entity_id']] = $row;
        }

        return $rows;
    }

    /**
     * Store-0 rows of the attributes, plus every store's rows of the scoped ones.
     *
     * @param string $table
     * @param int[] $ids
     * @param int[] $attributeIds
     * @param int[] $scopedIds
     * @return array<int, array<string,mixed>>
     */
    private function loadValues(string $table, array $ids, array $attributeIds, array $scopedIds): array
    {
        $connection = $this->productResource->getConnection();
        $select = $connection->select()
            ->from($table, ['entity_id', 'attribute_id', 'store_id', 'value'])
            ->where('entity_id IN (?)', $ids)
            ->where('attribute_id IN (?)', $attributeIds);
        if ($scopedIds === []) {
            $select->where('store_id = ?', 0);
        } else {
            $select->where(
                $connection->quoteInto('store_id = ?', 0)
                . $connection->quoteInto(' OR attribute_id IN (?)', $scopedIds)
            );
        }

        return $connection->fetchAll($select);
    }
}

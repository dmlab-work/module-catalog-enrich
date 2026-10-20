<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Attempt ledger resource model.
 */
class Attempt extends AbstractDb
{
    public const TABLE = 'dmlab_catalog_enrich_attempt';
    private const KEY_COLUMNS = ['attempt_id', 'product_id', 'attribute_code'];
    private const DELETE_CHUNK = 1000;

    /**
     * @inheritdoc
     */
    protected function _construct()
    {
        $this->_init(self::TABLE, 'attempt_id');
    }

    /**
     * Ledger rows for the products × codes, in one query.
     *
     * @param int[] $productIds
     * @param string[] $codes
     * @return array<int, array<string, array>> product id => attribute code => row
     */
    public function loadForProducts(array $productIds, array $codes): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        $codes = array_values(array_unique(array_map('strval', $codes)));
        if ($productIds === [] || $codes === []) {
            return [];
        }
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable())
            ->where('product_id IN (?)', $productIds)
            ->where('attribute_code IN (?)', $codes);

        $result = [];
        foreach ($connection->fetchAll($select) as $row) {
            $result[(int)$row['product_id']][(string)$row['attribute_code']] = $row;
        }

        return $result;
    }

    /**
     * Outcome counts per attribute code, in one grouped query.
     *
     * @param string[] $codes
     * @return array<string, array<string, int>> attribute code => outcome => rows
     */
    public function countOutcomes(array $codes): array
    {
        $codes = array_values(array_unique(array_map('strval', $codes)));
        if ($codes === []) {
            return [];
        }
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), ['attribute_code', 'outcome', 'total' => 'COUNT(*)'])
            ->where('attribute_code IN (?)', $codes)
            ->group(['attribute_code', 'outcome']);

        $result = [];
        foreach ($connection->fetchAll($select) as $row) {
            $result[(string)$row['attribute_code']][(string)$row['outcome']] = (int)$row['total'];
        }

        return $result;
    }

    /**
     * Delete ledger rows in chunks, so their pairs are asked again on the next run.
     *
     * @param int[] $attemptIds
     * @return int deleted rows
     */
    public function deleteByIds(array $attemptIds): int
    {
        $deleted = 0;
        $ids = array_values(array_unique(array_map('intval', $attemptIds)));
        foreach (array_chunk($ids, self::DELETE_CHUNK) as $chunk) {
            $deleted += (int)$this->getConnection()->delete($this->getMainTable(), ['attempt_id IN (?)' => $chunk]);
        }

        return $deleted;
    }

    /**
     * Insert or update the row of its product × attribute.
     *
     * @param array<string,mixed> $row must contain product_id and attribute_code
     * @return void
     */
    public function upsert(array $row): void
    {
        if (!isset($row['product_id'], $row['attribute_code'])) {
            throw new \InvalidArgumentException('An attempt row needs product_id and attribute_code.');
        }
        unset($row['attempt_id']);
        $update = array_values(array_diff(array_keys($row), self::KEY_COLUMNS));
        $this->getConnection()->insertOnDuplicate($this->getMainTable(), $row, $update);
    }
}

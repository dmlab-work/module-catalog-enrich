<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\ResourceModel;

use DmLab\CatalogEnrich\Model\Suggestion as SuggestionModel;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Suggestion resource model.
 */
class Suggestion extends AbstractDb
{
    public const TABLE = 'dmlab_catalog_enrich_suggestion';

    /**
     * @inheritdoc
     */
    protected function _construct()
    {
        $this->_init(self::TABLE, 'suggestion_id');
    }

    /**
     * Rows of the pair, newest first.
     *
     * @param int $productId
     * @param string $code
     * @return array<int, array<string,mixed>>
     */
    public function findFor(int $productId, string $code): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable())
            ->where('product_id = ?', $productId)
            ->where('attribute_code = ?', $code)
            ->order(['created_at DESC', 'suggestion_id DESC']);

        return $connection->fetchAll($select);
    }

    /**
     * Rows of the products' attributes in one query: product id => code => rows, newest first.
     *
     * @param int[] $productIds
     * @param string[] $codes
     * @return array<int, array<string, array<int, array<string,mixed>>>>
     */
    public function findForProducts(array $productIds, array $codes): array
    {
        if ($productIds === [] || $codes === []) {
            return [];
        }
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable())
            ->where('product_id IN (?)', array_map('intval', $productIds))
            ->where('attribute_code IN (?)', array_values(array_map('strval', $codes)))
            ->order(['created_at DESC', 'suggestion_id DESC']);

        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $rows[(int)$row['product_id']][(string)$row['attribute_code']][] = $row;
        }

        return $rows;
    }

    /**
     * Delete pending/shadow rows of the pair whose fingerprint differs.
     *
     * @param int $productId
     * @param string $code
     * @param string $keepFingerprint
     * @return int deleted rows
     */
    public function deleteStale(int $productId, string $code, string $keepFingerprint): int
    {
        return (int)$this->getConnection()->delete($this->getMainTable(), [
            'product_id = ?' => $productId,
            'attribute_code = ?' => $code,
            'status IN (?)' => [SuggestionModel::STATUS_PENDING, SuggestionModel::STATUS_SHADOW],
            'fingerprint <> ?' => $keepFingerprint,
        ]);
    }

    /**
     * Delete every pending/shadow row of the pair.
     *
     * @param int $productId
     * @param string $code
     * @return int deleted rows
     */
    public function deleteOpen(int $productId, string $code): int
    {
        return (int)$this->getConnection()->delete($this->getMainTable(), [
            'product_id = ?' => $productId,
            'attribute_code = ?' => $code,
            'status IN (?)' => [SuggestionModel::STATUS_PENDING, SuggestionModel::STATUS_SHADOW],
        ]);
    }

    /**
     * Delete pending/shadow rows of one attribute on the given products.
     *
     * @param string $code
     * @param int[] $productIds
     * @return int deleted rows
     */
    public function deleteOpenForProducts(string $code, array $productIds): int
    {
        if ($productIds === []) {
            return 0;
        }

        return (int)$this->getConnection()->delete($this->getMainTable(), [
            'product_id IN (?)' => array_map('intval', $productIds),
            'attribute_code = ?' => $code,
            'status IN (?)' => [SuggestionModel::STATUS_PENDING, SuggestionModel::STATUS_SHADOW],
        ]);
    }

    /**
     * Current status of the row, locked until the transaction ends; null when the row is gone.
     *
     * @param int $suggestionId
     * @return string|null
     */
    public function lockStatus(int $suggestionId): ?string
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), ['status'])
            ->where('suggestion_id = ?', $suggestionId)
            ->forUpdate();
        $status = $connection->fetchOne($select);

        return $status === false || $status === null ? null : (string)$status;
    }

    /**
     * Write value, probability and status only while the stored status is still `$expected`.
     *
     * @param SuggestionModel $suggestion
     * @param string $expected
     * @return bool false when another process changed the row first
     */
    public function saveIfStatus(SuggestionModel $suggestion, string $expected): bool
    {
        return $this->getConnection()->update(
            $this->getMainTable(),
            [
                'value' => (string)$suggestion->getData('value'),
                'probability' => $suggestion->getData('probability'),
                'status' => $suggestion->getStatus(),
            ],
            ['suggestion_id = ?' => (int)$suggestion->getId(), 'status = ?' => $expected]
        ) > 0;
    }

    /**
     * Move the row from `$from` to `$to` only while it is still in `$from`.
     *
     * @param int $suggestionId
     * @param string $from
     * @param string $to
     * @return bool false when another process changed or deleted the row first
     */
    public function transitionStatus(int $suggestionId, string $from, string $to): bool
    {
        return $this->getConnection()->update(
            $this->getMainTable(),
            ['status' => $to],
            ['suggestion_id = ?' => $suggestionId, 'status = ?' => $from]
        ) > 0;
    }

    /**
     * Applied rows of one attribute that filled an empty value, on products of the sets.
     *
     * Mirrors CatalogQuery::countFilled: with $zeroIsEmpty an applied 0 left the product empty, so it is not counted.
     *
     * @param string $code
     * @param int[] $setIds empty selects nothing
     * @param bool $zeroIsEmpty value 0 counts as empty
     * @return int
     */
    public function countAppliedToEmpty(string $code, array $setIds, bool $zeroIsEmpty = false): int
    {
        if ($setIds === []) {
            return 0;
        }
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from(['s' => $this->getMainTable()], [new Expression('COUNT(*)')])
            ->join(
                ['e' => $this->getTable('catalog_product_entity')],
                'e.entity_id = s.product_id',
                []
            )
            ->where('s.attribute_code = ?', $code)
            ->where('s.status = ?', SuggestionModel::STATUS_APPLIED)
            ->where($zeroIsEmpty ? "s.previous_value IS NULL OR s.previous_value = '0'" : 's.previous_value IS NULL')
            ->where('e.attribute_set_id IN (?)', array_map('intval', $setIds));
        if ($zeroIsEmpty) {
            $select->where("s.value <> '0'");
        }

        return (int)$connection->fetchOne($select);
    }

    /**
     * Row count per status for one attribute.
     *
     * @param string $code
     * @return array<string,int>
     */
    public function countByStatus(string $code): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), ['status', 'cnt' => new Expression('COUNT(*)')])
            ->where('attribute_code = ?', $code)
            ->group('status');

        return array_map('intval', $connection->fetchPairs($select));
    }
}

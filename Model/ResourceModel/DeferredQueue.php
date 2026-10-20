<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * Product ids of queued enrichment that a stopped run could not finish.
 */
class DeferredQueue
{
    public const TABLE = 'dmlab_catalog_enrich_deferred';

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Remember product ids; ids already deferred are kept once.
     *
     * @param int[] $productIds
     * @return void
     */
    public function add(array $productIds): void
    {
        $rows = [];
        foreach (array_unique(array_map('intval', $productIds)) as $productId) {
            if ($productId > 0) {
                $rows[] = ['product_id' => $productId];
            }
        }
        if ($rows === []) {
            return;
        }
        $this->resource->getConnection()->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE),
            $rows,
            ['product_id']
        );
    }

    /**
     * Remove and return up to `$limit` deferred ids, oldest first.
     *
     * @param int $limit
     * @return int[]
     */
    public function take(int $limit): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $ids = array_map('intval', $connection->fetchCol(
            $connection->select()->from($table, ['product_id'])->order(['deferred_at ASC', 'product_id ASC'])
                ->limit($limit)
        ));
        if ($ids !== []) {
            $connection->delete($table, ['product_id IN (?)' => $ids]);
        }

        return $ids;
    }
}

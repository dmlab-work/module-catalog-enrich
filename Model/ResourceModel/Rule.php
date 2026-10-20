<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Rule resource model.
 */
class Rule extends AbstractDb
{
    public const TABLE = 'dmlab_catalog_enrich_rule';

    /**
     * @inheritdoc
     */
    protected function _construct()
    {
        $this->_init(self::TABLE, 'rule_id');
    }
}

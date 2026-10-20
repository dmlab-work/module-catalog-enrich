<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\ResourceModel\Rule;

use DmLab\CatalogEnrich\Model\ResourceModel\Rule as RuleResource;
use DmLab\CatalogEnrich\Model\Rule;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Rule collection.
 */
class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = 'rule_id';

    /**
     * @inheritdoc
     */
    protected function _construct()
    {
        $this->_init(Rule::class, RuleResource::class);
    }
}

<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\ResourceModel\Attempt;

use DmLab\CatalogEnrich\Model\Attempt;
use DmLab\CatalogEnrich\Model\ResourceModel\Attempt as AttemptResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Attempt collection.
 */
class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = 'attempt_id';

    /**
     * @inheritdoc
     */
    protected function _construct()
    {
        $this->_init(Attempt::class, AttemptResource::class);
    }
}

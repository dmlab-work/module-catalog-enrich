<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\ResourceModel\Suggestion;

use DmLab\CatalogEnrich\Model\ResourceModel\Suggestion as SuggestionResource;
use DmLab\CatalogEnrich\Model\Suggestion;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Suggestion collection.
 */
class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = 'suggestion_id';

    /**
     * @inheritdoc
     */
    protected function _construct()
    {
        $this->_init(Suggestion::class, SuggestionResource::class);
    }
}

<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\ResourceModel\Rule\Grid;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

/**
 * Rules grid collection; `attribute_label` is the attribute code, rendered as "Label (code)" by its column.
 */
class Collection extends SearchResult
{
    public const LABEL_FIELD = 'attribute_label';

    /**
     * @inheritdoc
     */
    protected function _initSelect()
    {
        parent::_initSelect();
        // A separate field: the status, outcome and action columns still read the raw attribute_code.
        $this->getSelect()->columns([self::LABEL_FIELD => 'main_table.attribute_code']);
        $this->addFilterToMap(self::LABEL_FIELD, 'main_table.attribute_code');

        return $this;
    }
}

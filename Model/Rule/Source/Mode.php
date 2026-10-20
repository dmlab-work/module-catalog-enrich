<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Rule\Source;

use DmLab\CatalogEnrich\Model\Rule;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Rule modes.
 */
class Mode implements OptionSourceInterface
{
    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => Rule::MODE_OFF, 'label' => __('Off')],
            ['value' => Rule::MODE_SHADOW, 'label' => __('Shadow (record only)')],
            ['value' => Rule::MODE_REVIEW, 'label' => __('Review (suggest)')],
            ['value' => Rule::MODE_AUTO, 'label' => __('Auto (apply)')],
        ];
    }
}

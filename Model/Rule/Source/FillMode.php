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
 * Rule fill modes.
 */
class FillMode implements OptionSourceInterface
{
    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => Rule::FILL_EMPTY_ONLY, 'label' => __('Empty only')],
            ['value' => Rule::FILL_ADD_MISSING, 'label' => __('Add missing options (multiselect)')],
        ];
    }
}

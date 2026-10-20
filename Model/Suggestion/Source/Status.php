<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Suggestion\Source;

use DmLab\CatalogEnrich\Model\Suggestion;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Suggestion statuses.
 */
class Status implements OptionSourceInterface
{
    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => Suggestion::STATUS_SHADOW, 'label' => __('Shadow')],
            ['value' => Suggestion::STATUS_PENDING, 'label' => __('Pending')],
            ['value' => Suggestion::STATUS_APPLIED, 'label' => __('Applied')],
            ['value' => Suggestion::STATUS_REJECTED, 'label' => __('Rejected')],
            ['value' => Suggestion::STATUS_REVERTED, 'label' => __('Reverted')],
        ];
    }
}

<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Block\Adminhtml\Rule\Edit;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

/**
 * "Back" to the rules grid.
 */
class BackButton extends GenericButton implements ButtonProviderInterface
{
    /**
     * @inheritdoc
     */
    public function getButtonData(): array
    {
        return [
            'label' => __('Back'),
            'on_click' => sprintf("location.href = '%s';", $this->getBackUrl()),
            'class' => 'back',
            'sort_order' => 10,
        ];
    }

    /**
     * Rules grid URL.
     *
     * @return string
     */
    public function getBackUrl(): string
    {
        return $this->getUrl('*/*/');
    }
}

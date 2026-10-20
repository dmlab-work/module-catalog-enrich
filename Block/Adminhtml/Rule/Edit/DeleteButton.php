<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Block\Adminhtml\Rule\Edit;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Escaper;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

/**
 * "Delete Rule", shown only when editing an existing rule.
 */
class DeleteButton extends GenericButton implements ButtonProviderInterface
{
    /**
     * @param UrlInterface $urlBuilder
     * @param RequestInterface $request
     * @param Escaper $escaper
     */
    public function __construct(
        UrlInterface $urlBuilder,
        RequestInterface $request,
        private readonly Escaper $escaper
    ) {
        parent::__construct($urlBuilder, $request);
    }

    /**
     * @inheritdoc
     */
    public function getButtonData(): array
    {
        if ($this->getRuleId() === null) {
            return [];
        }
        $message = $this->escaper->escapeJs(
            $this->escaper->escapeHtml(__('Are you sure you want to delete this rule?'))
        );

        return [
            'label' => __('Delete Rule'),
            'class' => 'delete',
            'on_click' => sprintf("deleteConfirm('%s', '%s', {\"data\": {}})", $message, $this->getDeleteUrl()),
            'sort_order' => 20,
        ];
    }

    /**
     * POST target of the delete confirmation.
     *
     * @return string
     */
    public function getDeleteUrl(): string
    {
        return $this->getUrl('*/*/delete', ['id' => $this->getRuleId()]);
    }
}

<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Edit / Delete row actions of the rules grid.
 */
class RuleActions extends Column
{
    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param UrlInterface $urlBuilder
     * @param array $components
     * @param array $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @inheritdoc
     */
    public function prepareDataSource(array $dataSource)
    {
        foreach ($dataSource['data']['items'] ?? [] as $i => $item) {
            if (!isset($item['rule_id'])) {
                continue;
            }
            $id = (int)$item['rule_id'];
            $dataSource['data']['items'][$i][$this->getData('name')] = [
                'edit' => [
                    'href' => $this->urlBuilder->getUrl('dmlab_catalog_enrich/rule/edit', ['id' => $id]),
                    'label' => __('Edit'),
                ],
                'delete' => [
                    'href' => $this->urlBuilder->getUrl('dmlab_catalog_enrich/rule/delete', ['id' => $id]),
                    'label' => __('Delete'),
                    'confirm' => [
                        'title' => __('Delete rule'),
                        'message' => __('Are you sure you want to delete this rule?'),
                    ],
                    'post' => true,
                ],
            ];
        }

        return $dataSource;
    }
}

<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Ui\Component\Listing\Column;

use Magento\Framework\Escaper;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Renders the row's value as an escaped link to the product edit page, opened in a new tab (html cell template).
 */
class ProductLink extends Column
{
    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param UrlInterface $urlBuilder
     * @param Escaper $escaper
     * @param array $components
     * @param array $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        private readonly Escaper $escaper,
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
        $field = (string)$this->getData('name');
        foreach ($dataSource['data']['items'] ?? [] as $i => $item) {
            $text = $this->escaper->escapeHtml((string)($item[$field] ?? ''));
            $productId = (int)($item['product_id'] ?? 0);
            $dataSource['data']['items'][$i][$field] = $productId > 0 && $text !== ''
                ? sprintf(
                    '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                    $this->escaper->escapeUrl(
                        $this->urlBuilder->getUrl('catalog/product/edit', ['id' => $productId])
                    ),
                    $text
                )
                : $text;
        }

        return $dataSource;
    }
}

<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Ui\Component\Listing\Column;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Rules grid: the rule's attribute as "Label (code)"; the bare code when the attribute has no label or is gone.
 */
class AttributeLabel extends Column
{
    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param EavConfig $eavConfig
     * @param array $components
     * @param array $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly EavConfig $eavConfig,
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
            $code = (string)($item['attribute_code'] ?? '');
            $label = $code === '' ? '' : $this->label($code);
            $dataSource['data']['items'][$i][$field] = $label === '' ? $code : sprintf('%s (%s)', $label, $code);
        }

        return $dataSource;
    }

    /**
     * Admin label of a product attribute, empty when there is none.
     *
     * @param string $code
     * @return string
     */
    private function label(string $code): string
    {
        try {
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $code);
        } catch (LocalizedException $e) {
            return '';
        }

        return $attribute && $attribute->getAttributeId() ? trim((string)$attribute->getDefaultFrontendLabel()) : '';
    }
}

<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Config\Source;

use DmLab\CatalogEnrich\Model\State\ProductStateBuilder;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Labelled text/textarea product attributes holding product content, for the product state.
 */
class TextAttributes implements OptionSourceInterface
{
    /** Identifiers, URLs, layout XML and image labels: not product content. */
    private const EXCLUDED_CODES = [
        'sku', 'url_key', 'url_path', 'custom_layout_update', 'image_label', 'small_image_label', 'thumbnail_label',
    ];

    /**
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('frontend_input', ['in' => ['text', 'textarea']]);
        $collection->addFieldToFilter('backend_type', ['in' => ['varchar', 'text']]);
        $collection->addFieldToFilter('frontend_label', ['neq' => '']);
        $collection->addFieldToFilter(
            'attribute_code',
            ['nin' => [...ProductStateBuilder::BASE_CODES, ...self::EXCLUDED_CODES]]
        );
        $collection->setOrder('frontend_label', 'ASC');

        $options = [];
        foreach ($collection->getItems() as $attribute) {
            $code = (string)$attribute->getAttributeCode();
            $label = trim((string)$attribute->getDefaultFrontendLabel());
            $options[] = ['value' => $code, 'label' => sprintf('%s (%s)', $label !== '' ? $label : $code, $code)];
        }

        return $options;
    }
}

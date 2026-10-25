<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\ResourceModel;

use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;

/**
 * Removes the NULL store-0 row a mass attribute update writes for a null value, so "no value" stays no row.
 */
class EmptyValue
{
    /**
     * @param ProductResource $productResource
     */
    public function __construct(
        private readonly ProductResource $productResource
    ) {
    }

    /**
     * Delete the product's NULL store-0 value of the attribute; a non-NULL value is kept.
     *
     * @param int $productId
     * @param string $attributeCode
     * @return void
     */
    public function delete(int $productId, string $attributeCode): void
    {
        $attribute = $this->productResource->getAttribute($attributeCode);
        if (!$attribute instanceof AbstractAttribute || $attribute->isStatic()) {
            return;
        }
        $this->productResource->getConnection()->delete((string)$attribute->getBackendTable(), [
            'entity_id = ?' => $productId,
            'attribute_id = ?' => (int)$attribute->getAttributeId(),
            'store_id = ?' => 0,
            'value IS NULL',
        ]);
    }
}

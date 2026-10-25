<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\ResourceModel;

use DmLab\CatalogEnrich\Model\ResourceModel\EmptyValue;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class EmptyValueTest extends TestCase
{
    /**
     * @var AdapterInterface&MockObject
     */
    private AdapterInterface $adapter;

    private function emptyValue(?AbstractAttribute $attribute): EmptyValue
    {
        $this->adapter = $this->createMock(AdapterInterface::class);
        $productResource = $this->createStub(ProductResource::class);
        $productResource->method('getAttribute')->willReturnMap([['sleeve', $attribute ?? false]]);
        $productResource->method('getConnection')->willReturn($this->adapter);

        return new EmptyValue($productResource);
    }

    private function attribute(bool $static = false): AbstractAttribute
    {
        $attribute = $this->createStub(AbstractAttribute::class);
        $attribute->method('isStatic')->willReturn($static);
        $attribute->method('getBackendTable')->willReturn('pfx_catalog_product_entity_text');
        $attribute->method('getAttributeId')->willReturn('141');

        return $attribute;
    }

    public function testDeletesOnlyTheNullStoreZeroRow(): void
    {
        $emptyValue = $this->emptyValue($this->attribute());
        $this->adapter->expects(self::once())->method('delete')->with('pfx_catalog_product_entity_text', [
            'entity_id = ?' => 94,
            'attribute_id = ?' => 141,
            'store_id = ?' => 0,
            'value IS NULL',
        ]);

        $emptyValue->delete(94, 'sleeve');
    }

    public function testUnknownAttributeDeletesNothing(): void
    {
        $emptyValue = $this->emptyValue(null);
        $this->adapter->expects(self::never())->method('delete');

        $emptyValue->delete(94, 'sleeve');
    }

    public function testStaticAttributeDeletesNothing(): void
    {
        $emptyValue = $this->emptyValue($this->attribute(true));
        $this->adapter->expects(self::never())->method('delete');

        $emptyValue->delete(94, 'sleeve');
    }
}

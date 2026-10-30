<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\Config\Source;

use DmLab\CatalogEnrich\Model\Config\Source\TextAttributes;
use DmLab\CatalogEnrich\Test\Unit\PartialStubTrait;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use PHPUnit\Framework\TestCase;

class TextAttributesTest extends TestCase
{
    use PartialStubTrait;

    private function attribute(string $code, string $label): Attribute
    {
        $attribute = $this->partialStub(Attribute::class, ['getAttributeCode', 'getDefaultFrontendLabel']);
        $attribute->method('getAttributeCode')->willReturn($code);
        $attribute->method('getDefaultFrontendLabel')->willReturn($label);

        return $attribute;
    }

    public function testOptionArrayKeepsLabelledContentAttributesOnly(): void
    {
        $filters = [];
        $collection = $this->createStub(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(
            function (string $field, array $condition) use (&$filters, $collection) {
                $filters[$field] = $condition;
                return $collection;
            }
        );
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('getItems')->willReturn([
            $this->attribute('material_text', 'Material'),
            $this->attribute('meta_keyword', ''),
        ]);
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $options = (new TextAttributes($factory))->toOptionArray();

        self::assertSame(['in' => ['text', 'textarea']], $filters['frontend_input']);
        self::assertSame(['in' => ['varchar', 'text']], $filters['backend_type']);
        self::assertSame(['neq' => ''], $filters['frontend_label']);
        self::assertSame(
            ['nin' => [
                'name', 'short_description', 'description', 'sku', 'url_key', 'url_path', 'custom_layout_update',
                'image_label', 'small_image_label', 'thumbnail_label',
            ]],
            $filters['attribute_code']
        );
        self::assertSame(
            [
                ['value' => 'material_text', 'label' => 'Material (material_text)'],
                ['value' => 'meta_keyword', 'label' => 'meta_keyword (meta_keyword)'],
            ],
            $options
        );
    }
}

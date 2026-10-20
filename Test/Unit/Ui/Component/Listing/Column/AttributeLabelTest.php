<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Ui\Component\Listing\Column;

use DmLab\CatalogEnrich\Ui\Component\Listing\Column\AttributeLabel;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use PHPUnit\Framework\TestCase;

class AttributeLabelTest extends TestCase
{
    private function attribute(?int $id, ?string $label): AbstractAttribute
    {
        $attribute = $this->createStub(AbstractAttribute::class);
        $attribute->method('getAttributeId')->willReturn($id);
        $attribute->method('getDefaultFrontendLabel')->willReturn($label);

        return $attribute;
    }

    public function testLabelAndCode(): void
    {
        $eavConfig = $this->createStub(EavConfig::class);
        $eavConfig->method('getAttribute')->willReturnCallback(
            fn (string $entity, string $code): AbstractAttribute => match ($code) {
                'color' => $this->attribute(93, ' Color '),
                'nolabel' => $this->attribute(94, ''),
                'gone' => $this->attribute(null, null),
                default => throw new LocalizedException(__('Invalid.')),
            }
        );
        $column = new AttributeLabel(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class),
            $eavConfig,
            [],
            ['name' => 'attribute_label']
        );

        $result = $column->prepareDataSource(['data' => ['items' => [
            ['attribute_code' => 'color'],
            ['attribute_code' => 'nolabel'],
            ['attribute_code' => 'gone'],
            ['attribute_code' => 'broken'],
        ]]]);

        self::assertSame(
            ['Color (color)', 'nolabel', 'gone', 'broken'],
            array_column($result['data']['items'], 'attribute_label')
        );
        // The raw code stays for the status, outcome and action columns.
        self::assertSame('color', $result['data']['items'][0]['attribute_code']);
        self::assertSame([], $column->prepareDataSource([])['data']['items'] ?? []);
    }
}

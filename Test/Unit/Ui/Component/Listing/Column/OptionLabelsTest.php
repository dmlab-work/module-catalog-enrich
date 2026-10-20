<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Ui\Component\Listing\Column;

use DmLab\CatalogEnrich\Exception\IneligibleAttributeException;
use DmLab\CatalogEnrich\Model\Attribute\AttributeCatalog;
use DmLab\CatalogEnrich\Model\Attribute\AttributeDescriptor;
use DmLab\CatalogEnrich\Model\ResourceModel\CatalogQuery;
use DmLab\CatalogEnrich\Ui\Component\Listing\Column\CurrentValue;
use DmLab\CatalogEnrich\Ui\Component\Listing\Column\OptionLabels;
use DmLab\CatalogEnrich\Ui\Component\Listing\Column\Probability;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OptionLabelsTest extends TestCase
{
    private function column(string $name): OptionLabels
    {
        return new OptionLabels(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class),
            $this->catalog(),
            [],
            ['name' => $name]
        );
    }

    private function catalog(): AttributeCatalog
    {
        $catalog = $this->createStub(AttributeCatalog::class);
        $catalog->method('get')->willReturnCallback(static fn (string $code): AttributeDescriptor => match ($code) {
            'color' => new AttributeDescriptor(1, 'color', 'Color', 'select', 't', [5 => 'Red', 6 => 'Blue']),
            'material' => new AttributeDescriptor(2, 'material', 'Material', 'multiselect', 't', [
                7 => 'Cotton',
                8 => 'Wool',
                9 => 'Silk',
            ]),
            'is_vegan' => new AttributeDescriptor(3, 'is_vegan', 'Vegan', 'boolean', 't'),
            default => throw new IneligibleAttributeException(__('Gone.')),
        });

        return $catalog;
    }

    public static function values(): array
    {
        return [
            'select' => ['color', '5', 'Red'],
            'select unknown id' => ['color', '99', '#99'],
            'multiselect' => ['material', '9,7', 'Silk, Cotton'],
            'multiselect partial unknown' => ['material', '7, 42', 'Cotton, #42'],
            'boolean yes' => ['is_vegan', '1', 'Yes'],
            'boolean no' => ['is_vegan', '0', 'No'],
            'empty' => ['color', '', '—'],
            'null' => ['color', null, '—'],
            'ineligible attribute' => ['gone', '3,4', '#3, #4'],
        ];
    }

    #[DataProvider('values')]
    public function testRendersLabels(string $code, ?string $raw, string $expected): void
    {
        $result = $this->column('value')->prepareDataSource(
            ['data' => ['items' => [['attribute_code' => $code, 'value' => $raw]]]]
        );

        self::assertSame($expected, $result['data']['items'][0]['value']);
    }

    public function testRendersOnlyItsOwnField(): void
    {
        $result = $this->column('previous_value')->prepareDataSource(['data' => ['items' => [
            ['attribute_code' => 'color', 'value' => '5', 'previous_value' => '6'],
        ]]]);

        self::assertSame('Blue', $result['data']['items'][0]['previous_value']);
        self::assertSame('5', $result['data']['items'][0]['value']);
    }

    public function testCurrentValueRendersTheLiveValuePerAttribute(): void
    {
        $query = $this->createMock(CatalogQuery::class);
        $query->expects(self::exactly(2))->method('fetchValuesByStore')->willReturnCallback(
            static fn (AttributeDescriptor $descriptor, array $ids): array => match ($descriptor->getCode()) {
                'color' => (static function () use ($ids): array {
                    self::assertSame([10, 11, 14], $ids);
                    return [10 => [0 => '6', 1 => '5'], 14 => [1 => '5', 2 => '6', 3 => '5']];
                })(),
                'material' => [12 => [0 => '8,9'], 15 => [2 => '9,7', 3 => '7']],
            }
        );
        $column = new CurrentValue(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class),
            $this->catalog(),
            $query,
            [],
            ['name' => 'current_value']
        );

        $result = $column->prepareDataSource(['data' => ['items' => [
            ['attribute_code' => 'color', 'product_id' => '10', 'previous_value' => null],
            ['attribute_code' => 'color', 'product_id' => '11'],
            ['attribute_code' => 'material', 'product_id' => '12'],
            ['attribute_code' => 'gone', 'product_id' => '13'],
            ['attribute_code' => 'color', 'product_id' => '14'],
            ['attribute_code' => 'material', 'product_id' => '15'],
        ]]]);

        self::assertSame(
            ['Blue', '—', 'Wool, Silk', '—', 'Red, Blue (store view)', 'Silk, Cotton (store view)'],
            array_column($result['data']['items'], 'current_value')
        );
    }

    public function testProbabilityHasTwoDecimals(): void
    {
        $column = new Probability(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class),
            [],
            ['name' => 'probability']
        );

        $result = $column->prepareDataSource(['data' => ['items' => [
            ['probability' => '0.9134'],
            ['probability' => null],
        ]]]);

        self::assertSame('0.91', $result['data']['items'][0]['probability']);
        self::assertNull($result['data']['items'][1]['probability']);
    }
}

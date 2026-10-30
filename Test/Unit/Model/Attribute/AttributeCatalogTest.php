<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\Attribute;

use DmLab\CatalogEnrich\Exception\IneligibleAttributeException;
use DmLab\CatalogEnrich\Model\Attribute\AttributeCatalog;
use DmLab\CatalogEnrich\Model\Attribute\AttributeDescriptor;
use DmLab\CatalogEnrich\Test\Unit\PartialStubTrait;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\Backend\AbstractBackend;
use Magento\Eav\Model\Entity\Attribute\Source\Boolean;
use Magento\Eav\Model\Entity\Attribute\Source\Table;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AttributeCatalogTest extends TestCase
{
    use PartialStubTrait;

    /**
     * @param array<int|string, string> $options store-0 option id => label
     */
    private function attribute(
        string $code,
        string $input,
        array $options = [],
        ?string $sourceModel = null,
        int $id = 10,
        string $label = 'Some Label',
        bool $mock = false
    ): Attribute {
        $methods = [
            'getAttributeId', 'getAttributeCode', 'getFrontendInput', 'getSourceModel',
            'getDefaultFrontendLabel', 'getBackend', 'getSource',
        ];
        $attribute = $mock
            ? $this->getMockBuilder(Attribute::class)->disableOriginalConstructor()->onlyMethods($methods)->getMock()
            : $this->partialStub(Attribute::class, $methods);
        $attribute->method('getAttributeId')->willReturn($id);
        $attribute->method('getAttributeCode')->willReturn($code);
        $attribute->method('getFrontendInput')->willReturn($input);
        $attribute->method('getSourceModel')->willReturn($sourceModel);
        $attribute->method('getDefaultFrontendLabel')->willReturn($label);

        $backend = $this->createStub(AbstractBackend::class);
        $table = 'catalog_product_entity_' . ($input === 'multiselect' ? 'varchar' : 'int');
        $backend->method('getTable')->willReturn($table);
        $attribute->method('getBackend')->willReturn($backend);

        $source = $this->createStub(Table::class);
        $rows = [];
        foreach ($options as $value => $optionLabel) {
            $rows[] = ['value' => (string)$value, 'label' => $optionLabel];
        }
        $source->method('getAllOptions')->willReturnCallback(
            static function (bool $withEmpty = true, bool $defaultValues = false) use ($rows, $attribute): array {
                // Labels must be read for store 0 as admin (default) values.
                return $withEmpty === false && $defaultValues === true && $attribute->getData('store_id') === 0
                    ? $rows
                    : [['value' => '999', 'label' => 'WRONG STORE']];
            }
        );
        $attribute->method('getSource')->willReturn($source);

        return $attribute;
    }

    /**
     * @param array<string, Attribute> $byCode
     * @param Attribute[] $collectionItems
     */
    private function catalog(
        array $byCode = [],
        array $collectionItems = [],
        ?EavConfig $eavConfig = null
    ): AttributeCatalog {
        if ($eavConfig === null) {
            $eavConfig = $this->createStub(EavConfig::class);
            $eavConfig->method('getAttribute')->willReturnCallback(
                function (string $entityType, string $code) use ($byCode) {
                    self::assertSame('catalog_product', $entityType);
                    return $byCode[$code] ?? $this->attribute($code, '', [], null, 0);
                }
            );
        }
        $collection = $this->createStub(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('getItems')->willReturn($collectionItems);
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return new AttributeCatalog($eavConfig, $factory);
    }

    public function testSelectDescriptor(): void
    {
        $catalog = $this->catalog([
            'number_of_seats' => $this->attribute(
                'number_of_seats',
                'select',
                [12 => '2', 10 => '4', 11 => '6'],
                Table::class,
                77,
                ' Number of Seats '
            ),
        ]);

        $descriptor = $catalog->get('number_of_seats');

        self::assertSame(77, $descriptor->getAttributeId());
        self::assertSame('number_of_seats', $descriptor->getCode());
        self::assertSame('Number of Seats', $descriptor->getLabel());
        self::assertSame(AttributeDescriptor::INPUT_SELECT, $descriptor->getInput());
        self::assertSame('catalog_product_entity_int', $descriptor->getBackendTable());
        self::assertSame([12 => '2', 10 => '4', 11 => '6'], $descriptor->getOptions());
    }

    public function testSharedAttributeKeepsItsStoreAfterLoadingOptions(): void
    {
        $withStore = $this->attribute('color', 'select', [5 => 'Red'], Table::class, 77);
        $withStore->setData('store_id', 3);
        $withoutStore = $this->attribute('size', 'select', [6 => 'M'], Table::class, 78);

        $catalog = $this->catalog(['color' => $withStore, 'size' => $withoutStore]);

        self::assertSame([5 => 'Red'], $catalog->get('color')->getOptions());
        self::assertSame(3, $withStore->getData('store_id'));
        self::assertSame([6 => 'M'], $catalog->get('size')->getOptions());
        self::assertFalse($withoutStore->hasData('store_id'));
    }

    public function testSelectWithEmptySourceModelIsTableBacked(): void
    {
        $catalog = $this->catalog(['finish' => $this->attribute('finish', 'select', [1 => 'Matte'], null)]);

        self::assertSame([1 => 'Matte'], $catalog->get('finish')->getOptions());
    }

    public function testMultiselectDescriptorDedupsLabels(): void
    {
        $catalog = $this->catalog([
            'bedroom_suite_incl' => $this->attribute(
                'bedroom_suite_incl',
                'multiselect',
                [412 => 'Bedside Table', 413 => '', 414 => 'bedside table ', 415 => 'Dresser'],
                '\\' . Table::class
            ),
        ]);

        $descriptor = $catalog->get('bedroom_suite_incl');

        self::assertSame(AttributeDescriptor::INPUT_MULTISELECT, $descriptor->getInput());
        self::assertSame('catalog_product_entity_varchar', $descriptor->getBackendTable());
        self::assertSame([412 => 'Bedside Table', 415 => 'Dresser'], $descriptor->getOptions());
    }

    public function testBooleanDescriptorHasFixedOptions(): void
    {
        $attribute = $this->attribute('is_assembled', 'boolean', [], Boolean::class, 10, 'Is Assembled', true);
        self::assertInstanceOf(MockObject::class, $attribute);
        $attribute->expects(self::never())->method('getSource');

        $descriptor = $this->catalog(['is_assembled' => $attribute])->get('is_assembled');

        self::assertSame(AttributeDescriptor::INPUT_BOOLEAN, $descriptor->getInput());
        self::assertSame([1 => 'Yes', 0 => 'No'], $descriptor->getOptions());
    }

    public function testEmptyLabelFallsBackToCode(): void
    {
        $catalog = $this->catalog([
            'finish' => $this->attribute('finish', 'select', [1 => 'Matte'], null, 10, ''),
        ]);

        self::assertSame('finish', $catalog->get('finish')->getLabel());
    }

    public function testGetIsMemoized(): void
    {
        $eavConfig = $this->createMock(EavConfig::class);
        $eavConfig->expects(self::once())->method('getAttribute')
            ->willReturn($this->attribute('finish', 'select', [1 => 'Matte']));
        $catalog = $this->catalog([], [], $eavConfig);

        self::assertSame($catalog->get('finish'), $catalog->get('finish'));
    }

    public function testSelectWith254OptionsIsEligible(): void
    {
        $options = [];
        for ($i = 1; $i <= 254; $i++) {
            $options[$i] = 'Option ' . $i;
        }
        $catalog = $this->catalog(['big' => $this->attribute('big', 'select', $options)]);

        self::assertCount(254, $catalog->get('big')->getOptions());
    }

    /**
     * @return array<string, array{string, string, array<int, string>, ?string, string}>
     */
    public static function ineligibleProvider(): array
    {
        $tooMany = [];
        for ($i = 1; $i <= 255; $i++) {
            $tooMany[$i] = 'Option ' . $i;
        }

        return [
            'text input' => ['name', 'text', [], null, 'only select, multiselect and boolean'],
            'textarea input' => ['description', 'textarea', [], null, 'only select, multiselect and boolean'],
            'select with custom source' => ['country', 'select', [1 => 'US'], 'Vendor\\Country', 'unsupported source'],
            'multiselect custom source' => ['tags', 'multiselect', [1 => 'A'], 'Vendor\\Tags', 'unsupported source'],
            'boolean with non-boolean source' => ['flag', 'boolean', [], Table::class, 'unsupported source'],
            'select over 254 options' => ['big', 'select', $tooMany, Table::class, '255 options'],
            'select without options' => ['empty', 'select', [], null, 'no options'],
            'multiselect with only empty labels' => ['empty', 'multiselect', [1 => ' ', 2 => ''], null, 'no options'],
            'reserved label' => ['seats', 'select', [1 => '2', 2 => 'Not Stated'], null, 'reserved'],
            'reserved label padded' => ['incl', 'multiselect', [1 => ' NOT STATED '], null, 'reserved'],
        ];
    }

    /**
     * @param array<int, string> $options
     */
    #[DataProvider('ineligibleProvider')]
    public function testIneligible(string $code, string $input, array $options, ?string $source, string $reason): void
    {
        $catalog = $this->catalog([$code => $this->attribute($code, $input, $options, $source)]);

        $this->expectException(IneligibleAttributeException::class);
        $this->expectExceptionMessage($reason);
        $catalog->get($code);
    }

    public function testUnknownCode(): void
    {
        $this->expectException(IneligibleAttributeException::class);
        $this->expectExceptionMessage('Attribute "nope" does not exist.');
        $this->catalog()->get('nope');
    }

    public function testEavConfigFailureIsUnknown(): void
    {
        $eavConfig = $this->createStub(EavConfig::class);
        $eavConfig->method('getAttribute')->willThrowException(new LocalizedException(__('boom')));

        $this->expectException(IneligibleAttributeException::class);
        $this->expectExceptionMessage('does not exist');
        $this->catalog([], [], $eavConfig)->get('nope');
    }

    public function testGetEligibleSkipsIneligibleAndFeedsGet(): void
    {
        $eavConfig = $this->createMock(EavConfig::class);
        $eavConfig->expects(self::never())->method('getAttribute');
        $collection = $this->createMock(Collection::class);
        $collection->expects(self::once())->method('addFieldToFilter')
            ->with('frontend_input', ['in' => ['select', 'multiselect', 'boolean']])
            ->willReturnSelf();
        $collection->method('setOrder')->willReturnSelf();
        $collection->expects(self::once())->method('getItems')->willReturn([
            $this->attribute('color', 'select', [1 => 'Red']),
            $this->attribute('country', 'select', [1 => 'US'], 'Vendor\\Source\\Country'),
            $this->attribute('is_assembled', 'boolean', [], Boolean::class),
        ]);
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        $catalog = new AttributeCatalog($eavConfig, $factory);

        $eligible = $catalog->getEligible();

        self::assertSame(['color', 'is_assembled'], array_map(static fn ($d) => $d->getCode(), $eligible));
        self::assertSame($eligible, $catalog->getEligible());
        self::assertSame($eligible[0], $catalog->get('color'));
    }
}

<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\State;

use DmLab\CatalogEnrich\Model\Config;
use DmLab\CatalogEnrich\Model\State\ProductStateBuilder;
use DmLab\CatalogEnrich\Model\State\TextCleaner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProductStateBuilderTest extends TestCase
{
    /**
     * @param string[] $extraCodes
     */
    private function builder(array $extraCodes = []): ProductStateBuilder
    {
        $config = $this->createStub(Config::class);
        $config->method('getStateAttributes')->willReturn($extraCodes);

        return new ProductStateBuilder(new TextCleaner(), $config);
    }

    public function testFixedKeyOrderWithExtrasInConfigOrder(): void
    {
        $state = $this->builder(['material', 'care'])->build([
            'care' => 'Wash cold',
            'description' => '<p>Soft <b>cotton</b> tee</p>',
            'sku' => 'SKU-1',
            'material' => 'Cotton',
            'short_description' => 'A tee',
            'name' => 'Tee',
            'color' => '12',
        ]);

        self::assertSame(
            [
                'name' => 'Tee',
                'short_description' => 'A tee',
                'description' => 'Soft cotton tee',
                'material' => 'Cotton',
                'care' => 'Wash cold',
            ],
            $state
        );
    }

    public function testEmptyFieldsOmitted(): void
    {
        $state = $this->builder(['material'])->build([
            'name' => 'Tee',
            'short_description' => null,
            'description' => '<p>&nbsp;</p>',
            'material' => '   ',
        ]);

        self::assertSame(['name' => 'Tee'], $state);
    }

    public function testExtraDuplicatingBaseCodeIsNotRepeated(): void
    {
        $builder = $this->builder(['name', 'material']);

        self::assertSame(['name', 'short_description', 'description', 'material'], $builder->getCodes());
        self::assertSame(
            ['name' => 'Tee', 'material' => 'Wool'],
            $builder->build(['name' => 'Tee', 'material' => 'Wool'])
        );
    }

    public function testDescriptionCutAt6000MultibyteChars(): void
    {
        $long = '<p>' . str_repeat('щ', 7000) . '</p>';

        $state = $this->builder(['material'])->build([
            'name' => str_repeat('и', 7000),
            'description' => $long,
            'material' => str_repeat('ы', 7000),
        ]);

        self::assertSame(6000, mb_strlen($state['description'], 'UTF-8'));
        self::assertTrue(mb_check_encoding($state['description'], 'UTF-8'));
        self::assertSame(7000, mb_strlen($state['name'], 'UTF-8'));
        self::assertSame(7000, mb_strlen($state['material'], 'UTF-8'));
    }

    public function testCutDoesNotLeaveTrailingSpace(): void
    {
        $description = str_repeat('a', 5999) . ' bcd';

        $state = $this->builder()->build(['description' => $description]);

        self::assertSame(str_repeat('a', 5999), $state['description']);
    }

    /**
     * @return array<string, array{array<string, string>, bool}>
     */
    public static function usableProvider(): array
    {
        return [
            'name only' => [['name' => 'Tee'], true],
            'description only' => [['description' => 'Soft tee'], true],
            'short description only' => [['short_description' => 'A tee'], false],
            'extra only' => [['material' => 'Cotton'], false],
            'empty' => [[], false],
        ];
    }

    /**
     * @param array<string, string> $state
     */
    #[DataProvider('usableProvider')]
    public function testIsUsable(array $state, bool $expected): void
    {
        self::assertSame($expected, $this->builder()->isUsable($state));
    }
}

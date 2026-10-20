<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\Question;

use DmLab\CatalogEnrich\Model\Attribute\AttributeDescriptor;
use DmLab\CatalogEnrich\Model\Question\InstructionsBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InstructionsBuilderTest extends TestCase
{
    /**
     * @var InstructionsBuilder
     */
    private InstructionsBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new InstructionsBuilder();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function templateProvider(): array
    {
        return [
            'select' => ['select', 'What is the {attribute} of this product?'],
            'multiselect' => ['multiselect', 'Does "{option}" apply to the {attribute} of this product?'],
            'boolean' => ['boolean', 'Is "{attribute}" true for this product?'],
        ];
    }

    #[DataProvider('templateProvider')]
    public function testDefaultTextPerInput(string $input, string $expected): void
    {
        $descriptor = new AttributeDescriptor(1, 'code', 'Label', $input, 't', [1 => 'A']);

        self::assertSame($expected, $this->builder->defaultText($descriptor));
    }

    public function testBuildPlainStringReplacesPlaceholders(): void
    {
        self::assertSame(
            'Does "Bedside Table" apply to the bedroom suite includes of this product?',
            $this->builder->build(
                'Does "{option}" apply to the {attribute} of this product?',
                [],
                'Bedside Table',
                ' Bedroom Suite Includes '
            )
        );
    }

    public function testBuildWithoutLabelsKeepsPlaceholders(): void
    {
        self::assertSame('{attribute} / {option}', $this->builder->build('{attribute} / {option}', []));
    }

    public function testBuildWithRulesIsStructured(): void
    {
        self::assertSame(
            ['question' => 'What is the number of seats of this product?', 'rules' => ['Rule one', 'Rule two']],
            $this->builder->build(
                'What is the {attribute} of this product?',
                [3 => 'Rule one', 7 => 'Rule two'],
                null,
                'Number of Seats'
            )
        );
    }

    public function testParseRules(): void
    {
        self::assertSame(
            ['First rule', 'Second rule', 'Third'],
            $this->builder->parseRules("  First rule \r\n\n   \nSecond rule\rThird\n")
        );
        self::assertSame([], $this->builder->parseRules(null));
        self::assertSame([], $this->builder->parseRules(" \n \n"));
    }
}

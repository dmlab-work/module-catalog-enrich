<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model;

use DmLab\CatalogEnrich\Model\ResourceModel\Rule as RuleResource;
use DmLab\CatalogEnrich\Model\Rule;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\Db\Context as DbContext;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;

class RuleTest extends TestCase
{
    /**
     * @param array<string,mixed> $data
     */
    private function rule(array $data = []): Rule
    {
        return new Rule(
            $this->createStub(Context::class),
            $this->createStub(Registry::class),
            new RuleResource($this->createStub(DbContext::class)),
            null,
            $data
        );
    }

    public function testDefaults(): void
    {
        $rule = $this->rule();

        self::assertSame('', $rule->getAttributeCode());
        self::assertSame(Rule::MODE_SHADOW, $rule->getMode());
        self::assertTrue($rule->isActive());
        self::assertSame(0.9, $rule->getThreshold());
        self::assertNull($rule->getInstructions());
        self::assertSame([], $rule->getRules());
        self::assertSame([], $rule->getAttributeSetIds());
        self::assertSame(Rule::FILL_EMPTY_ONLY, $rule->getFillMode());
        self::assertFalse($rule->isTreatZeroAsEmpty());
    }

    /**
     * @return array<string, array{mixed, bool, bool}>
     */
    public static function emptyValues(): array
    {
        return [
            'null' => [null, false, true],
            'blank' => ['  ', false, true],
            'array' => [['1'], false, true],
            'zero kept' => ['0', false, false],
            'int zero kept' => [0, false, false],
            'zero as empty' => ['0', true, true],
            'int zero as empty' => [0, true, true],
            'one' => ['1', true, false],
            'option id' => ['10', true, false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('emptyValues')]
    public function testIsEmptyValue(mixed $value, bool $treatZero, bool $expected): void
    {
        $rule = $this->rule(['treat_zero_as_empty' => $treatZero ? '1' : '0']);

        self::assertSame($treatZero, $rule->isTreatZeroAsEmpty());
        self::assertSame($expected, $rule->isEmptyValue($value));
    }

    public function testAccessorsParseStoredValues(): void
    {
        $rule = $this->rule([
            'rule_id' => '3',
            'attribute_code' => 'color',
            'mode' => 'review',
            'threshold' => '0.8500',
            'instructions' => '  What colour is it?  ',
            'extra_rules' => "  First rule \r\n\n Second rule\n   \n",
            'attribute_set_ids' => '4, 9,x,,4',
            'fill_mode' => 'add_missing',
        ]);

        self::assertSame('3', $rule->getId());
        self::assertSame('color', $rule->getAttributeCode());
        self::assertTrue($rule->isActive());
        self::assertSame(0.85, $rule->getThreshold());
        self::assertSame('What colour is it?', $rule->getInstructions());
        self::assertSame(['First rule', 'Second rule'], $rule->getRules());
        self::assertSame([4, 9], $rule->getAttributeSetIds());
        self::assertSame(Rule::FILL_ADD_MISSING, $rule->getFillMode());
    }

    public function testBlankInstructionsAreNull(): void
    {
        self::assertNull($this->rule(['instructions' => "  \n "])->getInstructions());
    }
}

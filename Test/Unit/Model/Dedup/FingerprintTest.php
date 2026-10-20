<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\Dedup;

use DmLab\CatalogEnrich\Model\Attribute\AttributeDescriptor;
use DmLab\CatalogEnrich\Model\Dedup\CanonicalJson;
use DmLab\CatalogEnrich\Model\Dedup\Fingerprint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FingerprintTest extends TestCase
{
    private const STATE = [
        'name' => 'Oslo 7 Piece Dining Suite',
        'short_description' => 'Seven pieces',
        'description' => 'Solid oak table with six chairs',
        'material_notes' => 'Oak',
    ];
    private const OPTIONS = [12 => '2', 14 => '4', 16 => '6'];
    private const INSTRUCTIONS = 'What is the number of seats of this product?';
    private const RULES = ['A dining setting of N pieces is one table plus N-1 seats.'];
    private const MODEL = 'jev-1.13.0';

    /**
     * @var Fingerprint
     */
    private Fingerprint $fingerprint;

    protected function setUp(): void
    {
        $this->fingerprint = new Fingerprint(new CanonicalJson());
    }

    public function testIsSha256Hex(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->base());
    }

    public function testStateKeyOrderDoesNotChangeTheHash(): void
    {
        $state = array_reverse(self::STATE, true);

        self::assertSame($this->base(), $this->compute(['state' => $state]));
    }

    public function testOptionOrderDoesNotChangeTheHash(): void
    {
        $options = [16 => '6', 12 => '2', 14 => '4'];

        self::assertSame($this->base(), $this->compute(['options' => $options]));
    }

    public function testStructuredInstructionKeyOrderDoesNotChangeTheHash(): void
    {
        $a = ['question' => 'Q?', 'rules' => ['r1', 'r2']];
        $b = ['rules' => ['r1', 'r2'], 'question' => 'Q?'];

        self::assertSame($this->compute(['instructions' => $a]), $this->compute(['instructions' => $b]));
    }

    /**
     * @return array<string, array{0: array<string,mixed>}>
     */
    public static function changes(): array
    {
        return [
            'description text' => [['state' => ['description' => 'Solid oak table with four chairs'] + self::STATE]],
            'short description' => [['state' => ['short_description' => 'Five pieces'] + self::STATE]],
            'extra text attribute' => [['state' => ['material_notes' => 'Pine'] + self::STATE]],
            'extra text attribute added' => [['state' => self::STATE + ['finish' => 'Matte']]],
            'option added' => [['options' => self::OPTIONS + [18 => '8']]],
            'option label renamed' => [['options' => [12 => '2', 14 => 'Four', 16 => '6']]],
            'instructions' => [['instructions' => 'How many seats does this product have?']],
            'instructions structured' => [['instructions' => ['question' => self::INSTRUCTIONS, 'rules' => []]]],
            'extra rule added' => [['rules' => array_merge(self::RULES, ['Benches count as two seats.'])]],
            'extra rule edited' => [['rules' => ['A dining setting of N pieces is one table plus N seats.']]],
            'rule order' => [['rules' => ['b', 'a']], ['rules' => ['a', 'b']]],
            'model id' => [['model' => 'jev-1.14.0']],
        ];
    }

    /**
     * @param array<string,mixed> $override
     * @param array<string,mixed>|null $baseOverride
     */
    #[DataProvider('changes')]
    public function testEachInputChangesTheHash(array $override, ?array $baseOverride = null): void
    {
        $base = $baseOverride === null ? $this->base() : $this->compute($baseOverride);

        self::assertNotSame($base, $this->compute($override));
    }

    public function testQuestionVersionChangesTheHash(): void
    {
        $bumped = new class (new CanonicalJson()) extends Fingerprint {
            public const QUESTION_VERSION = 2;
        };

        self::assertSame(1, Fingerprint::QUESTION_VERSION);
        self::assertNotSame(
            $this->base(),
            $bumped->compute(
                self::STATE,
                $this->descriptor(self::OPTIONS),
                self::INSTRUCTIONS,
                self::RULES,
                self::MODEL
            )
        );
    }

    public function testBooleanOptionsAreFixed(): void
    {
        $a = new AttributeDescriptor(1, 'is_assembled', 'Is Assembled', 'boolean', 'catalog_product_entity_int');
        $b = new AttributeDescriptor(
            1,
            'is_assembled',
            'Is Assembled',
            'boolean',
            'catalog_product_entity_int',
            [5 => 'x']
        );

        self::assertSame(
            $this->fingerprint->compute(self::STATE, $a, 'Q', [], self::MODEL),
            $this->fingerprint->compute(self::STATE, $b, 'Q', [], self::MODEL)
        );
    }

    private function base(): string
    {
        return $this->compute([]);
    }

    /**
     * @param array<string,mixed> $override
     */
    private function compute(array $override): string
    {
        return $this->fingerprint->compute(
            $override['state'] ?? self::STATE,
            $this->descriptor($override['options'] ?? self::OPTIONS),
            $override['instructions'] ?? self::INSTRUCTIONS,
            $override['rules'] ?? self::RULES,
            $override['model'] ?? self::MODEL
        );
    }

    /**
     * @param array<int,string> $options
     */
    private function descriptor(array $options): AttributeDescriptor
    {
        return new AttributeDescriptor(
            7,
            'number_of_seats',
            'Number of Seats',
            'select',
            'catalog_product_entity_int',
            $options
        );
    }
}

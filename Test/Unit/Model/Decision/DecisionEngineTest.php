<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\Decision;

use DmLab\CatalogEnrich\Exception\MalformedAnswerException;
use DmLab\CatalogEnrich\Model\Attribute\AttributeDescriptor;
use DmLab\CatalogEnrich\Model\Decision\Decision;
use DmLab\CatalogEnrich\Model\Decision\DecisionEngine;
use DmLab\CatalogEnrich\Model\Question\InstructionsBuilder;
use DmLab\CatalogEnrich\Model\Question\QuestionBuilder;
use DmLab\CatalogEnrich\Model\Question\QuestionPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DecisionEngineTest extends TestCase
{
    private const T = 0.9;

    /**
     * @var DecisionEngine
     */
    private DecisionEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new DecisionEngine();
    }

    /**
     * @param array $forward [choice, p(choice)]
     * @param array $reversed [choice, p(choice)]
     * @param string $outcome
     * @param string|null $value
     * @param float|null $probability
     */
    #[DataProvider('selectCases')]
    public function testSelect(
        array $forward,
        array $reversed,
        string $outcome,
        ?string $value,
        ?float $probability
    ): void {
        $plan = $this->plan('select', [10 => '2', 11 => '4', 12 => '6']);
        $normalized = [
            'seats' => $this->choice(...$forward),
            'seats:rev' => $this->choice(...$reversed),
        ];

        $decision = $this->engine->decide($plan, $normalized, self::T, DecisionEngine::FILL_EMPTY_ONLY, null);

        $this->assertDecision($decision, $outcome, $value, $probability);
    }

    /**
     * @return array<string, array>
     */
    public static function selectCases(): array
    {
        return [
            'agree above t' => [['6', 0.96], ['6', 0.93], Decision::ACCEPTED, '12', 0.93],
            'agree exactly t' => [['4', 0.9], ['4', 0.95], Decision::ACCEPTED, '11', 0.9],
            'agree below t' => [['6', 0.96], ['6', 0.85], Decision::UNCERTAIN, null, null],
            'disagree' => [['6', 0.96], ['4', 0.97], Decision::UNCERTAIN, null, null],
            'both not stated' => [['not stated', 0.9], ['not stated', 0.8], Decision::NOT_STATED, null, null],
            'one not stated' => [['6', 0.96], ['not stated', 0.6], Decision::UNCERTAIN, null, null],
            'unknown label' => [['10', 0.99], ['10', 0.99], Decision::UNCERTAIN, null, null],
        ];
    }

    /**
     * @param array $forward
     * @param array $reversed
     * @param string $outcome
     * @param string|null $value
     * @param float|null $probability
     */
    #[DataProvider('booleanCases')]
    public function testBoolean(
        array $forward,
        array $reversed,
        string $outcome,
        ?string $value,
        ?float $probability
    ): void {
        $plan = $this->plan('boolean', []);
        $normalized = [
            'seats' => $this->choice(...$forward),
            'seats:rev' => $this->choice(...$reversed),
        ];

        $decision = $this->engine->decide($plan, $normalized, self::T, DecisionEngine::FILL_EMPTY_ONLY, null);

        $this->assertDecision($decision, $outcome, $value, $probability);
    }

    /**
     * @return array<string, array>
     */
    public static function booleanCases(): array
    {
        return [
            'both yes' => [['Yes', 0.97], ['Yes', 0.92], Decision::ACCEPTED, '1', 0.92],
            'both no' => [['No', 0.91], ['No', 0.99], Decision::ACCEPTED, '0', 0.91],
            'agree below t' => [['Yes', 0.97], ['Yes', 0.7], Decision::UNCERTAIN, null, null],
            'yes vs no' => [['Yes', 0.97], ['No', 0.95], Decision::UNCERTAIN, null, null],
            'one not stated' => [['No', 0.97], ['not stated', 0.6], Decision::UNCERTAIN, null, null],
            'both not stated' => [['not stated', 0.97], ['not stated', 0.95], Decision::NOT_STATED, null, null],
        ];
    }

    /**
     * @param array<int, float> $p option id => probability
     * @param string $fillMode
     * @param string|null $current
     * @param string $outcome
     * @param string|null $value
     * @param float|null $probability
     */
    #[DataProvider('multiselectCases')]
    public function testMultiselect(
        array $p,
        string $fillMode,
        ?string $current,
        string $outcome,
        ?string $value,
        ?float $probability
    ): void {
        $plan = $this->plan('multiselect', [412 => 'Bedside Table', 7 => 'Dresser', 300 => 'Mirror']);
        $normalized = [];
        foreach ($p as $optionId => $probabilityOfOption) {
            $normalized['seats:' . $optionId] = ['type' => 'noul', 'p' => $probabilityOfOption];
        }

        $decision = $this->engine->decide($plan, $normalized, self::T, $fillMode, $current);

        $this->assertDecision($decision, $outcome, $value, $probability);
    }

    /**
     * @return array<string, array>
     */
    public static function multiselectCases(): array
    {
        $empty = DecisionEngine::FILL_EMPTY_ONLY;
        $add = DecisionEngine::FILL_ADD_MISSING;

        return [
            'yes at t' => [[412 => 0.9, 7 => 0.05, 300 => 0.02], $empty, null, Decision::ACCEPTED, '412', 0.9],
            'two yes ascending, min p' => [
                [412 => 0.95, 7 => 0.97, 300 => 0.5], $empty, null, Decision::ACCEPTED, '7,412', 0.95,
            ],
            'just below t is uncertain' => [
                [412 => 0.89, 7 => 0.0, 300 => 0.0], $empty, null, Decision::UNCERTAIN, null, null,
            ],
            'just above 1-t is uncertain' => [
                [412 => 0.11, 7 => 0.0, 300 => 0.0], $empty, null, Decision::UNCERTAIN, null, null,
            ],
            'exactly 1-t is no' => [[412 => 0.1, 7 => 0.1, 300 => 0.0], $empty, null, Decision::NOT_STATED, null, null],
            'all no' => [[412 => 0.01, 7 => 0.02, 300 => 0.03], $empty, null, Decision::NOT_STATED, null, null],
            'empty_only ignores current' => [
                [412 => 0.95, 7 => 0.01, 300 => 0.01], $empty, '412', Decision::ACCEPTED, '412', 0.95,
            ],
            'add_missing drops present ids' => [
                [412 => 0.95, 7 => 0.92, 300 => 0.01], $add, '412, 5', Decision::ACCEPTED, '7', 0.92,
            ],
            'add_missing nothing left' => [
                [412 => 0.95, 7 => 0.01, 300 => 0.5], $add, '412', Decision::NOT_STATED, null, null,
            ],
            'add_missing with empty current' => [
                [412 => 0.95, 7 => 0.01, 300 => 0.01], $add, null, Decision::ACCEPTED, '412', 0.95,
            ],
        ];
    }

    public function testNumericOptionLabelsWithIntKeysAfterJsonRoundTrip(): void
    {
        $plan = $this->plan('select', [10 => '2', 11 => '4', 12 => '6']);
        $normalized = json_decode((string)json_encode([
            'seats' => ['type' => 'choice', 'choice' => '6', 'probabilities' => [2 => 0.01, 6 => 0.96]],
            'seats:rev' => ['type' => 'choice', 'choice' => '6', 'probabilities' => [6 => 0.94, 'not stated' => 0.0]],
        ]), true);

        $decision = $this->engine->decide($plan, $normalized, self::T, DecisionEngine::FILL_EMPTY_ONLY, null);

        $this->assertDecision($decision, Decision::ACCEPTED, '12', 0.94);
    }

    public function testChosenLabelMissingFromProbabilitiesIsUncertain(): void
    {
        $missing = ['type' => 'choice', 'choice' => '6', 'probabilities' => ['2' => 0.99]];

        $decision = $this->engine->decide(
            $this->plan('select', [10 => '2', 12 => '6']),
            ['seats' => $missing, 'seats:rev' => $missing],
            self::T,
            DecisionEngine::FILL_EMPTY_ONLY,
            null
        );

        self::assertSame(Decision::UNCERTAIN, $decision->getOutcome());
    }

    public function testMissingReversedAnswerIsMalformed(): void
    {
        $this->expectException(MalformedAnswerException::class);

        $this->engine->decide(
            $this->plan('select', [10 => '2']),
            ['seats' => $this->choice('2', 0.99)],
            self::T,
            DecisionEngine::FILL_EMPTY_ONLY,
            null
        );
    }

    public function testNoulAnswerForBooleanIsMalformed(): void
    {
        $this->expectException(MalformedAnswerException::class);

        $this->engine->decide(
            $this->plan('boolean', []),
            ['seats' => ['type' => 'noul', 'p' => 0.99], 'seats:rev' => ['type' => 'noul', 'p' => 0.99]],
            self::T,
            DecisionEngine::FILL_EMPTY_ONLY,
            null
        );
    }

    public function testChoiceAnswerForMultiselectIsMalformed(): void
    {
        $this->expectException(MalformedAnswerException::class);

        $this->engine->decide(
            $this->plan('multiselect', [1 => 'A']),
            ['seats:1' => $this->choice('A', 0.99)],
            self::T,
            DecisionEngine::FILL_EMPTY_ONLY,
            null
        );
    }

    public function testChoiceEqualToTheStoredValueAddsNothing(): void
    {
        $plan = $this->plan('boolean', []);
        $no = ['seats' => $this->choice('No', 0.97), 'seats:rev' => $this->choice('No', 0.95)];
        $yes = ['seats' => $this->choice('Yes', 0.97), 'seats:rev' => $this->choice('Yes', 0.95)];

        $same = $this->engine->decide($plan, $no, self::T, DecisionEngine::FILL_EMPTY_ONLY, ' 0 ');
        $changed = $this->engine->decide($plan, $yes, self::T, DecisionEngine::FILL_EMPTY_ONLY, '0');

        $this->assertDecision($same, Decision::NOT_STATED, null, null);
        $this->assertDecision($changed, Decision::ACCEPTED, '1', 0.95);
    }

    public function testDecisionRejectsUnknownOutcome(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Decision('error');
    }

    /**
     * @param string $input
     * @param array<int, string> $options
     * @return QuestionPlan
     */
    private function plan(string $input, array $options): QuestionPlan
    {
        return (new QuestionBuilder(new InstructionsBuilder()))
            ->build(new AttributeDescriptor(1, 'seats', 'Seats', $input, 't', $options), null, []);
    }

    /**
     * Choice answer where the picked option has $p and the rest share the remainder.
     *
     * @param string $choice
     * @param float $p
     * @return array
     */
    private function choice(string $choice, float $p): array
    {
        return ['type' => 'choice', 'choice' => $choice, 'probabilities' => [$choice => $p, 'other' => 1 - $p]];
    }

    /**
     * @param Decision $decision
     * @param string $outcome
     * @param string|null $value
     * @param float|null $probability
     */
    private function assertDecision(Decision $decision, string $outcome, ?string $value, ?float $probability): void
    {
        self::assertSame($outcome, $decision->getOutcome());
        self::assertSame($outcome === Decision::ACCEPTED, $decision->isAccepted());
        self::assertSame($value, $decision->getValue());
        if ($probability === null) {
            self::assertNull($decision->getProbability());
        } else {
            self::assertEqualsWithDelta($probability, $decision->getProbability(), 1e-9);
        }
    }
}

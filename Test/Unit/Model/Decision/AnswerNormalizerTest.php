<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\Decision;

use DmLab\CatalogEnrich\Exception\MalformedAnswerException;
use DmLab\CatalogEnrich\Model\Attribute\AttributeDescriptor;
use DmLab\CatalogEnrich\Model\Decision\AnswerNormalizer;
use DmLab\CatalogEnrich\Model\Question\InstructionsBuilder;
use DmLab\CatalogEnrich\Model\Question\QuestionBuilder;
use DmLab\CatalogEnrich\Model\Question\QuestionPlan;
use DmLab\TypeSafeCore\Api\Data\Answer\AnswerInterface;
use DmLab\TypeSafeCore\Api\Data\Answer\ChoiceAnswer;
use DmLab\TypeSafeCore\Api\Data\Answer\NoulAnswer;
use DmLab\TypeSafeCore\Api\Data\Response;
use PHPUnit\Framework\TestCase;

class AnswerNormalizerTest extends TestCase
{
    /**
     * @var AnswerNormalizer
     */
    private AnswerNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new AnswerNormalizer();
    }

    public function testChoiceAnswersForPlanIdsOnly(): void
    {
        $plan = $this->plan('select', [10 => 'Red', 11 => 'Blue']);
        $response = $this->response([
            'seats' => $this->choiceAnswer('Red', ['Red' => 0.97, 'Blue' => 0.02, 'not stated' => 0.01]),
            'seats:rev' => $this->choiceAnswer('Red', ['Blue' => 0.03, 'Red' => 0.95, 'not stated' => 0.02]),
            'other_attr' => $this->choiceAnswer('X', ['X' => 1.0]),
        ]);

        self::assertSame(
            [
                'seats' => [
                    'type' => 'choice',
                    'choice' => 'Red',
                    'probabilities' => ['Red' => 0.97, 'Blue' => 0.02, 'not stated' => 0.01],
                ],
                'seats:rev' => [
                    'type' => 'choice',
                    'choice' => 'Red',
                    'probabilities' => ['Blue' => 0.03, 'Red' => 0.95, 'not stated' => 0.02],
                ],
            ],
            $this->normalizer->fromResponse($response, $plan)
        );
    }

    public function testNumericOptionIdsKeepStringLookupsWorking(): void
    {
        $plan = $this->plan('select', [10 => '2', 12 => '6']);
        $response = $this->response([
            'seats' => $this->choiceAnswer('6', [2 => 0.01, 6 => 0.96, 'not stated' => 0.03]),
            'seats:rev' => $this->choiceAnswer('6', [6 => 0.95, 2 => 1, 'not stated' => 0.0]),
        ]);

        $normalized = $this->normalizer->fromResponse($response, $plan);

        self::assertSame('6', $normalized['seats']['choice']);
        $keys = array_map('strval', array_keys($normalized['seats']['probabilities']));
        self::assertSame(['2', '6', 'not stated'], $keys);
        self::assertSame(1.0, $normalized['seats:rev']['probabilities']['2']);
        self::assertSame(0.96, $normalized['seats']['probabilities']['6']);
        self::assertEquals($normalized, json_decode((string)json_encode($normalized), true));
    }

    public function testNoulAnswersForMultiselect(): void
    {
        $plan = $this->plan('multiselect', [412 => 'Bedside Table', 7 => 'Dresser']);
        $response = $this->response([
            'seats:412' => $this->noulAnswer(0.93),
            'seats:7' => $this->noulAnswer(0.02),
        ]);

        self::assertSame(
            ['seats:412' => ['type' => 'noul', 'p' => 0.93], 'seats:7' => ['type' => 'noul', 'p' => 0.02]],
            $this->normalizer->fromResponse($response, $plan)
        );
    }

    public function testMissingIdThrows(): void
    {
        $plan = $this->plan('select', [10 => 'Red']);
        $response = $this->response(['seats' => $this->choiceAnswer('Red', ['Red' => 1.0])]);

        $this->expectException(MalformedAnswerException::class);
        $this->expectExceptionMessage('No answer for question "seats:rev".');

        $this->normalizer->fromResponse($response, $plan);
    }

    public function testNoulAnswerForBooleanThrows(): void
    {
        $plan = $this->plan('boolean', []);
        $response = $this->response([
            'seats' => $this->noulAnswer(0.99),
            'seats:rev' => $this->noulAnswer(0.99),
        ]);

        $this->expectException(MalformedAnswerException::class);

        $this->normalizer->fromResponse($response, $plan);
    }

    public function testChoiceAnswerForMultiselectThrows(): void
    {
        $plan = $this->plan('multiselect', [1 => 'A']);
        $response = $this->response(['seats:1' => $this->choiceAnswer('A', ['A' => 1.0])]);

        $this->expectException(MalformedAnswerException::class);

        $this->normalizer->fromResponse($response, $plan);
    }

    public function testOtherAnswerClassThrows(): void
    {
        $plan = $this->plan('select', [10 => 'Red']);
        $response = $this->response([
            'seats' => $this->createStub(AnswerInterface::class),
            'seats:rev' => $this->choiceAnswer('Red', ['Red' => 1.0]),
        ]);

        $this->expectException(MalformedAnswerException::class);

        $this->normalizer->fromResponse($response, $plan);
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
     * @param array<string, AnswerInterface> $answers
     * @return Response
     */
    private function response(array $answers): Response
    {
        $response = $this->createStub(Response::class);
        $response->method('getAnswers')->willReturn($answers);

        return $response;
    }

    /**
     * @param string $choice
     * @param array $probabilities
     * @return ChoiceAnswer
     */
    private function choiceAnswer(string $choice, array $probabilities): ChoiceAnswer
    {
        $answer = $this->createStub(ChoiceAnswer::class);
        $answer->method('getChoice')->willReturn($choice);
        $answer->method('getProbabilities')->willReturn($probabilities);

        return $answer;
    }

    /**
     * @param float $p
     * @return NoulAnswer
     */
    private function noulAnswer(float $p): NoulAnswer
    {
        $answer = $this->createStub(NoulAnswer::class);
        $answer->method('getProbability')->willReturn($p);

        return $answer;
    }
}

<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\Question;

use DmLab\CatalogEnrich\Model\Attribute\AttributeDescriptor;
use DmLab\CatalogEnrich\Model\Question\InstructionsBuilder;
use DmLab\CatalogEnrich\Model\Question\QuestionBuilder;
use DmLab\TypeSafeCore\Api\Data\Question\ChoiceQuestion;
use DmLab\TypeSafeCore\Api\Data\Question\NoulQuestion;
use PHPUnit\Framework\TestCase;

class QuestionBuilderTest extends TestCase
{
    /**
     * @var QuestionBuilder
     */
    private QuestionBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new QuestionBuilder(new InstructionsBuilder());
    }

    public function testSelectBothOrdersWithSentinelLast(): void
    {
        $descriptor = new AttributeDescriptor(1, 'number_of_seats', 'Number of Seats', 'select', 't', [
            10 => '2',
            11 => '4',
            12 => '6',
            13 => '8',
        ]);

        $plan = $this->builder->build($descriptor, null, []);

        self::assertSame(['number_of_seats', 'number_of_seats:rev'], array_keys($plan->getQuestions()));
        self::assertSame('number_of_seats', $plan->getAttributeCode());
        self::assertSame('select', $plan->getInput());
        $forward = $plan->getQuestions()['number_of_seats'];
        $reversed = $plan->getQuestions()['number_of_seats:rev'];
        self::assertInstanceOf(ChoiceQuestion::class, $forward);
        self::assertInstanceOf(ChoiceQuestion::class, $reversed);
        self::assertSame(
            [
                'type' => 'choice',
                'instructions' => 'What is the number of seats of this product?',
                'criteria' => [
                    '2' => null,
                    '4' => null,
                    '6' => null,
                    '8' => null,
                    'not stated' => 'The text does not say',
                ],
            ],
            $this->normalize($forward->toArray())
        );
        self::assertSame(
            ['8', '6', '4', '2', 'not stated'],
            array_map('strval', array_keys((array)$reversed->toArray()['criteria']))
        );
        self::assertSame('The text does not say', ((array)$reversed->toArray()['criteria'])['not stated']);
        self::assertSame(['2' => 10, '4' => 11, '6' => 12, '8' => 13], $plan->getLabelToOptionId());
        self::assertSame([], $plan->getOptionIdByQuestionId());
        self::assertSame('What is the number of seats of this product?', $plan->getEffectiveInstructions());
    }

    public function testSelectWithRulesUsesStructuredInstructionsInBothQuestions(): void
    {
        $descriptor = new AttributeDescriptor(1, 'color', 'Color', 'select', 't', [5 => 'Red', 6 => 'Blue']);
        $expected = ['question' => 'Which colour is it?', 'rules' => ['Navy counts as Blue.']];

        $plan = $this->builder->build($descriptor, '  Which colour is it?  ', ['Navy counts as Blue.']);

        foreach ($plan->getQuestions() as $question) {
            self::assertSame($expected, $question->toArray()['instructions']);
        }
        self::assertSame(
            ['Blue', 'Red', 'not stated'],
            array_keys((array)$plan->getQuestions()['color:rev']->toArray()['criteria'])
        );
        self::assertSame($expected, $plan->getEffectiveInstructions());
    }

    public function testCustomInstructionsReplaceAttributePlaceholder(): void
    {
        $descriptor = new AttributeDescriptor(1, 'color', 'Main Color', 'select', 't', [5 => 'Red']);

        $plan = $this->builder->build($descriptor, 'Pick the {attribute}.', []);

        self::assertSame('Pick the main color.', $plan->getEffectiveInstructions());
    }

    public function testBlankCustomInstructionsFallBackToDefault(): void
    {
        $descriptor = new AttributeDescriptor(1, 'color', 'Color', 'select', 't', [5 => 'Red']);

        $plan = $this->builder->build($descriptor, "  \n ", []);

        self::assertSame('What is the color of this product?', $plan->getEffectiveInstructions());
    }

    public function testMultiselectOneNoulPerOption(): void
    {
        $descriptor = new AttributeDescriptor(2, 'bedroom_suite_incl', 'Bedroom Suite Includes', 'multiselect', 't', [
            412 => 'Bedside Table',
            413 => 'Dresser',
        ]);

        $plan = $this->builder->build($descriptor, null, []);

        self::assertSame(['bedroom_suite_incl:412', 'bedroom_suite_incl:413'], array_keys($plan->getQuestions()));
        foreach ($plan->getQuestions() as $question) {
            self::assertInstanceOf(NoulQuestion::class, $question);
        }
        self::assertSame(
            [
                'type' => 'noul',
                'instructions' => 'Does "Bedside Table" apply to the bedroom suite includes of this product?',
            ],
            $plan->getQuestions()['bedroom_suite_incl:412']->toArray()
        );
        self::assertSame(
            ['bedroom_suite_incl:412' => 412, 'bedroom_suite_incl:413' => 413],
            $plan->getOptionIdByQuestionId()
        );
        self::assertSame([], $plan->getLabelToOptionId());
        self::assertSame(
            'Does "{option}" apply to the bedroom suite includes of this product?',
            $plan->getEffectiveInstructions()
        );
    }

    public function testMultiselectCustomTextWithRules(): void
    {
        $descriptor = new AttributeDescriptor(2, 'features', 'Features', 'multiselect', 't', [7 => 'Drawers']);

        $plan = $this->builder->build($descriptor, 'Has it {option}?', ['Count built-in parts only.']);

        self::assertSame(
            ['question' => 'Has it Drawers?', 'rules' => ['Count built-in parts only.']],
            $plan->getQuestions()['features:7']->toArray()['instructions']
        );
        self::assertSame(
            ['question' => 'Has it {option}?', 'rules' => ['Count built-in parts only.']],
            $plan->getEffectiveInstructions()
        );
    }

    public function testMultiselectCustomTextWithoutOptionPlaceholderRejected(): void
    {
        $descriptor = new AttributeDescriptor(2, 'features', 'Features', 'multiselect', 't', [7 => 'Drawers']);

        $this->expectException(\InvalidArgumentException::class);
        $this->builder->build($descriptor, 'Which features does it have?', []);
    }

    public function testSelectWith254OptionsBuilds(): void
    {
        $options = [];
        for ($i = 1; $i <= 254; $i++) {
            $options[$i] = 'Option ' . $i;
        }
        $descriptor = new AttributeDescriptor(1, 'big', 'Big', 'select', 't', $options);

        $plan = $this->builder->build($descriptor, null, []);

        foreach ($plan->getQuestions() as $question) {
            $criteria = (array)$question->toArray()['criteria'];
            self::assertCount(255, $criteria);
            self::assertSame('not stated', array_key_last($criteria));
        }
        self::assertSame('Option 254', array_key_first((array)$plan->getQuestions()['big:rev']->toArray()['criteria']));
    }

    public function testBooleanIsTwoChoicesInBothOrders(): void
    {
        $descriptor = new AttributeDescriptor(3, 'is_assembled', 'Is Assembled', 'boolean', 't');

        $plan = $this->builder->build($descriptor, null, []);

        self::assertSame(['is_assembled', 'is_assembled:rev'], array_keys($plan->getQuestions()));
        foreach ($plan->getQuestions() as $question) {
            self::assertInstanceOf(ChoiceQuestion::class, $question);
            self::assertNotInstanceOf(NoulQuestion::class, $question);
        }
        self::assertSame(
            [
                'type' => 'choice',
                'instructions' => 'Is "is assembled" true for this product?',
                'criteria' => ['Yes' => null, 'No' => null, 'not stated' => 'The text does not say'],
            ],
            $this->normalize($plan->getQuestions()['is_assembled']->toArray())
        );
        self::assertSame(
            ['No', 'Yes', 'not stated'],
            array_keys((array)$plan->getQuestions()['is_assembled:rev']->toArray()['criteria'])
        );
        self::assertSame(['Yes' => 1, 'No' => 0], $plan->getLabelToOptionId());
        self::assertSame([], $plan->getOptionIdByQuestionId());
    }

    public function testBooleanRulesGoIntoStructuredInstructions(): void
    {
        $descriptor = new AttributeDescriptor(3, 'is_assembled', 'Is Assembled', 'boolean', 't');
        $expected = ['question' => 'Is "is assembled" true for this product?', 'rules' => ['Flat-pack means No.']];

        $plan = $this->builder->build($descriptor, null, ['Flat-pack means No.']);

        foreach ($plan->getQuestions() as $question) {
            self::assertSame($expected, $question->toArray()['instructions']);
        }
        self::assertSame($expected, $plan->getEffectiveInstructions());
    }

    /**
     * Cast the Choice criteria object to an array with string keys.
     *
     * @param array $data
     * @return array
     */
    private function normalize(array $data): array
    {
        $criteria = [];
        foreach ((array)$data['criteria'] as $option => $description) {
            $criteria[(string)$option] = $description;
        }
        $data['criteria'] = $criteria;

        return $data;
    }
}

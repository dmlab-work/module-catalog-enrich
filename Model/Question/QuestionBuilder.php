<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Question;

use DmLab\CatalogEnrich\Model\Attribute\AttributeDescriptor;
use DmLab\TypeSafeCore\Api\Data\Question\ChoiceQuestion;
use DmLab\TypeSafeCore\Api\Data\Question\NoulQuestion;

/**
 * Builds the TypeSafe questions for one attribute.
 *
 * Select and boolean are asked as two Choices in opposite option orders (accepted only on agreement);
 * multiselect is one Noul per option. Boolean is never a Noul: it could not say "the text does not say".
 */
class QuestionBuilder
{
    public const NOT_STATED = 'not stated';
    public const NOT_STATED_DESCRIPTION = 'The text does not say';
    public const REVERSED_SUFFIX = ':rev';

    /**
     * @param InstructionsBuilder $instructionsBuilder
     */
    public function __construct(
        private readonly InstructionsBuilder $instructionsBuilder
    ) {
    }

    /**
     * Build the question plan; empty custom instructions fall back to the default template.
     *
     * @param AttributeDescriptor $descriptor
     * @param string|null $customInstructions
     * @param string[] $rules
     * @return QuestionPlan
     * @throws \InvalidArgumentException multiselect custom text without {option}
     */
    public function build(AttributeDescriptor $descriptor, ?string $customInstructions, array $rules): QuestionPlan
    {
        $text = $customInstructions !== null ? trim($customInstructions) : '';
        if ($text === '') {
            $text = $this->instructionsBuilder->defaultText($descriptor);
        } elseif ($descriptor->getInput() === AttributeDescriptor::INPUT_MULTISELECT
            && !str_contains($text, InstructionsBuilder::PLACEHOLDER_OPTION)
        ) {
            throw new \InvalidArgumentException(
                sprintf('Multiselect instructions must contain "%s".', InstructionsBuilder::PLACEHOLDER_OPTION)
            );
        }

        return $descriptor->getInput() === AttributeDescriptor::INPUT_MULTISELECT
            ? $this->buildMultiselect($descriptor, $text, $rules)
            : $this->buildChoice($descriptor, $text, $rules);
    }

    /**
     * Two Choices: source order and reversed, the sentinel last in both.
     *
     * @param AttributeDescriptor $descriptor
     * @param string $text
     * @param string[] $rules
     * @return QuestionPlan
     */
    private function buildChoice(AttributeDescriptor $descriptor, string $text, array $rules): QuestionPlan
    {
        $instructions = $this->instructionsBuilder->build($text, $rules, null, $descriptor->getLabel());
        $labelToOptionId = array_flip(array_map('strval', $descriptor->getOptions()));
        $criteria = array_fill_keys(array_keys($labelToOptionId), null);
        $reversed = array_reverse($criteria, true);
        $criteria[self::NOT_STATED] = self::NOT_STATED_DESCRIPTION;
        $reversed[self::NOT_STATED] = self::NOT_STATED_DESCRIPTION;
        $code = $descriptor->getCode();

        return new QuestionPlan(
            $code,
            $descriptor->getInput(),
            [
                $code => new ChoiceQuestion($instructions, $criteria),
                $code . self::REVERSED_SUFFIX => new ChoiceQuestion($instructions, $reversed),
            ],
            $labelToOptionId,
            [],
            $instructions
        );
    }

    /**
     * One Noul per option, id `<code>:<optionId>`.
     *
     * @param AttributeDescriptor $descriptor
     * @param string $text
     * @param string[] $rules
     * @return QuestionPlan
     */
    private function buildMultiselect(AttributeDescriptor $descriptor, string $text, array $rules): QuestionPlan
    {
        $label = $descriptor->getLabel();
        $questions = [];
        $optionIdByQuestionId = [];
        foreach ($descriptor->getOptions() as $optionId => $optionLabel) {
            $id = $descriptor->getCode() . ':' . $optionId;
            $questions[$id] = new NoulQuestion($this->instructionsBuilder->build($text, $rules, $optionLabel, $label));
            $optionIdByQuestionId[$id] = $optionId;
        }

        return new QuestionPlan(
            $descriptor->getCode(),
            $descriptor->getInput(),
            $questions,
            [],
            $optionIdByQuestionId,
            $this->instructionsBuilder->build($text, $rules, null, $label)
        );
    }
}

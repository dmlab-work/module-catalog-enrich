<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Question;

use DmLab\TypeSafeCore\Api\Data\Question\QuestionInterface;

/**
 * The questions asked for one attribute and how their answers map back to option ids.
 */
class QuestionPlan
{
    /**
     * @param string $attributeCode
     * @param string $input
     * @param array<string,QuestionInterface> $questions question id => question
     * @param array<string|int,int> $labelToOptionId select/boolean; numeric labels become int keys in PHP
     * @param array<string,int> $optionIdByQuestionId multiselect
     * @param string|array $effectiveInstructions instructions as sent (template-level for multiselect)
     */
    public function __construct(
        private readonly string $attributeCode,
        private readonly string $input,
        private readonly array $questions,
        private readonly array $labelToOptionId,
        private readonly array $optionIdByQuestionId,
        private readonly string|array $effectiveInstructions
    ) {
    }

    /**
     * Attribute code.
     *
     * @return string
     */
    public function getAttributeCode(): string
    {
        return $this->attributeCode;
    }

    /**
     * One of the AttributeDescriptor::INPUT_* constants.
     *
     * @return string
     */
    public function getInput(): string
    {
        return $this->input;
    }

    /**
     * Question id => question.
     *
     * @return array<string, QuestionInterface>
     */
    public function getQuestions(): array
    {
        return $this->questions;
    }

    /**
     * Choice label => option id (select, boolean).
     *
     * @return array<string|int, int>
     */
    public function getLabelToOptionId(): array
    {
        return $this->labelToOptionId;
    }

    /**
     * Noul question id => option id (multiselect).
     *
     * @return array<string, int>
     */
    public function getOptionIdByQuestionId(): array
    {
        return $this->optionIdByQuestionId;
    }

    /**
     * Instructions used by the fingerprint.
     *
     * @return string|array
     */
    public function getEffectiveInstructions(): string|array
    {
        return $this->effectiveInstructions;
    }
}

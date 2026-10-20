<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Decision;

use DmLab\CatalogEnrich\Exception\MalformedAnswerException;
use DmLab\CatalogEnrich\Model\Attribute\AttributeDescriptor;
use DmLab\CatalogEnrich\Model\Question\QuestionBuilder;
use DmLab\CatalogEnrich\Model\Question\QuestionPlan;

/**
 * Turns normalized answers into a decision; pure, so stored answers can be re-decided locally.
 */
class DecisionEngine
{
    public const FILL_EMPTY_ONLY = 'empty_only';
    public const FILL_ADD_MISSING = 'add_missing';

    /**
     * Decide one attribute.
     *
     * @param QuestionPlan $plan
     * @param array<string,array> $normalized AnswerNormalizer output (or its JSON round-trip)
     * @param float $threshold 0.5 < t < 1
     * @param string $fillMode
     * @param string|null $currentValue raw store-0 value, even one the rule treats as empty
     * @return Decision
     * @throws MalformedAnswerException
     */
    public function decide(
        QuestionPlan $plan,
        array $normalized,
        float $threshold,
        string $fillMode,
        ?string $currentValue
    ): Decision {
        if ($plan->getInput() === AttributeDescriptor::INPUT_MULTISELECT) {
            $exclude = $fillMode === self::FILL_ADD_MISSING ? $this->parseIds($currentValue) : [];

            return $this->decideMultiselect($plan, $normalized, $threshold, $exclude);
        }

        $decision = $this->decideChoice($plan, $normalized, $threshold);
        // A choice equal to the stored value (a boolean 0 the rule treats as empty) has nothing to add.
        if ($decision->isAccepted() && $currentValue !== null && trim($currentValue) === $decision->getValue()) {
            return new Decision(Decision::NOT_STATED);
        }

        return $decision;
    }

    /**
     * Both orders must pick the same option with min probability ≥ t.
     *
     * @param QuestionPlan $plan
     * @param array<string,array> $normalized
     * @param float $threshold
     * @return Decision
     * @throws MalformedAnswerException
     */
    private function decideChoice(QuestionPlan $plan, array $normalized, float $threshold): Decision
    {
        $code = $plan->getAttributeCode();
        $forward = $this->choiceAnswer($normalized, $code);
        $reversed = $this->choiceAnswer($normalized, $code . QuestionBuilder::REVERSED_SUFFIX);
        $choice = (string)$forward['choice'];

        if ($choice !== (string)$reversed['choice']) {
            return new Decision(Decision::UNCERTAIN);
        }
        if ($choice === QuestionBuilder::NOT_STATED) {
            return new Decision(Decision::NOT_STATED);
        }
        $optionId = $this->optionIdForLabel($plan, $choice);
        if ($optionId === null) {
            return new Decision(Decision::UNCERTAIN);
        }
        $probability = min(
            $this->probabilityOf($forward['probabilities'], $choice),
            $this->probabilityOf($reversed['probabilities'], $choice)
        );

        return $probability >= $threshold
            ? new Decision(Decision::ACCEPTED, (string)$optionId, $probability)
            : new Decision(Decision::UNCERTAIN);
    }

    /**
     * Yes when p ≥ t, no when p ≤ 1 − t, uncertain in between.
     *
     * @param QuestionPlan $plan
     * @param array<string,array> $normalized
     * @param float $threshold
     * @param int[] $exclude option ids already present (add_missing)
     * @return Decision
     * @throws MalformedAnswerException
     */
    private function decideMultiselect(
        QuestionPlan $plan,
        array $normalized,
        float $threshold,
        array $exclude
    ): Decision {
        $noBelow = round(1.0 - $threshold, 10);
        $yes = [];
        $uncertain = false;
        foreach ($plan->getOptionIdByQuestionId() as $questionId => $optionId) {
            $answer = $normalized[(string)$questionId] ?? null;
            if (!is_array($answer) || ($answer['type'] ?? null) !== AnswerNormalizer::TYPE_NOUL
                || !is_numeric($answer['p'] ?? null)
            ) {
                throw new MalformedAnswerException(__('No Noul answer for question "%1".', (string)$questionId));
            }
            $p = (float)$answer['p'];
            if ($p >= $threshold) {
                $yes[(int)$optionId] = $p;
            } elseif ($p > $noBelow) {
                $uncertain = true;
            }
        }

        if ($yes === [] && $uncertain) {
            return new Decision(Decision::UNCERTAIN);
        }
        foreach ($exclude as $id) {
            unset($yes[$id]);
        }
        if ($yes === []) {
            return new Decision(Decision::NOT_STATED);
        }
        ksort($yes);

        return new Decision(Decision::ACCEPTED, implode(',', array_keys($yes)), min($yes));
    }

    /**
     * Validated choice answer.
     *
     * @param array<string,array> $normalized
     * @param string $id
     * @return array{choice: string, probabilities: array}
     * @throws MalformedAnswerException
     */
    private function choiceAnswer(array $normalized, string $id): array
    {
        $answer = $normalized[$id] ?? null;
        if (!is_array($answer) || ($answer['type'] ?? null) !== AnswerNormalizer::TYPE_CHOICE
            || !is_scalar($answer['choice'] ?? null) || !is_array($answer['probabilities'] ?? null)
        ) {
            throw new MalformedAnswerException(__('No Choice answer for question "%1".', $id));
        }

        return $answer;
    }

    /**
     * Option id for a choice label; labels and keys compared as strings.
     *
     * @param QuestionPlan $plan
     * @param string $label
     * @return int|null
     */
    private function optionIdForLabel(QuestionPlan $plan, string $label): ?int
    {
        foreach ($plan->getLabelToOptionId() as $candidate => $optionId) {
            if ((string)$candidate === $label) {
                return (int)$optionId;
            }
        }

        return null;
    }

    /**
     * Probability of an option; numeric option keys are PHP ints, so compare as strings.
     *
     * @param array $probabilities
     * @param string $option
     * @return float
     */
    private function probabilityOf(array $probabilities, string $option): float
    {
        foreach ($probabilities as $key => $probability) {
            if ((string)$key === $option) {
                return (float)$probability;
            }
        }

        return 0.0;
    }

    /**
     * Option ids of a raw multiselect value.
     *
     * @param string|null $value
     * @return int[]
     */
    private function parseIds(?string $value): array
    {
        $ids = [];
        foreach (explode(',', (string)$value) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                $ids[] = (int)$part;
            }
        }

        return $ids;
    }
}

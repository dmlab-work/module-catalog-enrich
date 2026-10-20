<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Decision;

use DmLab\CatalogEnrich\Exception\MalformedAnswerException;
use DmLab\CatalogEnrich\Model\Attribute\AttributeDescriptor;
use DmLab\CatalogEnrich\Model\Question\QuestionPlan;
use DmLab\TypeSafeCore\Api\Data\Answer\ChoiceAnswer;
use DmLab\TypeSafeCore\Api\Data\Answer\NoulAnswer;
use DmLab\TypeSafeCore\Api\Data\Response;

/**
 * Extracts a plan's answers from a response into plain, JSON-storable arrays.
 */
class AnswerNormalizer
{
    public const TYPE_CHOICE = 'choice';
    public const TYPE_NOUL = 'noul';

    /**
     * Normalized answers for the plan's question ids only.
     *
     * @param Response $response
     * @param QuestionPlan $plan
     * @return array<string,array> id => ['type'=>'choice','choice'=>…,'probabilities'=>…] | ['type'=>'noul','p'=>…]
     * @throws MalformedAnswerException
     */
    public function fromResponse(Response $response, QuestionPlan $plan): array
    {
        $answers = $response->getAnswers();
        $multiselect = $plan->getInput() === AttributeDescriptor::INPUT_MULTISELECT;
        $result = [];
        foreach (array_keys($plan->getQuestions()) as $id) {
            $id = (string)$id;
            $answer = $answers[$id] ?? null;
            if ($answer === null) {
                throw new MalformedAnswerException(__('No answer for question "%1".', $id));
            }
            if ($multiselect && $answer instanceof NoulAnswer) {
                $result[$id] = ['type' => self::TYPE_NOUL, 'p' => $answer->getProbability()];
            } elseif (!$multiselect && $answer instanceof ChoiceAnswer) {
                $probabilities = [];
                foreach ($answer->getProbabilities() as $option => $probability) {
                    // PHP turns numeric option keys into ints; consumers must compare keys as strings.
                    $probabilities[$option] = (float)$probability;
                }
                $result[$id] = [
                    'type' => self::TYPE_CHOICE,
                    'choice' => (string)$answer->getChoice(),
                    'probabilities' => $probabilities,
                ];
            } else {
                throw new MalformedAnswerException(
                    __('Unexpected answer type "%1" for question "%2".', get_debug_type($answer), $id)
                );
            }
        }

        return $result;
    }
}

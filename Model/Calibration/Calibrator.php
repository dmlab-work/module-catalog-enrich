<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Calibration;

use DmLab\CatalogEnrich\Exception\MalformedAnswerException;
use DmLab\CatalogEnrich\Model\Attribute\AttributeCatalog;
use DmLab\CatalogEnrich\Model\Attribute\AttributeDescriptor;
use DmLab\CatalogEnrich\Model\Config;
use DmLab\CatalogEnrich\Model\Decision\AnswerNormalizer;
use DmLab\CatalogEnrich\Model\Decision\Decision;
use DmLab\CatalogEnrich\Model\Decision\DecisionEngine;
use DmLab\CatalogEnrich\Model\Question\QuestionBuilder;
use DmLab\CatalogEnrich\Model\Question\QuestionPlan;
use DmLab\CatalogEnrich\Model\ResourceModel\CatalogQuery;
use DmLab\CatalogEnrich\Model\Rule;
use DmLab\CatalogEnrich\Model\RuleRepository;
use DmLab\CatalogEnrich\Model\Run\StopCondition;
use DmLab\CatalogEnrich\Model\State\ProductDataLoader;
use DmLab\CatalogEnrich\Model\State\ProductStateBuilder;
use DmLab\TypeSafeCore\Api\ClientInterface;
use DmLab\TypeSafeCore\Api\ConfigInterface;
use DmLab\TypeSafeCore\Api\Data\Request;
use DmLab\TypeSafeCore\Api\Data\Response;
use DmLab\TypeSafeCore\Exception\TypeSafeException;
use Magento\Framework\Exception\LocalizedException;

/**
 * Measures precision and coverage per threshold on products that already hold a value (the gold answer).
 *
 * Read-only: touches neither the ledger nor suggestions.
 */
class Calibrator
{
    public const CONSUMER = 'catalog_enrich_calibrate';
    public const CHUNK_SIZE = 50;

    /**
     * @param AttributeCatalog $attributeCatalog
     * @param RuleRepository $ruleRepository
     * @param CatalogQuery $catalogQuery
     * @param ProductDataLoader $productDataLoader
     * @param ProductStateBuilder $productStateBuilder
     * @param QuestionBuilder $questionBuilder
     * @param AnswerNormalizer $answerNormalizer
     * @param DecisionEngine $decisionEngine
     * @param ClientInterface $client
     * @param ConfigInterface $coreConfig
     * @param StopCondition $stopCondition
     * @param Config $config
     */
    public function __construct(
        private readonly AttributeCatalog $attributeCatalog,
        private readonly RuleRepository $ruleRepository,
        private readonly CatalogQuery $catalogQuery,
        private readonly ProductDataLoader $productDataLoader,
        private readonly ProductStateBuilder $productStateBuilder,
        private readonly QuestionBuilder $questionBuilder,
        private readonly AnswerNormalizer $answerNormalizer,
        private readonly DecisionEngine $decisionEngine,
        private readonly ClientInterface $client,
        private readonly ConfigInterface $coreConfig,
        private readonly StopCondition $stopCondition,
        private readonly Config $config
    ) {
    }

    /**
     * Calibrate one attribute on the newest products holding a value.
     *
     * @param string $code
     * @param int $sample maximum number of products
     * @return CalibrationResult
     * @throws LocalizedException not configured, ineligible attribute, invalid rule or a stop-type failure
     */
    public function calibrate(string $code, int $sample): CalibrationResult
    {
        if (!$this->coreConfig->isConfigured()) {
            throw new LocalizedException($this->stopCondition->notConfigured());
        }
        if ($sample < 1) {
            throw new LocalizedException(__('The sample size must be a positive integer.'));
        }
        $model = $this->coreConfig->getModel();
        $descriptor = $this->attributeCatalog->get($code);
        $rule = $this->ruleRepository->getByAttributeCode($code);
        try {
            $plan = $this->questionBuilder->build($descriptor, $rule?->getInstructions(), $rule?->getRules() ?? []);
        } catch (\InvalidArgumentException $e) {
            throw new LocalizedException(__('The rule instructions are invalid: %1', $e->getMessage()));
        }

        $result = new CalibrationResult(
            $code,
            $descriptor->getInput(),
            $rule?->getThreshold() ?? Rule::DEFAULT_THRESHOLD
        );
        $loadCodes = array_values(array_unique([...$this->productStateBuilder->getCodes(), $code]));
        $visibleOnly = !$this->config->isIncludeNotVisible();
        // A 0 the rule treats as empty is not an answer the merchant gave.
        $excludeZero = $rule !== null && $rule->isTreatZeroAsEmpty()
            && $descriptor->getInput() === AttributeDescriptor::INPUT_BOOLEAN;
        if ($excludeZero) {
            $result->setExcludedZero($this->catalogQuery->countZeroGold($descriptor, $visibleOnly));
        }
        $ids = $this->catalogQuery->fetchFilledIds($descriptor, $sample, $visibleOnly, $excludeZero);
        foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
            $rows = $this->productDataLoader->loadMany($chunk, $loadCodes);
            $requests = [];
            $products = [];
            foreach ($chunk as $productId) {
                $data = $rows[$productId] ?? null;
                $gold = $this->goldValue($data[$code] ?? null);
                if ($data === null || $gold === null || ($excludeZero && $gold === '0')) {
                    continue;
                }
                $state = $this->productStateBuilder->build($data);
                // The answer must come from the text, never from the stored value.
                unset($state[$code]);
                if (!$this->productStateBuilder->isUsable($state)) {
                    continue;
                }
                $requests[$productId] = new Request($state, $plan->getQuestions(), self::CONSUMER, $model);
                $products[$productId] = ['sku' => (string)$data['sku'], 'gold' => $gold];
            }
            if ($requests !== []) {
                $this->askAndScore($requests, $products, $plan, $descriptor, $result);
            }
        }

        return $result;
    }

    /**
     * Send one chunk and score every response.
     *
     * @param Request[] $requests keyed by product id
     * @param array $products product id => ['sku' => string, 'gold' => string]
     * @param QuestionPlan $plan
     * @param AttributeDescriptor $descriptor
     * @param CalibrationResult $result
     * @return void
     * @throws LocalizedException a stop-type failure
     */
    private function askAndScore(
        array $requests,
        array $products,
        QuestionPlan $plan,
        AttributeDescriptor $descriptor,
        CalibrationResult $result
    ): void {
        try {
            $responses = $this->client->evaluateMany($requests);
        } catch (TypeSafeException $e) {
            $responses = array_fill_keys(array_keys($requests), $e);
        }

        foreach ($products as $productId => $product) {
            $response = $responses[$productId] ?? null;
            if ($this->stopCondition->isStop($response)) {
                throw new LocalizedException(__('Calibration stopped: %1', $response->getMessage()));
            }
            if (!$response instanceof Response) {
                $result->addError();
                continue;
            }
            try {
                $decisions = $this->decide($response, $plan, $result);
            } catch (MalformedAnswerException) {
                $result->addError();
                continue;
            }
            $result->addProduct($response->getInputTokens());
            $this->score($decisions, $product, $descriptor, $result);
        }
    }

    /**
     * Decisions per calibration threshold, plus the gold-gap threshold for multiselect.
     *
     * @param Response $response
     * @param QuestionPlan $plan
     * @param CalibrationResult $result
     * @return Decision[] keyed by threshold
     * @throws MalformedAnswerException
     */
    private function decide(Response $response, QuestionPlan $plan, CalibrationResult $result): array
    {
        $normalized = $this->answerNormalizer->fromResponse($response, $plan);
        $thresholds = CalibrationResult::THRESHOLDS;
        if ($result->isMultiselect()) {
            $thresholds[] = $result->getGapThreshold();
        }
        $decisions = [];
        foreach ($thresholds as $threshold) {
            $decisions[(string)$threshold] = $this->decisionEngine->decide(
                $plan,
                $normalized,
                $threshold,
                Rule::FILL_EMPTY_ONLY,
                null
            );
        }

        return $decisions;
    }

    /**
     * Compare one product's decisions with its gold value.
     *
     * @param Decision[] $decisions keyed by threshold
     * @param array $product ['sku' => string, 'gold' => string]
     * @param AttributeDescriptor $descriptor
     * @param CalibrationResult $result
     * @return void
     */
    private function score(
        array $decisions,
        array $product,
        AttributeDescriptor $descriptor,
        CalibrationResult $result
    ): void {
        if (!$result->isMultiselect()) {
            foreach (CalibrationResult::THRESHOLDS as $threshold) {
                $decision = $decisions[(string)$threshold];
                $correct = $decision->isAccepted() && $decision->getValue() === $product['gold'];
                $result->record($threshold, $decision->isAccepted(), (int)$correct);
            }

            return;
        }

        $gold = $this->parseIds($product['gold']);
        foreach (CalibrationResult::THRESHOLDS as $threshold) {
            $decision = $decisions[(string)$threshold];
            $yes = $decision->isAccepted() ? $this->parseIds($decision->getValue()) : [];
            $result->record(
                $threshold,
                $decision->isAccepted(),
                count(array_intersect($yes, $gold)),
                count(array_diff($yes, $gold)),
                count(array_diff($gold, $yes))
            );
        }
        $decision = $decisions[(string)$result->getGapThreshold()];
        $yes = $decision->isAccepted() ? $this->parseIds($decision->getValue()) : [];
        $options = $descriptor->getOptions();
        foreach (array_diff($yes, $gold) as $optionId) {
            $result->addGoldGap(sprintf('%s: %s', $product['sku'], $options[$optionId] ?? '#' . $optionId));
        }
    }

    /**
     * Stored value as a trimmed string; null when empty.
     *
     * @param mixed $value
     * @return string|null
     */
    private function goldValue(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string)$value);

        return $value === '' ? null : $value;
    }

    /**
     * Option ids of a comma-joined value.
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
                $ids[(int)$part] = (int)$part;
            }
        }

        return array_values($ids);
    }
}

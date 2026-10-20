<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Run;

use DmLab\CatalogEnrich\Exception\MalformedAnswerException;
use DmLab\CatalogEnrich\Model\Config;
use DmLab\CatalogEnrich\Model\Decision\AnswerNormalizer;
use DmLab\CatalogEnrich\Model\Decision\Decision;
use DmLab\CatalogEnrich\Model\Decision\DecisionEngine;
use DmLab\CatalogEnrich\Model\Dedup\AttemptLedger;
use DmLab\CatalogEnrich\Model\Dedup\Fingerprint;
use DmLab\CatalogEnrich\Model\Dedup\LedgerVerdict;
use DmLab\CatalogEnrich\Model\Question\QuestionBuilder;
use DmLab\CatalogEnrich\Model\Question\QuestionPlan;
use DmLab\CatalogEnrich\Model\ResourceModel\CatalogQuery;
use DmLab\CatalogEnrich\Model\Rule;
use DmLab\CatalogEnrich\Model\State\ProductDataLoader;
use DmLab\CatalogEnrich\Model\State\ProductStateBuilder;
use DmLab\CatalogEnrich\Model\Suggestion\SuggestionWriter;
use DmLab\TypeSafeCore\Api\ClientInterface;
use DmLab\TypeSafeCore\Api\ConfigInterface;
use DmLab\TypeSafeCore\Api\Data\Request;
use DmLab\TypeSafeCore\Api\Data\Response;
use DmLab\TypeSafeCore\Exception\RateLimitException;
use DmLab\TypeSafeCore\Exception\RequestException;
use DmLab\TypeSafeCore\Exception\TypeSafeException;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Runs enrichment over product ids: one request per product, one evaluateMany per chunk.
 *
 * Every read of a chunk (values, ledger, suggestions) is one batch; an unchanged chunk costs no writes.
 */
class Enricher
{
    public const CHUNK_SIZE = 50;

    private const TYPE_CONFIGURABLE = 'configurable';

    /** RequestException codes that mean "this data is refused" (0 = could not be encoded locally). */
    private const REJECTED_STATUSES = [0, 400, 413, 422];

    private const OUTCOME_COUNTERS = [
        Decision::ACCEPTED => RunReport::ACCEPTED,
        Decision::NOT_STATED => RunReport::NOT_STATED,
        Decision::UNCERTAIN => RunReport::UNCERTAIN,
    ];

    /**
     * @param ClientInterface $client
     * @param AttributePlanner $attributePlanner
     * @param ProductDataLoader $productDataLoader
     * @param ProductStateBuilder $productStateBuilder
     * @param QuestionBuilder $questionBuilder
     * @param Fingerprint $fingerprint
     * @param AttemptLedger $attemptLedger
     * @param AnswerNormalizer $answerNormalizer
     * @param DecisionEngine $decisionEngine
     * @param SuggestionWriter $suggestionWriter
     * @param ConfigInterface $coreConfig
     * @param LoggerInterface $logger
     * @param StopCondition $stopCondition
     * @param Config $config
     * @param CatalogQuery $catalogQuery
     */
    public function __construct(
        private readonly ClientInterface $client,
        private readonly AttributePlanner $attributePlanner,
        private readonly ProductDataLoader $productDataLoader,
        private readonly ProductStateBuilder $productStateBuilder,
        private readonly QuestionBuilder $questionBuilder,
        private readonly Fingerprint $fingerprint,
        private readonly AttemptLedger $attemptLedger,
        private readonly AnswerNormalizer $answerNormalizer,
        private readonly DecisionEngine $decisionEngine,
        private readonly SuggestionWriter $suggestionWriter,
        private readonly ConfigInterface $coreConfig,
        private readonly LoggerInterface $logger,
        private readonly StopCondition $stopCondition,
        private readonly Config $config,
        private readonly CatalogQuery $catalogQuery
    ) {
    }

    /**
     * Enrich the given products with every active rule (or the one in the options).
     *
     * @param int[] $productIds
     * @param RunOptions $options
     * @return RunReport
     * @throws LocalizedException the requested attribute has no active rule
     */
    public function run(array $productIds, RunOptions $options): RunReport
    {
        $report = new RunReport();
        if (!$this->coreConfig->isConfigured()) {
            $report->setStopReason((string)$this->stopCondition->notConfigured());

            return $report;
        }
        // One model id per run, so the fingerprint and the request always agree.
        $model = $this->coreConfig->getModel();
        $items = $this->buildItems($this->attributePlanner->plan($options->getAttributeCode()));
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if ($items === [] || $productIds === []) {
            return $report;
        }

        foreach (array_chunk($productIds, self::CHUNK_SIZE) as $chunk) {
            $this->processChunk($chunk, $items, $model, $options, $report);
            if ($report->getStopReason() !== null || $report->isLimitReached()) {
                break;
            }
        }

        return $report;
    }

    /**
     * Pair each attribute plan with its questions; a rule whose questions cannot be built is logged and skipped.
     *
     * @param AttributePlan[] $plans
     * @return array attribute code => [AttributePlan, QuestionPlan]
     */
    private function buildItems(array $plans): array
    {
        $items = [];
        foreach ($plans as $plan) {
            $rule = $plan->getRule();
            try {
                $questions = $this->questionBuilder->build(
                    $plan->getDescriptor(),
                    $rule->getInstructions(),
                    $rule->getRules()
                );
            } catch (\InvalidArgumentException $e) {
                $this->logger->warning('DmLab_CatalogEnrich: rule skipped, questions cannot be built.', [
                    'attribute_code' => $rule->getAttributeCode(),
                    'reason' => $e->getMessage(),
                ]);
                continue;
            }
            $items[$plan->getDescriptor()->getCode()] = [$plan, $questions];
        }

        return $items;
    }

    /**
     * Plan, ask and record one chunk.
     *
     * @param int[] $chunk
     * @param array $items attribute code => [AttributePlan, QuestionPlan]
     * @param string $model
     * @param RunOptions $options
     * @param RunReport $report
     * @return void
     */
    private function processChunk(
        array $chunk,
        array $items,
        string $model,
        RunOptions $options,
        RunReport $report
    ): void {
        $codes = array_map('strval', array_keys($items));
        $products = $this->loadProducts($chunk, $codes);
        if ($products !== []) {
            $this->attemptLedger->prefetch(array_keys($products), $codes);
            if (!$options->isDryRun()) {
                $this->suggestionWriter->prefetch(array_keys($products), $codes);
            }
        }
        $superAttributes = $this->superAttributes($products);

        $requests = [];
        $asked = [];
        $filled = [];
        foreach ($chunk as $productId) {
            if ($options->getProductLimit() !== null && $report->getAskedProducts() >= $options->getProductLimit()) {
                $report->setLimitReached();
                break;
            }
            $report->setLastProductId($productId);
            $data = $products[$productId] ?? null;
            if ($data === null) {
                continue;
            }
            $state = $this->productStateBuilder->build($data);
            if (!$this->productStateBuilder->isUsable($state)) {
                continue;
            }
            $report->increment(RunReport::PRODUCTS);

            $productAsked = [];
            $skipped = [];
            foreach ($items as $code => [$plan, $questionPlan]) {
                $code = (string)$code;
                $rule = $plan->getRule();
                if (!$plan->appliesToSet((int)$data['attribute_set_id'])
                    || in_array($plan->getDescriptor()->getAttributeId(), $superAttributes[$productId] ?? [], true)
                ) {
                    continue;
                }
                $raw = $data[$code] ?? null;
                $stored = is_scalar($raw) && trim((string)$raw) !== '' ? trim((string)$raw) : null;
                $current = $rule->isEmptyValue($raw) ? null : $stored;
                if ($this->isFilled($current, $rule, $data[ProductDataLoader::STORE_VIEW_VALUES][$code] ?? [])) {
                    $filled[$code][] = $productId;
                    continue;
                }
                $fingerprint = $this->fingerprint->compute(
                    $state,
                    $plan->getDescriptor(),
                    $questionPlan->getEffectiveInstructions(),
                    $rule->getRules(),
                    $model
                );
                $verdict = $this->attemptLedger->verdict($productId, $code, $fingerprint, $options->isForce());
                if ($verdict->isAsk()) {
                    $report->increment(RunReport::ASKED);
                    $productAsked[$code] = [
                        'fingerprint' => $fingerprint,
                        'current' => $current,
                        'stored' => $stored,
                    ];
                    continue;
                }
                $skipped[] = $code;
                if ($verdict->getType() === LedgerVerdict::SKIP_BACKOFF) {
                    $report->increment(RunReport::SKIPPED_BACKOFF);
                    continue;
                }
                $report->increment(RunReport::SKIPPED_FINAL);
                if (!$options->isDryRun() && $verdict->getType() === LedgerVerdict::SKIP_FINAL) {
                    $this->redecide(
                        $productId,
                        $code,
                        $items[$code],
                        $verdict,
                        $fingerprint,
                        $current,
                        $stored,
                        $model,
                        $report
                    );
                }
            }
            if ($productAsked !== []) {
                $report->addAskedProduct();
            }

            if ($options->isDryRun()) {
                if ($productAsked === [] && $skipped === []) {
                    continue;
                }
                $report->addLine(sprintf(
                    '%s: asked [%s] skipped [%s]',
                    (string)$data['sku'],
                    implode(', ', array_keys($productAsked)),
                    implode(', ', $skipped)
                ));
                continue;
            }
            if ($productAsked !== []) {
                $questions = [];
                foreach (array_keys($productAsked) as $code) {
                    foreach ($items[$code][1]->getQuestions() as $id => $question) {
                        $questions[(string)$id] = $question;
                    }
                }
                $requests[$productId] = new Request($state, $questions, $options->getConsumer(), $model);
                $asked[$productId] = $productAsked;
            }
        }

        if (!$options->isDryRun()) {
            foreach ($filled as $code => $productIds) {
                $this->suggestionWriter->dropFilled((string)$code, $productIds);
            }
        }
        if ($requests !== []) {
            $this->askAndRecord($requests, $asked, $items, $report);
        }
    }

    /**
     * Raw values of the chunk's products the run may enrich (store-view values of the rule attributes included).
     *
     * Products "Not Visible Individually" are left out unless configured.
     *
     * @param int[] $chunk
     * @param string[] $codes rule attribute codes
     * @return array<int, array<string,mixed>>
     */
    private function loadProducts(array $chunk, array $codes): array
    {
        $includeNotVisible = $this->config->isIncludeNotVisible();
        $loadCodes = [...$this->productStateBuilder->getCodes(), ...$codes];
        if (!$includeNotVisible) {
            $loadCodes[] = 'visibility';
        }
        $products = $this->productDataLoader->loadMany($chunk, array_values(array_unique($loadCodes)), $codes);
        if ($includeNotVisible) {
            return $products;
        }

        return array_filter(
            $products,
            static fn (array $data): bool => (int)($data['visibility'] ?? 0) !== Visibility::VISIBILITY_NOT_VISIBLE
        );
    }

    /**
     * Configurable attribute ids of the chunk's configurable parents (Magento keeps them empty there).
     *
     * @param array $products product id => raw values
     * @return array<int, int[]>
     */
    private function superAttributes(array $products): array
    {
        $parentIds = array_keys(array_filter(
            $products,
            static fn (array $data): bool => ($data['type_id'] ?? null) === self::TYPE_CONFIGURABLE
        ));

        return $parentIds === [] ? [] : $this->catalogQuery->fetchSuperAttributeIds($parentIds);
    }

    /**
     * Whether the pair is skipped as already filled.
     *
     * Any non-empty store-view value (a 0 included) fills every rule, since a store-0 write would stay hidden
     * behind it; otherwise a store-0 value fills an empty_only rule. "Treat 0 as empty" applies to store 0 only.
     *
     * @param string|null $current store-0 value, null when empty for the rule
     * @param Rule $rule
     * @param string[] $storeViewValues
     * @return bool
     */
    private function isFilled(?string $current, Rule $rule, array $storeViewValues): bool
    {
        foreach ($storeViewValues as $value) {
            if (trim((string)$value) !== '') {
                return true;
            }
        }

        return $current !== null && $rule->getFillMode() === Rule::FILL_EMPTY_ONLY;
    }

    /**
     * Send the chunk's requests and record every result.
     *
     * @param Request[] $requests keyed by product id
     * @param array $asked product id => attribute code => ['fingerprint', 'current', 'stored']
     * @param array $items attribute code => [AttributePlan, QuestionPlan]
     * @param RunReport $report
     * @return void
     */
    private function askAndRecord(array $requests, array $asked, array $items, RunReport $report): void
    {
        $report->increment(RunReport::REQUESTS, count($requests));
        try {
            $results = $this->client->evaluateMany($requests);
        } catch (TypeSafeException $e) {
            $results = array_fill_keys(array_keys($requests), $e);
        }
        $rateLimited = array_filter($results, static fn ($result): bool => $result instanceof RateLimitException);
        if (count($rateLimited) === count($requests)) {
            // Every request of the chunk was throttled after core's retries: stop instead of backing off the catalog.
            $report->setStopReason(reset($rateLimited)->getMessage());
            $this->logger->warning('DmLab_CatalogEnrich: run stopped.', ['reason' => $report->getStopReason()]);

            return;
        }

        foreach ($asked as $productId => $productAsked) {
            $result = $results[$productId] ?? null;
            if ($result instanceof Response) {
                $this->recordResponse($productId, $result, $productAsked, $items, $report);
            } elseif ($this->stopCondition->isStop($result)) {
                $report->setStopReason($result->getMessage());
            } elseif ($result instanceof RequestException
                && in_array($result->getCode(), self::REJECTED_STATUSES, true)
            ) {
                foreach ($productAsked as $code => $pair) {
                    $message = $result->getMessage();
                    $this->attemptLedger->recordRejected($productId, $code, $pair['fingerprint'], $message);
                    $report->increment(RunReport::REJECTED);
                }
            } else {
                $message = $result instanceof \Throwable ? $result->getMessage() : 'No response for the product.';
                foreach ($productAsked as $code => $pair) {
                    $this->attemptLedger->recordError($productId, $code, $pair['fingerprint'], $message);
                    $report->increment(RunReport::ERRORS);
                }
            }
        }
        if ($report->getStopReason() !== null) {
            $this->logger->warning('DmLab_CatalogEnrich: run stopped.', ['reason' => $report->getStopReason()]);
        }
    }

    /**
     * Decide and record each asked attribute of one product's response.
     *
     * @param int $productId
     * @param Response $response
     * @param array $productAsked attribute code => ['fingerprint' => string, 'current' => ?string,
     *     'stored' => ?string] (current: null when empty for the rule; stored: the raw store-0 value)
     * @param array $items attribute code => [AttributePlan, QuestionPlan]
     * @param RunReport $report
     * @return void
     */
    private function recordResponse(
        int $productId,
        Response $response,
        array $productAsked,
        array $items,
        RunReport $report
    ): void {
        if ($response->isCached()) {
            $report->increment(RunReport::CACHED);
        }
        $report->increment(RunReport::INPUT_TOKENS, $response->getInputTokens());
        foreach ($productAsked as $code => $pair) {
            [$plan, $questionPlan] = $items[$code];
            $rule = $plan->getRule();
            try {
                $normalized = $this->answerNormalizer->fromResponse($response, $questionPlan);
                $decision = $this->decisionEngine->decide(
                    $questionPlan,
                    $normalized,
                    $rule->getThreshold(),
                    $rule->getFillMode(),
                    $pair['stored']
                );
            } catch (MalformedAnswerException $e) {
                $this->attemptLedger->recordError($productId, $code, $pair['fingerprint'], $e->getMessage());
                $report->increment(RunReport::ERRORS);
                continue;
            }
            $this->attemptLedger->recordDecision(
                $productId,
                $code,
                $pair['fingerprint'],
                $decision,
                $normalized,
                $response->getModel()
            );
            $report->increment(self::OUTCOME_COUNTERS[$decision->getOutcome()]);
            $this->writeSuggestion(
                $productId,
                $code,
                $decision,
                $rule,
                $pair['fingerprint'],
                $response->getModel(),
                $pair['current'],
                $report
            );
        }
    }

    /**
     * Re-decide a final outcome from the stored answers with the current threshold, fill mode and mode.
     *
     * @param int $productId
     * @param string $code
     * @param array $item [AttributePlan, QuestionPlan]
     * @param LedgerVerdict $verdict
     * @param string $fingerprint
     * @param string|null $current store-0 value, null when empty for the rule
     * @param string|null $stored raw store-0 value
     * @param string $model
     * @param RunReport $report
     * @return void
     */
    private function redecide(
        int $productId,
        string $code,
        array $item,
        LedgerVerdict $verdict,
        string $fingerprint,
        ?string $current,
        ?string $stored,
        string $model,
        RunReport $report
    ): void {
        [$plan, $questionPlan] = $item;
        $rule = $plan->getRule();
        try {
            $decision = $this->decisionEngine->decide(
                $questionPlan,
                $verdict->getAnswers(),
                $rule->getThreshold(),
                $rule->getFillMode(),
                $stored
            );
        } catch (MalformedAnswerException $e) {
            // Corrupt stored answers: an error row makes the pair ask again after the backoff.
            $this->attemptLedger->recordError($productId, $code, $fingerprint, $e->getMessage());

            return;
        }
        if ($decision->getOutcome() !== $verdict->getOutcome()) {
            $this->attemptLedger->updateOutcome($productId, $code, $decision->getOutcome());
        }
        $answeredBy = $verdict->getModel() ?? $model;
        $this->writeSuggestion($productId, $code, $decision, $rule, $fingerprint, $answeredBy, $current, $report);
    }

    /**
     * Hand the decision to the writer and count what it created or changed.
     *
     * @param int $productId
     * @param string $code
     * @param Decision $decision
     * @param Rule $rule
     * @param string $fingerprint
     * @param string $model
     * @param string|null $current
     * @param RunReport $report
     * @return void
     */
    private function writeSuggestion(
        int $productId,
        string $code,
        Decision $decision,
        Rule $rule,
        string $fingerprint,
        string $model,
        ?string $current,
        RunReport $report
    ): void {
        $suggestion = $this->suggestionWriter->record(
            $productId,
            $code,
            $decision,
            $rule->getMode(),
            $fingerprint,
            $model,
            $current
        );
        if ($suggestion !== null) {
            $report->addSuggestion($suggestion->getStatus());
        }
    }
}

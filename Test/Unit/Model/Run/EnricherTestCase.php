<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\Run;

use DmLab\CatalogEnrich\Exception\IneligibleAttributeException;
use DmLab\CatalogEnrich\Model\Apply\Applier;
use DmLab\CatalogEnrich\Model\Apply\ApplyResult;
use DmLab\CatalogEnrich\Model\Attribute\AttributeCatalog;
use DmLab\CatalogEnrich\Model\Attribute\AttributeDescriptor;
use DmLab\CatalogEnrich\Model\Config;
use DmLab\CatalogEnrich\Model\Decision\AnswerNormalizer;
use DmLab\CatalogEnrich\Model\Decision\DecisionEngine;
use DmLab\CatalogEnrich\Model\Dedup\AttemptLedger;
use DmLab\CatalogEnrich\Model\Dedup\CanonicalJson;
use DmLab\CatalogEnrich\Model\Dedup\Fingerprint;
use DmLab\CatalogEnrich\Model\Dedup\RejectionGuard;
use DmLab\CatalogEnrich\Model\Question\InstructionsBuilder;
use DmLab\CatalogEnrich\Model\Question\QuestionBuilder;
use DmLab\CatalogEnrich\Model\ResourceModel\Attempt as AttemptResource;
use DmLab\CatalogEnrich\Model\ResourceModel\CatalogQuery;
use DmLab\CatalogEnrich\Model\ResourceModel\Rule as RuleResource;
use DmLab\CatalogEnrich\Model\ResourceModel\Suggestion as SuggestionResource;
use DmLab\CatalogEnrich\Model\Rule;
use DmLab\CatalogEnrich\Model\RuleRepository;
use DmLab\CatalogEnrich\Model\Run\AttributePlanner;
use DmLab\CatalogEnrich\Model\Run\Enricher;
use DmLab\CatalogEnrich\Model\Run\RunOptions;
use DmLab\CatalogEnrich\Model\Run\RunReport;
use DmLab\CatalogEnrich\Model\Run\StopCondition;
use DmLab\CatalogEnrich\Model\State\ProductDataLoader;
use DmLab\CatalogEnrich\Model\State\ProductStateBuilder;
use DmLab\CatalogEnrich\Model\State\TextCleaner;
use DmLab\CatalogEnrich\Model\Suggestion;
use DmLab\CatalogEnrich\Model\Suggestion\SuggestionWriter;
use DmLab\CatalogEnrich\Model\SuggestionFactory;
use DmLab\TypeSafeCore\Api\ClientInterface;
use DmLab\TypeSafeCore\Api\ConfigInterface;
use DmLab\TypeSafeCore\Api\Data\Answer\ChoiceAnswer;
use DmLab\TypeSafeCore\Api\Data\Answer\NoulAnswer;
use DmLab\TypeSafeCore\Api\Data\Question\NoulQuestion;
use DmLab\TypeSafeCore\Api\Data\Request;
use DmLab\TypeSafeCore\Api\Data\Response;
use DmLab\TypeSafeCore\Exception\TypeSafeException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\Db\Context as DbContext;
use Magento\Framework\Registry;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Wires a real Enricher over in-memory products, rules, ledger and suggestions; only the client is scripted.
 */
abstract class EnricherTestCase extends TestCase
{
    protected const SET = 4;

    /**
     * Product id => raw store-0 values (sku, attribute_set_id, text and attribute codes).
     *
     * @var array<int, array<string,mixed>>
     */
    protected array $products = [];

    /**
     * Product id => attribute code => store-view raw values.
     *
     * @var array<int, array<string, string[]>>
     */
    protected array $storeViewValues = [];

    /**
     * Configurable parent id => super attribute ids.
     *
     * @var array<int, int[]>
     */
    protected array $superAttributes = [];

    /**
     * @var bool
     */
    protected bool $includeNotVisible = false;

    /**
     * @var array<string, AttributeDescriptor>
     */
    protected array $descriptors = [];

    /**
     * Attribute code => rule data, in getActive order.
     *
     * @var array<string, array<string,mixed>>
     */
    protected array $rules = [];

    /**
     * Attribute code => attribute sets containing it.
     *
     * @var array<string, int[]>
     */
    protected array $setsForAttribute = [];

    /**
     * @var string[]
     */
    protected array $stateAttributes = [];

    /**
     * @var bool
     */
    protected bool $configured = true;

    /**
     * @var string
     */
    protected string $model = 'jev-1.13.0';

    /**
     * Product id => attribute code => answer script.
     * Choice: a label for both orders, or ['fwd' => label, 'rev' => label, 'p' => float].
     * Multiselect: ['noul' => [optionId => p]].
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $script = [];

    /**
     * Product id => result returned instead of a response.
     *
     * @var array<int, TypeSafeException>
     */
    protected array $failures = [];

    /**
     * Product id => question ids left out of its response.
     *
     * @var array<int, string[]>
     */
    protected array $dropAnswers = [];

    /**
     * Product ids whose response is served from the core cache.
     *
     * @var int[]
     */
    protected array $cached = [];

    /**
     * Thrown by evaluateMany itself.
     *
     * @var TypeSafeException|null
     */
    protected ?TypeSafeException $batchFailure = null;

    /**
     * evaluateMany calls, each the requests it received.
     *
     * @var array<int, array<int, Request>>
     */
    protected array $batches = [];

    /**
     * Attempt table: product id => code => row.
     *
     * @var array<int, array<string, array<string,mixed>>>
     */
    protected array $attempts = [];

    /**
     * Suggestion table: id => row.
     *
     * @var array<int, array<string,mixed>>
     */
    protected array $suggestions = [];

    /**
     * @var int
     */
    protected int $now;

    /**
     * @var bool
     */
    protected bool $applyOk = true;

    /**
     * Collaborator call counters: loads, ledgerReads, ledgerWrites, suggestionReads, suggestionWrites,
     * suggestionDeletes, superReads, ruleReads.
     *
     * @var array<string,int>
     */
    protected array $calls = [];

    /**
     * @var int
     */
    private int $nextSuggestionId = 1;

    protected function setUp(): void
    {
        $this->now = gmmktime(10, 0, 0, 10, 19, 2026);
        $this->calls = array_fill_keys(
            [
                'loads', 'ledgerReads', 'ledgerWrites', 'suggestionReads', 'suggestionWrites', 'suggestionDeletes',
                'superReads', 'ruleReads',
            ],
            0
        );
    }

    /**
     * Select attribute `number_of_seats` (options 10 => 2, 11 => 4, 12 => 6).
     *
     * @param array<string,mixed> $rule
     * @return void
     */
    protected function addSeats(array $rule = []): void
    {
        $this->addAttribute(
            new AttributeDescriptor(101, 'number_of_seats', 'Number of Seats', 'select', 't', [
                10 => '2',
                11 => '4',
                12 => '6',
            ]),
            $rule
        );
    }

    /**
     * Boolean attribute `is_assembled`.
     *
     * @param array<string,mixed> $rule
     * @return void
     */
    protected function addAssembled(array $rule = []): void
    {
        $this->addAttribute(new AttributeDescriptor(102, 'is_assembled', 'Is Assembled', 'boolean', 't'), $rule);
    }

    /**
     * Multiselect attribute `bedroom_suite_incl` (options 412 Bedside Table, 413 Dresser).
     *
     * @param array<string,mixed> $rule
     * @return void
     */
    protected function addSuite(array $rule = []): void
    {
        $this->addAttribute(
            new AttributeDescriptor(103, 'bedroom_suite_incl', 'Bedroom Suite Includes', 'multiselect', 't', [
                412 => 'Bedside Table',
                413 => 'Dresser',
            ]),
            $rule
        );
    }

    /**
     * Register an attribute with a rule (mode review by default) present in set SET.
     *
     * @param AttributeDescriptor $descriptor
     * @param array<string,mixed> $rule
     * @return void
     */
    protected function addAttribute(AttributeDescriptor $descriptor, array $rule = []): void
    {
        $code = $descriptor->getCode();
        $this->descriptors[$code] = $descriptor;
        $this->setsForAttribute[$code] ??= [self::SET];
        $this->rules[$code] = $rule + [
            'rule_id' => count($this->rules) + 1,
            'attribute_code' => $code,
            'mode' => Rule::MODE_REVIEW,
            'threshold' => 0.9,
            'fill_mode' => Rule::FILL_EMPTY_ONLY,
        ];
    }

    /**
     * Add a product in set SET.
     *
     * @param int $id
     * @param array<string,mixed> $values
     * @return void
     */
    protected function addProduct(int $id, array $values = []): void
    {
        $this->products[$id] = $values + [
            'sku' => 'SKU-' . $id,
            'attribute_set_id' => (string)self::SET,
            'type_id' => 'simple',
            'visibility' => '4',
            'name' => 'Product ' . $id,
            'description' => 'Description of product ' . $id,
        ];
    }

    /**
     * Run a freshly wired enricher (a new process: no in-memory ledger cache).
     *
     * @param int[]|null $productIds null = every product
     * @param RunOptions|null $options
     * @return RunReport
     */
    protected function enrich(?array $productIds = null, ?RunOptions $options = null): RunReport
    {
        return $this->enricher()->run($productIds ?? array_keys($this->products), $options ?? new RunOptions());
    }

    /**
     * Requests of every batch, flattened in order.
     *
     * @return Request[]
     */
    protected function sentRequests(): array
    {
        return $this->batches === [] ? [] : array_merge(...array_map('array_values', $this->batches));
    }

    /**
     * Product ids of every sent request.
     *
     * @return int[]
     */
    protected function askedProductIds(): array
    {
        return $this->batches === [] ? [] : array_merge(...array_map('array_keys', $this->batches));
    }

    /**
     * Suggestion rows of a pair.
     *
     * @param int $productId
     * @param string $code
     * @return array<int, array<string,mixed>>
     */
    protected function suggestionsFor(int $productId, string $code): array
    {
        return array_values(array_filter(
            $this->suggestions,
            static fn (array $row): bool => (int)$row['product_id'] === $productId
                && (string)$row['attribute_code'] === $code
        ));
    }

    /**
     * The fingerprint the enricher should compute for a pair.
     *
     * @param int $productId
     * @param string $code
     * @param string|null $model
     * @return string
     */
    protected function expectedFingerprint(int $productId, string $code, ?string $model = null): string
    {
        $rule = $this->rule($this->rules[$code]);
        $descriptor = $this->descriptors[$code];
        $plan = (new QuestionBuilder(new InstructionsBuilder()))
            ->build($descriptor, $rule->getInstructions(), $rule->getRules());

        return (new Fingerprint(new CanonicalJson()))->compute(
            $this->stateBuilder()->build($this->products[$productId]),
            $descriptor,
            $plan->getEffectiveInstructions(),
            $rule->getRules(),
            $model ?? $this->model
        );
    }

    /**
     * @return Enricher
     */
    protected function enricher(): Enricher
    {
        $coreConfig = $this->createStub(ConfigInterface::class);
        $coreConfig->method('isConfigured')->willReturnCallback(fn (): bool => $this->configured);
        $coreConfig->method('getModel')->willReturnCallback(fn (): string => $this->model);
        $logger = $this->createStub(LoggerInterface::class);
        $questionBuilder = new QuestionBuilder(new InstructionsBuilder());

        return new Enricher(
            $this->client(),
            $this->planner($logger),
            $this->loader(),
            $this->stateBuilder(),
            $questionBuilder,
            new Fingerprint(new CanonicalJson()),
            $this->ledger(),
            new AnswerNormalizer(),
            new DecisionEngine(),
            $this->writer($logger),
            $coreConfig,
            $logger,
            new StopCondition(),
            $this->moduleConfig(),
            $this->catalogQuery()
        );
    }

    /**
     * Planner over the in-memory rules and descriptors.
     *
     * @param LoggerInterface $logger
     * @return AttributePlanner
     */
    protected function planner(LoggerInterface $logger): AttributePlanner
    {
        $repository = $this->createStub(RuleRepository::class);
        $repository->method('getActive')->willReturnCallback(function (): array {
            $this->calls['ruleReads']++;
            $rules = array_map(fn (array $data): Rule => $this->rule($data), array_values($this->rules));

            return array_values(array_filter($rules, static fn (Rule $rule): bool => $rule->isActive()));
        });
        $repository->method('getByAttributeCode')->willReturnCallback(function (string $code): ?Rule {
            $this->calls['ruleReads']++;

            return isset($this->rules[$code]) ? $this->rule($this->rules[$code]) : null;
        });
        $catalog = $this->createStub(AttributeCatalog::class);
        $catalog->method('get')->willReturnCallback(function (string $code): AttributeDescriptor {
            return $this->descriptors[$code]
                ?? throw new IneligibleAttributeException(__('Attribute "%1" does not exist.', $code));
        });
        $query = $this->createStub(CatalogQuery::class);
        $query->method('setIdsForAttribute')->willReturnCallback(function (int $attributeId): array {
            foreach ($this->descriptors as $code => $descriptor) {
                if ($descriptor->getAttributeId() === $attributeId) {
                    return $this->setsForAttribute[$code];
                }
            }

            return [];
        });

        return new AttributePlanner($repository, $catalog, $query, $logger);
    }

    /**
     * Real Rule model over stored data.
     *
     * @param array<string,mixed> $data
     * @return Rule
     */
    protected function rule(array $data): Rule
    {
        return new Rule(
            $this->createStub(Context::class),
            $this->createStub(Registry::class),
            new RuleResource($this->createStub(DbContext::class)),
            null,
            $data
        );
    }

    /**
     * @return ProductStateBuilder
     */
    private function stateBuilder(): ProductStateBuilder
    {
        return new ProductStateBuilder(new TextCleaner(), $this->moduleConfig());
    }

    /**
     * @return Config
     */
    private function moduleConfig(): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('getStateAttributes')->willReturnCallback(fn (): array => $this->stateAttributes);
        $config->method('isIncludeNotVisible')->willReturnCallback(fn (): bool => $this->includeNotVisible);

        return $config;
    }

    /**
     * @return CatalogQuery
     */
    private function catalogQuery(): CatalogQuery
    {
        $query = $this->createStub(CatalogQuery::class);
        $query->method('fetchSuperAttributeIds')->willReturnCallback(function (array $ids): array {
            $this->calls['superReads']++;

            return array_intersect_key($this->superAttributes, array_flip($ids));
        });

        return $query;
    }

    /**
     * @return ProductDataLoader
     */
    private function loader(): ProductDataLoader
    {
        $loader = $this->createStub(ProductDataLoader::class);
        $loader->method('loadMany')->willReturnCallback(function (array $ids, array $codes, array $scoped): array {
            $this->calls['loads']++;
            $result = [];
            foreach ($ids as $id) {
                if (!isset($this->products[$id])) {
                    continue;
                }
                foreach (array_unique([...ProductDataLoader::STATIC_CODES, ...$codes, ...$scoped]) as $code) {
                    $result[$id][$code] = $this->products[$id][$code] ?? null;
                }
                foreach ($scoped as $code) {
                    $result[$id][ProductDataLoader::STORE_VIEW_VALUES][$code]
                        = $this->storeViewValues[$id][$code] ?? [];
                }
            }

            return $result;
        });

        return $loader;
    }

    /**
     * @return AttemptLedger
     */
    private function ledger(): AttemptLedger
    {
        $resource = $this->createStub(AttemptResource::class);
        $resource->method('loadForProducts')->willReturnCallback(function (array $ids, array $codes): array {
            $this->calls['ledgerReads']++;
            $result = [];
            foreach ($ids as $id) {
                foreach ($codes as $code) {
                    if (isset($this->attempts[$id][$code])) {
                        $result[$id][$code] = $this->attempts[$id][$code];
                    }
                }
            }

            return $result;
        });
        $resource->method('upsert')->willReturnCallback(function (array $row): void {
            $this->calls['ledgerWrites']++;
            $this->attempts[$row['product_id']][$row['attribute_code']] = $row;
        });
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturnCallback(fn (): int => $this->now);

        return new AttemptLedger($resource, $dateTime);
    }

    /**
     * Real writer and rejection guard over the in-memory suggestion table; the applier only flips the status.
     *
     * @param LoggerInterface $logger
     * @return SuggestionWriter
     */
    private function writer(LoggerInterface $logger): SuggestionWriter
    {
        $resource = $this->createStub(SuggestionResource::class);
        $resource->method('findFor')->willReturnCallback(function (int $productId, string $code): array {
            $this->calls['suggestionReads']++;

            return array_reverse($this->suggestionsFor($productId, $code));
        });
        $resource->method('findForProducts')->willReturnCallback(function (array $ids, array $codes): array {
            $this->calls['suggestionReads']++;
            $rows = [];
            foreach (array_reverse($this->suggestions) as $row) {
                if (in_array((int)$row['product_id'], $ids, true) && in_array($row['attribute_code'], $codes, true)) {
                    $rows[(int)$row['product_id']][$row['attribute_code']][] = $row;
                }
            }

            return $rows;
        });
        $deleteOpen = function (int $productId, string $code, ?string $keep = null): int {
            $this->calls['suggestionDeletes']++;
            $deleted = 0;
            foreach ($this->suggestions as $id => $row) {
                if ((int)$row['product_id'] === $productId && $row['attribute_code'] === $code
                    && in_array($row['status'], [Suggestion::STATUS_PENDING, Suggestion::STATUS_SHADOW], true)
                    && $row['fingerprint'] !== $keep
                ) {
                    unset($this->suggestions[$id]);
                    $deleted++;
                }
            }

            return $deleted;
        };
        $resource->method('deleteStale')->willReturnCallback($deleteOpen);
        $resource->method('deleteOpen')->willReturnCallback($deleteOpen);
        $resource->method('deleteOpenForProducts')->willReturnCallback(
            function (string $code, array $productIds) use ($deleteOpen): int {
                $this->calls['dropFilled'][] = [$code, $productIds];
                $this->calls['suggestionDeletes']++;
                $deleted = 0;
                foreach ($productIds as $productId) {
                    $deleted += $deleteOpen((int)$productId, $code);
                }

                return $deleted;
            }
        );
        $resource->method('save')->willReturnCallback(function (Suggestion $s) use ($resource): SuggestionResource {
            $this->calls['suggestionWrites']++;
            if (!$s->getData('suggestion_id')) {
                $s->setData('suggestion_id', $this->nextSuggestionId++);
            }
            $this->suggestions[(int)$s->getData('suggestion_id')] = $s->getData();

            return $resource;
        });
        $resource->method('saveIfStatus')->willReturnCallback(function (Suggestion $s, string $expected): bool {
            $id = (int)$s->getData('suggestion_id');
            if (($this->suggestions[$id]['status'] ?? null) !== $expected) {
                return false;
            }
            $this->calls['suggestionWrites']++;
            $this->suggestions[$id] = $s->getData();

            return true;
        });

        $factory = $this->createStub(SuggestionFactory::class);
        $factory->method('create')->willReturnCallback(fn (): Suggestion => new Suggestion(
            $this->createStub(Context::class),
            $this->createStub(Registry::class),
            new SuggestionResource($this->createStub(DbContext::class))
        ));
        $applier = $this->createStub(Applier::class);
        $applier->method('apply')->willReturnCallback(function (Suggestion $s) use ($resource): ApplyResult {
            if (!$this->applyOk) {
                return new ApplyResult(false, 'refused');
            }
            $s->setData('status', Suggestion::STATUS_APPLIED);
            $resource->save($s);

            return new ApplyResult(true);
        });

        return new SuggestionWriter(new RejectionGuard(), $resource, $factory, $applier, $logger);
    }

    /**
     * Client answering from the script and recording every batch.
     *
     * @return ClientInterface
     */
    private function client(): ClientInterface
    {
        $client = $this->createStub(ClientInterface::class);
        $client->method('evaluateMany')->willReturnCallback(function (array $requests): array {
            $this->batches[] = $requests;
            if ($this->batchFailure !== null) {
                throw $this->batchFailure;
            }
            $results = [];
            foreach ($requests as $productId => $request) {
                $results[$productId] = $this->failures[$productId] ?? $this->respond((int)$productId, $request);
            }

            return $results;
        });

        return $client;
    }

    /**
     * Scripted response for one request.
     *
     * @param int $productId
     * @param Request $request
     * @return Response
     */
    private function respond(int $productId, Request $request): Response
    {
        $answers = [];
        foreach ($request->getQuestions() as $id => $question) {
            $id = (string)$id;
            if (in_array($id, $this->dropAnswers[$productId] ?? [], true)) {
                continue;
            }
            if ($question instanceof NoulQuestion) {
                [$code, $optionId] = explode(':', $id, 2);
                $p = $this->script[$productId][$code]['noul'][(int)$optionId] ?? 0.02;
                $answer = $this->createStub(NoulAnswer::class);
                $answer->method('getProbability')->willReturn((float)$p);
                $answers[$id] = $answer;
                continue;
            }
            $reversed = str_ends_with($id, QuestionBuilder::REVERSED_SUFFIX);
            $code = $reversed ? substr($id, 0, -strlen(QuestionBuilder::REVERSED_SUFFIX)) : $id;
            $spec = $this->script[$productId][$code] ?? QuestionBuilder::NOT_STATED;
            $spec = is_string($spec) ? ['fwd' => $spec, 'rev' => $spec] : $spec;
            $answers[$id] = $this->choiceAnswer(
                (string)($reversed ? $spec['rev'] : $spec['fwd']),
                (float)($spec['p'] ?? 0.96),
                array_keys((array)$question->toArray()['criteria'])
            );
        }
        $response = $this->createStub(Response::class);
        $response->method('getAnswers')->willReturn($answers);
        $response->method('getModel')->willReturn((string)$request->getModel());
        $response->method('getInputTokens')->willReturn(770);
        $response->method('isCached')->willReturn(in_array($productId, $this->cached, true));

        return $response;
    }

    /**
     * Choice answer: the choice gets p, the rest share 1 − p (numeric labels stay PHP int keys).
     *
     * @param string $choice
     * @param float $p
     * @param array<int|string> $labels
     * @return ChoiceAnswer
     */
    private function choiceAnswer(string $choice, float $p, array $labels): ChoiceAnswer
    {
        $rest = count($labels) > 1 ? (1.0 - $p) / (count($labels) - 1) : 0.0;
        $probabilities = [];
        foreach ($labels as $label) {
            $probabilities[$label] = (string)$label === $choice ? $p : $rest;
        }
        $answer = $this->createStub(ChoiceAnswer::class);
        $answer->method('getChoice')->willReturn($choice);
        $answer->method('getProbabilities')->willReturn($probabilities);

        return $answer;
    }
}

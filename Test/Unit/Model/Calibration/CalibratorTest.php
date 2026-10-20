<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\Calibration;

use DmLab\CatalogEnrich\Exception\IneligibleAttributeException;
use DmLab\CatalogEnrich\Model\Attribute\AttributeCatalog;
use DmLab\CatalogEnrich\Model\Attribute\AttributeDescriptor;
use DmLab\CatalogEnrich\Model\Calibration\CalibrationResult;
use DmLab\CatalogEnrich\Model\Calibration\Calibrator;
use DmLab\CatalogEnrich\Model\Config;
use DmLab\CatalogEnrich\Model\Decision\AnswerNormalizer;
use DmLab\CatalogEnrich\Model\Decision\DecisionEngine;
use DmLab\CatalogEnrich\Model\Question\InstructionsBuilder;
use DmLab\CatalogEnrich\Model\Question\QuestionBuilder;
use DmLab\CatalogEnrich\Model\ResourceModel\CatalogQuery;
use DmLab\CatalogEnrich\Model\ResourceModel\Rule as RuleResource;
use DmLab\CatalogEnrich\Model\Rule;
use DmLab\CatalogEnrich\Model\RuleRepository;
use DmLab\CatalogEnrich\Model\Run\StopCondition;
use DmLab\CatalogEnrich\Model\State\ProductDataLoader;
use DmLab\CatalogEnrich\Model\State\ProductStateBuilder;
use DmLab\CatalogEnrich\Model\State\TextCleaner;
use DmLab\TypeSafeCore\Api\ClientInterface;
use DmLab\TypeSafeCore\Api\ConfigInterface;
use DmLab\TypeSafeCore\Api\Data\Answer\ChoiceAnswer;
use DmLab\TypeSafeCore\Api\Data\Answer\NoulAnswer;
use DmLab\TypeSafeCore\Api\Data\Question\NoulQuestion;
use DmLab\TypeSafeCore\Api\Data\Request;
use DmLab\TypeSafeCore\Api\Data\Response;
use DmLab\TypeSafeCore\Exception\AuthenticationException;
use DmLab\TypeSafeCore\Exception\BudgetExceededException;
use DmLab\TypeSafeCore\Exception\CircuitOpenException;
use DmLab\TypeSafeCore\Exception\NotConfiguredException;
use DmLab\TypeSafeCore\Exception\RequestException;
use DmLab\TypeSafeCore\Exception\TypeSafeException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\Db\Context as DbContext;
use Magento\Framework\Registry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CalibratorTest extends TestCase
{
    /**
     * @var AttributeDescriptor|null
     */
    private ?AttributeDescriptor $descriptor = null;

    /**
     * @var array<string,mixed>|null
     */
    private ?array $ruleData = null;

    /**
     * Product id => raw store-0 values; ids are returned newest (highest) first.
     *
     * @var array<int, array<string,mixed>>
     */
    private array $products = [];

    /**
     * Product id => answer script. Choice: a label for both orders, or ['fwd' => …, 'rev' => …, 'p' => float].
     * Multiselect: ['noul' => [optionId => p]].
     *
     * @var array<int, mixed>
     */
    private array $script = [];

    /**
     * Product id => result returned instead of a response.
     *
     * @var array<int, TypeSafeException>
     */
    private array $failures = [];

    /**
     * @var TypeSafeException|null
     */
    private ?TypeSafeException $batchFailure = null;

    /**
     * @var string[]
     */
    private array $stateAttributes = [];

    /**
     * @var bool
     */
    private bool $configured = true;

    /**
     * @var string
     */
    private string $model = 'jev-1.13.0';

    /**
     * evaluateMany calls, each the requests it received.
     *
     * @var array<int, array<int, Request>>
     */
    private array $batches = [];

    /**
     * Sample sizes passed to fetchFilledIds.
     *
     * @var int[]
     */
    private array $fetchedLimits = [];

    /**
     * @var int
     */
    private int $catalogCalls = 0;

    /**
     * @var bool
     */
    private bool $includeNotVisible = false;

    /**
     * Flags passed to fetchFilledIds: [visibleOnly, excludeZero].
     *
     * @var array<int, array{bool, bool}>
     */
    private array $fetchedFlags = [];

    /**
     * Load calls (each a batch).
     *
     * @var int
     */
    private int $loads = 0;

    private function seats(): void
    {
        $this->descriptor = new AttributeDescriptor(101, 'number_of_seats', 'Number of Seats', 'select', 't', [
            10 => '2',
            11 => '4',
            12 => '6',
        ]);
    }

    private function assembled(): void
    {
        $this->descriptor = new AttributeDescriptor(102, 'is_assembled', 'Is Assembled', 'boolean', 't');
    }

    private function suite(): void
    {
        $this->descriptor = new AttributeDescriptor(
            103,
            'bedroom_suite_incl',
            'Bedroom Suite Includes',
            'multiselect',
            't',
            [412 => 'Bedside Table', 413 => 'Dresser', 414 => 'Mirror']
        );
    }

    /**
     * @param int $id
     * @param string|null $gold stored value of the calibrated attribute
     * @param mixed $answer script entry
     * @param array<string,mixed> $values
     */
    private function product(int $id, ?string $gold, mixed $answer = null, array $values = []): void
    {
        $this->products[$id] = $values + [
            'sku' => 'SKU-' . $id,
            'attribute_set_id' => '4',
            'name' => 'Product ' . $id,
            'description' => 'Description of product ' . $id,
            $this->descriptor->getCode() => $gold,
        ];
        if ($answer !== null) {
            $this->script[$id] = $answer;
        }
    }

    private function calibrate(int $sample = 200): CalibrationResult
    {
        return $this->calibrator()->calibrate($this->descriptor->getCode(), $sample);
    }

    /**
     * @return array<string, array<string,mixed>> threshold => row
     */
    private function rows(CalibrationResult $result): array
    {
        $rows = [];
        foreach ($result->getRows() as $row) {
            $rows[sprintf('%.2f', $row['threshold'])] = $row;
        }

        return $rows;
    }

    public function testSelectMetricsPerThresholdWithBothOrdersRule(): void
    {
        $this->seats();
        for ($id = 1; $id <= 10; $id++) {
            $this->product($id, '12', ['fwd' => '6', 'rev' => '6', 'p' => 0.96]);
        }
        $this->product(11, '11', ['fwd' => '6', 'rev' => '6', 'p' => 0.75]);
        $this->product(12, '10', ['fwd' => '2', 'rev' => '4', 'p' => 0.99]);
        $this->product(13, '10', QuestionBuilder::NOT_STATED);
        $this->product(14, '10', ['fwd' => '2', 'rev' => '2', 'p' => 0.85]);

        $result = $this->calibrate();
        $rows = $this->rows($result);

        self::assertSame(14, $result->getProducts());
        self::assertSame(0, $result->getErrors());
        self::assertSame(14 * 770, $result->getInputTokens());
        self::assertSame([12, 12, 11, 10, 10], array_column($result->getRows(), 'decided'));
        self::assertSame([11, 11, 11, 10, 10], array_column($result->getRows(), 'correct'));
        self::assertEqualsWithDelta(11 / 12, $rows['0.50']['precision'], 1e-9);
        self::assertEqualsWithDelta(12 / 14, $rows['0.50']['coverage'], 1e-9);
        self::assertEqualsWithDelta(1.0, $rows['0.80']['precision'], 1e-9);
        self::assertEqualsWithDelta(11 / 14, $rows['0.80']['coverage'], 1e-9);
        self::assertSame(0.8, $result->suggestedThreshold());
        self::assertSame([], $result->getGoldGaps());
    }

    public function testSelectWithFewerThanTenDecidedSuggestsNothing(): void
    {
        $this->seats();
        for ($id = 1; $id <= 9; $id++) {
            $this->product($id, '12', '6');
        }

        $result = $this->calibrate();

        self::assertSame(9, $this->rows($result)['0.50']['decided']);
        self::assertEqualsWithDelta(1.0, $this->rows($result)['0.50']['precision'], 1e-9);
        self::assertNull($result->suggestedThreshold());
    }

    public function testMultiselectCountsConfirmedGapsAndMissedSeparately(): void
    {
        $this->suite();
        $this->product(1, '412', ['noul' => [412 => 0.97, 413 => 0.96]]);
        $this->product(2, '412,414', ['noul' => [412 => 0.6, 414 => 0.98]]);
        $this->product(3, '413', ['noul' => []]);

        $result = $this->calibrate();
        $rows = $this->rows($result);

        self::assertTrue($result->isMultiselect());
        self::assertSame(3, $result->getProducts());
        self::assertSame(2, $rows['0.50']['decided']);
        self::assertSame(3, $rows['0.50']['correct']);
        self::assertSame(1, $rows['0.50']['gaps']);
        self::assertSame(1, $rows['0.50']['missed']);
        self::assertEqualsWithDelta(0.75, $rows['0.50']['precision'], 1e-9);
        self::assertEqualsWithDelta(0.75, $rows['0.50']['recall'], 1e-9);

        self::assertSame(2, $rows['0.90']['decided']);
        self::assertSame(2, $rows['0.90']['correct']);
        self::assertSame(1, $rows['0.90']['gaps']);
        self::assertSame(2, $rows['0.90']['missed']);
        self::assertEqualsWithDelta(2 / 3, $rows['0.90']['precision'], 1e-9);
        self::assertEqualsWithDelta(0.5, $rows['0.90']['recall'], 1e-9);

        self::assertSame(1, $rows['0.95']['gaps']);
        self::assertSame(0.9, $result->getGapThreshold());
        self::assertSame(['SKU-1: Dresser'], $result->getGoldGaps());
    }

    public function testMultiselectGoldGapsUseTheRuleThreshold(): void
    {
        $this->suite();
        $this->ruleData = ['threshold' => '0.6500'];
        $this->product(1, '412', ['noul' => [412 => 0.97, 413 => 0.66, 414 => 0.6]]);

        $result = $this->calibrate();

        self::assertSame(0.65, $result->getGapThreshold());
        self::assertSame(['SKU-1: Dresser'], $result->getGoldGaps());
        self::assertSame(0, $this->rows($result)['0.70']['gaps']);
    }

    public function testBooleanAbstainsOnNotStatedOrDisagreementAndNeverCountsAWrongNo(): void
    {
        $this->assembled();
        $this->product(1, '0', 'No');
        $this->product(2, '0', QuestionBuilder::NOT_STATED);
        $this->product(3, '1', ['fwd' => 'Yes', 'rev' => QuestionBuilder::NOT_STATED, 'p' => 0.99]);
        $this->product(4, '1', 'No');
        $this->product(5, '1', 'Yes');

        $result = $this->calibrate();
        $row = $this->rows($result)['0.90'];

        self::assertSame(5, $result->getProducts());
        self::assertSame(3, $row['decided']);
        self::assertSame(2, $row['correct']);
        self::assertEqualsWithDelta(2 / 3, $row['precision'], 1e-9);
        self::assertEqualsWithDelta(0.6, $row['coverage'], 1e-9);
        self::assertSame(['is_assembled', 'is_assembled:rev'], array_keys($this->batches[0][1]->getQuestions()));
    }

    public function testRequestShapeWithDefaults(): void
    {
        $this->model = 'jev-9.9.9';
        $this->seats();
        $this->stateAttributes = ['number_of_seats', 'material'];
        $this->product(5, '12', '6', ['material' => 'Oak']);

        $this->calibrate(25);

        self::assertSame([25], $this->fetchedLimits);
        $request = $this->batches[0][5];
        self::assertSame('catalog_enrich_calibrate', $request->getConsumer());
        self::assertSame('jev-9.9.9', $request->getModel());
        self::assertSame(
            ['name' => 'Product 5', 'description' => 'Description of product 5', 'material' => 'Oak'],
            $request->getState()
        );
        self::assertSame(['number_of_seats', 'number_of_seats:rev'], array_keys($request->getQuestions()));
        self::assertSame(
            'What is the number of seats of this product?',
            $request->getQuestions()['number_of_seats']->toArray()['instructions']
        );
    }

    public function testRuleInstructionsAndRulesAreUsed(): void
    {
        $this->seats();
        $this->ruleData = [
            'instructions' => 'How many people can sit?',
            'extra_rules' => "A dining setting of N pieces is one table plus N-1 seats.",
        ];
        $this->product(1, '12', '6');

        $this->calibrate();

        self::assertSame(
            [
                'question' => 'How many people can sit?',
                'rules' => ['A dining setting of N pieces is one table plus N-1 seats.'],
            ],
            $this->batches[0][1]->getQuestions()['number_of_seats']->toArray()['instructions']
        );
    }

    public function testChunksOfFifty(): void
    {
        $this->seats();
        for ($id = 1; $id <= 120; $id++) {
            $this->product($id, '12', '6');
        }

        $result = $this->calibrate(500);

        self::assertSame([50, 50, 20], array_map('count', $this->batches));
        self::assertSame(120, $result->getProducts());
    }

    public function testUnusableProductsAreSkipped(): void
    {
        $this->seats();
        $this->product(1, '12', '6');
        $this->product(2, null);
        $this->product(3, '12', '6', ['name' => '', 'description' => '<p></p>']);
        $this->product(4, ' ', '6');

        $result = $this->calibrate();

        self::assertSame([1], array_keys($this->batches[0]));
        self::assertSame(1, $result->getProducts());
    }

    public function testNoFilledProductsSendsNothing(): void
    {
        $this->seats();

        $result = $this->calibrate();

        self::assertSame([], $this->batches);
        self::assertSame(0, $result->getProducts());
        self::assertNull($result->suggestedThreshold());
    }

    public function testNotConfiguredAbortsBeforeAnyWork(): void
    {
        $this->seats();
        $this->product(1, '12', '6');
        $this->configured = false;

        try {
            $this->calibrate();
            self::fail('Expected a LocalizedException.');
        } catch (LocalizedException $e) {
            self::assertStringContainsString('TypeSafe is not configured', $e->getMessage());
        }
        self::assertSame([], $this->batches);
        self::assertSame(0, $this->catalogCalls);
        self::assertSame([], $this->fetchedLimits);
    }

    public function testIneligibleAttributeThrows(): void
    {
        $this->seats();

        $this->expectException(IneligibleAttributeException::class);
        $this->calibrator()->calibrate('unknown', 10);
    }

    public function testInvalidRuleInstructionsThrow(): void
    {
        $this->suite();
        $this->ruleData = ['instructions' => 'No placeholder here'];

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The rule instructions are invalid');
        $this->calibrate();
    }

    public function testNonPositiveSampleThrows(): void
    {
        $this->seats();

        $this->expectException(LocalizedException::class);
        $this->calibrate(0);
    }

    public function testPerProductFailureAndMalformedAnswerCountAsErrors(): void
    {
        $this->seats();
        $this->product(1, '12', '6');
        $this->product(2, '12', '6');
        $this->product(3, '12', ['malformed' => true]);
        $this->failures[2] = new RequestException(__('Bad request'));

        $result = $this->calibrate();

        self::assertSame(1, $result->getProducts());
        self::assertSame(2, $result->getErrors());
        self::assertSame(1, $this->rows($result)['0.90']['decided']);
    }

    public function testBatchFailureCountsEveryProductAsError(): void
    {
        $this->seats();
        $this->product(1, '12', '6');
        $this->product(2, '12', '6');
        $this->batchFailure = new RequestException(__('Bad request'));

        $result = $this->calibrate();

        self::assertSame(0, $result->getProducts());
        self::assertSame(2, $result->getErrors());
    }

    /**
     * @return array<string, array{0: TypeSafeException}>
     */
    public static function stopFailures(): array
    {
        return [
            'budget' => [new BudgetExceededException(__('Monthly budget exceeded'))],
            'circuit' => [new CircuitOpenException(__('Monthly budget exceeded'))],
            'not configured' => [new NotConfiguredException(__('Monthly budget exceeded'))],
            'authentication' => [new AuthenticationException(__('Monthly budget exceeded'))],
        ];
    }

    #[DataProvider('stopFailures')]
    public function testStopFailureAbortsWithMessage(TypeSafeException $failure): void
    {
        $this->seats();
        for ($id = 1; $id <= 60; $id++) {
            $this->product($id, '12', '6');
        }
        $this->failures[30] = $failure;

        try {
            $this->calibrate();
            self::fail('Expected a LocalizedException.');
        } catch (LocalizedException $e) {
            self::assertSame('Calibration stopped: Monthly budget exceeded', $e->getMessage());
        }
        self::assertCount(1, $this->batches);
    }

    public function testSampleSkipsNotVisibleProductsUnlessConfiguredAndLoadsPerChunk(): void
    {
        $this->seats();
        for ($id = 1; $id <= 60; $id++) {
            $this->product($id, '12', '6');
        }

        $this->calibrate();
        $this->includeNotVisible = true;
        $this->calibrate();

        self::assertSame([[true, false], [false, false]], $this->fetchedFlags);
        self::assertSame(4, $this->loads);
    }

    public function testTreatZeroAsEmptyExcludesZeroGold(): void
    {
        $this->assembled();
        $this->ruleData = ['treat_zero_as_empty' => 1];
        $this->product(1, '1', 'Yes');
        $this->product(2, '0', 'No');
        $this->product(3, '0', 'No');

        $result = $this->calibrate();

        self::assertSame([[true, true]], $this->fetchedFlags);
        self::assertSame(2, $result->getExcludedZero());
        self::assertSame(1, $result->getProducts());
        self::assertSame([1], array_keys($this->batches[0]));
    }

    public function testZeroGoldIsKeptWithoutTheFlag(): void
    {
        $this->assembled();
        $this->ruleData = [];
        $this->product(1, '1', 'Yes');
        $this->product(2, '0', 'No');

        $result = $this->calibrate();

        self::assertSame([[true, false]], $this->fetchedFlags);
        self::assertSame(0, $result->getExcludedZero());
        self::assertSame(2, $result->getProducts());
    }

    public function testStopFailureFromTheBatchCallAborts(): void
    {
        $this->seats();
        $this->product(1, '12', '6');
        $this->batchFailure = new BudgetExceededException(__('Monthly budget exceeded'));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Calibration stopped: Monthly budget exceeded');
        $this->calibrate();
    }

    private function calibrator(): Calibrator
    {
        $coreConfig = $this->createStub(ConfigInterface::class);
        $coreConfig->method('isConfigured')->willReturnCallback(fn (): bool => $this->configured);
        $coreConfig->method('getModel')->willReturnCallback(fn (): string => $this->model);

        $catalog = $this->createStub(AttributeCatalog::class);
        $catalog->method('get')->willReturnCallback(function (string $code): AttributeDescriptor {
            $this->catalogCalls++;
            if ($this->descriptor === null || $this->descriptor->getCode() !== $code) {
                throw new IneligibleAttributeException(__('Attribute "%1" does not exist.', $code));
            }

            return $this->descriptor;
        });

        $repository = $this->createStub(RuleRepository::class);
        $repository->method('getByAttributeCode')->willReturnCallback(
            fn (string $code): ?Rule => $this->ruleData === null ? null : $this->rule($code)
        );

        $query = $this->createStub(CatalogQuery::class);
        $query->method('fetchFilledIds')->willReturnCallback(
            function (AttributeDescriptor $descriptor, int $limit, bool $visibleOnly, bool $excludeZero): array {
                $this->fetchedLimits[] = $limit;
                $this->fetchedFlags[] = [$visibleOnly, $excludeZero];
                $ids = array_keys(array_filter(
                    $this->products,
                    static fn (array $p): bool => ($p[$descriptor->getCode()] ?? null) !== null
                        && !($excludeZero && $p[$descriptor->getCode()] === '0')
                ));
                rsort($ids);

                return array_slice($ids, 0, $limit);
            }
        );
        $query->method('countZeroGold')->willReturnCallback(
            fn (AttributeDescriptor $descriptor): int => count(array_filter(
                $this->products,
                static fn (array $p): bool => ($p[$descriptor->getCode()] ?? null) === '0'
            ))
        );

        $loader = $this->createStub(ProductDataLoader::class);
        $loader->method('loadMany')->willReturnCallback(function (array $ids, array $codes): array {
            $this->loads++;
            $result = [];
            foreach ($ids as $id) {
                if (!isset($this->products[$id])) {
                    continue;
                }
                foreach (array_unique([...ProductDataLoader::STATIC_CODES, ...$codes]) as $code) {
                    $result[$id][$code] = $this->products[$id][$code] ?? null;
                }
            }

            return $result;
        });

        $config = $this->createStub(Config::class);
        $config->method('getStateAttributes')->willReturnCallback(fn (): array => $this->stateAttributes);
        $config->method('isIncludeNotVisible')->willReturnCallback(fn (): bool => $this->includeNotVisible);

        return new Calibrator(
            $catalog,
            $repository,
            $query,
            $loader,
            new ProductStateBuilder(new TextCleaner(), $config),
            new QuestionBuilder(new InstructionsBuilder()),
            new AnswerNormalizer(),
            new DecisionEngine(),
            $this->client(),
            $coreConfig,
            new StopCondition(),
            $config
        );
    }

    private function rule(string $code): Rule
    {
        return new Rule(
            $this->createStub(Context::class),
            $this->createStub(Registry::class),
            new RuleResource($this->createStub(DbContext::class)),
            null,
            $this->ruleData + ['rule_id' => 1, 'attribute_code' => $code, 'mode' => Rule::MODE_SHADOW]
        );
    }

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

    private function respond(int $productId, Request $request): Response
    {
        $spec = $this->script[$productId] ?? QuestionBuilder::NOT_STATED;
        $answers = [];
        foreach ($request->getQuestions() as $id => $question) {
            $id = (string)$id;
            if (is_array($spec) && isset($spec['malformed'])) {
                continue;
            }
            if ($question instanceof NoulQuestion) {
                $optionId = (int)explode(':', $id, 2)[1];
                $answer = $this->createStub(NoulAnswer::class);
                $answer->method('getProbability')->willReturn((float)($spec['noul'][$optionId] ?? 0.02));
                $answers[$id] = $answer;
                continue;
            }
            $choice = is_string($spec) ? ['fwd' => $spec, 'rev' => $spec] : $spec;
            $reversed = str_ends_with($id, QuestionBuilder::REVERSED_SUFFIX);
            $answers[$id] = $this->choiceAnswer(
                (string)($reversed ? $choice['rev'] : $choice['fwd']),
                (float)($choice['p'] ?? 0.96),
                array_keys((array)$question->toArray()['criteria'])
            );
        }
        $response = $this->createStub(Response::class);
        $response->method('getAnswers')->willReturn($answers);
        $response->method('getModel')->willReturn((string)$request->getModel());
        $response->method('getInputTokens')->willReturn(770);

        return $response;
    }

    /**
     * @param array<int|string> $labels
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

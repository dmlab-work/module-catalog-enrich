<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\Report;

use DmLab\CatalogEnrich\Exception\IneligibleAttributeException;
use DmLab\CatalogEnrich\Model\Attribute\AttributeCatalog;
use DmLab\CatalogEnrich\Model\Attribute\AttributeDescriptor;
use DmLab\CatalogEnrich\Model\Report\ReportBuilder;
use DmLab\CatalogEnrich\Model\ResourceModel\CatalogQuery;
use DmLab\CatalogEnrich\Model\ResourceModel\Rule as RuleResource;
use DmLab\CatalogEnrich\Model\ResourceModel\Suggestion as SuggestionResource;
use DmLab\CatalogEnrich\Model\Rule;
use DmLab\CatalogEnrich\Model\RuleRepository;
use DmLab\TypeSafeCore\Api\UsageInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\Db\Context as DbContext;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;

class ReportBuilderTest extends TestCase
{
    /**
     * @var array<string, AttributeDescriptor>
     */
    private array $descriptors = [];

    /**
     * @var array<int, int[]> attribute id => set ids
     */
    private array $sets = [];

    /**
     * @var array<string, int> "set,set" => product count
     */
    private array $inSets = [];

    /**
     * @var array<string, int> "code|set,set" => filled count
     */
    private array $filled = [];

    /**
     * @var array<string, array<string,int>>
     */
    private array $statuses = [];

    /**
     * @var array<string, int>
     */
    private array $appliedToEmpty = [];

    private function rule(string $code, string $mode, string $setIds = '', int $treatZero = 0): Rule
    {
        return new Rule(
            $this->createStub(Context::class),
            $this->createStub(Registry::class),
            new RuleResource($this->createStub(DbContext::class)),
            null,
            [
                'attribute_code' => $code,
                'mode' => $mode,
                'attribute_set_ids' => $setIds,
                'treat_zero_as_empty' => $treatZero,
            ]
        );
    }

    /**
     * @param Rule[] $rules
     */
    private function builder(array $rules, ?UsageInterface $usage = null): ReportBuilder
    {
        $repository = $this->createStub(RuleRepository::class);
        $repository->method('getActive')->willReturn($rules);
        $catalog = $this->createStub(AttributeCatalog::class);
        $catalog->method('get')->willReturnCallback(function (string $code): AttributeDescriptor {
            return $this->descriptors[$code]
                ?? throw new IneligibleAttributeException(__('Attribute "%1" does not exist.', $code));
        });
        $query = $this->createStub(CatalogQuery::class);
        $query->method('setIdsForAttribute')->willReturnCallback(fn (int $id): array => $this->sets[$id] ?? []);
        $query->method('countInSets')
            ->willReturnCallback(fn (array $setIds): int => $setIds === [] ? 0 : $this->inSets[implode(',', $setIds)]);
        $query->method('countFilled')->willReturnCallback(
            fn (AttributeDescriptor $d, array $setIds, bool $excludeZero): int => $setIds === []
                ? 0
                : $this->filled[$d->getCode() . '|' . implode(',', $setIds) . ($excludeZero ? '|no0' : '')]
        );
        $suggestions = $this->createStub(SuggestionResource::class);
        $suggestions->method('countByStatus')
            ->willReturnCallback(fn (string $code): array => $this->statuses[$code] ?? []);
        $suggestions->method('countAppliedToEmpty')
            ->willReturnCallback(
                fn (string $code, array $setIds, bool $zero): int
                    => $this->appliedToEmpty[$code . '|' . implode(',', $setIds) . ($zero ? '|no0' : '')] ?? 0
            );

        return new ReportBuilder($repository, $catalog, $query, $suggestions, $usage ?? $this->usage(0, 0, null));
    }

    private function usage(int $enrich, int $calibrate, ?int $budget): UsageInterface
    {
        $usage = $this->createStub(UsageInterface::class);
        $usage->method('getMonthTokens')->willReturnMap([
            ['catalog_enrich', $enrich],
            ['catalog_enrich_calibrate', $calibrate],
        ]);
        $usage->method('getRemainingBudget')->willReturn($budget);

        return $usage;
    }

    private function addSeats(): void
    {
        $this->descriptors['number_of_seats'] = new AttributeDescriptor(
            11,
            'number_of_seats',
            'Number of seats',
            AttributeDescriptor::INPUT_SELECT,
            'catalog_product_entity_int',
            [6 => '6']
        );
        $this->sets[11] = [4, 9];
    }

    public function testTreatZeroAsEmptyCountsZeroAsEmptyForBooleans(): void
    {
        $this->descriptors['is_assembled'] = new AttributeDescriptor(
            12,
            'is_assembled',
            'Is assembled',
            AttributeDescriptor::INPUT_BOOLEAN,
            'catalog_product_entity_int'
        );
        $this->sets[12] = [4];
        $this->inSets['4'] = 100;
        $this->filled['is_assembled|4'] = 90;
        $this->filled['is_assembled|4|no0'] = 30;
        $this->appliedToEmpty['is_assembled|4|no0'] = 10;

        $withFlag = $this->builder([$this->rule('is_assembled', Rule::MODE_AUTO, '', 1)])->build()->getRows()[0];
        $without = $this->builder([$this->rule('is_assembled', Rule::MODE_AUTO)])->build()->getRows()[0];

        self::assertSame([30, 10, 0.2, 0.3], [
            $withFlag['filled'],
            $withFlag['filled_by_apply'],
            $withFlag['fill_before'],
            $withFlag['fill_now'],
        ]);
        self::assertSame([90, 0], [$without['filled'], $without['filled_by_apply']]);
    }

    public function testFillRateMathAndStatusCounts(): void
    {
        $this->addSeats();
        $this->inSets['4,9'] = 200;
        $this->filled['number_of_seats|4,9'] = 150;
        $this->statuses['number_of_seats'] = ['applied' => 34, 'pending' => 7, 'reverted' => 2];
        $this->appliedToEmpty['number_of_seats|4,9'] = 30;

        $report = $this->builder([$this->rule('number_of_seats', Rule::MODE_REVIEW)])->build();

        self::assertSame([[
            'attribute' => 'number_of_seats',
            'mode' => 'review',
            'eligible' => 200,
            'filled' => 150,
            'filled_by_apply' => 30,
            'fill_before' => 0.6,
            'fill_now' => 0.75,
            'statuses' => ['shadow' => 0, 'pending' => 7, 'applied' => 34, 'rejected' => 0, 'reverted' => 2],
        ]], $report->getRows());
        self::assertSame([], $report->getSkipped());
    }

    public function testRuleSetsNarrowTheEligibleProducts(): void
    {
        $this->addSeats();
        $this->inSets['9'] = 40;
        $this->filled['number_of_seats|9'] = 10;
        $this->appliedToEmpty['number_of_seats|4,9'] = 10;
        $this->appliedToEmpty['number_of_seats|9'] = 4;

        $row = $this->builder([$this->rule('number_of_seats', Rule::MODE_AUTO, '9,30')])->build()->getRows()[0];

        self::assertSame(40, $row['eligible']);
        self::assertSame(0.25, $row['fill_now']);
        self::assertSame(4, $row['filled_by_apply']);
        self::assertSame(0.15, $row['fill_before']);
    }

    public function testZeroEligibleGivesUndefinedFillRates(): void
    {
        $this->addSeats();
        $this->sets[11] = [];

        $row = $this->builder([$this->rule('number_of_seats', Rule::MODE_SHADOW)])->build()->getRows()[0];

        self::assertSame(0, $row['eligible']);
        self::assertNull($row['fill_before']);
        self::assertNull($row['fill_now']);
    }

    public function testFillBeforeNeverNegative(): void
    {
        $this->addSeats();
        $this->inSets['4,9'] = 10;
        $this->filled['number_of_seats|4,9'] = 3;
        $this->appliedToEmpty['number_of_seats|4,9'] = 5;

        $row = $this->builder([$this->rule('number_of_seats', Rule::MODE_AUTO)])->build()->getRows()[0];

        self::assertSame(0.0, $row['fill_before']);
        self::assertSame(0.3, $row['fill_now']);
    }

    public function testIneligibleRuleIsSkippedWithReason(): void
    {
        $this->addSeats();
        $this->inSets['4,9'] = 1;
        $this->filled['number_of_seats|4,9'] = 1;

        $report = $this->builder([
            $this->rule('gone', Rule::MODE_AUTO),
            $this->rule('number_of_seats', Rule::MODE_AUTO),
        ])->build();

        self::assertSame(['number_of_seats'], array_column($report->getRows(), 'attribute'));
        self::assertSame(['gone' => 'Attribute "gone" does not exist.'], $report->getSkipped());
    }

    public function testUsageFooterPerConsumer(): void
    {
        $report = $this->builder([], $this->usage(1_000_000, 250_000, 4_000_000))->build();

        self::assertSame([], $report->getRows());
        self::assertSame(1_000_000, $report->getEnrichTokens());
        self::assertSame(250_000, $report->getCalibrateTokens());
        self::assertSame(4_000_000, $report->getRemainingBudget());
    }

    public function testUnlimitedBudget(): void
    {
        self::assertNull($this->builder([], $this->usage(0, 0, null))->build()->getRemainingBudget());
    }
}

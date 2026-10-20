<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\Run;

use DmLab\CatalogEnrich\Model\Attribute\AttributeDescriptor;
use DmLab\CatalogEnrich\Model\Rule;
use DmLab\CatalogEnrich\Model\Run\AttributePlan;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;

class AttributePlannerTest extends EnricherTestCase
{
    public function testActiveRulesBecomePlans(): void
    {
        $this->addSeats();
        $this->addAssembled(['mode' => Rule::MODE_OFF]);
        $this->addSuite(['mode' => Rule::MODE_AUTO]);

        $plans = $this->planner($this->createStub(LoggerInterface::class))->plan();

        self::assertSame(
            ['number_of_seats', 'bedroom_suite_incl'],
            array_map(static fn (AttributePlan $p): string => $p->getDescriptor()->getCode(), $plans)
        );
        self::assertSame('number_of_seats', $plans[0]->getRule()->getAttributeCode());
        self::assertSame([self::SET], $plans[0]->getSetIds());
    }

    public function testRuleSetsAreIntersectedWithAttributeSets(): void
    {
        $this->setsForAttribute['number_of_seats'] = [4, 9, 12];
        $this->addSeats(['attribute_set_ids' => '9,12,30']);

        $plan = $this->planner($this->createStub(LoggerInterface::class))->plan()[0];

        self::assertSame([9, 12], $plan->getSetIds());
        self::assertTrue($plan->appliesToSet(9));
        self::assertFalse($plan->appliesToSet(4));
        self::assertFalse($plan->appliesToSet(30));
    }

    public function testSingleAttribute(): void
    {
        $this->addSeats();
        $this->addAssembled();

        $plans = $this->planner($this->createStub(LoggerInterface::class))->plan('is_assembled');

        self::assertCount(1, $plans);
        self::assertSame('is_assembled', $plans[0]->getDescriptor()->getCode());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function inactiveCodes(): array
    {
        return ['off' => ['is_assembled'], 'no rule' => ['color']];
    }

    #[DataProvider('inactiveCodes')]
    public function testSingleAttributeMustBeActive(string $code): void
    {
        $this->addAssembled(['mode' => Rule::MODE_OFF]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(sprintf('There is no active enrichment rule for attribute "%s".', $code));

        $this->planner($this->createStub(LoggerInterface::class))->plan($code);
    }

    public function testIneligibleAttributeIsLoggedAndSkipped(): void
    {
        $this->addSeats();
        $this->addAssembled();
        unset($this->descriptors['number_of_seats']);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'DmLab_CatalogEnrich: rule skipped, attribute is not eligible.',
            ['attribute_code' => 'number_of_seats', 'reason' => 'Attribute "number_of_seats" does not exist.']
        );

        $plans = $this->planner($logger)->plan();

        self::assertSame(['is_assembled'], array_map(
            static fn (AttributePlan $p): string => $p->getDescriptor()->getCode(),
            $plans
        ));
    }

    public function testSkipReasonOfARunnableRuleIsNull(): void
    {
        $this->addSeats();

        self::assertNull($this->planner($this->createStub(LoggerInterface::class))
            ->skipReason($this->rule($this->rules['number_of_seats'])));
    }

    public function testSkipReasonExplainsAnIneligibleAttribute(): void
    {
        $this->addSeats();
        unset($this->descriptors['number_of_seats']);

        self::assertSame(
            'Attribute "number_of_seats" does not exist.',
            $this->planner($this->createStub(LoggerInterface::class))
                ->skipReason($this->rule($this->rules['number_of_seats']))
        );
    }

    public function testSkipReasonExplainsSetsWithoutTheAttribute(): void
    {
        $this->setsForAttribute['number_of_seats'] = [4, 9];
        $this->addSeats(['attribute_set_ids' => '12,30']);

        self::assertSame(
            'None of the selected attribute sets contains the attribute.',
            $this->planner($this->createStub(LoggerInterface::class))
                ->skipReason($this->rule($this->rules['number_of_seats']))
        );
    }

    public function testPlanAccessors(): void
    {
        $rule = $this->rule(['attribute_code' => 'is_assembled']);
        $descriptor = new AttributeDescriptor(1, 'is_assembled', 'Is Assembled', 'boolean', 't');

        $plan = new AttributePlan($rule, $descriptor, [4]);

        self::assertSame($rule, $plan->getRule());
        self::assertSame($descriptor, $plan->getDescriptor());
        self::assertSame([4], $plan->getSetIds());
    }
}

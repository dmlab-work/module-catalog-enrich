<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\Run;

use DmLab\CatalogEnrich\Model\Attribute\AttributeDescriptor;
use DmLab\CatalogEnrich\Model\Config;
use DmLab\CatalogEnrich\Model\ResourceModel\CatalogQuery;
use DmLab\CatalogEnrich\Model\Rule;
use DmLab\CatalogEnrich\Model\Run\AttributePlan;
use DmLab\CatalogEnrich\Model\Run\AttributePlanner;
use DmLab\CatalogEnrich\Model\Run\ProductSelector;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class ProductSelectorTest extends TestCase
{
    private function config(bool $includeNotVisible = false): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isIncludeNotVisible')->willReturn($includeNotVisible);

        return $config;
    }

    /**
     * @param int[][] $setIdsPerPlan
     */
    private function planner(array $setIdsPerPlan, ?string $expectedAttribute = null): AttributePlanner
    {
        $plans = array_map(
            fn (array $setIds): AttributePlan => new AttributePlan(
                $this->createStub(Rule::class),
                $this->createStub(AttributeDescriptor::class),
                $setIds
            ),
            $setIdsPerPlan
        );
        $planner = $this->createMock(AttributePlanner::class);
        $planner->expects(self::once())->method('plan')->with($expectedAttribute)->willReturn($plans);

        return $planner;
    }

    public function testUnionOfPlanSets(): void
    {
        $query = $this->createMock(CatalogQuery::class);
        $query->expects(self::once())->method('fetchIds')
            ->with(0, null, [4, 9, 12], [], true)
            ->willReturn([1, 2, 3]);

        $ids = (new ProductSelector($query, $this->planner([[9, 4], [12, 9]]), $this->config()))->select(null, null);

        self::assertSame([1, 2, 3], $ids);
    }

    public function testSkusLimitCursorAndAttributeArePassed(): void
    {
        $query = $this->createMock(CatalogQuery::class);
        $query->expects(self::once())->method('fetchIds')
            ->with(100, 20, [4], ['A', 'B'], false)
            ->willReturn([101]);

        $selector = new ProductSelector($query, $this->planner([[4]], 'color'), $this->config(true));

        self::assertSame([101], $selector->select(['A', 'B'], 20, 100, 'color'));
    }

    public function testNoPlansSelectsWithEmptySets(): void
    {
        $query = $this->createMock(CatalogQuery::class);
        $query->expects(self::once())->method('fetchIds')->with(0, 5, [], [], true)->willReturn([]);

        self::assertSame([], (new ProductSelector($query, $this->planner([]), $this->config()))->select(null, 5));
    }

    public function testInactiveAttributePropagates(): void
    {
        $planner = $this->createStub(AttributePlanner::class);
        $planner->method('plan')->willThrowException(new LocalizedException(__('no rule')));
        $query = $this->createMock(CatalogQuery::class);
        $query->expects(self::never())->method('fetchIds');

        $this->expectException(LocalizedException::class);
        (new ProductSelector($query, $planner, $this->config()))->select(null, null, 0, 'missing');
    }
}

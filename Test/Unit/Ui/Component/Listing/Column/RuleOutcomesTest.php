<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Ui\Component\Listing\Column;

use DmLab\CatalogEnrich\Model\Attempt\Source\Outcome;
use DmLab\CatalogEnrich\Model\ResourceModel\Attempt as AttemptResource;
use DmLab\CatalogEnrich\Ui\Component\Listing\Column\RuleOutcomes;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use PHPUnit\Framework\TestCase;

class RuleOutcomesTest extends TestCase
{
    private function column(AttemptResource $resource): RuleOutcomes
    {
        return new RuleOutcomes(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class),
            $resource,
            new Outcome(),
            [],
            ['name' => 'outcomes']
        );
    }

    public function testCountsOfThePageInOneQuery(): void
    {
        $resource = $this->createMock(AttemptResource::class);
        $resource->expects(self::once())->method('countOutcomes')->with(['color', 'size'])->willReturn([
            'color' => ['error' => 1, 'accepted' => 12, 'not_stated' => 3, 'rejected' => 2],
        ]);

        $result = $this->column($resource)->prepareDataSource(['data' => ['items' => [
            ['attribute_code' => 'color'],
            ['attribute_code' => 'size'],
        ]]]);

        self::assertSame(
            ['Accepted: 12, Not stated: 3, Error: 1, Rejected by TypeSafe: 2', 'No attempts yet'],
            array_column($result['data']['items'], 'outcomes')
        );
    }

    public function testEmptyPageRunsNoQuery(): void
    {
        $resource = $this->createMock(AttemptResource::class);
        $resource->expects(self::never())->method('countOutcomes');

        self::assertSame(['data' => []], $this->column($resource)->prepareDataSource(['data' => []]));
    }
}

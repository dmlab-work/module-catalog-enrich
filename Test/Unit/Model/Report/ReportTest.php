<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\Report;

use DmLab\CatalogEnrich\Model\Report\Report;
use PHPUnit\Framework\TestCase;

class ReportTest extends TestCase
{
    public function testCostCoversBothConsumersAtRate(): void
    {
        $report = new Report([], [], 1_000_000, 500_000, null);

        self::assertEqualsWithDelta(0.063, $report->getCost(), 1e-12);
    }

    public function testNoTokensCostNothing(): void
    {
        self::assertSame(0.0, (new Report([], [], 0, 0, 10))->getCost());
    }
}

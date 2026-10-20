<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\Suggestion\Source;

use DmLab\CatalogEnrich\Model\Suggestion;
use DmLab\CatalogEnrich\Model\Suggestion\Source\Status;
use PHPUnit\Framework\TestCase;

class StatusTest extends TestCase
{
    public function testCoversEveryStatus(): void
    {
        self::assertSame(Suggestion::STATUSES, array_column((new Status())->toOptionArray(), 'value'));
    }
}

<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\Attempt\Source;

use DmLab\CatalogEnrich\Model\Attempt;
use DmLab\CatalogEnrich\Model\Attempt\Source\Outcome;
use PHPUnit\Framework\TestCase;

class OutcomeTest extends TestCase
{
    public function testEveryOutcomeHasALabel(): void
    {
        $options = (new Outcome())->toOptionArray();

        self::assertSame(
            [...Attempt::FINAL_OUTCOMES, Attempt::OUTCOME_ERROR, Attempt::OUTCOME_REJECTED],
            array_column($options, 'value')
        );
        self::assertSame(
            ['Accepted', 'Not stated', 'Uncertain', 'Error', 'Rejected by TypeSafe'],
            array_map(static fn (array $o): string => (string)$o['label'], $options)
        );
    }
}

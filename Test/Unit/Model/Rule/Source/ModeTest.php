<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\Rule\Source;

use DmLab\CatalogEnrich\Model\Rule;
use DmLab\CatalogEnrich\Model\Rule\Source\FillMode;
use DmLab\CatalogEnrich\Model\Rule\Source\Mode;
use PHPUnit\Framework\TestCase;

class ModeTest extends TestCase
{
    public function testModesCoverEveryRuleMode(): void
    {
        $options = (new Mode())->toOptionArray();

        self::assertSame(Rule::MODES, array_column($options, 'value'));
        self::assertSame(
            ['Off', 'Shadow (record only)', 'Review (suggest)', 'Auto (apply)'],
            array_map('strval', array_column($options, 'label'))
        );
    }

    public function testFillModesCoverEveryRuleFillMode(): void
    {
        $options = (new FillMode())->toOptionArray();

        self::assertSame(Rule::FILL_MODES, array_column($options, 'value'));
        self::assertSame(
            ['Empty only', 'Add missing options (multiselect)'],
            array_map('strval', array_column($options, 'label'))
        );
    }
}

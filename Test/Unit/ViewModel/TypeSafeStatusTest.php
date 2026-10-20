<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\ViewModel;

use DmLab\CatalogEnrich\Model\Run\StopCondition;
use DmLab\CatalogEnrich\ViewModel\TypeSafeStatus;
use DmLab\TypeSafeCore\Api\ConfigInterface;
use PHPUnit\Framework\TestCase;

class TypeSafeStatusTest extends TestCase
{
    public function testWarnsOnlyWhileNotConfigured(): void
    {
        $configured = true;
        $config = $this->createStub(ConfigInterface::class);
        $config->method('isConfigured')->willReturnCallback(static function () use (&$configured): bool {
            return $configured;
        });
        $viewModel = new TypeSafeStatus($config, new StopCondition());

        self::assertNull($viewModel->getNotConfiguredMessage());

        $configured = false;
        self::assertSame(
            (string)(new StopCondition())->notConfigured(),
            $viewModel->getNotConfiguredMessage()
        );
    }
}

<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Plugin\Catalog;

use DmLab\CatalogEnrich\Plugin\Catalog\ProductMassActionAcl;
use Magento\Catalog\Ui\Component\Product\MassAction;
use Magento\Framework\AuthorizationInterface;
use PHPUnit\Framework\TestCase;

class ProductMassActionAclTest extends TestCase
{
    private function plugin(bool $allowed): ProductMassActionAcl
    {
        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static fn (string $resource): bool => $resource === 'DmLab_CatalogEnrich::run' && $allowed
        );

        return new ProductMassActionAcl($authorization);
    }

    public function testEnrichActionNeedsTheRunResource(): void
    {
        $subject = $this->createStub(MassAction::class);

        self::assertTrue($this->plugin(true)->afterIsActionAllowed($subject, true, 'dmlab_catalog_enrich'));
        self::assertFalse($this->plugin(false)->afterIsActionAllowed($subject, true, 'dmlab_catalog_enrich'));
        self::assertFalse($this->plugin(true)->afterIsActionAllowed($subject, false, 'dmlab_catalog_enrich'));
    }

    public function testOtherActionsAreUntouched(): void
    {
        $subject = $this->createStub(MassAction::class);

        self::assertTrue($this->plugin(false)->afterIsActionAllowed($subject, true, 'delete'));
        self::assertFalse($this->plugin(true)->afterIsActionAllowed($subject, false, 'attributes'));
    }
}

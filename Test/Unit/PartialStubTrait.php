<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit;

use PHPUnit\Framework\MockObject\Stub;

/**
 * Partial stub on PHPUnit 11.5 (no getStubBuilder) and 12.5 (mocks without expectations raise notices).
 */
trait PartialStubTrait
{
    /**
     * @template T of object
     * @param class-string<T> $class
     * @param string[] $methods
     * @return T&Stub
     */
    private function partialStub(string $class, array $methods): object
    {
        if (method_exists($this, 'getStubBuilder')) {
            return $this->getStubBuilder($class)->disableOriginalConstructor()->onlyMethods($methods)->getStub();
        }

        return $this->getMockBuilder($class)->disableOriginalConstructor()->onlyMethods($methods)->getMock();
    }
}

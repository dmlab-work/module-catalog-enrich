<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Ui\Component\MassAction;

use DmLab\CatalogEnrich\Ui\Component\MassAction\AclAction;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponent\Processor;
use PHPUnit\Framework\TestCase;

class AclActionTest extends TestCase
{
    private function action(?string $resource, bool $allowed): AclAction
    {
        $context = $this->createStub(ContextInterface::class);
        $context->method('getProcessor')->willReturn($this->createStub(Processor::class));
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->expects($resource === null ? self::never() : self::once())
            ->method('isAllowed')
            ->willReturnCallback(static function (string $checked) use ($resource, $allowed): bool {
                self::assertSame($resource, $checked);
                return $allowed;
            });
        $config = ['type' => 'approve', 'label' => 'Approve'];
        if ($resource !== null) {
            $config['aclResource'] = $resource;
        }

        return new AclAction($context, $authorization, [], ['config' => $config]);
    }

    public function testDisabledWithoutTheResource(): void
    {
        $action = $this->action('DmLab_CatalogEnrich::suggestions_apply', false);
        $action->prepare();

        self::assertTrue($action->getConfiguration()['actionDisable']);
    }

    public function testKeptWithTheResource(): void
    {
        $action = $this->action('DmLab_CatalogEnrich::suggestions_apply', true);
        $action->prepare();

        self::assertArrayNotHasKey('actionDisable', $action->getConfiguration());
        self::assertSame('approve', $action->getConfiguration()['type']);
    }

    public function testNoResourceIsNotChecked(): void
    {
        $action = $this->action(null, false);
        $action->prepare();

        self::assertArrayNotHasKey('actionDisable', $action->getConfiguration());
    }
}

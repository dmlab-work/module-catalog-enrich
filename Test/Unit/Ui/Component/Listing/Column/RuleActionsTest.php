<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Ui\Component\Listing\Column;

use DmLab\CatalogEnrich\Ui\Component\Listing\Column\RuleActions;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use PHPUnit\Framework\TestCase;

class RuleActionsTest extends TestCase
{
    public function testAddsEditAndDeleteLinks(): void
    {
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn (string $route, array $params): string => $route . '/id/' . $params['id']
        );
        $column = new RuleActions(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class),
            $url,
            [],
            ['name' => 'actions']
        );

        $result = $column->prepareDataSource(['data' => ['items' => [['rule_id' => '4'], ['other' => 1]]]]);

        $actions = $result['data']['items'][0]['actions'];
        self::assertSame('dmlab_catalog_enrich/rule/edit/id/4', $actions['edit']['href']);
        self::assertSame('dmlab_catalog_enrich/rule/delete/id/4', $actions['delete']['href']);
        self::assertTrue($actions['delete']['post']);
        self::assertArrayHasKey('confirm', $actions['delete']);
        self::assertArrayNotHasKey('actions', $result['data']['items'][1]);
    }

    public function testEmptyDataSourceUnchanged(): void
    {
        $column = new RuleActions(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class),
            $this->createStub(UrlInterface::class)
        );

        self::assertSame(['data' => []], $column->prepareDataSource(['data' => []]));
    }
}

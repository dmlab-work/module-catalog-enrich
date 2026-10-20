<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Ui\Component\Listing\Column;

use DmLab\CatalogEnrich\Model\ResourceModel\Rule as RuleResource;
use DmLab\CatalogEnrich\Model\Rule;
use DmLab\CatalogEnrich\Model\RuleFactory;
use DmLab\CatalogEnrich\Model\Run\AttributePlanner;
use DmLab\CatalogEnrich\Ui\Component\Listing\Column\RuleStatus;
use DmLab\TypeSafeCore\Api\ConfigInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\Db\Context as DbContext;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use PHPUnit\Framework\TestCase;

class RuleStatusTest extends TestCase
{
    private function factory(): RuleFactory
    {
        $factory = $this->createStub(RuleFactory::class);
        $factory->method('create')->willReturnCallback(fn (array $args): Rule => new Rule(
            $this->createStub(Context::class),
            $this->createStub(Registry::class),
            new RuleResource($this->createStub(DbContext::class)),
            null,
            $args['data']
        ));

        return $factory;
    }

    private function coreConfig(bool $configured): ConfigInterface
    {
        $config = $this->createStub(ConfigInterface::class);
        $config->method('isConfigured')->willReturn($configured);

        return $config;
    }

    public function testOffActiveAndSkippedRules(): void
    {
        $planner = $this->createMock(AttributePlanner::class);
        $planner->expects(self::exactly(2))->method('skipReason')->willReturnCallback(
            static fn (Rule $rule): ?string => $rule->getAttributeCode() === 'gone'
                ? 'Attribute "gone" does not exist.'
                : null
        );
        $column = new RuleStatus(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class),
            $this->factory(),
            $planner,
            $this->coreConfig(true),
            [],
            ['name' => 'status']
        );

        $result = $column->prepareDataSource(['data' => ['items' => [
            ['attribute_code' => 'color', 'mode' => 'off'],
            ['attribute_code' => 'size', 'mode' => 'review'],
            ['attribute_code' => 'gone', 'mode' => 'auto'],
        ]]]);

        self::assertSame(
            ['Off', 'Active', 'Skipped: Attribute "gone" does not exist.'],
            array_column($result['data']['items'], 'status')
        );
    }

    public function testActiveRulesAreInactiveWithoutTypeSafe(): void
    {
        $planner = $this->createMock(AttributePlanner::class);
        $planner->expects(self::never())->method('skipReason');
        $column = new RuleStatus(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class),
            $this->factory(),
            $planner,
            $this->coreConfig(false),
            [],
            ['name' => 'status']
        );

        $result = $column->prepareDataSource(['data' => ['items' => [
            ['attribute_code' => 'color', 'mode' => 'off'],
            ['attribute_code' => 'size', 'mode' => 'review'],
        ]]]);

        self::assertSame(
            ['Off', 'Inactive: TypeSafe not configured'],
            array_column($result['data']['items'], 'status')
        );
    }
}

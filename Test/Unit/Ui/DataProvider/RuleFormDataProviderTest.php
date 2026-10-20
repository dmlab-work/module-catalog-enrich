<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Ui\DataProvider;

use DmLab\CatalogEnrich\Model\ResourceModel\Rule as RuleResource;
use DmLab\CatalogEnrich\Model\ResourceModel\Rule\Collection;
use DmLab\CatalogEnrich\Model\ResourceModel\Rule\CollectionFactory;
use DmLab\CatalogEnrich\Model\Rule;
use DmLab\CatalogEnrich\Ui\DataProvider\RuleFormDataProvider;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\Db\Context as DbContext;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;

class RuleFormDataProviderTest extends TestCase
{
    /**
     * @param array<string,mixed> $data
     */
    private function rule(array $data): Rule
    {
        return new Rule(
            $this->createStub(Context::class),
            $this->createStub(Registry::class),
            new RuleResource($this->createStub(DbContext::class)),
            null,
            $data
        );
    }

    /**
     * @param Rule[] $rules
     */
    private function provider(
        array $rules,
        DataPersistorInterface $persistor,
        mixed $requestId = null
    ): RuleFormDataProvider {
        $collection = $this->createStub(Collection::class);
        $collection->method('getItems')->willReturn($rules);
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnMap([['id', null, $requestId]]);

        return new RuleFormDataProvider('form', 'rule_id', 'id', $factory, $persistor, $request);
    }

    public function testStoredRuleKeyedByIdWithSetIdsAsArray(): void
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $persistor->method('get')->willReturn(null);
        $persistor->expects(self::never())->method('clear');
        $provider = $this->provider([
            $this->rule(['rule_id' => 3, 'attribute_code' => 'color', 'attribute_set_ids' => '4,9']),
            $this->rule(['rule_id' => 5, 'attribute_code' => 'style', 'attribute_set_ids' => null]),
        ], $persistor);

        $data = $provider->getData();

        self::assertSame([3, 5], array_keys($data));
        self::assertSame(['4', '9'], $data[3]['attribute_set_ids']);
        self::assertSame('color', $data[3]['attribute_code']);
        self::assertSame([], $data[5]['attribute_set_ids']);
        self::assertSame($data, $provider->getData());
    }

    public function testPersistedNewRuleIsKeyedByEmptyStringAndCleared(): void
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $persistor->method('get')->with('dmlab_catalog_enrich_rule')->willReturn([
            'rule_id' => '',
            'attribute_code' => 'color',
            'attribute_set_ids' => '4',
        ]);
        $persistor->expects(self::once())->method('clear')->with('dmlab_catalog_enrich_rule');

        $data = $this->provider([], $persistor)->getData();

        self::assertSame([''], array_keys($data));
        self::assertSame('color', $data['']['attribute_code']);
        self::assertSame(['4'], $data['']['attribute_set_ids']);
    }

    public function testPersistedEditOverridesStoredValuesAndKeepsTheAttribute(): void
    {
        $persistor = $this->createStub(DataPersistorInterface::class);
        $persistor->method('get')->willReturn([
            'rule_id' => '3',
            'threshold' => '1.5',
            'attribute_set_ids' => null,
        ]);

        $data = $this->provider(
            [$this->rule([
                'rule_id' => 3,
                'attribute_code' => 'color',
                'threshold' => '0.9',
                'attribute_set_ids' => '4',
            ])],
            $persistor
        )->getData();

        self::assertSame('color', $data[3]['attribute_code']);
        self::assertSame('1.5', $data[3]['threshold']);
        self::assertSame([], $data[3]['attribute_set_ids']);
    }

    public function testAttributeDisabledOnlyWhenEditing(): void
    {
        $persistor = $this->createStub(DataPersistorInterface::class);
        $path = static fn (array $meta) => $meta['general']['children']['attribute_code']['arguments']['data']['config']
            ['disabled'] ?? null;

        self::assertTrue($path($this->provider([], $persistor, '3')->getMeta()));
        self::assertNull($path($this->provider([], $persistor)->getMeta()));
    }
}

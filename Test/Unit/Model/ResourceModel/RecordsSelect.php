<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Model\ResourceModel\Db\Context;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Mocked adapter whose Select records every builder call.
 */
trait RecordsSelect
{
    /**
     * @var array<int, array{0: string, 1: array}>
     */
    private array $calls = [];

    /**
     * @var AdapterInterface&MockObject
     */
    private AdapterInterface $adapter;

    private function recordingSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $methods = ['from', 'where', 'order', 'limit', 'distinct', 'join', 'joinLeft', 'group', 'forUpdate', 'columns'];
        foreach ($methods as $method) {
            $select->method($method)->willReturnCallback(function (...$args) use ($method, $select): Select {
                $this->calls[] = [$method, self::withoutTrailingDefaults($method, $args)];

                return $select;
            });
        }

        return $select;
    }

    /**
     * Mocks receive every parameter; keep only the ones the code under test passed explicitly.
     *
     * @param array<int, mixed> $args
     * @return array<int, mixed>
     */
    private static function withoutTrailingDefaults(string $method, array $args): array
    {
        $params = (new \ReflectionMethod(Select::class, $method))->getParameters();
        while ($args !== []) {
            $param = $params[count($args) - 1] ?? null;
            if ($param === null || !$param->isDefaultValueAvailable() || $param->getDefaultValue() !== end($args)) {
                break;
            }
            array_pop($args);
        }

        return $args;
    }

    private function resourceConnection(): ResourceConnection
    {
        $this->adapter = $this->createMock(AdapterInterface::class);
        $this->adapter->method('select')->willReturnCallback(fn (): Select => $this->recordingSelect());
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->adapter);
        $resource->method('getTableName')->willReturnCallback(static fn ($name): string => 'pfx_' . $name);

        return $resource;
    }

    private function dbContext(): Context
    {
        $context = $this->createStub(Context::class);
        $context->method('getResources')->willReturn($this->resourceConnection());

        return $context;
    }

    /**
     * @return array<int, array> argument lists of one builder method
     */
    private function callsOf(string $method): array
    {
        return array_values(array_map(
            static fn (array $call): array => $call[1],
            array_filter($this->calls, static fn (array $call): bool => $call[0] === $method)
        ));
    }
}

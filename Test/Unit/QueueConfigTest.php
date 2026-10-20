<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit;

use DmLab\CatalogEnrich\Model\Queue\EnrichConsumer;
use DmLab\CatalogEnrich\Model\Queue\SuggestionConsumer;
use DmLab\CatalogEnrich\Plugin\Catalog\ProductMassActionAcl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QueueConfigTest extends TestCase
{
    private static function load(string $relative): \SimpleXMLElement
    {
        $xml = simplexml_load_file(dirname(__DIR__, 2) . '/' . $relative);
        self::assertInstanceOf(\SimpleXMLElement::class, $xml, $relative . ' is not well-formed');

        return $xml;
    }

    /**
     * @return array<string,array{string}>
     */
    public static function files(): array
    {
        return [
            'communication' => ['etc/communication.xml'],
            'publisher' => ['etc/queue_publisher.xml'],
            'topology' => ['etc/queue_topology.xml'],
            'consumer' => ['etc/queue_consumer.xml'],
            'routes' => ['etc/adminhtml/routes.xml'],
            'product listing' => ['view/adminhtml/ui_component/product_listing.xml'],
        ];
    }

    #[DataProvider('files')]
    public function testWellFormed(string $file): void
    {
        self::load($file);
    }

    /**
     * @return array<string,array{string,string,string}>
     */
    public static function topics(): array
    {
        return [
            'products' => [EnrichConsumer::TOPIC, 'int[]', EnrichConsumer::class],
            'suggestions' => [SuggestionConsumer::TOPIC, 'string', SuggestionConsumer::class],
        ];
    }

    /**
     * The only element whose attribute has the value.
     */
    private static function one(
        \SimpleXMLElement $xml,
        string $path,
        string $attribute,
        string $value
    ): \SimpleXMLElement {
        $found = $xml->xpath(sprintf('%s[@%s="%s"]', $path, $attribute, $value));
        self::assertIsArray($found);
        self::assertCount(1, $found, $path . ' ' . $value);

        return $found[0];
    }

    #[DataProvider('topics')]
    public function testTopicNamesAreConsistent(string $topic, string $request, string $handler): void
    {
        $communication = self::one(self::load('etc/communication.xml'), 'topic', 'name', $topic);
        self::assertSame($request, (string)$communication['request']);
        self::assertSame('false', (string)$communication['is_synchronous']);

        $publisher = self::one(self::load('etc/queue_publisher.xml'), 'publisher', 'topic', $topic);
        self::assertSame('db', (string)$publisher->connection['name']);
        self::assertSame('magento-db', (string)$publisher->connection['exchange']);

        $exchange = self::load('etc/queue_topology.xml')->exchange;
        self::assertSame('magento-db', (string)$exchange['name']);
        self::assertSame('db', (string)$exchange['connection']);
        $binding = self::one($exchange, 'binding', 'topic', $topic);
        self::assertSame('queue', (string)$binding['destinationType']);
        self::assertSame($topic, (string)$binding['destination']);

        $consumer = self::one(self::load('etc/queue_consumer.xml'), 'consumer', 'name', $topic);
        self::assertSame((string)$binding['destination'], (string)$consumer['queue']);
        self::assertSame('db', (string)$consumer['connection']);
        self::assertSame($handler . '::process', (string)$consumer['handler']);
        self::assertTrue(method_exists($handler, 'process'));
    }

    public function testAdminRouteAndMassActionUrl(): void
    {
        $route = self::load('etc/adminhtml/routes.xml')->router->route;
        self::assertSame('dmlab_catalog_enrich', (string)$route['id']);
        self::assertSame('dmlab_catalog_enrich', (string)$route['frontName']);
        self::assertSame('DmLab_CatalogEnrich', (string)$route->module['name']);

        $action = self::load('view/adminhtml/ui_component/product_listing.xml')
            ->listingToolbar->massaction->action;
        self::assertSame('dmlab_catalog_enrich', (string)$action['name']);
        self::assertSame('Enrich attributes', (string)$action->settings->label);
        self::assertSame(ProductMassActionAcl::ACTION_TYPE, (string)$action->settings->type);
        self::assertSame('dmlab_catalog_enrich/product/massEnrich', (string)$action->settings->url['path']);
        self::assertNotSame('', (string)$action->settings->confirm->message);
    }
}

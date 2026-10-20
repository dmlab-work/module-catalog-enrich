<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model;

use DmLab\CatalogEnrich\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    /**
     * @param array<string, mixed> $values
     */
    private function config(array $values): Config
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->willReturnCallback(static fn (string $path) => $values[$path] ?? null);
        $scopeConfig->method('isSetFlag')
            ->willReturnCallback(static fn (string $path) => (bool)($values[$path] ?? false));

        return new Config($scopeConfig);
    }

    public function testDefaultsWhenUnset(): void
    {
        $config = $this->config([]);

        self::assertSame([], $config->getStateAttributes());
        self::assertFalse($config->isCronEnabled());
        self::assertSame(500, $config->getCronProductsPerRun());
        self::assertFalse($config->isIncludeNotVisible());
    }

    public function testIncludeNotVisible(): void
    {
        self::assertTrue($this->config([Config::XML_PATH_INCLUDE_NOT_VISIBLE => '1'])->isIncludeNotVisible());
    }

    public function testCronEnabled(): void
    {
        self::assertTrue($this->config([Config::XML_PATH_CRON_ENABLED => '1'])->isCronEnabled());
    }

    /**
     * @return array<string, array{mixed, string[]}>
     */
    public static function stateAttributesProvider(): array
    {
        return [
            'empty string' => ['', []],
            'single' => ['material', ['material']],
            'csv keeps order' => ['material,color_text,brand_story', ['material', 'color_text', 'brand_story']],
            'trims and drops empties' => [' material , ,color_text, ', ['material', 'color_text']],
            'drops duplicates' => ['material,material,color_text', ['material', 'color_text']],
            'non-scalar' => [['material'], []],
        ];
    }

    /**
     * @param mixed $raw
     * @param string[] $expected
     */
    #[DataProvider('stateAttributesProvider')]
    public function testStateAttributesCsvParsing(mixed $raw, array $expected): void
    {
        self::assertSame($expected, $this->config([Config::XML_PATH_STATE_ATTRIBUTES => $raw])->getStateAttributes());
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function perRunProvider(): array
    {
        return [
            'zero raised to 1' => ['0', 1],
            'negative raised to 1' => ['-5', 1],
            'non-numeric → default' => ['abc', 500],
            'empty → default' => ['', 500],
            'valid' => ['120', 120],
        ];
    }

    #[DataProvider('perRunProvider')]
    public function testProductsPerRunHasMinimumOne(string $raw, int $expected): void
    {
        self::assertSame(
            $expected,
            $this->config([Config::XML_PATH_CRON_PRODUCTS_PER_RUN => $raw])->getCronProductsPerRun()
        );
    }

    public function testConfigXmlDefaults(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/config.xml');
        self::assertNotFalse($xml);

        $section = $xml->default->dmlab_catalog_enrich;
        self::assertSame('', (string)$section->general->state_attributes);
        self::assertSame('0', (string)$section->cron->enabled);
        self::assertSame('500', (string)$section->cron->products_per_run);
    }

    public function testNoModelSettingInConfigOrSystemXml(): void
    {
        $root = dirname(__DIR__, 3);
        $config = simplexml_load_file($root . '/etc/config.xml');
        $system = simplexml_load_file($root . '/etc/adminhtml/system.xml');
        self::assertNotFalse($config);
        self::assertNotFalse($system);

        self::assertSame([], $config->xpath('//dmlab_catalog_enrich//model'));
        self::assertSame([], $system->xpath('//section[@id="dmlab_catalog_enrich"]//field[@id="model"]'));
    }

    public function testSystemXmlIsDefaultScopeOnlyWithConfigResource(): void
    {
        $system = simplexml_load_file(dirname(__DIR__, 3) . '/etc/adminhtml/system.xml');
        self::assertNotFalse($system);

        $section = $system->xpath('//section[@id="dmlab_catalog_enrich"]')[0] ?? null;
        self::assertNotNull($section);
        self::assertSame('dmlab', (string)$section->tab);
        self::assertSame('DmLab_CatalogEnrich::config', (string)$section->resource);
        foreach ($system->xpath('//section[@id="dmlab_catalog_enrich"]//*[@showInDefault]') as $node) {
            self::assertSame('1', (string)$node['showInDefault']);
            self::assertSame('0', (string)$node['showInWebsite']);
            self::assertSame('0', (string)$node['showInStore']);
        }
        $fields = array_map(
            static fn ($f) => (string)$f['id'],
            $system->xpath('//section[@id="dmlab_catalog_enrich"]/group/field')
        );
        self::assertSame(['state_attributes', 'include_not_visible', 'enabled', 'products_per_run'], $fields);
    }
}

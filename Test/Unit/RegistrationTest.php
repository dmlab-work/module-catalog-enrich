<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit;

use Magento\Framework\Component\ComponentRegistrar;
use PHPUnit\Framework\TestCase;

class RegistrationTest extends TestCase
{
    public function testModuleIsRegisteredAtThisDirectory(): void
    {
        $paths = (new ComponentRegistrar())->getPaths(ComponentRegistrar::MODULE);

        self::assertArrayHasKey('DmLab_CatalogEnrich', $paths);
        self::assertSame(realpath(dirname(__DIR__, 2)), realpath($paths['DmLab_CatalogEnrich']));
    }

    public function testModuleXmlHasNoSetupVersionAndDeclaresSequence(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 2) . '/etc/module.xml');
        self::assertNotFalse($xml);

        $module = $xml->module;
        self::assertSame('DmLab_CatalogEnrich', (string)$module['name']);
        self::assertFalse(isset($module['setup_version']));

        $sequence = [];
        foreach ($module->sequence->module as $dependency) {
            $sequence[] = (string)$dependency['name'];
        }
        self::assertSame(['Magento_Catalog', 'Magento_Eav', 'DmLab_TypeSafeCore'], $sequence);
    }

    public function testAclDeclaresTheResourceTree(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 2) . '/etc/acl.xml');
        self::assertNotFalse($xml);

        $base = '/config/acl/resources/resource[@id="Magento_Backend::admin"]';
        $enrich = $base . '/resource[@id="Magento_Catalog::catalog"]/resource[@id="DmLab_CatalogEnrich::enrich"]';
        foreach (['rules', 'suggestions', 'run'] as $child) {
            self::assertCount(1, $xml->xpath($enrich . '/resource[@id="DmLab_CatalogEnrich::' . $child . '"]'));
        }
        $config = $base . '/resource[@id="Magento_Backend::stores"]/resource[@id="Magento_Backend::stores_settings"]'
            . '/resource[@id="Magento_Config::config"]/resource[@id="DmLab_CatalogEnrich::config"]';
        self::assertCount(1, $xml->xpath($config));
    }
}

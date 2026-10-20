<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit;

use DmLab\CatalogEnrich\Cron\NightlyEnrich;
use DmLab\CatalogEnrich\Plugin\Catalog\ProductMassActionAcl;
use DmLab\CatalogEnrich\Ui\Component\MassAction\AclAction;
use PHPUnit\Framework\TestCase;

class AdminConfigTest extends TestCase
{
    private static function load(string $relative): \SimpleXMLElement
    {
        $xml = simplexml_load_file(dirname(__DIR__, 2) . '/' . $relative);
        self::assertInstanceOf(\SimpleXMLElement::class, $xml, $relative);

        return $xml;
    }

    public function testAclTree(): void
    {
        $acl = self::load('etc/acl.xml');
        $enrich = $acl->xpath('//resource[@id="DmLab_CatalogEnrich::enrich"]')[0];
        $children = array_map(static fn ($node): string => (string)$node['id'], $enrich->xpath('resource'));
        self::assertSame([
            'DmLab_CatalogEnrich::rules',
            'DmLab_CatalogEnrich::suggestions',
            'DmLab_CatalogEnrich::attempts',
            'DmLab_CatalogEnrich::run',
        ], $children);
        $apply = $acl->xpath(
            '//resource[@id="DmLab_CatalogEnrich::suggestions"]/resource[@id="DmLab_CatalogEnrich::suggestions_apply"]'
        );
        self::assertCount(1, $apply);
        self::assertSame('Approve, Reject and Revert', (string)$apply[0]['title']);
    }

    public function testAttemptsMenuEntry(): void
    {
        $menu = self::load('etc/adminhtml/menu.xml')->xpath('//add[@id="DmLab_CatalogEnrich::attempts"]');
        self::assertCount(1, $menu);
        self::assertSame('dmlab_catalog_enrich/attempt/index', (string)$menu[0]['action']);
        self::assertSame('DmLab_CatalogEnrich::attempts', (string)$menu[0]['resource']);
        self::assertSame('DmLab_CatalogEnrich::enrich', (string)$menu[0]['parent']);
    }

    /**
     * Mass action name => [class, ACL resource].
     *
     * @return array<string, array{string, string}>
     */
    private static function aclActions(string $listing): array
    {
        $result = [];
        foreach (self::load('view/adminhtml/ui_component/' . $listing . '.xml')->xpath('//massaction/action') as $a) {
            $resource = $a->xpath('argument/item[@name="config"]/item[@name="aclResource"]');
            $result[(string)$a['name']] = [(string)$a['class'], $resource ? (string)$resource[0] : ''];
        }

        return $result;
    }

    public function testSuggestionTransitionsNeedTheApplyResource(): void
    {
        $expected = [AclAction::class, 'DmLab_CatalogEnrich::suggestions_apply'];
        self::assertSame(
            ['approve' => $expected, 'reject' => $expected, 'revert' => $expected],
            self::aclActions('dmlab_catalog_enrich_suggestion_listing')
        );
    }

    public function testAskAgainNeedsTheRunResource(): void
    {
        self::assertSame(
            ['ask_again' => [AclAction::class, 'DmLab_CatalogEnrich::run']],
            self::aclActions('dmlab_catalog_enrich_attempt_listing')
        );
        $url = self::load('view/adminhtml/ui_component/dmlab_catalog_enrich_attempt_listing.xml')
            ->xpath('//massaction/action/settings/url')[0];
        self::assertSame('dmlab_catalog_enrich/attempt/massDelete', (string)$url['path']);
    }

    public function testProductGridPluginIsAdminOnly(): void
    {
        $plugin = self::load('etc/adminhtml/di.xml')
            ->xpath('//type[@name="Magento\Catalog\Ui\Component\Product\MassAction"]/plugin')[0];
        self::assertSame(ProductMassActionAcl::class, (string)$plugin['type']);
        $global = self::load('etc/di.xml')->xpath('//type[@name="Magento\Catalog\Ui\Component\Product\MassAction"]');
        self::assertSame([], $global);
    }

    public function testNewRulesDefaultToShadowInTheForm(): void
    {
        $default = self::load('view/adminhtml/ui_component/dmlab_catalog_enrich_rule_form.xml')
            ->xpath('//field[@name="mode"]/argument/item[@name="config"]/item[@name="default"]')[0];
        self::assertSame('shadow', (string)$default);
    }

    public function testNoticesAboveTheGrids(): void
    {
        $expected = [
            'rule' => ['not_configured.phtml', 'last_run.phtml'],
            'suggestion' => ['not_configured.phtml', 'last_run.phtml'],
            'attempt' => ['not_configured.phtml'],
        ];
        foreach ($expected as $grid => $templates) {
            $content = self::load('view/adminhtml/layout/dmlab_catalog_enrich_' . $grid . '_index.xml')
                ->xpath('//referenceContainer[@name="content"]')[0];
            $children = iterator_to_array($content->children(), false);
            $gridComponent = array_pop($children);
            self::assertSame('uiComponent', $gridComponent->getName(), $grid);
            self::assertSame(
                array_map(static fn (string $t): string => 'DmLab_CatalogEnrich::' . $t, $templates),
                array_map(static fn ($block): string => (string)$block['template'], $children),
                $grid
            );
        }
        $queueFlag = self::load('view/adminhtml/layout/dmlab_catalog_enrich_suggestion_index.xml')
            ->xpath('//argument[@name="show_suggestion_queue"]');
        self::assertSame('true', (string)$queueFlag[0]);
        self::assertSame([], self::load('view/adminhtml/layout/dmlab_catalog_enrich_rule_index.xml')
            ->xpath('//argument[@name="show_suggestion_queue"]'));
    }

    public function testProductsPerRunCommentStatesTheScanFactor(): void
    {
        $comment = (string)self::load('etc/adminhtml/system.xml')
            ->xpath('//field[@id="products_per_run"]/comment')[0];

        self::assertStringContainsString(NightlyEnrich::SCAN_FACTOR . '×', $comment);
    }
}

<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Ui\Component\Listing\Column;

use DmLab\CatalogEnrich\Ui\Component\Listing\Column\ProductLink;
use Magento\Framework\Escaper;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use PHPUnit\Framework\TestCase;

class ProductLinkTest extends TestCase
{
    public function testEscapedLinkToTheProductEditPageInANewTab(): void
    {
        $url = $this->createMock(UrlInterface::class);
        $url->expects(self::once())->method('getUrl')->with('catalog/product/edit', ['id' => 42])
            ->willReturn('https://magento.loc/admin/catalog/product/edit/id/42/?a="b"');
        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeHtml')->willReturnCallback(
            static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES)
        );
        $escaper->method('escapeUrl')->willReturnCallback(
            static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES)
        );
        $column = new ProductLink(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class),
            $url,
            $escaper,
            [],
            ['name' => 'product_name']
        );

        $result = $column->prepareDataSource(['data' => ['items' => [
            ['product_id' => '42', 'product_name' => 'Sofa <b>"3 seat"</b>'],
            ['product_id' => null, 'product_name' => 'Orphan & co'],
            ['product_id' => '7', 'product_name' => null],
        ]]]);

        self::assertSame([
            '<a href="https://magento.loc/admin/catalog/product/edit/id/42/?a=&quot;b&quot;" target="_blank" '
                . 'rel="noopener noreferrer">Sofa &lt;b&gt;&quot;3 seat&quot;&lt;/b&gt;</a>',
            'Orphan &amp; co',
            '',
        ], array_column($result['data']['items'], 'product_name'));
    }
}

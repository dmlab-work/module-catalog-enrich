<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Controller\Adminhtml\Suggestion;

use DmLab\CatalogEnrich\Controller\Adminhtml\Suggestion\Index;
use DmLab\CatalogEnrich\Test\Unit\Controller\Adminhtml\Rule\ControllerTestCase;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\View\Page\Config;
use Magento\Framework\View\Page\Title;

class IndexTest extends ControllerTestCase
{
    public function testRendersGridPage(): void
    {
        $titles = [];
        $title = $this->createStub(Title::class);
        $title->method('prepend')->willReturnCallback(function ($t) use (&$titles): void {
            $titles[] = (string)$t;
        });
        $config = $this->createStub(Config::class);
        $config->method('getTitle')->willReturn($title);
        $page = $this->createMock(Page::class);
        $page->expects(self::once())->method('setActiveMenu')->with('DmLab_CatalogEnrich::suggestions')
            ->willReturnSelf();
        $page->method('getConfig')->willReturn($config);
        $this->resultFactory->method('create')->willReturn($page);

        self::assertSame($page, (new Index($this->context()))->execute());
        self::assertSame(['Enrichment Suggestions'], $titles);
        self::assertSame('DmLab_CatalogEnrich::suggestions', Index::ADMIN_RESOURCE);
    }
}

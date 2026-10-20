<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Controller\Adminhtml\Rule;

use DmLab\CatalogEnrich\Controller\Adminhtml\Rule\Edit;
use DmLab\CatalogEnrich\Controller\Adminhtml\Rule\Index;
use DmLab\CatalogEnrich\Controller\Adminhtml\Rule\NewAction;
use DmLab\CatalogEnrich\Model\RuleRepository;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Page\Config;
use Magento\Framework\View\Page\Title;

class PageActionsTest extends ControllerTestCase
{
    /**
     * @var string[]
     */
    private array $titles = [];

    private function page(): Page
    {
        $title = $this->createStub(Title::class);
        $title->method('prepend')->willReturnCallback(function ($t): void {
            $this->titles[] = (string)$t;
        });
        $config = $this->createStub(Config::class);
        $config->method('getTitle')->willReturn($title);
        $page = $this->createMock(Page::class);
        $page->expects(self::once())->method('setActiveMenu')->with('DmLab_CatalogEnrich::rules')->willReturnSelf();
        $page->method('getConfig')->willReturn($config);
        $this->resultFactory->method('create')->willReturnCallback(
            static fn (string $type): ?Page => $type === ResultFactory::TYPE_PAGE ? $page : null
        );

        return $page;
    }

    public function testIndexRendersGridPage(): void
    {
        $page = $this->page();

        self::assertSame($page, (new Index($this->context()))->execute());
        self::assertSame(['Enrichment Rules'], $this->titles);
    }

    public function testNewForwardsToEdit(): void
    {
        $forward = $this->createMock(Forward::class);
        $forward->expects(self::once())->method('forward')->with('edit')->willReturnSelf();
        $this->resultFactory->method('create')->willReturnCallback(
            static fn (string $type): ?Forward => $type === ResultFactory::TYPE_FORWARD ? $forward : null
        );

        self::assertSame($forward, (new NewAction($this->context()))->execute());
    }

    public function testEditExistingRule(): void
    {
        $page = $this->page();
        $this->request->method('getParam')->willReturnMap([['id', null, '3']]);
        $repository = $this->createStub(RuleRepository::class);
        $repository->method('getById')->willReturn($this->rule(['rule_id' => 3, 'attribute_code' => 'color']));

        self::assertSame($page, (new Edit($this->context(), $repository))->execute());
        self::assertSame(['Edit Rule: color'], $this->titles);
    }

    public function testEditNewRule(): void
    {
        $page = $this->page();
        $repository = $this->createMock(RuleRepository::class);
        $repository->expects(self::never())->method('getById');

        self::assertSame($page, (new Edit($this->context(), $repository))->execute());
        self::assertSame(['New Rule'], $this->titles);
    }

    public function testEditMissingRuleRedirectsToGrid(): void
    {
        $this->request->method('getParam')->willReturnMap([['id', null, '9']]);
        $repository = $this->createStub(RuleRepository::class);
        $repository->method('getById')->willThrowException(new NoSuchEntityException(__('Gone.')));

        (new Edit($this->context(), $repository))->execute();

        self::assertSame(['*/*/', []], $this->redirectedTo);
        self::assertSame(['Gone.'], $this->errors);
    }
}

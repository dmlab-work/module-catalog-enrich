<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Controller\Adminhtml\Product;

use DmLab\CatalogEnrich\Controller\Adminhtml\Product\MassEnrich;
use DmLab\CatalogEnrich\Model\Rule;
use DmLab\CatalogEnrich\Model\RuleRepository;
use DmLab\CatalogEnrich\Model\Run\LastRunStore;
use DmLab\CatalogEnrich\Model\Run\StopCondition;
use DmLab\TypeSafeCore\Api\ConfigInterface;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Ui\Component\MassAction\Filter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MassEnrichTest extends TestCase
{
    /**
     * @var ManagerInterface&MockObject
     */
    private ManagerInterface $messageManager;

    /**
     * @var Redirect&MockObject
     */
    private Redirect $redirect;

    /**
     * @var Filter
     */
    private Filter $filter;

    /**
     * @var bool
     */
    private bool $configured = true;

    /**
     * @var Rule[]
     */
    private array $activeRules = [];

    /**
     * @var LastRunStore&MockObject
     */
    private LastRunStore $lastRunStore;

    protected function setUp(): void
    {
        $this->messageManager = $this->createMock(ManagerInterface::class);
        $this->redirect = $this->createMock(Redirect::class);
        $this->redirect->expects($this->once())
            ->method('setPath')
            ->with('catalog/product/index')
            ->willReturnSelf();
        $this->filter = $this->createStub(Filter::class);
        $this->activeRules = [$this->createStub(Rule::class)];
        $this->lastRunStore = $this->createMock(LastRunStore::class);
    }

    private function controller(PublisherInterface $publisher): MassEnrich
    {
        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($this->redirect);
        $context = $this->createStub(Context::class);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);
        $context->method('getMessageManager')->willReturn($this->messageManager);
        $collectionFactory = $this->createStub(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($this->createStub(Collection::class));

        $coreConfig = $this->createStub(ConfigInterface::class);
        $coreConfig->method('isConfigured')->willReturn($this->configured);
        $rules = $this->createStub(RuleRepository::class);
        $rules->method('getActive')->willReturn($this->activeRules);

        return new MassEnrich(
            $context,
            $this->filter,
            $collectionFactory,
            $publisher,
            $coreConfig,
            $rules,
            new StopCondition(),
            $this->lastRunStore
        );
    }

    /**
     * @param int[] $ids
     */
    private function selection(array $ids): void
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('getAllIds')->willReturn(array_map('strval', $ids));
        $this->filter->method('getCollection')->willReturn($collection);
    }

    public function testChunksSelectionIntoMessagesOnTheTopic(): void
    {
        $this->selection(range(1, 250));
        $published = [];
        $this->lastRunStore->expects($this->once())->method('startQueue')->with(250)
            ->willReturnCallback(static function () use (&$published): void {
                self::assertSame([], $published);
            });
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->exactly(3))
            ->method('publish')
            ->willReturnCallback(static function (string $topic, array $data) use (&$published): void {
                $published[] = [$topic, $data];
            });
        $this->messageManager->expects($this->once())
            ->method('addSuccessMessage')
            ->with($this->callback(static fn ($m): bool => (string)$m === '250 product(s) queued for enrichment.'));
        $this->messageManager->expects($this->never())->method('addErrorMessage');

        $controller = $this->controller($publisher);
        self::assertSame($this->redirect, $controller->execute());

        self::assertSame('DmLab_CatalogEnrich::run', MassEnrich::ADMIN_RESOURCE);
        self::assertInstanceOf(HttpPostActionInterface::class, $controller);
        self::assertSame(['dmlab.catalog_enrich.products'], array_unique(array_column($published, 0)));
        self::assertSame(range(1, 100), $published[0][1]);
        self::assertSame(range(101, 200), $published[1][1]);
        self::assertSame(range(201, 250), $published[2][1]);
    }

    public function testEmptySelectionShowsErrorAndPublishesNothing(): void
    {
        $this->selection([]);
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->never())->method('publish');
        $this->lastRunStore->expects($this->never())->method('startQueue');
        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with($this->callback(static fn ($m): bool => (string)$m === 'Please select products to enrich.'));
        $this->messageManager->expects($this->never())->method('addSuccessMessage');

        self::assertSame($this->redirect, $this->controller($publisher)->execute());
    }

    public function testFilterExceptionShowsErrorAndPublishesNothing(): void
    {
        $this->filter->method('getCollection')
            ->willThrowException(new LocalizedException(__('An item needs to be selected. Select and try again.')));
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->never())->method('publish');
        $this->lastRunStore->expects($this->never())->method('startQueue');
        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with('An item needs to be selected. Select and try again.');

        self::assertSame($this->redirect, $this->controller($publisher)->execute());
    }

    public function testNotConfiguredShowsErrorAndPublishesNothing(): void
    {
        $this->configured = false;
        $this->selection([1, 2]);
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->never())->method('publish');
        $this->lastRunStore->expects($this->never())->method('startQueue');
        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with($this->callback(static fn ($m): bool => str_starts_with((string)$m, 'TypeSafe is not configured')));
        $this->messageManager->expects($this->never())->method('addSuccessMessage');

        self::assertSame($this->redirect, $this->controller($publisher)->execute());
    }

    public function testNoActiveRuleShowsErrorAndPublishesNothing(): void
    {
        $this->activeRules = [];
        $this->selection([1, 2]);
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->never())->method('publish');
        $this->lastRunStore->expects($this->never())->method('startQueue');
        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with($this->callback(
                static fn ($m): bool => str_starts_with((string)$m, 'There is no active enrichment rule:')
            ));
        $this->messageManager->expects($this->never())->method('addSuccessMessage');

        self::assertSame($this->redirect, $this->controller($publisher)->execute());
    }
}

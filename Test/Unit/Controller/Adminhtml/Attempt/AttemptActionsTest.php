<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Controller\Adminhtml\Attempt;

use DmLab\CatalogEnrich\Controller\Adminhtml\Attempt\Index;
use DmLab\CatalogEnrich\Controller\Adminhtml\Attempt\MassDelete;
use DmLab\CatalogEnrich\Model\ResourceModel\Attempt as AttemptResource;
use DmLab\CatalogEnrich\Model\ResourceModel\Attempt\Grid\Collection as GridCollection;
use DmLab\CatalogEnrich\Test\Unit\Controller\Adminhtml\Rule\ControllerTestCase;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\Api\Filter as ApiFilter;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\DataProviderInterface;
use Magento\Framework\View\Element\UiComponentInterface;
use Magento\Framework\View\Page\Config;
use Magento\Framework\View\Page\Title;
use Magento\Ui\Component\MassAction\Filter;

class AttemptActionsTest extends ControllerTestCase
{
    public function testIndexRendersGridPage(): void
    {
        $titles = [];
        $title = $this->createStub(Title::class);
        $title->method('prepend')->willReturnCallback(function ($t) use (&$titles): void {
            $titles[] = (string)$t;
        });
        $config = $this->createStub(Config::class);
        $config->method('getTitle')->willReturn($title);
        $page = $this->createMock(Page::class);
        $page->expects(self::once())->method('setActiveMenu')->with('DmLab_CatalogEnrich::attempts')
            ->willReturnSelf();
        $page->method('getConfig')->willReturn($config);
        $this->resultFactory->method('create')->willReturn($page);

        self::assertSame($page, (new Index($this->context()))->execute());
        self::assertSame(['Enrichment Attempts'], $titles);
        self::assertSame('DmLab_CatalogEnrich::attempts', Index::ADMIN_RESOURCE);
    }

    /**
     * Filters the data provider received: [condition, field, value].
     *
     * @var array<int, array{0: string, 1: string, 2: mixed}>
     */
    private array $idFilters = [];

    /**
     * Filter whose prepared listing resolves the selection to the given ids.
     *
     * @param string[] $ids
     * @param bool $prepared whether the listing must be prepared (it applies the grid's filters)
     * @return Filter
     */
    private function filter(array $ids, bool $prepared = true): Filter
    {
        $result = $this->createMock(GridCollection::class);
        $result->method('getAllIds')->willReturn($ids);
        $result->expects(self::never())->method('getItems');
        $result->expects(self::never())->method('load');
        $provider = $this->createStub(DataProviderInterface::class);
        $provider->method('getPrimaryFieldName')->willReturn('attempt_id');
        $provider->method('addFilter')->willReturnCallback(function (ApiFilter $filter): void {
            $this->idFilters[] = [$filter->getConditionType(), $filter->getField(), $filter->getValue()];
        });
        $provider->method('getSearchResult')->willReturn($result);
        $context = $this->createStub(ContextInterface::class);
        $context->method('getDataProvider')->willReturn($provider);
        $component = $this->createStub(UiComponentInterface::class);
        $component->method('getContext')->willReturn($context);
        $filter = $this->createMock(Filter::class);
        $filter->method('getComponent')->willReturn($component);
        $filter->expects($prepared ? self::once() : self::never())->method('prepareComponent')->with($component);

        return $filter;
    }

    private function action(Filter $filter, AttemptResource $resource): MassDelete
    {
        $builder = $this->createStub(FilterBuilder::class);
        $data = [];
        foreach (['setConditionType' => 'condition_type', 'setField' => 'field', 'setValue' => 'value'] as $m => $k) {
            $builder->method($m)->willReturnCallback(function ($value) use ($builder, &$data, $k): FilterBuilder {
                $data[$k] = $value;

                return $builder;
            });
        }
        $builder->method('create')->willReturnCallback(static function () use (&$data): ApiFilter {
            $filter = new ApiFilter($data);
            $data = [];

            return $filter;
        });

        return new MassDelete($this->context(), $filter, $builder, $resource);
    }

    /**
     * @param array<string,mixed> $params
     */
    private function params(array $params): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static fn (string $name): mixed => $params[$name] ?? null
        );
    }

    public function testMassDeleteForgetsTheSelectedAttempts(): void
    {
        $this->params([Filter::SELECTED_PARAM => ['4', '9']]);
        $resource = $this->createMock(AttemptResource::class);
        $resource->expects(self::once())->method('deleteByIds')->with([4, 9])->willReturn(2);

        $action = $this->action($this->filter(['4', '9']), $resource);
        $action->execute();

        self::assertSame([['in', 'attempt_id', ['4', '9']]], $this->idFilters);
        self::assertSame('DmLab_CatalogEnrich::run', MassDelete::ADMIN_RESOURCE);
        self::assertInstanceOf(HttpPostActionInterface::class, $action);
        self::assertSame(['2 attempt(s) cleared; these products are asked again on the next run.'], $this->successes);
        self::assertSame(['*/*/', []], $this->redirectedTo);
    }

    public function testMassDeleteSelectAllResolvesFilteredIdsWithoutLoadingTheGrid(): void
    {
        $this->params([Filter::EXCLUDED_PARAM => 'false']);
        $ids = array_map('strval', range(1, 3000));
        $resource = $this->createMock(AttemptResource::class);
        $resource->expects(self::once())->method('deleteByIds')->with(range(1, 3000))->willReturn(3000);

        $this->action($this->filter($ids), $resource)->execute();

        // Only the grid's own filters (applied by preparing the listing) restrict a select-all.
        self::assertSame([], $this->idFilters);
        self::assertSame(
            ['3000 attempt(s) cleared; these products are asked again on the next run.'],
            $this->successes
        );
    }

    public function testMassDeleteSelectAllExceptExcluded(): void
    {
        $this->params([Filter::EXCLUDED_PARAM => ['7'], Filter::SELECTED_PARAM => ['7']]);
        $resource = $this->createMock(AttemptResource::class);
        $resource->expects(self::once())->method('deleteByIds')->with([5])->willReturn(1);

        $this->action($this->filter(['5']), $resource)->execute();

        self::assertSame([['nin', 'attempt_id', ['7']]], $this->idFilters);
    }

    public function testMassDeleteWithoutSelectionDeletesNothing(): void
    {
        $this->params([]);
        $resource = $this->createMock(AttemptResource::class);
        $resource->expects(self::never())->method('deleteByIds');

        $this->action($this->filter([], false), $resource)->execute();

        self::assertSame(['An item needs to be selected. Select and try again.'], $this->errors);
    }

    public function testMassDeleteFilterErrorDeletesNothing(): void
    {
        $this->params([Filter::EXCLUDED_PARAM => ['3']]);
        $filter = $this->createStub(Filter::class);
        $filter->method('getComponent')->willThrowException(new LocalizedException(__('Select items.')));
        $resource = $this->createMock(AttemptResource::class);
        $resource->expects(self::never())->method('deleteByIds');

        $this->action($filter, $resource)->execute();

        self::assertSame(['Select items.'], $this->errors);
        self::assertSame([], $this->successes);
    }
}

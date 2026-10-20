<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Controller\Adminhtml\Suggestion;

use DmLab\CatalogEnrich\Controller\Adminhtml\Suggestion\AbstractMassAction;
use DmLab\CatalogEnrich\Controller\Adminhtml\Suggestion\MassApprove;
use DmLab\CatalogEnrich\Controller\Adminhtml\Suggestion\MassReject;
use DmLab\CatalogEnrich\Controller\Adminhtml\Suggestion\MassRevert;
use DmLab\CatalogEnrich\Model\Apply\Applier;
use DmLab\CatalogEnrich\Model\Apply\ApplyResult;
use DmLab\CatalogEnrich\Model\Queue\SuggestionConsumer;
use DmLab\CatalogEnrich\Model\Queue\SuggestionPublisher;
use DmLab\CatalogEnrich\Model\ResourceModel\Suggestion as SuggestionResource;
use DmLab\CatalogEnrich\Model\ResourceModel\Suggestion\Collection;
use DmLab\CatalogEnrich\Model\ResourceModel\Suggestion\CollectionFactory;
use DmLab\CatalogEnrich\Model\Suggestion;
use DmLab\CatalogEnrich\Model\Suggestion\Source\Status;
use DmLab\CatalogEnrich\Test\Unit\Controller\Adminhtml\Rule\ControllerTestCase;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context as ModelContext;
use Magento\Framework\Model\ResourceModel\Db\Context as DbContext;
use Magento\Framework\Registry;
use Magento\Ui\Component\MassAction\Filter;

class MassActionTest extends ControllerTestCase
{
    private function suggestion(int $id, string $status): Suggestion
    {
        return new Suggestion(
            $this->createStub(ModelContext::class),
            $this->createStub(Registry::class),
            new SuggestionResource($this->createStub(DbContext::class)),
            null,
            ['suggestion_id' => $id, 'product_id' => 10, 'attribute_code' => 'color', 'value' => '5',
             'status' => $status]
        );
    }

    /**
     * @param Suggestion[] $items
     * @return array{0: Filter, 1: CollectionFactory}
     */
    private function selection(array $items): array
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('getItems')->willReturn($items);
        $collection->method('getAllIds')->willReturn(
            array_map(static fn (Suggestion $item): string => (string)$item->getId(), $items)
        );
        $filter = $this->createStub(Filter::class);
        $filter->method('getCollection')->willReturn($collection);
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($this->createStub(Collection::class));

        return [$filter, $factory];
    }

    private function publisher(): SuggestionPublisher
    {
        return $this->createStub(SuggestionPublisher::class);
    }

    public function testApproveAppliesOnlyPending(): void
    {
        $pending = $this->suggestion(1, Suggestion::STATUS_PENDING);
        [$filter, $factory] = $this->selection([
            $pending,
            $this->suggestion(2, Suggestion::STATUS_SHADOW),
            $this->suggestion(3, Suggestion::STATUS_APPLIED),
        ]);
        $applier = $this->createMock(Applier::class);
        $applier->expects(self::once())->method('apply')->with($pending)->willReturn(new ApplyResult(true));
        $applier->expects(self::never())->method('revert');

        (new MassApprove($this->context(), $filter, $factory, $this->publisher(), $applier, new Status()))->execute();

        self::assertSame('DmLab_CatalogEnrich::suggestions_apply', MassApprove::ADMIN_RESOURCE);
        self::assertSame('DmLab_CatalogEnrich::suggestions_apply', MassReject::ADMIN_RESOURCE);
        self::assertSame('DmLab_CatalogEnrich::suggestions_apply', MassRevert::ADMIN_RESOURCE);
        self::assertSame(['1 done, 2 skipped.'], $this->successes);
        self::assertSame([
            'Suggestion #2: status is "Shadow", only "Pending" can be processed.',
            'Suggestion #3: status is "Applied", only "Pending" can be processed.',
        ], $this->warnings);
        self::assertSame(['*/*/', []], $this->redirectedTo);
    }

    public function testApproveRefusedByApplierIsSkippedAndStatusUnchanged(): void
    {
        $pending = $this->suggestion(4, Suggestion::STATUS_PENDING);
        [$filter, $factory] = $this->selection([$pending]);
        $applier = $this->createStub(Applier::class);
        $applier->method('apply')->willReturn(new ApplyResult(false, 'The attribute already has a value.'));

        (new MassApprove($this->context(), $filter, $factory, $this->publisher(), $applier, new Status()))->execute();

        self::assertSame(Suggestion::STATUS_PENDING, $pending->getStatus());
        self::assertSame([], $this->successes);
        self::assertSame(
            ['0 done, 1 skipped.', 'Suggestion #4: The attribute already has a value.'],
            $this->warnings
        );
    }

    public function testRejectMarksPendingRejected(): void
    {
        $pending = $this->suggestion(1, Suggestion::STATUS_PENDING);
        $applied = $this->suggestion(2, Suggestion::STATUS_APPLIED);
        [$filter, $factory] = $this->selection([$pending, $applied]);
        $resource = $this->createMock(SuggestionResource::class);
        $resource->expects(self::once())->method('transitionStatus')
            ->with(1, Suggestion::STATUS_PENDING, Suggestion::STATUS_REJECTED)
            ->willReturn(true);

        (new MassReject($this->context(), $filter, $factory, $this->publisher(), $resource, new Status()))->execute();

        self::assertSame(Suggestion::STATUS_REJECTED, $pending->getStatus());
        self::assertSame(Suggestion::STATUS_APPLIED, $applied->getStatus());
        self::assertSame(['1 done, 1 skipped.'], $this->successes);
        self::assertSame(
            ['Suggestion #2: status is "Applied", only "Pending" can be processed.'],
            $this->warnings
        );
    }

    public function testRejectSaveFailureIsSkipped(): void
    {
        [$filter, $factory] = $this->selection([$this->suggestion(5, Suggestion::STATUS_PENDING)]);
        $resource = $this->createStub(SuggestionResource::class);
        $resource->method('transitionStatus')->willThrowException(new CouldNotSaveException(__('DB down')));

        (new MassReject($this->context(), $filter, $factory, $this->publisher(), $resource, new Status()))->execute();

        self::assertSame(['0 done, 1 skipped.', 'Suggestion #5: DB down'], $this->warnings);
    }

    public function testRejectOfRowChangedConcurrentlyIsSkipped(): void
    {
        $pending = $this->suggestion(6, Suggestion::STATUS_PENDING);
        [$filter, $factory] = $this->selection([$pending]);
        $resource = $this->createStub(SuggestionResource::class);
        $resource->method('transitionStatus')->willReturn(false);

        (new MassReject($this->context(), $filter, $factory, $this->publisher(), $resource, new Status()))->execute();

        self::assertSame(Suggestion::STATUS_PENDING, $pending->getStatus());
        self::assertSame(
            ['0 done, 1 skipped.', 'Suggestion #6: The suggestion was changed by another process.'],
            $this->warnings
        );
    }

    public function testRevertOnlyApplied(): void
    {
        $applied = $this->suggestion(1, Suggestion::STATUS_APPLIED);
        $changed = $this->suggestion(2, Suggestion::STATUS_APPLIED);
        [$filter, $factory] = $this->selection([
            $applied,
            $changed,
            $this->suggestion(3, Suggestion::STATUS_PENDING),
        ]);
        $applier = $this->createMock(Applier::class);
        $applier->expects(self::never())->method('apply');
        $applier->expects(self::exactly(2))->method('revert')->willReturnCallback(
            static fn (Suggestion $s): ApplyResult => $s === $applied
                ? new ApplyResult(true)
                : new ApplyResult(false, 'The value was changed after it was applied.')
        );

        (new MassRevert($this->context(), $filter, $factory, $this->publisher(), $applier, new Status()))->execute();

        self::assertSame(['1 done, 2 skipped.'], $this->successes);
        self::assertSame([
            'Suggestion #2: The value was changed after it was applied.',
            'Suggestion #3: status is "Pending", only "Applied" can be processed.',
        ], $this->warnings);
    }

    public function testAtMostFiveSkipReasonsAreShown(): void
    {
        $items = [];
        for ($i = 1; $i <= 7; $i++) {
            $items[] = $this->suggestion($i, Suggestion::STATUS_REJECTED);
        }
        [$filter, $factory] = $this->selection($items);

        $applier = $this->createStub(Applier::class);
        (new MassApprove($this->context(), $filter, $factory, $this->publisher(), $applier, new Status()))->execute();

        self::assertSame('0 done, 7 skipped.', $this->warnings[0]);
        self::assertCount(1 + 5, $this->warnings);
        self::assertStringStartsWith('Suggestion #5:', $this->warnings[5]);
    }

    public function testFilterErrorShowsError(): void
    {
        $filter = $this->createStub(Filter::class);
        $filter->method('getCollection')->willThrowException(new LocalizedException(__('Select items.')));
        [, $factory] = $this->selection([]);
        $applier = $this->createMock(Applier::class);
        $applier->expects(self::never())->method('apply');

        $action = new MassApprove($this->context(), $filter, $factory, $this->publisher(), $applier, new Status());
        $action->execute();

        self::assertInstanceOf(AbstractMassAction::class, $action);
        self::assertSame(['Select items.'], $this->errors);
        self::assertSame([], $this->successes);
        self::assertSame([], $this->warnings);
    }

    /**
     * @return Suggestion[]
     */
    private function many(int $count, string $status): array
    {
        $items = [];
        for ($i = 1; $i <= $count; $i++) {
            $items[] = $this->suggestion($i, $status);
        }

        return $items;
    }

    public function testApproveOfMoreThanFiftyIsQueued(): void
    {
        [$filter, $factory] = $this->selection($this->many(AbstractMassAction::SYNC_LIMIT + 1, 'pending'));
        $publisher = $this->createMock(SuggestionPublisher::class);
        $publisher->expects(self::once())->method('publish')
            ->with(SuggestionConsumer::OPERATION_APPROVE, range(1, 51));
        $applier = $this->createMock(Applier::class);
        $applier->expects(self::never())->method('apply');

        (new MassApprove($this->context(), $filter, $factory, $publisher, $applier, new Status()))->execute();

        self::assertSame(
            ['51 suggestion(s) queued. They are processed in the background; refusals are written to the Magento log.'],
            $this->successes
        );
        self::assertSame([], $this->warnings);
        self::assertSame(['*/*/', []], $this->redirectedTo);
    }

    public function testRevertOfMoreThanFiftyIsQueued(): void
    {
        [$filter, $factory] = $this->selection($this->many(60, 'applied'));
        $publisher = $this->createMock(SuggestionPublisher::class);
        $publisher->expects(self::once())->method('publish')
            ->with(SuggestionConsumer::OPERATION_REVERT, range(1, 60));
        $applier = $this->createMock(Applier::class);
        $applier->expects(self::never())->method('revert');

        (new MassRevert($this->context(), $filter, $factory, $publisher, $applier, new Status()))->execute();

        self::assertCount(1, $this->successes);
        self::assertStringStartsWith('60 suggestion(s) queued.', $this->successes[0]);
    }

    public function testFiftyOrFewerStaySynchronous(): void
    {
        [$filter, $factory] = $this->selection($this->many(AbstractMassAction::SYNC_LIMIT, 'pending'));
        $publisher = $this->createMock(SuggestionPublisher::class);
        $publisher->expects(self::never())->method('publish');
        $applier = $this->createMock(Applier::class);
        $applier->expects(self::exactly(50))->method('apply')->willReturn(new ApplyResult(true));

        (new MassApprove($this->context(), $filter, $factory, $publisher, $applier, new Status()))->execute();

        self::assertSame(['50 done, 0 skipped.'], $this->successes);
    }

    public function testRejectIsNeverQueued(): void
    {
        [$filter, $factory] = $this->selection($this->many(60, 'pending'));
        $publisher = $this->createMock(SuggestionPublisher::class);
        $publisher->expects(self::never())->method('publish');
        $resource = $this->createMock(SuggestionResource::class);
        $resource->expects(self::exactly(60))->method('transitionStatus')->willReturn(true);

        (new MassReject($this->context(), $filter, $factory, $publisher, $resource, new Status()))->execute();

        self::assertSame(['60 done, 0 skipped.'], $this->successes);
    }
}

<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Controller\Adminhtml\Rule;

use DmLab\CatalogEnrich\Controller\Adminhtml\Rule\Delete;
use DmLab\CatalogEnrich\Controller\Adminhtml\Rule\MassDelete;
use DmLab\CatalogEnrich\Model\ResourceModel\Rule\Collection;
use DmLab\CatalogEnrich\Model\ResourceModel\Rule\CollectionFactory;
use DmLab\CatalogEnrich\Model\RuleRepository;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Ui\Component\MassAction\Filter;

class DeleteTest extends ControllerTestCase
{
    private function delete(RuleRepository $repository, mixed $id): Delete
    {
        $this->request->method('getParam')->willReturnMap([['id', null, $id]]);

        return new Delete($this->context(), $repository);
    }

    public function testDeletesRule(): void
    {
        $rule = $this->rule(['rule_id' => 3]);
        $repository = $this->createMock(RuleRepository::class);
        $repository->method('getById')->with(3)->willReturn($rule);
        $repository->expects(self::once())->method('delete')->with($rule);

        $this->delete($repository, '3')->execute();

        self::assertSame('DmLab_CatalogEnrich::rules', Delete::ADMIN_RESOURCE);
        self::assertSame(['*/*/', []], $this->redirectedTo);
        self::assertSame(['The rule has been deleted.'], $this->successes);
    }

    public function testMissingIdShowsError(): void
    {
        $repository = $this->createMock(RuleRepository::class);
        $repository->expects(self::never())->method('delete');

        $this->delete($repository, null)->execute();

        self::assertSame(["We can't find a rule to delete."], $this->errors);
        self::assertSame(['*/*/', []], $this->redirectedTo);
    }

    public function testUnknownRuleShowsError(): void
    {
        $repository = $this->createMock(RuleRepository::class);
        $repository->method('getById')->willThrowException(new NoSuchEntityException(__('Gone.')));
        $repository->expects(self::never())->method('delete');

        $this->delete($repository, '9')->execute();

        self::assertSame(['Gone.'], $this->errors);
        self::assertSame([], $this->successes);
    }

    private function collectionFactory(): CollectionFactory
    {
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($this->createStub(Collection::class));

        return $factory;
    }

    public function testMassDeleteCountsDeletedAndReportsFailures(): void
    {
        $rules = [$this->rule(['rule_id' => 1]), $this->rule(['rule_id' => 2]), $this->rule(['rule_id' => 3])];
        $collection = $this->createStub(Collection::class);
        $collection->method('getItems')->willReturn($rules);
        $filter = $this->createStub(Filter::class);
        $filter->method('getCollection')->willReturn($collection);
        $repository = $this->createMock(RuleRepository::class);
        $repository->expects(self::exactly(3))->method('delete')->willReturnCallback(
            static function ($rule): void {
                if ((int)$rule->getId() === 2) {
                    throw new CouldNotDeleteException(__('Could not delete the enrichment rule: locked'));
                }
            }
        );

        (new MassDelete($this->context(), $filter, $this->collectionFactory(), $repository))->execute();

        self::assertSame('DmLab_CatalogEnrich::rules', MassDelete::ADMIN_RESOURCE);
        self::assertSame(['A total of 2 rule(s) have been deleted.'], $this->successes);
        self::assertSame(['Could not delete the enrichment rule: locked'], $this->errors);
        self::assertSame(['*/*/', []], $this->redirectedTo);
    }

    public function testMassDeleteFilterErrorShowsError(): void
    {
        $filter = $this->createStub(Filter::class);
        $filter->method('getCollection')->willThrowException(new LocalizedException(__('Select items.')));
        $repository = $this->createMock(RuleRepository::class);
        $repository->expects(self::never())->method('delete');

        (new MassDelete($this->context(), $filter, $this->collectionFactory(), $repository))->execute();

        self::assertSame(['Select items.'], $this->errors);
        self::assertSame([], $this->successes);
    }
}

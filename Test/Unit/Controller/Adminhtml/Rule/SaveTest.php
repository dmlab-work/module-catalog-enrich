<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Controller\Adminhtml\Rule;

use DmLab\CatalogEnrich\Controller\Adminhtml\Rule\Save;
use DmLab\CatalogEnrich\Model\Rule;
use DmLab\CatalogEnrich\Model\RuleFactory;
use DmLab\CatalogEnrich\Model\RuleRepository;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\ValidatorException;
use PHPUnit\Framework\MockObject\MockObject;

class SaveTest extends ControllerTestCase
{
    /**
     * @var RuleRepository&MockObject
     */
    private RuleRepository $repository;

    /**
     * @var DataPersistorInterface&MockObject
     */
    private DataPersistorInterface $persistor;

    /**
     * @var Rule|null
     */
    private ?Rule $saved = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createMock(RuleRepository::class);
        $this->persistor = $this->createMock(DataPersistorInterface::class);
    }

    /**
     * @param array<string,mixed>|null $post
     */
    private function controller(?array $post): Save
    {
        $this->request->method('getPostValue')->willReturn($post);
        $factory = $this->createStub(RuleFactory::class);
        $factory->method('create')->willReturnCallback(fn (): Rule => $this->rule());

        return new Save($this->context(), $this->repository, $factory, $this->persistor);
    }

    private function expectSave(?\Throwable $error = null): void
    {
        $this->repository->expects(self::once())->method('save')->willReturnCallback(
            function (Rule $rule) use ($error): Rule {
                $this->saved = $rule;
                if ($error !== null) {
                    throw $error;
                }
                return $rule;
            }
        );
    }

    public function testCreateConvertsSetIdsToCsv(): void
    {
        $this->expectSave();
        $this->repository->expects(self::never())->method('getById');
        $this->persistor->expects(self::once())->method('clear')->with('dmlab_catalog_enrich_rule');
        $this->persistor->expects(self::never())->method('set');

        $this->controller([
            'rule_id' => '',
            'attribute_code' => 'color',
            'mode' => 'review',
            'threshold' => '0.85',
            'instructions' => '',
            'extra_rules' => "a\nb",
            'attribute_set_ids' => ['4', '9'],
            'fill_mode' => 'empty_only',
            'treat_zero_as_empty' => '1',
            'form_key' => 'x',
        ])->execute();

        self::assertTrue(is_subclass_of(Save::class, HttpPostActionInterface::class));
        self::assertSame('DmLab_CatalogEnrich::rules', Save::ADMIN_RESOURCE);
        self::assertSame('color', $this->saved?->getAttributeCode());
        self::assertSame('4,9', $this->saved->getData('attribute_set_ids'));
        self::assertSame('0.85', $this->saved->getData('threshold'));
        self::assertSame("a\nb", $this->saved->getData('extra_rules'));
        self::assertSame('1', $this->saved->getData('treat_zero_as_empty'));
        self::assertNull($this->saved->getData('form_key'));
        self::assertSame(['*/*/', []], $this->redirectedTo);
        self::assertSame([
            'The rule for "color" has been saved. Mode, threshold and fill mode changes are applied on the next '
            . 'enrichment run.',
        ], $this->successes);
        self::assertSame([], $this->errors);
    }

    public function testUpdateKeepsAttributeAndClearsMissingSetIds(): void
    {
        $existing = $this->rule([
            'rule_id' => 3,
            'attribute_code' => 'color',
            'mode' => 'off',
            'attribute_set_ids' => '4',
        ]);
        $this->repository->expects(self::once())->method('getById')->with(3)->willReturn($existing);
        $this->expectSave();
        $this->persistor->expects(self::once())->method('clear');

        $this->controller(['rule_id' => '3', 'attribute_code' => 'style', 'mode' => 'auto'])->execute();

        self::assertSame($existing, $this->saved);
        self::assertSame('color', $existing->getAttributeCode());
        self::assertSame('auto', $existing->getMode());
        self::assertNull($existing->getData('attribute_set_ids'));
        self::assertSame(['*/*/', []], $this->redirectedTo);
    }

    public function testValidationErrorOnNewRuleGoesBackWithData(): void
    {
        $this->expectSave(new ValidatorException(__('The threshold must be greater than 0.5 and less than 1.')));
        $this->persistor->expects(self::once())->method('set')->with(
            'dmlab_catalog_enrich_rule',
            self::callback(static fn (array $d): bool => $d['threshold'] === '2'
                && $d['attribute_code'] === 'color'
                && $d['attribute_set_ids'] === '4')
        );
        $this->persistor->expects(self::never())->method('clear');

        $this->controller(['attribute_code' => 'color', 'threshold' => '2', 'attribute_set_ids' => ['4']])->execute();

        self::assertSame(['*/*/new', []], $this->redirectedTo);
        self::assertSame(['The threshold must be greater than 0.5 and less than 1.'], $this->errors);
        self::assertSame([], $this->successes);
    }

    public function testSaveErrorOnExistingRuleGoesBackToEdit(): void
    {
        $this->repository->method('getById')->willReturn($this->rule(['rule_id' => 3, 'attribute_code' => 'color']));
        $this->expectSave(new CouldNotSaveException(__('Could not save the enrichment rule: boom')));
        $this->persistor->expects(self::once())->method('set');

        $this->controller(['rule_id' => '3', 'mode' => 'auto'])->execute();

        self::assertSame(['*/*/edit', ['id' => 3]], $this->redirectedTo);
        self::assertSame(['Could not save the enrichment rule: boom'], $this->errors);
    }

    public function testMissingRuleRedirectsToGrid(): void
    {
        $this->repository->method('getById')->willThrowException(new NoSuchEntityException(__('Gone.')));
        $this->repository->expects(self::never())->method('save');
        $this->persistor->expects(self::never())->method('set');

        $this->controller(['rule_id' => '99'])->execute();

        self::assertSame(['*/*/', []], $this->redirectedTo);
        self::assertSame(['Gone.'], $this->errors);
    }

    public function testEmptyPostRedirectsToGrid(): void
    {
        $this->repository->expects(self::never())->method('save');
        $this->persistor->expects(self::never())->method('clear');

        $this->controller([])->execute();

        self::assertSame(['*/*/', []], $this->redirectedTo);
    }
}

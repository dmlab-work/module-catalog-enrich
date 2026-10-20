<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model;

use DmLab\CatalogEnrich\Exception\IneligibleAttributeException;
use DmLab\CatalogEnrich\Model\Attribute\AttributeCatalog;
use DmLab\CatalogEnrich\Model\Attribute\AttributeDescriptor;
use DmLab\CatalogEnrich\Model\Question\InstructionsBuilder;
use DmLab\CatalogEnrich\Model\Question\QuestionBuilder;
use DmLab\CatalogEnrich\Model\ResourceModel\Rule as RuleResource;
use DmLab\CatalogEnrich\Model\ResourceModel\Rule\Collection;
use DmLab\CatalogEnrich\Model\ResourceModel\Rule\CollectionFactory;
use DmLab\CatalogEnrich\Model\Rule;
use DmLab\CatalogEnrich\Model\RuleFactory;
use DmLab\CatalogEnrich\Model\RuleRepository;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\ValidatorException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\Db\Context as DbContext;
use Magento\Framework\Registry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class RuleRepositoryTest extends TestCase
{
    /**
     * @var array<int, array<string,mixed>> stored rows by rule id
     */
    private array $stored = [];

    /**
     * @var RuleResource&MockObject
     */
    private RuleResource $resource;

    /**
     * @var CollectionFactory&Stub
     */
    private CollectionFactory $collectionFactory;

    /**
     * @var RuleRepository
     */
    private RuleRepository $repository;

    protected function setUp(): void
    {
        $this->stored = [7 => ['rule_id' => 7, 'attribute_code' => 'color', 'mode' => 'review']];

        $factory = $this->createStub(RuleFactory::class);
        $factory->method('create')->willReturnCallback(fn (): Rule => $this->rule());

        $this->resource = $this->createMock(RuleResource::class);
        $this->resource->method('load')->willReturnCallback(
            function (Rule $rule, $value, $field = null): RuleResource {
                foreach ($this->stored as $id => $row) {
                    if (($field === null && $id === (int)$value)
                        || ($field !== null && ($row[$field] ?? null) === $value)
                    ) {
                        $rule->setData($row);
                    }
                }

                return $this->resource;
            }
        );

        $descriptors = [
            'color' => new AttributeDescriptor(1, 'color', 'Color', 'select', 'catalog_product_entity_int', [
                1 => 'Red',
                2 => 'Blue',
            ]),
            'style' => new AttributeDescriptor(2, 'style', 'Style', 'multiselect', 'catalog_product_entity_varchar', [
                5 => 'Modern',
            ]),
            'is_assembled' => new AttributeDescriptor(3, 'is_assembled', 'Assembled', 'boolean', 'cpe_int'),
        ];
        $catalog = $this->createStub(AttributeCatalog::class);
        $catalog->method('get')->willReturnCallback(
            static fn (string $code): AttributeDescriptor => $descriptors[$code]
                ?? throw new IneligibleAttributeException(__('Attribute "%1" does not exist.', $code))
        );

        $this->collectionFactory = $this->createStub(CollectionFactory::class);
        $this->repository = new RuleRepository(
            $factory,
            $this->resource,
            $this->collectionFactory,
            $catalog,
            new QuestionBuilder(new InstructionsBuilder())
        );
    }

    /**
     * @param array<string,mixed> $data
     */
    private function rule(array $data = []): Rule
    {
        return new Rule(
            $this->createStub(Context::class),
            $this->createStub(Registry::class),
            new RuleResource($this->createStub(DbContext::class)),
            null,
            $data
        );
    }

    public function testGetById(): void
    {
        $this->resource->expects(self::never())->method('save');
        self::assertSame('color', $this->repository->getById(7)->getAttributeCode());
    }

    public function testGetByIdMissingThrows(): void
    {
        $this->resource->expects(self::never())->method('save');
        $this->expectException(NoSuchEntityException::class);

        $this->repository->getById(99);
    }

    public function testGetByAttributeCode(): void
    {
        $this->resource->expects(self::never())->method('save');
        self::assertSame(7, (int)$this->repository->getByAttributeCode('color')?->getId());
        self::assertNull($this->repository->getByAttributeCode('style'));
    }

    public function testGetActiveFiltersOutOff(): void
    {
        $items = [7 => $this->rule(['rule_id' => 7]), 9 => $this->rule(['rule_id' => 9])];
        $collection = $this->createMock(Collection::class);
        $collection->expects(self::once())->method('addFieldToFilter')->with('mode', ['neq' => 'off']);
        $collection->expects(self::once())->method('setOrder')->with('attribute_code', 'ASC');
        $collection->method('getItems')->willReturn($items);
        $this->collectionFactory->method('create')->willReturn($collection);
        $this->resource->expects(self::never())->method('save');

        self::assertSame(array_values($items), $this->repository->getActive());
    }

    public function testSaveNormalizesAndStores(): void
    {
        $rule = $this->rule([
            'attribute_code' => ' style ',
            'mode' => 'auto',
            'threshold' => '0.85',
            'instructions' => '   ',
            'extra_rules' => "  \n ",
            'attribute_set_ids' => ['9', 4, '9'],
            'fill_mode' => 'add_missing',
        ]);
        $this->resource->expects(self::once())->method('save')->with($rule);

        self::assertSame($rule, $this->repository->save($rule));
        self::assertSame('style', $rule->getData('attribute_code'));
        self::assertSame(0.85, $rule->getData('threshold'));
        self::assertNull($rule->getData('instructions'));
        self::assertNull($rule->getData('extra_rules'));
        self::assertSame('9,4', $rule->getData('attribute_set_ids'));
    }

    public function testSaveAppliesDefaultsAndCsvSetIds(): void
    {
        $rule = $this->rule(['attribute_code' => 'is_assembled', 'attribute_set_ids' => ' 4, 7 ,,4']);
        $this->resource->expects(self::once())->method('save');

        $this->repository->save($rule);

        self::assertSame('shadow', $rule->getData('mode'));
        self::assertSame('empty_only', $rule->getData('fill_mode'));
        self::assertSame(0, $rule->getData('treat_zero_as_empty'));
        self::assertSame(0.9, $rule->getData('threshold'));
        self::assertSame('4,7', $rule->getData('attribute_set_ids'));
    }

    public function testTreatZeroAsEmptyAllowedForBoolean(): void
    {
        $rule = $this->rule(['attribute_code' => 'is_assembled', 'treat_zero_as_empty' => '1']);
        $this->resource->expects(self::once())->method('save');

        $this->repository->save($rule);

        self::assertSame(1, $rule->getData('treat_zero_as_empty'));
        self::assertTrue($rule->isTreatZeroAsEmpty());
    }

    public function testSaveEmptySetIdsStoredAsNull(): void
    {
        $rule = $this->rule(['attribute_code' => 'is_assembled', 'attribute_set_ids' => []]);
        $this->resource->expects(self::once())->method('save');

        $this->repository->save($rule);

        self::assertNull($rule->getData('attribute_set_ids'));
    }

    public function testUpdatingTheSameRuleIsNotADuplicate(): void
    {
        $rule = $this->rule(['rule_id' => 7, 'attribute_code' => 'color', 'mode' => 'shadow']);
        $this->resource->expects(self::once())->method('save');

        $this->repository->save($rule);
    }

    public function testMultiselectInstructionsWithOptionPlaceholderAccepted(): void
    {
        $rule = $this->rule(['attribute_code' => 'style', 'instructions' => 'Is the {attribute} "{option}"?']);
        $this->resource->expects(self::once())->method('save');

        $this->repository->save($rule);

        self::assertSame('Is the {attribute} "{option}"?', $rule->getData('instructions'));
    }

    /**
     * @return array<string, array{0: array<string,mixed>, 1: string}>
     */
    public static function invalidRules(): array
    {
        return [
            'no attribute' => [['attribute_code' => '  '], 'Select an attribute'],
            'ineligible attribute' => [['attribute_code' => 'weight'], 'Attribute "weight" does not exist'],
            'duplicate attribute' => [['attribute_code' => 'color'], 'already exists'],
            'unknown mode' => [['attribute_code' => 'style', 'mode' => 'turbo'], 'Unknown mode'],
            'unknown fill mode' => [['attribute_code' => 'style', 'fill_mode' => 'overwrite'], 'Unknown fill mode'],
            'add_missing on select' => [['attribute_code' => 'is_assembled', 'fill_mode' => 'add_missing'], 'only'],
            'treat zero on select' => [
                ['attribute_code' => 'color', 'rule_id' => 7, 'treat_zero_as_empty' => 1],
                'Yes/No',
            ],
            'treat zero on multiselect' => [['attribute_code' => 'style', 'treat_zero_as_empty' => '1'], 'Yes/No'],
            'threshold 0.5' => [['attribute_code' => 'style', 'threshold' => 0.5], 'threshold'],
            'threshold 1' => [['attribute_code' => 'style', 'threshold' => '1'], 'threshold'],
            'threshold rounds to 1' => [['attribute_code' => 'style', 'threshold' => 0.99999], 'threshold'],
            'threshold low' => [['attribute_code' => 'style', 'threshold' => 0.3], 'threshold'],
            'threshold text' => [['attribute_code' => 'style', 'threshold' => 'high'], 'threshold'],
            'set id text' => [['attribute_code' => 'style', 'attribute_set_ids' => '4,x'], 'integers'],
            'set id negative' => [['attribute_code' => 'style', 'attribute_set_ids' => ['-4']], 'integers'],
            'set id float' => [['attribute_code' => 'style', 'attribute_set_ids' => [4.5]], 'integers'],
            'multiselect without {option}' => [
                ['attribute_code' => 'style', 'instructions' => 'Which styles apply?'],
                '{option}',
            ],
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    #[DataProvider('invalidRules')]
    public function testSaveRejectsInvalidRule(array $data, string $message): void
    {
        $this->resource->expects(self::never())->method('save');
        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage($message);

        $this->repository->save($this->rule($data));
    }

    public function testSaveWrapsStorageErrors(): void
    {
        $this->resource->expects(self::once())->method('save')->willThrowException(new \RuntimeException('Deadlock'));
        $this->expectException(CouldNotSaveException::class);
        $this->expectExceptionMessage('Deadlock');

        $this->repository->save($this->rule(['attribute_code' => 'style']));
    }

    public function testDelete(): void
    {
        $rule = $this->rule(['rule_id' => 7]);
        $this->resource->expects(self::once())->method('delete')->with($rule);

        $this->repository->delete($rule);
    }

    public function testDeleteWrapsStorageErrors(): void
    {
        $this->resource->expects(self::once())->method('delete')->willThrowException(new \RuntimeException('Locked'));
        $this->expectException(CouldNotDeleteException::class);

        $this->repository->delete($this->rule(['rule_id' => 7]));
    }
}

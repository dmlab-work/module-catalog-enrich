<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model;

use DmLab\CatalogEnrich\Exception\IneligibleAttributeException;
use DmLab\CatalogEnrich\Model\Attribute\AttributeCatalog;
use DmLab\CatalogEnrich\Model\Attribute\AttributeDescriptor;
use DmLab\CatalogEnrich\Model\Question\QuestionBuilder;
use DmLab\CatalogEnrich\Model\ResourceModel\Rule as RuleResource;
use DmLab\CatalogEnrich\Model\ResourceModel\Rule\CollectionFactory;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\ValidatorException;

/**
 * Loads, validates and stores enrichment rules.
 */
class RuleRepository
{
    /**
     * @param RuleFactory $ruleFactory
     * @param RuleResource $resource
     * @param CollectionFactory $collectionFactory
     * @param AttributeCatalog $attributeCatalog
     * @param QuestionBuilder $questionBuilder
     */
    public function __construct(
        private readonly RuleFactory $ruleFactory,
        private readonly RuleResource $resource,
        private readonly CollectionFactory $collectionFactory,
        private readonly AttributeCatalog $attributeCatalog,
        private readonly QuestionBuilder $questionBuilder
    ) {
    }

    /**
     * Rule by id.
     *
     * @param int $ruleId
     * @return Rule
     * @throws NoSuchEntityException
     */
    public function getById(int $ruleId): Rule
    {
        $rule = $this->ruleFactory->create();
        $this->resource->load($rule, $ruleId);
        if (!$rule->getId()) {
            throw new NoSuchEntityException(__('The enrichment rule with id "%1" does not exist.', $ruleId));
        }

        return $rule;
    }

    /**
     * Rule of an attribute, if any.
     *
     * @param string $code
     * @return Rule|null
     */
    public function getByAttributeCode(string $code): ?Rule
    {
        $rule = $this->ruleFactory->create();
        $this->resource->load($rule, $code, 'attribute_code');

        return $rule->getId() ? $rule : null;
    }

    /**
     * Rules whose mode is not off, by attribute code.
     *
     * @return Rule[]
     */
    public function getActive(): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('mode', ['neq' => Rule::MODE_OFF]);
        $collection->setOrder('attribute_code', 'ASC');

        return array_values($collection->getItems());
    }

    /**
     * Validate, normalize and store a rule.
     *
     * @param Rule $rule
     * @return Rule
     * @throws ValidatorException
     * @throws CouldNotSaveException
     */
    public function save(Rule $rule): Rule
    {
        $this->validate($rule);
        try {
            $this->resource->save($rule);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not save the enrichment rule: %1', $e->getMessage()), $e);
        }

        return $rule;
    }

    /**
     * Delete a rule.
     *
     * @param Rule $rule
     * @return void
     * @throws CouldNotDeleteException
     */
    public function delete(Rule $rule): void
    {
        try {
            $this->resource->delete($rule);
        } catch (\Exception $e) {
            throw new CouldNotDeleteException(__('Could not delete the enrichment rule: %1', $e->getMessage()), $e);
        }
    }

    /**
     * Apply the rule validation and normalize stored values.
     *
     * @param Rule $rule
     * @return void
     * @throws ValidatorException
     */
    private function validate(Rule $rule): void
    {
        $code = trim($rule->getAttributeCode());
        if ($code === '') {
            throw new ValidatorException(__('Select an attribute.'));
        }
        $rule->setData('attribute_code', $code);
        try {
            $descriptor = $this->attributeCatalog->get($code);
        } catch (IneligibleAttributeException $e) {
            throw new ValidatorException(__('%1', $e->getMessage()), $e);
        }

        $existing = $this->getByAttributeCode($code);
        if ($existing !== null && (int)$existing->getId() !== (int)$rule->getId()) {
            throw new ValidatorException(__('A rule for attribute "%1" already exists.', $code));
        }

        $mode = (string)($rule->getData('mode') ?? Rule::DEFAULT_MODE);
        if (!in_array($mode, Rule::MODES, true)) {
            throw new ValidatorException(__('Unknown mode "%1".', $mode));
        }
        $rule->setData('mode', $mode);

        $fillMode = (string)($rule->getData('fill_mode') ?? Rule::FILL_EMPTY_ONLY);
        if (!in_array($fillMode, Rule::FILL_MODES, true)) {
            throw new ValidatorException(__('Unknown fill mode "%1".', $fillMode));
        }
        if ($fillMode === Rule::FILL_ADD_MISSING
            && $descriptor->getInput() !== AttributeDescriptor::INPUT_MULTISELECT
        ) {
            throw new ValidatorException(__('The "add missing" fill mode is only for multiselect attributes.'));
        }
        $rule->setData('fill_mode', $fillMode);

        $treatZero = (int)(bool)$rule->getData('treat_zero_as_empty');
        if ($treatZero === 1 && $descriptor->getInput() !== AttributeDescriptor::INPUT_BOOLEAN) {
            throw new ValidatorException(__('"Treat No as empty" is only for Yes/No attributes.'));
        }
        $rule->setData('treat_zero_as_empty', $treatZero);

        // Rounded to the column scale first, so 0.99999 cannot be stored as 1.
        $threshold = $rule->getData('threshold') ?? Rule::DEFAULT_THRESHOLD;
        $threshold = is_numeric($threshold) ? round((float)$threshold, 4) : null;
        if ($threshold === null || $threshold <= 0.5 || $threshold >= 1.0) {
            throw new ValidatorException(__('The threshold must be greater than 0.5 and less than 1.'));
        }
        $rule->setData('threshold', $threshold);

        $rule->setData('attribute_set_ids', $this->normalizeSetIds($rule->getData('attribute_set_ids')));

        $instructions = $rule->getInstructions();
        $rule->setData('instructions', $instructions);
        $extraRules = trim((string)$rule->getData('extra_rules'));
        $rule->setData('extra_rules', $extraRules === '' ? null : $extraRules);

        try {
            $this->questionBuilder->build($descriptor, $instructions, $rule->getRules());
        } catch (\InvalidArgumentException $e) {
            throw new ValidatorException(__('The instructions are invalid: %1', $e->getMessage()));
        }
    }

    /**
     * CSV of set ids from an array or CSV; null when empty.
     *
     * @param mixed $value
     * @return string|null
     * @throws ValidatorException
     */
    private function normalizeSetIds(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        $parts = is_array($value) ? $value : explode(',', (string)$value);
        $ids = [];
        foreach ($parts as $part) {
            $part = is_int($part) ? (string)$part : (is_string($part) ? trim($part) : null);
            if ($part === '') {
                continue;
            }
            if ($part === null || !ctype_digit($part)) {
                throw new ValidatorException(__('Attribute set ids must be integers.'));
            }
            $ids[(int)$part] = (int)$part;
        }

        return $ids === [] ? null : implode(',', $ids);
    }
}

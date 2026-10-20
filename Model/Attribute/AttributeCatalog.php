<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Attribute;

use DmLab\CatalogEnrich\Exception\IneligibleAttributeException;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Eav\Model\Entity\Attribute\Source\Boolean;
use Magento\Eav\Model\Entity\Attribute\Source\Table;
use Magento\Framework\Exception\LocalizedException;

/**
 * Resolves product attributes the module can enrich into descriptors.
 */
class AttributeCatalog
{
    public const MAX_SELECT_OPTIONS = 254;
    public const RESERVED_LABEL = 'not stated';

    /**
     * @var array<string, AttributeDescriptor>
     */
    private array $descriptors = [];

    /**
     * @var AttributeDescriptor[]|null
     */
    private ?array $eligible = null;

    /**
     * @param EavConfig $eavConfig
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly EavConfig $eavConfig,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * Descriptor of an eligible attribute.
     *
     * @param string $code
     * @return AttributeDescriptor
     * @throws IneligibleAttributeException
     */
    public function get(string $code): AttributeDescriptor
    {
        if (isset($this->descriptors[$code])) {
            return $this->descriptors[$code];
        }
        try {
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $code);
        } catch (LocalizedException $e) {
            $attribute = null;
        }
        if (!$attribute instanceof AbstractAttribute || !$attribute->getAttributeId()) {
            throw new IneligibleAttributeException(__('Attribute "%1" does not exist.', $code));
        }

        return $this->descriptors[$code] = $this->describe($attribute);
    }

    /**
     * All eligible product attributes.
     *
     * @return AttributeDescriptor[]
     */
    public function getEligible(): array
    {
        if ($this->eligible !== null) {
            return $this->eligible;
        }
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('frontend_input', ['in' => [
            AttributeDescriptor::INPUT_SELECT,
            AttributeDescriptor::INPUT_MULTISELECT,
            AttributeDescriptor::INPUT_BOOLEAN,
        ]]);
        $collection->setOrder('frontend_label', 'ASC');

        $this->eligible = [];
        foreach ($collection->getItems() as $attribute) {
            $code = (string)$attribute->getAttributeCode();
            try {
                $this->descriptors[$code] ??= $this->describe($attribute);
            } catch (IneligibleAttributeException $e) {
                continue;
            }
            $this->eligible[] = $this->descriptors[$code];
        }

        return $this->eligible;
    }

    /**
     * Build a descriptor or explain why the attribute is ineligible.
     *
     * @param AbstractAttribute $attribute
     * @return AttributeDescriptor
     * @throws IneligibleAttributeException
     */
    private function describe(AbstractAttribute $attribute): AttributeDescriptor
    {
        $code = (string)$attribute->getAttributeCode();
        $input = (string)$attribute->getFrontendInput();
        $sourceModel = $attribute->getSourceModel();
        $sourceModel = is_string($sourceModel) ? ltrim($sourceModel, '\\') : $sourceModel;

        $allowedSource = match ($input) {
            AttributeDescriptor::INPUT_SELECT, AttributeDescriptor::INPUT_MULTISELECT => Table::class,
            AttributeDescriptor::INPUT_BOOLEAN => Boolean::class,
            default => throw new IneligibleAttributeException(
                __('Attribute "%1" has input "%2"; only select, multiselect and boolean are supported.', $code, $input)
            ),
        };
        $tableBacked = $input !== AttributeDescriptor::INPUT_BOOLEAN && empty($sourceModel);
        if (!$tableBacked && $sourceModel !== $allowedSource) {
            throw new IneligibleAttributeException(__('Attribute "%1" uses an unsupported source model.', $code));
        }

        $label = trim((string)$attribute->getDefaultFrontendLabel());
        $descriptor = new AttributeDescriptor(
            (int)$attribute->getAttributeId(),
            $code,
            $label !== '' ? $label : $code,
            $input,
            (string)$attribute->getBackend()->getTable(),
            $input === AttributeDescriptor::INPUT_BOOLEAN ? [] : $this->loadOptions($attribute)
        );
        $this->assertOptions($descriptor);

        return $descriptor;
    }

    /**
     * Store-0 option labels in source order.
     *
     * @param AbstractAttribute $attribute
     * @return array<int|string, mixed>
     */
    private function loadOptions(AbstractAttribute $attribute): array
    {
        // The attribute instance is shared via EavConfig: restore its store afterwards.
        $hadStore = $attribute->hasData('store_id');
        $previousStore = $attribute->getData('store_id');
        $attribute->setData('store_id', 0);
        try {
            $all = $attribute->getSource()->getAllOptions(false, true);
        } finally {
            if ($hadStore) {
                $attribute->setData('store_id', $previousStore);
            } else {
                $attribute->unsetData('store_id');
            }
        }
        $options = [];
        foreach ($all as $option) {
            if (!is_array($option) || !isset($option['value']) || !is_numeric($option['value'])) {
                continue;
            }
            $options[(int)$option['value']] ??= $option['label'] ?? '';
        }

        return $options;
    }

    /**
     * Enforce option count limits and the reserved label.
     *
     * @param AttributeDescriptor $descriptor
     * @return void
     * @throws IneligibleAttributeException
     */
    private function assertOptions(AttributeDescriptor $descriptor): void
    {
        $code = $descriptor->getCode();
        $options = $descriptor->getOptions();
        if ($options === []) {
            throw new IneligibleAttributeException(__('Attribute "%1" has no options.', $code));
        }
        if ($descriptor->getInput() === AttributeDescriptor::INPUT_SELECT
            && count($options) > self::MAX_SELECT_OPTIONS
        ) {
            throw new IneligibleAttributeException(__(
                'Attribute "%1" has %2 options; at most %3 are supported.',
                $code,
                count($options),
                self::MAX_SELECT_OPTIONS
            ));
        }
        foreach ($options as $optionLabel) {
            if (mb_strtolower($optionLabel, 'UTF-8') === self::RESERVED_LABEL) {
                throw new IneligibleAttributeException(
                    __('Attribute "%1" has an option labelled "%2", which is reserved.', $code, $optionLabel)
                );
            }
        }
    }
}

<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Ui\Component\Listing\Column;

use DmLab\CatalogEnrich\Exception\IneligibleAttributeException;
use DmLab\CatalogEnrich\Model\Attribute\AttributeCatalog;
use DmLab\CatalogEnrich\Model\Attribute\AttributeDescriptor;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Renders raw option ids of the row's attribute as comma-joined labels.
 */
class OptionLabels extends Column
{
    private const EMPTY = '—';

    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param AttributeCatalog $attributeCatalog
     * @param array $components
     * @param array $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly AttributeCatalog $attributeCatalog,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @inheritdoc
     */
    public function prepareDataSource(array $dataSource)
    {
        $field = (string)$this->getData('name');
        foreach ($dataSource['data']['items'] ?? [] as $i => $item) {
            $dataSource['data']['items'][$i][$field] = $this->labels(
                (string)($item['attribute_code'] ?? ''),
                $item[$field] ?? null
            );
        }

        return $dataSource;
    }

    /**
     * Labels of a raw value; unknown ids as `#<id>`.
     *
     * @param string $code
     * @param mixed $raw
     * @return string
     */
    protected function labels(string $code, mixed $raw): string
    {
        if (!is_scalar($raw) || trim((string)$raw) === '') {
            return self::EMPTY;
        }
        $descriptor = $this->descriptor($code);
        $options = $descriptor?->getOptions() ?? [];
        $boolean = $descriptor?->getInput() === AttributeDescriptor::INPUT_BOOLEAN;

        $labels = [];
        foreach (explode(',', (string)$raw) as $id) {
            $id = trim($id);
            if ($id === '') {
                continue;
            }
            $label = ctype_digit($id) ? ($options[(int)$id] ?? null) : null;
            $labels[] = match (true) {
                $label === null => '#' . $id,
                $boolean => (string)__($label),
                default => $label,
            };
        }

        return $labels === [] ? self::EMPTY : implode(', ', $labels);
    }

    /**
     * Descriptor of the attribute, or null when it is no longer eligible.
     *
     * @param string $code
     * @return AttributeDescriptor|null
     */
    protected function descriptor(string $code): ?AttributeDescriptor
    {
        if ($code === '') {
            return null;
        }
        try {
            return $this->attributeCatalog->get($code);
        } catch (IneligibleAttributeException $e) {
            return null;
        }
    }
}

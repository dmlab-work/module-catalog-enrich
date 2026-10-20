<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Ui\Component\Listing\Column;

use DmLab\CatalogEnrich\Model\Attribute\AttributeCatalog;
use DmLab\CatalogEnrich\Model\ResourceModel\CatalogQuery;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;

/**
 * Renders the product's live store-0 value of the row's attribute as labels.
 *
 * Without a store-0 value, the distinct store-view values are shown with a hint.
 */
class CurrentValue extends OptionLabels
{
    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param AttributeCatalog $attributeCatalog
     * @param CatalogQuery $catalogQuery
     * @param array $components
     * @param array $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        AttributeCatalog $attributeCatalog,
        private readonly CatalogQuery $catalogQuery,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $attributeCatalog, $components, $data);
    }

    /**
     * @inheritdoc
     */
    public function prepareDataSource(array $dataSource)
    {
        $items = $dataSource['data']['items'] ?? [];
        $productIds = [];
        foreach ($items as $item) {
            $productIds[(string)($item['attribute_code'] ?? '')][] = (int)($item['product_id'] ?? 0);
        }
        $values = [];
        foreach ($productIds as $code => $ids) {
            $descriptor = $this->descriptor((string)$code);
            $values[$code] = $descriptor === null ? [] : $this->catalogQuery->fetchValuesByStore($descriptor, $ids);
        }

        $field = (string)$this->getData('name');
        foreach ($items as $i => $item) {
            $code = (string)($item['attribute_code'] ?? '');
            $dataSource['data']['items'][$i][$field] = $this->currentLabels(
                $code,
                $values[$code][(int)($item['product_id'] ?? 0)] ?? []
            );
        }

        return $dataSource;
    }

    /**
     * Labels of the store-0 value, else of the store-view values with a hint.
     *
     * @param string $code
     * @param array<int,string> $byStore store id => raw value
     * @return string
     */
    private function currentLabels(string $code, array $byStore): string
    {
        if (isset($byStore[0]) && trim($byStore[0]) !== '') {
            return $this->labels($code, $byStore[0]);
        }
        unset($byStore[0]);
        $ids = [];
        foreach ($byStore as $raw) {
            foreach (explode(',', $raw) as $id) {
                $id = trim($id);
                if ($id !== '') {
                    $ids[$id] = $id;
                }
            }
        }
        if ($ids === []) {
            return $this->labels($code, null);
        }

        return (string)__('%1 (store view)', $this->labels($code, implode(',', $ids)));
    }
}

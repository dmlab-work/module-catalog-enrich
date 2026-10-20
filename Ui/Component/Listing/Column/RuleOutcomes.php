<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Ui\Component\Listing\Column;

use DmLab\CatalogEnrich\Model\Attempt\Source\Outcome;
use DmLab\CatalogEnrich\Model\ResourceModel\Attempt as AttemptResource;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Rules grid: attempt ledger outcome counts of each rule's attribute, one grouped query per page.
 */
class RuleOutcomes extends Column
{
    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param AttemptResource $attemptResource
     * @param Outcome $outcome
     * @param array $components
     * @param array $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly AttemptResource $attemptResource,
        private readonly Outcome $outcome,
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
        $items = $dataSource['data']['items'] ?? [];
        if ($items === []) {
            return $dataSource;
        }
        $counts = $this->attemptResource->countOutcomes(
            array_map(static fn (array $item): string => (string)($item['attribute_code'] ?? ''), $items)
        );
        $field = (string)$this->getData('name');
        foreach ($items as $i => $item) {
            $code = (string)($item['attribute_code'] ?? '');
            $dataSource['data']['items'][$i][$field] = $this->format($counts[$code] ?? []);
        }

        return $dataSource;
    }

    /**
     * "Accepted: 3, Not stated: 1" in outcome order; zero counts are left out.
     *
     * @param array<string,int> $counts
     * @return string
     */
    private function format(array $counts): string
    {
        $parts = [];
        foreach ($this->outcome->toOptionArray() as $option) {
            $count = $counts[$option['value']] ?? 0;
            if ($count > 0) {
                $parts[] = $option['label'] . ': ' . $count;
            }
        }

        return $parts === [] ? (string)__('No attempts yet') : implode(', ', $parts);
    }
}

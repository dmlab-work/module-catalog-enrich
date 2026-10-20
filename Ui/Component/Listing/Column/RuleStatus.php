<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Ui\Component\Listing\Column;

use DmLab\CatalogEnrich\Model\RuleFactory;
use DmLab\CatalogEnrich\Model\Run\AttributePlanner;
use DmLab\TypeSafeCore\Api\ConfigInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Rules grid: "Active", "Off", "Skipped: <reason>" when a run would ignore the rule, or "Inactive" without TypeSafe.
 */
class RuleStatus extends Column
{
    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param RuleFactory $ruleFactory
     * @param AttributePlanner $attributePlanner
     * @param ConfigInterface $coreConfig
     * @param array $components
     * @param array $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly RuleFactory $ruleFactory,
        private readonly AttributePlanner $attributePlanner,
        private readonly ConfigInterface $coreConfig,
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
        $configured = $this->coreConfig->isConfigured();
        foreach ($dataSource['data']['items'] ?? [] as $i => $item) {
            $rule = $this->ruleFactory->create(['data' => $item]);
            if (!$rule->isActive()) {
                $status = __('Off');
            } elseif (!$configured) {
                $status = __('Inactive: TypeSafe not configured');
            } else {
                $reason = $this->attributePlanner->skipReason($rule);
                $status = $reason === null ? __('Active') : __('Skipped: %1', $reason);
            }
            $dataSource['data']['items'][$i][$field] = (string)$status;
        }

        return $dataSource;
    }
}

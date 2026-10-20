<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\ResourceModel\Attempt\Grid;

use DmLab\CatalogEnrich\Model\ResourceModel\Suggestion\Grid\SelectJoiner;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface as FetchStrategy;
use Magento\Framework\Data\Collection\EntityFactoryInterface as EntityFactory;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Psr\Log\LoggerInterface as Logger;

/**
 * Attempt ledger grid collection with product and attribute columns.
 */
class Collection extends SearchResult
{
    /**
     * Grid field => qualified column; aliases of the joined tables come from SelectJoiner.
     */
    public const FILTER_MAP = [
        'attempt_id' => 'main_table.attempt_id',
        'product_id' => 'main_table.product_id',
        'attribute_code' => 'main_table.attribute_code',
        'outcome' => 'main_table.outcome',
        'attempts' => 'main_table.attempts',
        'error_message' => 'main_table.error_message',
        'model' => 'main_table.model',
        'attempted_at' => 'main_table.attempted_at',
        'next_attempt_at' => 'main_table.next_attempt_at',
        'sku' => SelectJoiner::FILTER_MAP['sku'],
        'product_name' => SelectJoiner::FILTER_MAP['product_name'],
        'attribute_label' => SelectJoiner::FILTER_MAP['attribute_label'],
    ];

    /**
     * @param EntityFactory $entityFactory
     * @param Logger $logger
     * @param FetchStrategy $fetchStrategy
     * @param EventManager $eventManager
     * @param SelectJoiner $selectJoiner
     * @param string $mainTable
     * @param string|null $resourceModel
     * @param string|null $identifierName
     * @param string|null $connectionName
     */
    public function __construct(
        EntityFactory $entityFactory,
        Logger $logger,
        FetchStrategy $fetchStrategy,
        EventManager $eventManager,
        private readonly SelectJoiner $selectJoiner,
        $mainTable,
        $resourceModel = null,
        $identifierName = null,
        $connectionName = null
    ) {
        parent::__construct(
            $entityFactory,
            $logger,
            $fetchStrategy,
            $eventManager,
            $mainTable,
            $resourceModel,
            $identifierName,
            $connectionName
        );
    }

    /**
     * @inheritdoc
     */
    protected function _initSelect()
    {
        parent::_initSelect();
        $this->selectJoiner->join($this->getSelect());
        foreach (self::FILTER_MAP as $field => $column) {
            $this->addFilterToMap($field, $column);
        }

        return $this;
    }
}

<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Ui\DataProvider;

use DmLab\CatalogEnrich\Model\ResourceModel\Rule\CollectionFactory;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;

/**
 * Rule form data: the stored rule, or the data posted back after a failed save.
 */
class RuleFormDataProvider extends AbstractDataProvider
{
    public const PERSISTOR_KEY = 'dmlab_catalog_enrich_rule';

    /**
     * @var array<int|string, array<string, mixed>>|null
     */
    private ?array $loadedData = null;

    /**
     * @param string $name
     * @param string $primaryFieldName
     * @param string $requestFieldName
     * @param CollectionFactory $collectionFactory
     * @param DataPersistorInterface $dataPersistor
     * @param RequestInterface $request
     * @param array $meta
     * @param array $data
     */
    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        CollectionFactory $collectionFactory,
        private readonly DataPersistorInterface $dataPersistor,
        private readonly RequestInterface $request,
        array $meta = [],
        array $data = []
    ) {
        $this->collection = $collectionFactory->create();
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    /**
     * Form data by rule id ('' for a new rule); attribute set ids as an array.
     *
     * @return array
     */
    public function getData()
    {
        if ($this->loadedData !== null) {
            return $this->loadedData;
        }
        $this->loadedData = [];
        foreach ($this->collection->getItems() as $rule) {
            if ($rule->getId() !== null) {
                $this->loadedData[$rule->getId()] = $this->prepare($rule->getData());
            }
        }

        $persisted = $this->dataPersistor->get(self::PERSISTOR_KEY);
        if (is_array($persisted) && $persisted !== []) {
            $key = $persisted['rule_id'] ?? '';
            $this->loadedData[$key] = $this->prepare(array_merge($this->loadedData[$key] ?? [], $persisted));
            $this->dataPersistor->clear(self::PERSISTOR_KEY);
        }

        return $this->loadedData;
    }

    /**
     * The attribute of an existing rule cannot change.
     *
     * @return array
     */
    public function getMeta()
    {
        $meta = parent::getMeta();
        if ((int)$this->request->getParam($this->getRequestFieldName()) > 0) {
            $meta['general']['children']['attribute_code']['arguments']['data']['config']['disabled'] = true;
        }

        return $meta;
    }

    /**
     * Normalize one row for the form.
     *
     * @param array $row
     * @return array
     */
    private function prepare(array $row): array
    {
        $ids = $row['attribute_set_ids'] ?? null;
        if (!is_array($ids)) {
            $ids = array_values(array_filter(
                array_map('trim', explode(',', (string)$ids)),
                static fn (string $id): bool => $id !== ''
            ));
        }
        $row['attribute_set_ids'] = array_map('strval', $ids);

        return $row;
    }
}

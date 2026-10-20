<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Ui\Component\Listing\Column;

use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Probability with two decimals.
 */
class Probability extends Column
{
    /**
     * @inheritdoc
     */
    public function prepareDataSource(array $dataSource)
    {
        $field = (string)$this->getData('name');
        foreach ($dataSource['data']['items'] ?? [] as $i => $item) {
            $value = $item[$field] ?? null;
            if (is_numeric($value)) {
                $dataSource['data']['items'][$i][$field] = number_format((float)$value, 2, '.', '');
            }
        }

        return $dataSource;
    }
}

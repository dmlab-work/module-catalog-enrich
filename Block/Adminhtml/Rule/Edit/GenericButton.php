<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Block\Adminhtml\Rule\Edit;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;

/**
 * Shared helpers for the rule form buttons.
 */
class GenericButton
{
    /**
     * @param UrlInterface $urlBuilder
     * @param RequestInterface $request
     */
    public function __construct(
        protected readonly UrlInterface $urlBuilder,
        protected readonly RequestInterface $request
    ) {
    }

    /**
     * Id of the edited rule; null on a new rule.
     *
     * @return int|null
     */
    public function getRuleId(): ?int
    {
        $id = (int)$this->request->getParam('id');

        return $id > 0 ? $id : null;
    }

    /**
     * Backend URL.
     *
     * @param string $route
     * @param array $params
     * @return string
     */
    public function getUrl(string $route = '', array $params = []): string
    {
        return $this->urlBuilder->getUrl($route, $params);
    }
}

<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Ui\Component\MassAction;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Ui\Component\Action;

/**
 * Mass action hidden for roles without the ACL resource in its `aclResource` config item.
 *
 * Relies on `actionDisable`, which the generic `Magento\Ui\Component\MassAction` honours.
 */
class AclAction extends Action
{
    /**
     * @param ContextInterface $context
     * @param AuthorizationInterface $authorization
     * @param array $components
     * @param array $data
     * @param array|\JsonSerializable|null $actions
     */
    public function __construct(
        ContextInterface $context,
        private readonly AuthorizationInterface $authorization,
        array $components = [],
        array $data = [],
        $actions = null
    ) {
        parent::__construct($context, $components, $data, $actions);
    }

    /**
     * @inheritdoc
     */
    public function prepare()
    {
        $config = $this->getConfiguration();
        $resource = $config['aclResource'] ?? null;
        if (is_string($resource) && $resource !== '' && !$this->authorization->isAllowed($resource)) {
            $config['actionDisable'] = true;
            $this->setData('config', $config);
        }

        parent::prepare();
    }
}

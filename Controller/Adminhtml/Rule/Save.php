<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Controller\Adminhtml\Rule;

use DmLab\CatalogEnrich\Model\RuleFactory;
use DmLab\CatalogEnrich\Model\RuleRepository;
use DmLab\CatalogEnrich\Ui\DataProvider\RuleFormDataProvider;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Saves the rule form; on a validation error returns to the form with the posted data.
 */
class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'DmLab_CatalogEnrich::rules';

    private const FIELDS = ['mode', 'threshold', 'instructions', 'extra_rules', 'fill_mode', 'treat_zero_as_empty'];

    /**
     * @param Context $context
     * @param RuleRepository $ruleRepository
     * @param RuleFactory $ruleFactory
     * @param DataPersistorInterface $dataPersistor
     */
    public function __construct(
        Context $context,
        private readonly RuleRepository $ruleRepository,
        private readonly RuleFactory $ruleFactory,
        private readonly DataPersistorInterface $dataPersistor
    ) {
        parent::__construct($context);
    }

    /**
     * Save the posted rule.
     *
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $data = $this->getRequest()->getPostValue();
        if (!is_array($data) || $data === []) {
            return $redirect->setPath('*/*/');
        }

        $ruleId = (int)($data['rule_id'] ?? 0);
        try {
            $rule = $ruleId > 0 ? $this->ruleRepository->getById($ruleId) : $this->ruleFactory->create();
        } catch (NoSuchEntityException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $redirect->setPath('*/*/');
        }

        $values = array_intersect_key($data, array_flip(self::FIELDS));
        if ($ruleId <= 0) {
            $values['attribute_code'] = (string)($data['attribute_code'] ?? '');
        }
        // A cleared multiselect is not posted at all.
        $values['attribute_set_ids'] = $this->setIdsCsv($data['attribute_set_ids'] ?? null);
        $rule->addData($values);

        try {
            $this->ruleRepository->save($rule);
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            $this->dataPersistor->set(RuleFormDataProvider::PERSISTOR_KEY, array_merge($data, $values));
            return $ruleId > 0
                ? $redirect->setPath('*/*/edit', ['id' => $ruleId])
                : $redirect->setPath('*/*/new');
        }

        $this->messageManager->addSuccessMessage(__(
            'The rule for "%1" has been saved. Mode, threshold and fill mode changes are applied on the next '
            . 'enrichment run.',
            $rule->getAttributeCode()
        ));
        $this->dataPersistor->clear(RuleFormDataProvider::PERSISTOR_KEY);

        return $redirect->setPath('*/*/');
    }

    /**
     * Multiselect value (array or CSV) as CSV; null when empty.
     *
     * @param mixed $value
     * @return string|null
     */
    private function setIdsCsv(mixed $value): ?string
    {
        $parts = is_array($value) ? $value : explode(',', (string)$value);
        $parts = array_filter(
            array_map(static fn ($part): string => is_scalar($part) ? trim((string)$part) : '', $parts),
            static fn (string $part): bool => $part !== ''
        );

        return $parts === [] ? null : implode(',', $parts);
    }
}

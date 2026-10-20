<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Run;

use DmLab\CatalogEnrich\Exception\IneligibleAttributeException;
use DmLab\CatalogEnrich\Model\Attribute\AttributeCatalog;
use DmLab\CatalogEnrich\Model\Attribute\AttributeDescriptor;
use DmLab\CatalogEnrich\Model\ResourceModel\CatalogQuery;
use DmLab\CatalogEnrich\Model\Rule;
use DmLab\CatalogEnrich\Model\RuleRepository;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Resolves the active rules of a run into attribute plans.
 */
class AttributePlanner
{
    /**
     * @param RuleRepository $ruleRepository
     * @param AttributeCatalog $attributeCatalog
     * @param CatalogQuery $catalogQuery
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RuleRepository $ruleRepository,
        private readonly AttributeCatalog $attributeCatalog,
        private readonly CatalogQuery $catalogQuery,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Plans of every active rule, or of the single given attribute (which must have an active rule).
     *
     * @param string|null $attributeCode
     * @return AttributePlan[]
     * @throws LocalizedException
     */
    public function plan(?string $attributeCode = null): array
    {
        $plans = [];
        foreach ($this->rules($attributeCode) as $rule) {
            $plan = $this->planRule($rule);
            if ($plan !== null) {
                $plans[] = $plan;
            }
        }

        return $plans;
    }

    /**
     * Why an active rule would do nothing in a run: its attribute is ineligible or in none of its sets; null = runs.
     *
     * @param Rule $rule
     * @return string|null
     */
    public function skipReason(Rule $rule): ?string
    {
        try {
            $descriptor = $this->attributeCatalog->get($rule->getAttributeCode());
        } catch (IneligibleAttributeException $e) {
            return $e->getMessage();
        }

        return $this->setIds($rule, $descriptor) === []
            ? (string)__('None of the selected attribute sets contains the attribute.')
            : null;
    }

    /**
     * Active rules to plan.
     *
     * @param string|null $attributeCode
     * @return Rule[]
     * @throws LocalizedException
     */
    private function rules(?string $attributeCode): array
    {
        if ($attributeCode === null) {
            return $this->ruleRepository->getActive();
        }
        $rule = $this->ruleRepository->getByAttributeCode($attributeCode);
        if ($rule === null || !$rule->isActive()) {
            throw new LocalizedException(__('There is no active enrichment rule for attribute "%1".', $attributeCode));
        }

        return [$rule];
    }

    /**
     * Plan of one rule; null (logged) when the attribute is ineligible.
     *
     * @param Rule $rule
     * @return AttributePlan|null
     */
    private function planRule(Rule $rule): ?AttributePlan
    {
        try {
            $descriptor = $this->attributeCatalog->get($rule->getAttributeCode());
        } catch (IneligibleAttributeException $e) {
            $this->logger->warning('DmLab_CatalogEnrich: rule skipped, attribute is not eligible.', [
                'attribute_code' => $rule->getAttributeCode(),
                'reason' => $e->getMessage(),
            ]);

            return null;
        }

        return new AttributePlan($rule, $descriptor, $this->setIds($rule, $descriptor));
    }

    /**
     * Sets that contain the attribute, limited to the rule's sets when it has any.
     *
     * @param Rule $rule
     * @param AttributeDescriptor $descriptor
     * @return int[]
     */
    private function setIds(Rule $rule, AttributeDescriptor $descriptor): array
    {
        $setIds = $this->catalogQuery->setIdsForAttribute($descriptor->getAttributeId());
        $ruleSetIds = $rule->getAttributeSetIds();

        return $ruleSetIds === [] ? $setIds : array_values(array_intersect($setIds, $ruleSetIds));
    }
}

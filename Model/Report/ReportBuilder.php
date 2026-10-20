<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Model\Report;

use DmLab\CatalogEnrich\Exception\IneligibleAttributeException;
use DmLab\CatalogEnrich\Model\Attribute\AttributeCatalog;
use DmLab\CatalogEnrich\Model\Attribute\AttributeDescriptor;
use DmLab\CatalogEnrich\Model\Calibration\Calibrator;
use DmLab\CatalogEnrich\Model\ResourceModel\CatalogQuery;
use DmLab\CatalogEnrich\Model\ResourceModel\Suggestion as SuggestionResource;
use DmLab\CatalogEnrich\Model\Rule;
use DmLab\CatalogEnrich\Model\RuleRepository;
use DmLab\CatalogEnrich\Model\Run\RunOptions;
use DmLab\CatalogEnrich\Model\Suggestion;
use DmLab\TypeSafeCore\Api\UsageInterface;

/**
 * Builds the fill-rate / usage report over the active rules.
 */
class ReportBuilder
{
    /**
     * @param RuleRepository $ruleRepository
     * @param AttributeCatalog $attributeCatalog
     * @param CatalogQuery $catalogQuery
     * @param SuggestionResource $suggestionResource
     * @param UsageInterface $usage
     */
    public function __construct(
        private readonly RuleRepository $ruleRepository,
        private readonly AttributeCatalog $attributeCatalog,
        private readonly CatalogQuery $catalogQuery,
        private readonly SuggestionResource $suggestionResource,
        private readonly UsageInterface $usage
    ) {
    }

    /**
     * Report over every active rule.
     *
     * @return Report
     */
    public function build(): Report
    {
        $rows = [];
        $skipped = [];
        foreach ($this->ruleRepository->getActive() as $rule) {
            try {
                $rows[] = $this->row($rule);
            } catch (IneligibleAttributeException $e) {
                $skipped[$rule->getAttributeCode()] = $e->getMessage();
            }
        }

        return new Report(
            $rows,
            $skipped,
            $this->usage->getMonthTokens(RunOptions::DEFAULT_CONSUMER),
            $this->usage->getMonthTokens(Calibrator::CONSUMER),
            $this->usage->getRemainingBudget()
        );
    }

    /**
     * One rule's row.
     *
     * @param Rule $rule
     * @return array<string,mixed>
     * @throws IneligibleAttributeException
     */
    private function row(Rule $rule): array
    {
        $descriptor = $this->attributeCatalog->get($rule->getAttributeCode());
        $setIds = $this->catalogQuery->setIdsForAttribute($descriptor->getAttributeId());
        if ($rule->getAttributeSetIds() !== []) {
            $setIds = array_values(array_intersect($setIds, $rule->getAttributeSetIds()));
        }
        $zeroIsEmpty = $rule->isTreatZeroAsEmpty() && $descriptor->getInput() === AttributeDescriptor::INPUT_BOOLEAN;
        $eligible = $this->catalogQuery->countInSets($setIds);
        $filled = $this->catalogQuery->countFilled($descriptor, $setIds, $zeroIsEmpty);
        $counts = $this->suggestionResource->countByStatus($descriptor->getCode());
        $statuses = [];
        foreach (Suggestion::STATUSES as $status) {
            $statuses[$status] = (int)($counts[$status] ?? 0);
        }
        // add_missing applies to filled products, so only applies onto an empty value change the fill rate.
        $filledByApply = $this->suggestionResource->countAppliedToEmpty($descriptor->getCode(), $setIds, $zeroIsEmpty);

        return [
            'attribute' => $descriptor->getCode(),
            'mode' => $rule->getMode(),
            'eligible' => $eligible,
            'filled' => $filled,
            'filled_by_apply' => $filledByApply,
            'fill_before' => $this->ratio(max(0, $filled - $filledByApply), $eligible),
            'fill_now' => $this->ratio($filled, $eligible),
            'statuses' => $statuses,
        ];
    }

    /**
     * Share of eligible products; null when there are none.
     *
     * @param int $count
     * @param int $eligible
     * @return float|null
     */
    private function ratio(int $count, int $eligible): ?float
    {
        return $eligible > 0 ? min(1.0, $count / $eligible) : null;
    }
}

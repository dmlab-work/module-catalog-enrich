<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Console\Command;

use DmLab\CatalogEnrich\Model\Run\Enricher;
use DmLab\CatalogEnrich\Model\Run\LastRunStore;
use DmLab\CatalogEnrich\Model\Run\ProductSelector;
use DmLab\CatalogEnrich\Model\Run\RunOptions;
use DmLab\CatalogEnrich\Model\Run\RunReport;
use DmLab\CatalogEnrich\Model\Suggestion;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `dmlab:catalog-enrich:run` — enriches products with the active rules.
 */
class RunCommand extends Command
{
    public const NAME = 'dmlab:catalog-enrich:run';
    public const LOCK_NAME = 'dmlab_catalog_enrich_run';

    private const OPTION_ATTRIBUTE = 'attribute';
    private const OPTION_SKU = 'sku';
    private const OPTION_LIMIT = 'limit';
    private const OPTION_FORCE = 'force';
    private const OPTION_DRY_RUN = 'dry-run';

    /**
     * @param ProductSelector $productSelector
     * @param Enricher $enricher
     * @param LockManagerInterface $lockManager
     * @param State $appState
     * @param LastRunStore $lastRunStore
     */
    public function __construct(
        private readonly ProductSelector $productSelector,
        private readonly Enricher $enricher,
        private readonly LockManagerInterface $lockManager,
        private readonly State $appState,
        private readonly LastRunStore $lastRunStore
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Fills empty product attributes from product text using the active enrichment rules.')
            ->addOption(self::OPTION_ATTRIBUTE, null, InputOption::VALUE_REQUIRED, 'Only this attribute code')
            ->addOption(self::OPTION_SKU, null, InputOption::VALUE_REQUIRED, 'Comma-separated SKUs')
            ->addOption(self::OPTION_LIMIT, null, InputOption::VALUE_REQUIRED, 'Maximum number of products')
            ->addOption(self::OPTION_FORCE, null, InputOption::VALUE_NONE, 'Ask again even when nothing changed')
            ->addOption(self::OPTION_DRY_RUN, null, InputOption::VALUE_NONE, 'Show what would be asked; no requests');
        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $skus = $this->parseSkus($input->getOption(self::OPTION_SKU));
            $limit = $this->parseLimit($input->getOption(self::OPTION_LIMIT));
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $attribute = $input->getOption(self::OPTION_ATTRIBUTE);
        $options = new RunOptions(
            $attribute === null ? null : trim((string)$attribute),
            (bool)$input->getOption(self::OPTION_FORCE),
            (bool)$input->getOption(self::OPTION_DRY_RUN)
        );

        if (!$this->lockManager->lock(self::LOCK_NAME, 0)) {
            $output->writeln('<error>Another enrichment run is in progress.</error>');
            return Command::FAILURE;
        }
        try {
            $this->setAreaCode();
            $productIds = $this->productSelector->select($skus, $limit, 0, $options->getAttributeCode());
            if ($productIds === []) {
                $output->writeln('No products to enrich.');
                return Command::SUCCESS;
            }
            $report = $this->enricher->run($productIds, $options);
        } catch (LocalizedException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }

        if ($options->isDryRun()) {
            $this->printLines($report, $output);
        } else {
            $this->lastRunStore->save(LastRunStore::SOURCE_CLI, $report);
            $this->printSummary($report, $output);
        }
        if ($report->getStopReason() !== null) {
            $output->writeln('<error>Stopped: ' . $report->getStopReason() . '</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * SKUs from the comma-separated option; null when not given.
     *
     * @param mixed $value
     * @return string[]|null
     */
    private function parseSkus(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        $skus = array_values(array_unique(array_filter(
            array_map('trim', explode(',', (string)$value)),
            static fn (string $sku): bool => $sku !== ''
        )));
        if ($skus === []) {
            throw new \InvalidArgumentException('--sku must list at least one SKU.');
        }

        return $skus;
    }

    /**
     * Positive limit; null when not given.
     *
     * @param mixed $value
     * @return int|null
     */
    private function parseLimit(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string)$value);
        if (!ctype_digit($value) || (int)$value < 1) {
            throw new \InvalidArgumentException('--limit must be a positive integer.');
        }

        return (int)$value;
    }

    /**
     * Run in the admin area (product saves and EAV sources expect one).
     *
     * @return void
     */
    private function setAreaCode(): void
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (LocalizedException) {
            return; // Area already set.
        }
    }

    /**
     * Dry-run lines.
     *
     * @param RunReport $report
     * @param OutputInterface $output
     * @return void
     */
    private function printLines(RunReport $report, OutputInterface $output): void
    {
        $lines = $report->getLines();
        if ($lines === [] && $report->getStopReason() === null) {
            $output->writeln('Nothing to ask.');
        }
        foreach ($lines as $line) {
            $output->writeln($line);
        }
    }

    /**
     * Run counters.
     *
     * @param RunReport $report
     * @param OutputInterface $output
     * @return void
     */
    private function printSummary(RunReport $report, OutputInterface $output): void
    {
        $suggestions = $report->getSuggestions();
        $output->writeln(sprintf(
            'Products: %d  Requests: %d (cached %d)  Input tokens: %d',
            $report->get(RunReport::PRODUCTS),
            $report->get(RunReport::REQUESTS),
            $report->get(RunReport::CACHED),
            $report->get(RunReport::INPUT_TOKENS)
        ));
        $output->writeln(sprintf(
            'Asked: %d  Skipped (unchanged): %d  Skipped (backoff): %d',
            $report->get(RunReport::ASKED),
            $report->get(RunReport::SKIPPED_FINAL),
            $report->get(RunReport::SKIPPED_BACKOFF)
        ));
        $output->writeln(sprintf(
            'Outcomes: accepted %d, not_stated %d, uncertain %d, error %d, rejected %d',
            $report->get(RunReport::ACCEPTED),
            $report->get(RunReport::NOT_STATED),
            $report->get(RunReport::UNCERTAIN),
            $report->get(RunReport::ERRORS),
            $report->get(RunReport::REJECTED)
        ));
        $output->writeln(sprintf(
            'Suggestions: shadow %d, pending %d, applied %d',
            $suggestions[Suggestion::STATUS_SHADOW] ?? 0,
            $suggestions[Suggestion::STATUS_PENDING] ?? 0,
            $suggestions[Suggestion::STATUS_APPLIED] ?? 0
        ));
    }
}

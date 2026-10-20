<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Console\Command;

use DmLab\CatalogEnrich\Model\Report\Report;
use DmLab\CatalogEnrich\Model\Report\ReportBuilder;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `dmlab:catalog-enrich:report` — fill rates per active rule and the month's token usage.
 */
class ReportCommand extends Command
{
    public const NAME = 'dmlab:catalog-enrich:report';

    private const HEADERS = [
        'attribute', 'mode', 'eligible', 'fill before', 'fill now',
        'shadow', 'pending', 'applied', 'rejected', 'reverted',
    ];

    /**
     * @param ReportBuilder $reportBuilder
     * @param State $appState
     */
    public function __construct(
        private readonly ReportBuilder $reportBuilder,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Shows fill rates per active enrichment rule and this month\'s token usage.');
        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->setAreaCode();
            $report = $this->reportBuilder->build();
        } catch (LocalizedException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if ($report->getRows() === []) {
            $output->writeln('No active enrichment rules.');
        } else {
            $this->printTable($report, $output);
        }
        foreach ($report->getSkipped() as $code => $reason) {
            $output->writeln(sprintf('<comment>Rule "%s" skipped: %s</comment>', $code, $reason));
        }
        $budget = $report->getRemainingBudget();
        $output->writeln(sprintf(
            'Tokens this month: enrich %d, calibrate %d · Budget left: %s · Cost ≈ $%.4f',
            $report->getEnrichTokens(),
            $report->getCalibrateTokens(),
            $budget === null ? 'unlimited' : (string)$budget,
            $report->getCost()
        ));

        return Command::SUCCESS;
    }

    /**
     * Run in the admin area (EAV option sources expect one).
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
     * Rows table.
     *
     * @param Report $report
     * @param OutputInterface $output
     * @return void
     */
    private function printTable(Report $report, OutputInterface $output): void
    {
        $rows = [];
        foreach ($report->getRows() as $row) {
            $rows[] = [
                $row['attribute'],
                $row['mode'],
                $row['eligible'],
                $this->percent($row['fill_before']),
                $this->percent($row['fill_now']),
                ...array_values($row['statuses']),
            ];
        }
        (new Table($output))->setHeaders(self::HEADERS)->setRows($rows)->render();
    }

    /**
     * Ratio as a percentage; "n/a" when undefined.
     *
     * @param float|null $ratio
     * @return string
     */
    private function percent(?float $ratio): string
    {
        return $ratio === null ? 'n/a' : sprintf('%.1f%%', $ratio * 100);
    }
}

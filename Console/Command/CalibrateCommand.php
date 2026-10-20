<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Console\Command;

use DmLab\CatalogEnrich\Model\Calibration\CalibrationResult;
use DmLab\CatalogEnrich\Model\Calibration\Calibrator;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `dmlab:catalog-enrich:calibrate` — measures precision per threshold on products that already hold a value.
 */
class CalibrateCommand extends Command
{
    public const NAME = 'dmlab:catalog-enrich:calibrate';
    public const DEFAULT_SAMPLE = 200;

    private const OPTION_ATTRIBUTE = 'attribute';
    private const OPTION_SAMPLE = 'sample';

    /**
     * @param Calibrator $calibrator
     * @param State $appState
     */
    public function __construct(
        private readonly Calibrator $calibrator,
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
            ->setDescription('Measures enrichment precision per threshold on products that already have a value.')
            ->addOption(self::OPTION_ATTRIBUTE, null, InputOption::VALUE_REQUIRED, 'Attribute code')
            ->addOption(
                self::OPTION_SAMPLE,
                null,
                InputOption::VALUE_REQUIRED,
                'Number of newest products with a value',
                (string)self::DEFAULT_SAMPLE
            );
        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $attribute = trim((string)$input->getOption(self::OPTION_ATTRIBUTE));
        if ($attribute === '') {
            $output->writeln('<error>--attribute is required.</error>');
            return Command::FAILURE;
        }
        $sample = trim((string)$input->getOption(self::OPTION_SAMPLE));
        if (!ctype_digit($sample) || (int)$sample < 1) {
            $output->writeln('<error>--sample must be a positive integer.</error>');
            return Command::FAILURE;
        }

        try {
            $this->setAreaCode();
            $result = $this->calibrator->calibrate($attribute, (int)$sample);
        } catch (LocalizedException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if ($result->getExcludedZero() > 0) {
            $output->writeln(sprintf(
                'Excluded %d product(s) with value No: the rule treats No as empty.',
                $result->getExcludedZero()
            ));
        }
        if ($result->getProducts() === 0 && $result->getErrors() === 0) {
            $output->writeln(sprintf('No products with a value for "%s" to calibrate on.', $attribute));
            return Command::SUCCESS;
        }
        $this->printResult($result, $output);

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
     * Table, suggested threshold and gold gaps.
     *
     * @param CalibrationResult $result
     * @param OutputInterface $output
     * @return void
     */
    private function printResult(CalibrationResult $result, OutputInterface $output): void
    {
        $multiselect = $result->isMultiselect();
        $output->writeln(sprintf(
            'Attribute: %s (%s)  Products: %d  Errors: %d  Input tokens: %d',
            $result->getAttributeCode(),
            $result->getInput(),
            $result->getProducts(),
            $result->getErrors(),
            $result->getInputTokens()
        ));

        $headers = ['threshold', 'decided', 'coverage', 'correct', 'precision'];
        if ($multiselect) {
            array_push($headers, 'possible gold gaps', 'missed', 'recall');
        }
        $rows = [];
        foreach ($result->getRows() as $row) {
            $line = [
                sprintf('%.2f', $row['threshold']),
                $row['decided'],
                $this->percent($row['coverage']),
                $row['correct'],
                $this->percent($row['precision']),
            ];
            if ($multiselect) {
                array_push($line, $row['gaps'], $row['missed'], $this->percent($row['recall']));
            }
            $rows[] = $line;
        }
        (new Table($output))->setHeaders($headers)->setRows($rows)->render();

        $suggested = $result->suggestedThreshold();
        $output->writeln($suggested === null
            ? 'Suggested threshold: none — add rules or keep review mode'
            : sprintf('Suggested threshold: %.2f', $suggested));

        if ($multiselect && $result->getGoldGaps() !== []) {
            $output->writeln(sprintf('Possible gold gaps at threshold %.2f:', $result->getGapThreshold()));
            foreach ($result->getGoldGaps() as $line) {
                $output->writeln('  ' . $line);
            }
        }
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

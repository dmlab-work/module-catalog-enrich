<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Console\Command;

use DmLab\CatalogEnrich\Console\Command\CalibrateCommand;
use DmLab\CatalogEnrich\Exception\IneligibleAttributeException;
use DmLab\CatalogEnrich\Model\Calibration\CalibrationResult;
use DmLab\CatalogEnrich\Model\Calibration\Calibrator;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class CalibrateCommandTest extends TestCase
{
    private function tester(Calibrator $calibrator, ?State $appState = null): CommandTester
    {
        return new CommandTester(new CalibrateCommand($calibrator, $appState ?? $this->createStub(State::class)));
    }

    private function calibratorReturning(CalibrationResult $result): Calibrator
    {
        $calibrator = $this->createStub(Calibrator::class);
        $calibrator->method('calibrate')->willReturn($result);

        return $calibrator;
    }

    /**
     * Select result: 20 products, 20 correct at 0.5–0.8 except one wrong at 0.5, 5 decided at 0.9+.
     */
    private function selectResult(): CalibrationResult
    {
        $result = new CalibrationResult('number_of_seats', 'select', 0.9);
        for ($i = 0; $i < 20; $i++) {
            $result->addProduct(770);
            $result->record(0.5, true, $i === 0 ? 0 : 1);
            $result->record(0.7, true, 1);
            $result->record(0.8, true, 1);
            $result->record(0.9, $i < 5, (int)($i < 5));
            $result->record(0.95, $i < 5, (int)($i < 5));
        }

        return $result;
    }

    public function testNameIsRegistered(): void
    {
        $command = new CalibrateCommand($this->createStub(Calibrator::class), $this->createStub(State::class));

        self::assertSame('dmlab:catalog-enrich:calibrate', $command->getName());
    }

    public function testCalibratesWithDefaultSampleAndPrintsTableAndSuggestion(): void
    {
        $calibrator = $this->createMock(Calibrator::class);
        $calibrator->expects(self::once())->method('calibrate')
            ->with('number_of_seats', 200)->willReturn($this->selectResult());
        $appState = $this->createMock(State::class);
        $appState->expects(self::once())->method('setAreaCode')->with('adminhtml');
        $tester = $this->tester($calibrator, $appState);

        self::assertSame(Command::SUCCESS, $tester->execute(['--attribute' => ' number_of_seats ']));
        $display = $tester->getDisplay(true);
        self::assertStringContainsString(
            'Attribute: number_of_seats (select)  Products: 20  Errors: 0  Input tokens: 15400',
            $display
        );
        self::assertMatchesRegularExpression(
            '/threshold\s*\|\s*decided\s*\|\s*coverage\s*\|\s*correct\s*\|\s*precision/',
            $display
        );
        self::assertStringNotContainsString('recall', $display);
        self::assertMatchesRegularExpression('/0\.50\s*\|\s*20\s*\|\s*100\.0%\s*\|\s*19\s*\|\s*95\.0%/', $display);
        self::assertMatchesRegularExpression('/0\.90\s*\|\s*5\s*\|\s*25\.0%\s*\|\s*5\s*\|\s*100\.0%/', $display);
        self::assertStringContainsString('Suggested threshold: 0.70', $display);
    }

    public function testNoQualifyingThreshold(): void
    {
        $result = new CalibrationResult('number_of_seats', 'select', 0.9);
        $result->addProduct(770);
        $result->record(0.5, true, 1);
        $tester = $this->tester($this->calibratorReturning($result));

        self::assertSame(Command::SUCCESS, $tester->execute(['--attribute' => 'number_of_seats', '--sample' => '5']));
        $display = $tester->getDisplay(true);
        self::assertStringContainsString('Suggested threshold: none — add rules or keep review mode', $display);
        self::assertMatchesRegularExpression('/0\.70\s*\|\s*0\s*\|\s*0\.0%\s*\|\s*0\s*\|\s*n\/a/', $display);
    }

    public function testMultiselectAddsColumnsAndGoldGaps(): void
    {
        $result = new CalibrationResult('bedroom_suite_incl', 'multiselect', 0.9);
        $result->addProduct(770);
        $result->record(0.5, true, 3, 1, 1);
        $result->addGoldGap('SKU-1: Dresser');
        $result->addGoldGap('SKU-7: Mirror');
        $tester = $this->tester($this->calibratorReturning($result));

        self::assertSame(Command::SUCCESS, $tester->execute(['--attribute' => 'bedroom_suite_incl']));
        $display = $tester->getDisplay(true);
        self::assertMatchesRegularExpression(
            '/precision\s*\|\s*possible gold gaps\s*\|\s*missed\s*\|\s*recall/',
            $display
        );
        self::assertMatchesRegularExpression(
            '/0\.50\s*\|\s*1\s*\|\s*100\.0%\s*\|\s*3\s*\|\s*75\.0%\s*\|\s*1\s*\|\s*1\s*\|\s*75\.0%/',
            $display
        );
        self::assertStringEndsWith(
            "Possible gold gaps at threshold 0.90:\n  SKU-1: Dresser\n  SKU-7: Mirror\n",
            $display
        );
    }

    public function testNoProductsWithAValue(): void
    {
        $tester = $this->tester($this->calibratorReturning(new CalibrationResult('color', 'select', 0.9)));

        self::assertSame(Command::SUCCESS, $tester->execute(['--attribute' => 'color']));
        self::assertSame("No products with a value for \"color\" to calibrate on.\n", $tester->getDisplay(true));
    }

    public function testExcludedZeroIsPrinted(): void
    {
        $result = $this->selectResult();
        $result->setExcludedZero(12);
        $tester = $this->tester($this->calibratorReturning($result));

        self::assertSame(Command::SUCCESS, $tester->execute(['--attribute' => 'is_assembled']));
        self::assertStringStartsWith(
            "Excluded 12 product(s) with value No: the rule treats No as empty.\n",
            $tester->getDisplay(true)
        );
    }

    public function testExcludedZeroIsPrintedEvenWithoutProductsLeft(): void
    {
        $result = new CalibrationResult('is_assembled', 'boolean', 0.9);
        $result->setExcludedZero(3);
        $tester = $this->tester($this->calibratorReturning($result));

        $tester->execute(['--attribute' => 'is_assembled']);

        self::assertSame(
            "Excluded 3 product(s) with value No: the rule treats No as empty.\n"
            . "No products with a value for \"is_assembled\" to calibrate on.\n",
            $tester->getDisplay(true)
        );
    }

    public function testAllProductsFailedStillPrintsTheTable(): void
    {
        $result = new CalibrationResult('color', 'select', 0.9);
        $result->addError();
        $tester = $this->tester($this->calibratorReturning($result));

        self::assertSame(Command::SUCCESS, $tester->execute(['--attribute' => 'color']));
        self::assertStringContainsString('Products: 0  Errors: 1', $tester->getDisplay(true));
    }

    public function testAreaAlreadySetIsIgnored(): void
    {
        $appState = $this->createStub(State::class);
        $appState->method('setAreaCode')->willThrowException(new LocalizedException(__('Area code is already set')));
        $tester = $this->tester($this->calibratorReturning($this->selectResult()), $appState);

        self::assertSame(Command::SUCCESS, $tester->execute(['--attribute' => 'number_of_seats']));
    }

    /**
     * @return array<string, array{0: array<string,string>, 1: string}>
     */
    public static function invalidOptions(): array
    {
        return [
            'attribute missing' => [[], '--attribute is required.'],
            'attribute blank' => [['--attribute' => ' '], '--attribute is required.'],
            'sample zero' => [['--attribute' => 'color', '--sample' => '0'], '--sample must be a positive integer.'],
            'sample negative' => [
                ['--attribute' => 'color', '--sample' => '-5'],
                '--sample must be a positive integer.',
            ],
            'sample text' => [['--attribute' => 'color', '--sample' => 'many'], '--sample must be a positive integer.'],
            'sample float' => [['--attribute' => 'color', '--sample' => '2.5'], '--sample must be a positive integer.'],
        ];
    }

    /**
     * @param array<string,string> $input
     */
    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsExitOne(array $input, string $message): void
    {
        $calibrator = $this->createMock(Calibrator::class);
        $calibrator->expects(self::never())->method('calibrate');
        $tester = $this->tester($calibrator);

        self::assertSame(Command::FAILURE, $tester->execute($input));
        self::assertStringContainsString($message, $tester->getDisplay(true));
    }

    public function testSampleIsPassed(): void
    {
        $calibrator = $this->createMock(Calibrator::class);
        $calibrator->expects(self::once())->method('calibrate')
            ->with('color', 35)->willReturn(new CalibrationResult('color', 'select', 0.9));

        self::assertSame(Command::SUCCESS, $this->tester($calibrator)->execute([
            '--attribute' => 'color',
            '--sample' => ' 35 ',
        ]));
    }

    /**
     * @return array<string, array{0: LocalizedException}>
     */
    public static function calibrationFailures(): array
    {
        return [
            'not configured' => [new LocalizedException(__('TypeSafe is not configured: enable it.'))],
            'ineligible' => [new IneligibleAttributeException(__('Attribute "%1" does not exist.', 'color'))],
            'stopped' => [new LocalizedException(__('Calibration stopped: Monthly budget exceeded'))],
        ];
    }

    #[DataProvider('calibrationFailures')]
    public function testCalibrationFailureExitsOneWithMessage(LocalizedException $failure): void
    {
        $calibrator = $this->createStub(Calibrator::class);
        $calibrator->method('calibrate')->willThrowException($failure);
        $tester = $this->tester($calibrator);

        self::assertSame(Command::FAILURE, $tester->execute(['--attribute' => 'color']));
        self::assertStringContainsString($failure->getMessage(), $tester->getDisplay(true));
    }
}

<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Console\Command;

use DmLab\CatalogEnrich\Console\Command\ReportCommand;
use DmLab\CatalogEnrich\Model\Report\Report;
use DmLab\CatalogEnrich\Model\Report\ReportBuilder;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ReportCommandTest extends TestCase
{
    private function tester(Report $report, ?State $appState = null): CommandTester
    {
        $builder = $this->createStub(ReportBuilder::class);
        $builder->method('build')->willReturn($report);

        return new CommandTester(new ReportCommand($builder, $appState ?? $this->createStub(State::class)));
    }

    /**
     * @param array<string,mixed> $override
     * @return array<string,mixed>
     */
    private function row(array $override = []): array
    {
        return $override + [
            'attribute' => 'number_of_seats',
            'mode' => 'review',
            'eligible' => 200,
            'filled' => 150,
            'applied' => 30,
            'fill_before' => 0.6,
            'fill_now' => 0.75,
            'statuses' => ['shadow' => 1, 'pending' => 7, 'applied' => 30, 'rejected' => 4, 'reverted' => 2],
        ];
    }

    public function testNameIsRegistered(): void
    {
        $command = new ReportCommand($this->createStub(ReportBuilder::class), $this->createStub(State::class));

        self::assertSame('dmlab:catalog-enrich:report', $command->getName());
    }

    public function testPrintsTableAndFooter(): void
    {
        $appState = $this->createMock(State::class);
        $appState->expects(self::once())->method('setAreaCode')->with('adminhtml');
        $tester = $this->tester(new Report([$this->row()], [], 1_200_000, 300_000, 8_500_000), $appState);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay(true);
        self::assertMatchesRegularExpression(
            '/attribute\s*\|\s*mode\s*\|\s*eligible\s*\|\s*fill before\s*\|\s*fill now\s*\|\s*shadow\s*\|'
            . '\s*pending\s*\|\s*applied\s*\|\s*rejected\s*\|\s*reverted/',
            $display
        );
        self::assertMatchesRegularExpression(
            '/number_of_seats\s*\|\s*review\s*\|\s*200\s*\|\s*60\.0%\s*\|\s*75\.0%\s*\|\s*1\s*\|\s*7\s*\|'
            . '\s*30\s*\|\s*4\s*\|\s*2\s*\|/',
            $display
        );
        self::assertStringContainsString(
            'Tokens this month: enrich 1200000, calibrate 300000 · Budget left: 8500000 · Cost ≈ $0.0630',
            $display
        );
    }

    public function testZeroEligibleShowsNotApplicable(): void
    {
        $tester = $this->tester(new Report(
            [$this->row(['eligible' => 0, 'fill_before' => null, 'fill_now' => null])],
            [],
            0,
            0,
            null
        ));

        $tester->execute([]);

        self::assertMatchesRegularExpression('/\|\s*0\s*\|\s*n\/a\s*\|\s*n\/a\s*\|/', $tester->getDisplay(true));
    }

    public function testUnlimitedBudgetAndCostFormatting(): void
    {
        $tester = $this->tester(new Report([$this->row()], [], 770, 0, null));

        $tester->execute([]);

        self::assertStringContainsString(
            'Tokens this month: enrich 770, calibrate 0 · Budget left: unlimited · Cost ≈ $0.0000',
            $tester->getDisplay(true)
        );
    }

    public function testNoActiveRules(): void
    {
        $tester = $this->tester(new Report([], [], 0, 5_000, null));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay(true);
        self::assertStringContainsString('No active enrichment rules.', $display);
        self::assertStringNotContainsString('fill before', $display);
        self::assertStringContainsString('calibrate 5000', $display);
    }

    public function testSkippedRulesAreListed(): void
    {
        $tester = $this->tester(new Report([$this->row()], ['gone' => 'Attribute "gone" does not exist.'], 0, 0, 0));

        $tester->execute([]);

        $display = $tester->getDisplay(true);
        self::assertStringContainsString('Rule "gone" skipped: Attribute "gone" does not exist.', $display);
        self::assertStringContainsString('Budget left: 0 ·', $display);
    }

    public function testAreaAlreadySetIsIgnored(): void
    {
        $appState = $this->createStub(State::class);
        $appState->method('setAreaCode')->willThrowException(new LocalizedException(__('Area code is already set')));

        self::assertSame(Command::SUCCESS, $this->tester(new Report([], [], 0, 0, null), $appState)->execute([]));
    }

    public function testBuildFailureExitsOne(): void
    {
        $builder = $this->createStub(ReportBuilder::class);
        $builder->method('build')->willThrowException(new LocalizedException(__('DB down')));
        $tester = new CommandTester(new ReportCommand($builder, $this->createStub(State::class)));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('DB down', $tester->getDisplay(true));
    }
}

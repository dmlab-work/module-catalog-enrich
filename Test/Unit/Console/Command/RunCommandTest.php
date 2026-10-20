<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Console\Command;

use DmLab\CatalogEnrich\Console\Command\RunCommand;
use DmLab\CatalogEnrich\Model\Run\Enricher;
use DmLab\CatalogEnrich\Model\Run\LastRunStore;
use DmLab\CatalogEnrich\Model\Run\ProductSelector;
use DmLab\CatalogEnrich\Model\Run\RunOptions;
use DmLab\CatalogEnrich\Model\Run\RunReport;
use DmLab\CatalogEnrich\Model\Suggestion;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class RunCommandTest extends TestCase
{
    private const COLLABORATORS = [
        'selector' => ProductSelector::class,
        'enricher' => Enricher::class,
        'lockManager' => LockManagerInterface::class,
        'appState' => State::class,
        'lastRunStore' => LastRunStore::class,
    ];

    /**
     * @var ProductSelector
     */
    private ProductSelector $selector;

    /**
     * @var Enricher
     */
    private Enricher $enricher;

    /**
     * @var LockManagerInterface
     */
    private LockManagerInterface $lockManager;

    /**
     * @var State
     */
    private State $appState;

    /**
     * @var LastRunStore
     */
    private LastRunStore $lastRunStore;

    /**
     * @var array<string,MockObject>
     */
    private array $mocks = [];

    /**
     * Options the enricher was called with.
     *
     * @var RunOptions|null
     */
    private ?RunOptions $runOptions = null;

    protected function setUp(): void
    {
        $this->selector = $this->createStub(ProductSelector::class);
        $this->enricher = $this->createStub(Enricher::class);
        $this->lockManager = $this->createStub(LockManagerInterface::class);
        $this->appState = $this->createStub(State::class);
        $this->lastRunStore = $this->createStub(LastRunStore::class);
    }

    /**
     * Swap a stubbed collaborator for a mock once, so expectations can be set on it.
     */
    private function mock(string $property): MockObject
    {
        if (!isset($this->mocks[$property])) {
            $this->mocks[$property] = $this->createMock(self::COLLABORATORS[$property]);
            $this->$property = $this->mocks[$property];
        }

        return $this->mocks[$property];
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new RunCommand(
            $this->selector,
            $this->enricher,
            $this->lockManager,
            $this->appState,
            $this->lastRunStore
        ));
    }

    private function lockFree(): void
    {
        $this->mock('lockManager')->expects(self::once())->method('lock')
            ->with('dmlab_catalog_enrich_run', 0)->willReturn(true);
        $this->mock('lockManager')->expects(self::once())->method('unlock')->with('dmlab_catalog_enrich_run');
    }

    private function enricherReturns(RunReport $report): void
    {
        $this->enricher->method('run')->willReturnCallback(
            function (array $ids, RunOptions $options) use ($report): RunReport {
                $this->runOptions = $options;
                return $report;
            }
        );
    }

    public function testNameIsRegistered(): void
    {
        $command = new RunCommand(
            $this->selector,
            $this->enricher,
            $this->lockManager,
            $this->appState,
            $this->lastRunStore
        );

        self::assertSame('dmlab:catalog-enrich:run', $command->getName());
    }

    public function testOptionsAreParsedIntoRunOptions(): void
    {
        $this->lockFree();
        $this->mock('appState')->expects(self::once())->method('setAreaCode')->with('adminhtml');
        $this->mock('selector')->expects(self::once())->method('select')
            ->with(['A-1', 'B 2'], 25, 0, 'color')->willReturn([7, 8]);
        $this->enricherReturns(new RunReport());

        $exit = $this->tester()->execute([
            '--attribute' => ' color ',
            '--sku' => ' A-1, ,B 2,A-1 ',
            '--limit' => '25',
            '--force' => true,
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame('color', $this->runOptions->getAttributeCode());
        self::assertTrue($this->runOptions->isForce());
        self::assertTrue($this->runOptions->isDryRun());
        self::assertSame(RunOptions::DEFAULT_CONSUMER, $this->runOptions->getConsumer());
    }

    public function testDefaults(): void
    {
        $this->lockFree();
        $this->mock('selector')->expects(self::once())->method('select')->with(null, null, 0, null)->willReturn([1]);
        $this->enricherReturns(new RunReport());

        self::assertSame(Command::SUCCESS, $this->tester()->execute([]));
        self::assertNull($this->runOptions->getAttributeCode());
        self::assertFalse($this->runOptions->isForce());
        self::assertFalse($this->runOptions->isDryRun());
    }

    public function testAreaAlreadySetIsIgnored(): void
    {
        $this->lockFree();
        $this->appState->method('setAreaCode')
            ->willThrowException(new LocalizedException(__('Area code is already set')));
        $this->selector->method('select')->willReturn([1]);
        $this->enricherReturns(new RunReport());

        self::assertSame(Command::SUCCESS, $this->tester()->execute([]));
    }

    /**
     * @return array<string, array{0: array<string,string>, 1: string}>
     */
    public static function invalidOptions(): array
    {
        return [
            'limit zero' => [['--limit' => '0'], '--limit must be a positive integer.'],
            'limit negative' => [['--limit' => '-3'], '--limit must be a positive integer.'],
            'limit text' => [['--limit' => 'ten'], '--limit must be a positive integer.'],
            'limit float' => [['--limit' => '1.5'], '--limit must be a positive integer.'],
            'sku empty' => [['--sku' => ' , '], '--sku must list at least one SKU.'],
        ];
    }

    /**
     * @param array<string,string> $input
     */
    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsExitOne(array $input, string $message): void
    {
        $this->mock('lockManager')->expects(self::never())->method('lock');
        $this->mock('selector')->expects(self::never())->method('select');
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute($input));
        self::assertStringContainsString($message, $tester->getDisplay(true));
    }

    public function testInactiveAttributeExitsOneAndUnlocks(): void
    {
        $this->lockFree();
        $this->selector->method('select')->willThrowException(
            new LocalizedException(__('There is no active enrichment rule for attribute "%1".', 'color'))
        );
        $this->mock('enricher')->expects(self::never())->method('run');
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['--attribute' => 'color']));
        self::assertStringContainsString(
            'There is no active enrichment rule for attribute "color".',
            $tester->getDisplay(true)
        );
    }

    public function testLockBusyExitsOneAndSelectsNothing(): void
    {
        $this->mock('lockManager')->expects(self::once())->method('lock')->willReturn(false);
        $this->mock('lockManager')->expects(self::never())->method('unlock');
        $this->mock('selector')->expects(self::never())->method('select');
        $this->mock('enricher')->expects(self::never())->method('run');
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Another enrichment run is in progress.', $tester->getDisplay(true));
    }

    public function testNoProductsExitsZero(): void
    {
        $this->lockFree();
        $this->selector->method('select')->willReturn([]);
        $this->mock('enricher')->expects(self::never())->method('run');
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame("No products to enrich.\n", $tester->getDisplay(true));
    }

    public function testSummary(): void
    {
        $this->lockFree();
        $this->selector->method('select')->willReturn([1, 2, 3]);
        $report = new RunReport();
        $report->increment(RunReport::PRODUCTS, 3);
        $report->increment(RunReport::REQUESTS, 2);
        $report->increment(RunReport::CACHED);
        $report->increment(RunReport::INPUT_TOKENS, 1540);
        $report->increment(RunReport::ASKED, 4);
        $report->increment(RunReport::SKIPPED_FINAL, 2);
        $report->increment(RunReport::SKIPPED_BACKOFF);
        $report->increment(RunReport::ACCEPTED, 2);
        $report->increment(RunReport::NOT_STATED);
        $report->increment(RunReport::ERRORS);
        $report->increment(RunReport::REJECTED, 3);
        $report->addSuggestion(Suggestion::STATUS_PENDING);
        $report->addSuggestion(Suggestion::STATUS_APPLIED);
        $report->addSuggestion(Suggestion::STATUS_APPLIED);
        $this->enricherReturns($report);
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(
            "Products: 3  Requests: 2 (cached 1)  Input tokens: 1540\n"
            . "Asked: 4  Skipped (unchanged): 2  Skipped (backoff): 1\n"
            . "Outcomes: accepted 2, not_stated 1, uncertain 0, error 1, rejected 3\n"
            . "Suggestions: shadow 0, pending 1, applied 2\n",
            $tester->getDisplay(true)
        );
    }

    public function testStopReasonExitsOneWithMessage(): void
    {
        $this->lockFree();
        $this->selector->method('select')->willReturn([1]);
        $report = new RunReport();
        $report->setStopReason('Monthly token budget exceeded.');
        $this->enricherReturns($report);
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        $display = $tester->getDisplay(true);
        self::assertStringContainsString('Products: 0', $display);
        self::assertStringEndsWith("Stopped: Monthly token budget exceeded.\n", $display);
    }

    public function testRunStoresTheCliSummary(): void
    {
        $this->lockFree();
        $this->selector->method('select')->willReturn([1]);
        $report = new RunReport();
        $this->enricherReturns($report);
        $this->mock('lastRunStore')->expects(self::once())->method('save')->with(LastRunStore::SOURCE_CLI, $report);

        self::assertSame(Command::SUCCESS, $this->tester()->execute([]));
    }

    public function testDryRunStoresNoSummary(): void
    {
        $this->lockFree();
        $this->selector->method('select')->willReturn([1]);
        $this->enricherReturns(new RunReport());
        $this->mock('lastRunStore')->expects(self::never())->method('save');

        self::assertSame(Command::SUCCESS, $this->tester()->execute(['--dry-run' => true]));
    }

    public function testDryRunPrintsLines(): void
    {
        $this->lockFree();
        $this->selector->method('select')->willReturn([1, 2]);
        $report = new RunReport();
        $report->addLine('SKU-1: asked [color, size] skipped []');
        $report->addLine('SKU-2: asked [] skipped [color]');
        $this->enricherReturns($report);
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertSame(
            "SKU-1: asked [color, size] skipped []\nSKU-2: asked [] skipped [color]\n",
            $tester->getDisplay(true)
        );
    }

    public function testDryRunWithNothingToAsk(): void
    {
        $this->lockFree();
        $this->selector->method('select')->willReturn([1]);
        $this->enricherReturns(new RunReport());
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertSame("Nothing to ask.\n", $tester->getDisplay(true));
    }

    public function testUnlocksOnException(): void
    {
        $this->lockFree();
        $this->selector->method('select')->willReturn([1]);
        $this->enricher->method('run')->willThrowException(new \RuntimeException('boom'));

        $this->expectException(\RuntimeException::class);
        $this->tester()->execute([]);
    }
}

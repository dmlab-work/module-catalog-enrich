<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\Run;

use DmLab\CatalogEnrich\Model\Run\RunOptions;
use DmLab\CatalogEnrich\Model\Run\RunReport;
use PHPUnit\Framework\TestCase;

class RunReportTest extends TestCase
{
    public function testCountersStartAtZero(): void
    {
        $report = new RunReport();

        self::assertSame(array_fill_keys(RunReport::COUNTERS, 0), $report->getCounters());
        self::assertSame([], $report->getSuggestions());
        self::assertNull($report->getStopReason());
        self::assertSame([], $report->getLines());
        self::assertSame(0, $report->getAskedProducts());
        self::assertNull($report->getLastProductId());
        self::assertFalse($report->isLimitReached());
    }

    public function testProgressAndMerge(): void
    {
        $first = new RunReport();
        $first->increment(RunReport::REQUESTS, 2);
        $first->addSuggestion('pending');
        $first->addAskedProduct();
        $second = new RunReport();
        $second->increment(RunReport::REQUESTS);
        $second->addSuggestion('pending');
        $second->addSuggestion('applied');
        $second->addAskedProduct();
        $second->setLastProductId(42);
        $second->setLimitReached();

        $first->merge($second);

        self::assertSame(3, $first->get(RunReport::REQUESTS));
        self::assertSame(['pending' => 2, 'applied' => 1], $first->getSuggestions());
        self::assertSame(2, $first->getAskedProducts());
        self::assertNull($first->getLastProductId());
        self::assertFalse($first->isLimitReached());
        self::assertSame(42, $second->getLastProductId());
        self::assertTrue($second->isLimitReached());
    }

    public function testIncrementAndAccumulate(): void
    {
        $report = new RunReport();
        $report->increment(RunReport::REQUESTS);
        $report->increment(RunReport::INPUT_TOKENS, 770);
        $report->increment(RunReport::INPUT_TOKENS, 30);
        $report->addSuggestion('pending');
        $report->addSuggestion('pending');
        $report->addSuggestion('applied');
        $report->setStopReason('Budget exceeded');
        $report->addLine('SKU-1: asked [color] skipped []');

        self::assertSame(1, $report->get(RunReport::REQUESTS));
        self::assertSame(800, $report->get(RunReport::INPUT_TOKENS));
        self::assertSame(['pending' => 2, 'applied' => 1], $report->getSuggestions());
        self::assertSame('Budget exceeded', $report->getStopReason());
        self::assertSame(['SKU-1: asked [color] skipped []'], $report->getLines());
    }

    public function testUnknownCounterIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new RunReport())->increment('nope');
    }

    public function testOptionDefaults(): void
    {
        $options = new RunOptions();

        self::assertNull($options->getAttributeCode());
        self::assertFalse($options->isForce());
        self::assertFalse($options->isDryRun());
        self::assertSame('catalog_enrich', $options->getConsumer());
        self::assertNull($options->getProductLimit());
        self::assertSame(7, (new RunOptions(productLimit: 7))->getProductLimit());

        $options = new RunOptions('color', true, true, 'catalog_enrich_calibrate');
        self::assertSame('color', $options->getAttributeCode());
        self::assertTrue($options->isForce());
        self::assertTrue($options->isDryRun());
        self::assertSame('catalog_enrich_calibrate', $options->getConsumer());
    }
}

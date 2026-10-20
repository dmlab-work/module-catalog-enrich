<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\Queue;

use DmLab\CatalogEnrich\Model\Queue\SuggestionQueueSummary;
use Magento\Framework\FlagManager;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SuggestionQueueSummaryTest extends TestCase
{
    /**
     * @var mixed
     */
    private mixed $stored = null;

    /**
     * @var int
     */
    private int $now = 1000;

    private function summary(?LoggerInterface $logger = null, ?FlagManager $flags = null): SuggestionQueueSummary
    {
        if ($flags === null) {
            $flags = $this->createStub(FlagManager::class);
            $flags->method('getFlagData')->willReturnCallback(fn (): mixed => $this->stored);
            $flags->method('saveFlag')->willReturnCallback(function (string $code, array $data): bool {
                self::assertSame(SuggestionQueueSummary::FLAG, $code);
                $this->stored = $data;

                return true;
            });
        }
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturnCallback(fn (): int => $this->now);

        return new SuggestionQueueSummary($flags, $dateTime, $logger ?? $this->createStub(LoggerInterface::class));
    }

    public function testMessagesAddUpIntoTheMassAction(): void
    {
        $summary = $this->summary();
        self::assertNull($summary->get());

        $summary->start(250);
        $this->now = 1200;
        $summary->add('approve', 90, 10);
        $summary->add('revert', 3, 2);

        self::assertSame(
            ['time' => 1200, 'queued_at' => 1000, 'queued' => 250, 'approved' => 90, 'reverted' => 3, 'refused' => 12],
            $summary->get()
        );

        $this->now = 1300;
        $summary->start(60);
        self::assertSame(
            ['time' => 1300, 'queued_at' => 1300, 'queued' => 60, 'approved' => 0, 'reverted' => 0, 'refused' => 0],
            $summary->get()
        );
    }

    public function testMessageWithoutAStartedMassActionStartsFromZero(): void
    {
        $summary = $this->summary();

        $summary->add('approve', 2, 0);

        self::assertSame(
            ['time' => 1000, 'queued_at' => null, 'queued' => 0, 'approved' => 2, 'reverted' => 0, 'refused' => 0],
            $summary->get()
        );
    }

    public function testSaveFailureIsOnlyLogged(): void
    {
        $flags = $this->createStub(FlagManager::class);
        $flags->method('saveFlag')->willThrowException(new \RuntimeException('DB gone'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::stringContains('DB gone'));

        $this->summary($logger, $flags)->start(5);
    }
}

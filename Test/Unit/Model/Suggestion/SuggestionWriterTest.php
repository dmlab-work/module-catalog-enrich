<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\Suggestion;

use DmLab\CatalogEnrich\Model\Apply\Applier;
use DmLab\CatalogEnrich\Model\Apply\ApplyResult;
use DmLab\CatalogEnrich\Model\Decision\Decision;
use DmLab\CatalogEnrich\Model\Dedup\RejectionGuard;
use DmLab\CatalogEnrich\Model\ResourceModel\Suggestion as SuggestionResource;
use DmLab\CatalogEnrich\Model\Rule;
use DmLab\CatalogEnrich\Model\Suggestion;
use DmLab\CatalogEnrich\Model\Suggestion\SuggestionWriter;
use DmLab\CatalogEnrich\Model\SuggestionFactory;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\Db\Context as DbContext;
use Magento\Framework\Registry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SuggestionWriterTest extends TestCase
{
    private const FP = 'fp-new';

    /**
     * @var RejectionGuard&Stub
     */
    private RejectionGuard $guard;

    /**
     * @var SuggestionResource&Stub
     */
    private SuggestionResource $resource;

    /**
     * @var Applier&Stub
     */
    private Applier $applier;

    /**
     * @var LoggerInterface&Stub
     */
    private LoggerInterface $logger;

    /**
     * Status of each saved suggestion at save time.
     *
     * @var string[]
     */
    private array $saved = [];

    /**
     * Rows `findFor` returns.
     *
     * @var array<int, array<string,mixed>>
     */
    private array $rows = [];

    /**
     * @var int
     */
    private int $findForCalls = 0;

    /**
     * Status `saveIfStatus` finds in the table; null = the loaded row's status.
     *
     * @var string|null
     */
    private ?string $storedStatus = null;

    /**
     * Properties already swapped for a mock (PHPUnit 11 stubs are MockObject too, so instanceof can't tell).
     *
     * @var array<string, true>
     */
    private array $mocked = [];

    protected function setUp(): void
    {
        $this->guard = $this->createStub(RejectionGuard::class);
        $this->resource = $this->wire($this->createStub(SuggestionResource::class));
        $this->applier = $this->createStub(Applier::class);
        $this->logger = $this->createStub(LoggerInterface::class);
    }

    /**
     * Swap a double for a mock (once per test) so expectations can be set on it.
     *
     * @param string $property guard|resource|applier|logger
     * @return MockObject
     */
    private function mock(string $property): MockObject
    {
        if (!isset($this->mocked[$property])) {
            $this->mocked[$property] = true;
            $mock = $this->createMock(match ($property) {
                'guard' => RejectionGuard::class,
                'resource' => SuggestionResource::class,
                'applier' => Applier::class,
                'logger' => LoggerInterface::class,
            });
            $this->$property = $property === 'resource' ? $this->wire($mock) : $mock;
        }

        return $this->$property;
    }

    /**
     * Serve `$this->rows` from findFor and record saved statuses.
     *
     * @template T of SuggestionResource
     * @param T $resource
     * @return T
     */
    private function wire(SuggestionResource $resource): SuggestionResource
    {
        $resource->method('findFor')->willReturnCallback(function (): array {
            $this->findForCalls++;

            return $this->rows;
        });
        $resource->method('save')->willReturnCallback(function (Suggestion $s) use ($resource): SuggestionResource {
            $this->saved[] = $s->getStatus();

            return $resource;
        });
        $resource->method('saveIfStatus')->willReturnCallback(function (Suggestion $s, string $expected): bool {
            if ($expected !== $this->storedStatus) {
                return false;
            }
            $this->saved[] = $s->getStatus();

            return true;
        });

        return $resource;
    }

    private function writer(): SuggestionWriter
    {
        $factory = $this->createStub(SuggestionFactory::class);
        $factory->method('create')->willReturnCallback(fn (): Suggestion => new Suggestion(
            $this->createStub(Context::class),
            $this->createStub(Registry::class),
            new SuggestionResource($this->createStub(DbContext::class))
        ));

        return new SuggestionWriter($this->guard, $this->resource, $factory, $this->applier, $this->logger);
    }

    private function uncertain(): Decision
    {
        return new Decision(Decision::UNCERTAIN);
    }

    private function accepted(): Decision
    {
        return new Decision(Decision::ACCEPTED, '12', 0.934567);
    }

    private function record(string $mode, ?string $current = null): ?Suggestion
    {
        return $this->writer()->record(5, 'color', $this->accepted(), $mode, self::FP, 'jev-1.13.0', $current);
    }

    /**
     * @param array<int, array<string,mixed>> $rows
     */
    private function existing(array $rows): void
    {
        $this->rows = $rows;
        $this->storedStatus = $rows[0]['status'] ?? null;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonAccepted(): array
    {
        return ['not stated' => [Decision::NOT_STATED], 'uncertain' => [Decision::UNCERTAIN]];
    }

    #[DataProvider('nonAccepted')]
    public function testNonAcceptedDecisionDropsOpenRowsOnly(string $outcome): void
    {
        $this->existing([['suggestion_id' => '8', 'status' => 'shadow', 'fingerprint' => 'fp-old', 'value' => '3']]);
        $this->mock('resource')->expects(self::once())->method('deleteOpen')->with(5, 'color');
        $this->mock('resource')->expects(self::never())->method('deleteStale');
        $this->mock('resource')->expects(self::never())->method('save');
        $this->resource->expects(self::never())->method('saveIfStatus');
        $this->mock('applier')->expects(self::never())->method('apply');

        self::assertNull(
            $this->writer()->record(5, 'color', new Decision($outcome), Rule::MODE_AUTO, self::FP, 'jev', null)
        );
    }

    public function testNonAcceptedDecisionWithoutOpenRowsWritesNothing(): void
    {
        $this->existing([['suggestion_id' => '8', 'status' => 'rejected', 'fingerprint' => 'fp-old', 'value' => '3']]);
        $this->mock('resource')->expects(self::never())->method('deleteOpen');

        self::assertNull(
            $this->writer()->record(5, 'color', $this->uncertain(), Rule::MODE_REVIEW, self::FP, 'j', null)
        );
    }

    public function testModeOffDoesNothing(): void
    {
        $this->mock('resource')->expects(self::never())->method('save');
        $this->resource->expects(self::never())->method('saveIfStatus');

        self::assertNull($this->record(Rule::MODE_OFF));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function createdStatus(): array
    {
        return [
            'shadow' => [Rule::MODE_SHADOW, Suggestion::STATUS_SHADOW],
            'review' => [Rule::MODE_REVIEW, Suggestion::STATUS_PENDING],
        ];
    }

    #[DataProvider('createdStatus')]
    public function testCreatesRowForMode(string $mode, string $status): void
    {
        $this->existing([]);
        $this->mock('resource')->expects(self::never())->method('deleteStale');
        $this->mock('applier')->expects(self::never())->method('apply');

        $suggestion = $this->record($mode, '');

        self::assertNotNull($suggestion);
        self::assertSame([$status], $this->saved);
        self::assertSame([
            'product_id' => 5,
            'attribute_code' => 'color',
            'value' => '12',
            'previous_value' => null,
            'probability' => 0.9346,
            'status' => $status,
            'fingerprint' => self::FP,
            'model' => 'jev-1.13.0',
        ], $suggestion->getData());
    }

    public function testPreviousValueIsTheCurrentValue(): void
    {
        $this->existing([]);

        self::assertSame('3,9', $this->record(Rule::MODE_REVIEW, '3,9')?->getPreviousValue());
    }

    public function testAutoCreatesPendingThenApplies(): void
    {
        $this->existing([]);
        $this->mock('applier')->expects(self::once())->method('apply')->willReturnCallback(
            function (Suggestion $s): ApplyResult {
                self::assertSame(Suggestion::STATUS_PENDING, $s->getStatus());
                $s->setData('status', Suggestion::STATUS_APPLIED);

                return new ApplyResult(true);
            }
        );
        $this->mock('logger')->expects(self::never())->method('warning');

        self::assertSame(Suggestion::STATUS_APPLIED, $this->record(Rule::MODE_AUTO)?->getStatus());
        self::assertSame([Suggestion::STATUS_PENDING], $this->saved);
    }

    public function testAutoApplyRefusedStaysPendingAndWarns(): void
    {
        $this->existing([]);
        $this->mock('applier')->expects(self::once())->method('apply')
            ->willReturn(new ApplyResult(false, 'The attribute already has a value.'));
        $this->mock('logger')->expects(self::once())->method('warning')->with(
            self::stringContains('auto-apply refused'),
            ['product_id' => 5, 'attribute_code' => 'color', 'reason' => 'The attribute already has a value.']
        );

        self::assertSame(Suggestion::STATUS_PENDING, $this->record(Rule::MODE_AUTO)?->getStatus());
        self::assertSame([Suggestion::STATUS_PENDING], $this->saved);
    }

    public function testShadowAppliedByAConcurrentRunIsNotDemoted(): void
    {
        $this->existing([['suggestion_id' => '8', 'status' => 'shadow', 'fingerprint' => self::FP, 'value' => '12']]);
        $this->storedStatus = Suggestion::STATUS_APPLIED;
        $this->applier->method('apply')->willReturn(new ApplyResult(false, 'Only pending suggestions can be applied.'));

        self::assertNull($this->record(Rule::MODE_AUTO));
        self::assertSame([], $this->saved);
    }

    public function testShadowPromotedToPendingInReview(): void
    {
        $this->existing([['suggestion_id' => '8', 'status' => 'shadow', 'fingerprint' => self::FP, 'value' => '12']]);
        $this->mock('applier')->expects(self::never())->method('apply');

        $suggestion = $this->record(Rule::MODE_REVIEW);

        self::assertSame('8', $suggestion?->getId());
        self::assertSame([Suggestion::STATUS_PENDING], $this->saved);
    }

    public function testShadowAppliedInAuto(): void
    {
        $this->existing([['suggestion_id' => '8', 'status' => 'shadow', 'fingerprint' => self::FP, 'value' => '12']]);
        $this->mock('applier')->expects(self::once())->method('apply')->willReturnCallback(
            function (Suggestion $s): ApplyResult {
                self::assertSame('8', $s->getId());
                $s->setData('status', Suggestion::STATUS_APPLIED);

                return new ApplyResult(true);
            }
        );

        self::assertSame(Suggestion::STATUS_APPLIED, $this->record(Rule::MODE_AUTO)?->getStatus());
        self::assertSame([], $this->saved);
    }

    public function testShadowAutoApplyRefusedBecomesPending(): void
    {
        $this->existing([['suggestion_id' => '8', 'status' => 'shadow', 'fingerprint' => self::FP, 'value' => '12']]);
        $this->applier->method('apply')->willReturn(new ApplyResult(false, 'nope'));
        $this->mock('logger')->expects(self::once())->method('warning');

        self::assertSame(Suggestion::STATUS_PENDING, $this->record(Rule::MODE_AUTO)?->getStatus());
        self::assertSame([Suggestion::STATUS_PENDING], $this->saved);
    }

    public function testPendingDemotedToShadowInShadowMode(): void
    {
        $this->existing([['suggestion_id' => '8', 'status' => 'pending', 'fingerprint' => self::FP, 'value' => '12']]);
        $this->mock('applier')->expects(self::never())->method('apply');

        self::assertSame(Suggestion::STATUS_SHADOW, $this->record(Rule::MODE_SHADOW)?->getStatus());
        self::assertSame([Suggestion::STATUS_SHADOW], $this->saved);
    }

    public function testPendingAppliedInAuto(): void
    {
        $this->existing([['suggestion_id' => '8', 'status' => 'pending', 'fingerprint' => self::FP, 'value' => '12']]);
        $this->mock('applier')->expects(self::once())->method('apply')->willReturnCallback(
            function (Suggestion $s): ApplyResult {
                $s->setData('status', Suggestion::STATUS_APPLIED);

                return new ApplyResult(true);
            }
        );

        self::assertSame(Suggestion::STATUS_APPLIED, $this->record(Rule::MODE_AUTO)?->getStatus());
        self::assertSame([], $this->saved);
    }

    public function testPendingAutoApplyRefusedStaysPendingUnchanged(): void
    {
        $this->existing([['suggestion_id' => '8', 'status' => 'pending', 'fingerprint' => self::FP, 'value' => '12']]);
        $this->applier->method('apply')->willReturn(new ApplyResult(false, 'nope'));
        $this->mock('resource')->expects(self::never())->method('saveIfStatus');

        self::assertNull($this->record(Rule::MODE_AUTO));
    }

    public function testShadowStaysShadowInShadowMode(): void
    {
        $this->existing([['suggestion_id' => '8', 'status' => 'shadow', 'fingerprint' => self::FP, 'value' => '12']]);
        $this->mock('resource')->expects(self::never())->method('save');
        $this->resource->expects(self::never())->method('saveIfStatus');

        self::assertNull($this->record(Rule::MODE_SHADOW));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function noDuplicate(): array
    {
        $cases = ['pending/review' => [Suggestion::STATUS_PENDING, Rule::MODE_REVIEW]];
        foreach ([Rule::MODE_SHADOW, Rule::MODE_REVIEW, Rule::MODE_AUTO] as $mode) {
            $cases["applied/$mode"] = [Suggestion::STATUS_APPLIED, $mode];
        }

        return $cases;
    }

    #[DataProvider('noDuplicate')]
    public function testExistingPendingOrAppliedIsNotDuplicated(string $status, string $mode): void
    {
        $this->existing([['suggestion_id' => '8', 'status' => $status, 'fingerprint' => self::FP, 'value' => '12']]);
        $this->mock('resource')->expects(self::never())->method('save');
        $this->resource->expects(self::never())->method('saveIfStatus');
        $this->mock('applier')->expects(self::never())->method('apply');

        self::assertNull($this->record($mode));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function openStatus(): array
    {
        return [
            'shadow' => [Suggestion::STATUS_SHADOW, Rule::MODE_SHADOW],
            'pending' => [Suggestion::STATUS_PENDING, Rule::MODE_REVIEW],
        ];
    }

    #[DataProvider('openStatus')]
    public function testOpenRowOfSameFingerprintTakesTheNewDecision(string $status, string $mode): void
    {
        $this->existing([[
            'suggestion_id' => '8',
            'status' => $status,
            'fingerprint' => self::FP,
            'value' => '3',
            'probability' => '0.9',
        ]]);
        $this->mock('applier')->expects(self::never())->method('apply');

        $suggestion = $this->record($mode);

        self::assertSame('12', $suggestion?->getData('value'));
        self::assertSame(0.9346, $suggestion?->getData('probability'));
        self::assertSame([$status], $this->saved);
    }

    public function testAppliedRowIsNotRefreshed(): void
    {
        $this->existing([['suggestion_id' => '8', 'status' => 'applied', 'fingerprint' => self::FP, 'value' => '3']]);
        $this->mock('resource')->expects(self::never())->method('save');
        $this->resource->expects(self::never())->method('saveIfStatus');

        self::assertNull($this->record(Rule::MODE_REVIEW));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function modes(): array
    {
        return ['shadow' => [Rule::MODE_SHADOW], 'review' => [Rule::MODE_REVIEW], 'auto' => [Rule::MODE_AUTO]];
    }

    #[DataProvider('modes')]
    public function testBlockedFingerprintIsNeverResuggested(string $mode): void
    {
        $rows = [['suggestion_id' => '8', 'status' => 'rejected', 'fingerprint' => 'fp-old', 'value' => '12']];
        $this->existing($rows);
        $this->mock('guard')->expects(self::once())->method('isBlocked')->with($rows, self::FP, '12')->willReturn(true);
        $this->mock('resource')->expects(self::never())->method('save');
        $this->resource->expects(self::never())->method('saveIfStatus');
        $this->mock('applier')->expects(self::never())->method('apply');

        self::assertNull($this->record($mode));
    }

    public function testNewFingerprintAfterRejectionCreatesSuggestion(): void
    {
        $this->guard->method('isBlocked')->willReturn(false);
        $this->existing([['suggestion_id' => '8', 'status' => 'rejected', 'fingerprint' => 'fp-old']]);

        self::assertSame(Suggestion::STATUS_PENDING, $this->record(Rule::MODE_REVIEW)?->getStatus());
        self::assertSame([Suggestion::STATUS_PENDING], $this->saved);
    }

    public function testStalePendingOfOldFingerprintDeletedBeforeCreating(): void
    {
        $this->existing([['suggestion_id' => '3', 'status' => 'pending', 'fingerprint' => 'fp-old']]);
        $this->mock('resource')->expects(self::once())->method('deleteStale')->with(5, 'color', self::FP)
            ->willReturnCallback(function (): int {
                self::assertSame(1, $this->findForCalls);
                $this->rows = [];

                return 1;
            });

        self::assertNull($this->record(Rule::MODE_REVIEW)?->getId());
        self::assertSame([Suggestion::STATUS_PENDING], $this->saved);
        self::assertSame(2, $this->findForCalls);
    }

    public function testPrefetchedChunkIsServedWithoutPerPairQueries(): void
    {
        $this->mock('resource')->expects(self::once())->method('findForProducts')->with([5, 6], ['color'])
            ->willReturn([5 => ['color' => [
                ['suggestion_id' => '8', 'product_id' => '5', 'attribute_code' => 'color', 'status' => 'pending',
                    'fingerprint' => self::FP, 'value' => '12'],
            ]]]);
        $this->resource->expects(self::never())->method('findFor');
        $this->resource->expects(self::never())->method('deleteOpen');
        $this->resource->expects(self::never())->method('deleteStale');
        $this->resource->expects(self::never())->method('saveIfStatus');
        $writer = $this->writer();
        $writer->prefetch([5, 6], ['color']);

        self::assertNull($writer->record(5, 'color', $this->accepted(), Rule::MODE_REVIEW, self::FP, 'j', null));
        self::assertNull($writer->record(6, 'color', $this->uncertain(), Rule::MODE_REVIEW, 'f', 'j', null));
        self::assertNotNull($writer->record(6, 'color', $this->accepted(), Rule::MODE_REVIEW, self::FP, 'j', null));
        // The created row is cached: the same decision again is a no-op.
        self::assertNull($writer->record(6, 'color', $this->accepted(), Rule::MODE_REVIEW, self::FP, 'j', null));
        self::assertSame([Suggestion::STATUS_PENDING], $this->saved);
    }

    public function testPrefetchedCacheFollowsDeletesAndStatusChanges(): void
    {
        $this->mock('resource')->method('findForProducts')->willReturn([5 => ['color' => [
            ['suggestion_id' => '8', 'product_id' => '5', 'attribute_code' => 'color', 'status' => 'shadow',
                'fingerprint' => self::FP, 'value' => '12'],
        ]]]);
        $this->storedStatus = Suggestion::STATUS_SHADOW;
        $this->resource->expects(self::once())->method('deleteOpen');
        $writer = $this->writer();
        $writer->prefetch([5], ['color']);

        $promoted = $writer->record(5, 'color', $this->accepted(), Rule::MODE_REVIEW, self::FP, 'j', null);
        self::assertSame('8', $promoted?->getId());
        $this->storedStatus = Suggestion::STATUS_PENDING;
        self::assertNull($writer->record(5, 'color', $this->accepted(), Rule::MODE_REVIEW, self::FP, 'j', null));
        self::assertNull($writer->record(5, 'color', $this->uncertain(), Rule::MODE_REVIEW, 'f', 'j', null));
        self::assertNull($writer->record(5, 'color', $this->uncertain(), Rule::MODE_REVIEW, 'f', 'j', null));
        self::assertSame([Suggestion::STATUS_PENDING], $this->saved);
    }

    public function testDropFilledDeletesOnlyForProductsWithOpenRows(): void
    {
        $this->mock('resource')->method('findForProducts')->willReturn([
            5 => ['color' => [['suggestion_id' => '8', 'status' => 'pending', 'fingerprint' => 'f', 'value' => '1']]],
            6 => ['color' => [['suggestion_id' => '9', 'status' => 'applied', 'fingerprint' => 'f', 'value' => '1']]],
        ]);
        $this->resource->expects(self::once())->method('deleteOpenForProducts')->with('color', [5, 9]);
        $writer = $this->writer();
        $writer->prefetch([5, 6, 7], ['color']);

        $writer->dropFilled('color', [5, 6, 7, 9]);
        $writer->dropFilled('color', [5, 6, 7]);
    }
}

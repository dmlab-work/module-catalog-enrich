<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Model\Dedup;

use DmLab\CatalogEnrich\Model\Dedup\RejectionGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RejectionGuardTest extends TestCase
{
    private const FP = 'fp-current';

    /**
     * @return array<string, array{0: array<int, array<string,string>>, 1: string, 2: bool}>
     */
    public static function cases(): array
    {
        $row = static fn (string $status, string $fingerprint, string $value = '10'): array => [
            'status' => $status,
            'fingerprint' => $fingerprint,
            'value' => $value,
        ];

        return [
            'rejected same fingerprint' => [[$row('rejected', self::FP)], '11', true],
            'reverted same fingerprint' => [[$row('reverted', self::FP)], '11', true],
            'rejected older fingerprint, same value' => [[$row('rejected', 'fp-old')], '10', true],
            'reverted older fingerprint, same value' => [[$row('reverted', 'fp-old')], '10', true],
            'rejected older fingerprint, other value' => [[$row('rejected', 'fp-old')], '11', false],
            'boolean 0 rejected' => [[$row('rejected', 'fp-old', '0')], '0', true],
            'boolean 0 rejected, 1 suggested' => [[$row('rejected', 'fp-old', '0')], '1', false],
            'multiselect same id set' => [[$row('rejected', 'fp-old', '413,412')], '412, 413', true],
            'multiselect subset' => [[$row('rejected', 'fp-old', '412,413')], '412', false],
            'multiselect superset' => [[$row('reverted', 'fp-old', '412')], '412,413', false],
            'pending same value' => [[$row('pending', 'fp-old')], '10', false],
            'applied same value' => [[$row('applied', self::FP)], '10', false],
            'shadow' => [[$row('shadow', self::FP)], '10', false],
            'none' => [[], '10', false],
            'empty value' => [[$row('rejected', 'fp-old', '')], '', false],
            'rejected among others' => [[$row('pending', 'fp-old', '11'), $row('rejected', 'fp-x', '10')], '10', true],
        ];
    }

    /**
     * @param array<int, array<string,string>> $rows
     */
    #[DataProvider('cases')]
    public function testIsBlocked(array $rows, string $value, bool $expected): void
    {
        self::assertSame($expected, (new RejectionGuard())->isBlocked($rows, self::FP, $value));
    }
}

<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Domain\Finance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Domain\Finance\TaxRateKind;

/**
 * The kinds a rate of tax may be: the categories the rates of the countries
 * of the European Union fall into - standard, reduced, super-reduced, parking
 * and zero - each offered to a form under a name a person reads.
 */
#[CoversClass(TaxRateKind::class)]
final class TaxRateKindTest extends TestCase
{
    public function testEveryKindARateOfTheUnionMayBeIsOfferedUnderItsName(): void
    {
        self::assertSame([
            'standard' => 'Standard',
            'reduced' => 'Reduced',
            'super_reduced' => 'Super-reduced',
            'parking' => 'Parking',
            'zero' => 'Zero',
        ], TaxRateKind::choices());
    }
}

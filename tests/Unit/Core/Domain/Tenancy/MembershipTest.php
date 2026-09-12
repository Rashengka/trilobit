<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Domain\Tenancy;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Domain\Tenancy\Membership;
use Trilobit\Core\Domain\Tenancy\Tenant;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Domain\User\User;

/**
 * The one thing a membership refuses to be.
 *
 * An account that administers the installation is above the businesses rather
 * than inside one, and it may not be both. The refusal is in the constructor
 * and not in a form or in a service, because a membership is made in more than
 * one place and each of those would be a place to forget it - and forgetting
 * it produces a working account rather than an error, which is the shape of
 * mistake nobody finds.
 *
 * No database is involved on purpose. What is being asserted is that the object
 * cannot be built, which is exactly the point of putting it in the constructor:
 * there is no half-built one to save.
 */
#[CoversClass(Membership::class)]
final class MembershipTest extends TestCase
{
    public function testAnAccountThatAdministersTheInstallationCannotBeGivenOne(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('#landlord@example\.com#');

        new Membership($this->tenant(), $this->landlord(), $this->role());
    }

    /** The same call for an ordinary account, so that the refusal above is about the flag and not about the fixture. */
    public function testAnOrdinaryAccountIsGivenOneWithoutComplaint(): void
    {
        $membership = new Membership($this->tenant(), $this->member(), $this->role());

        self::assertSame('member@example.com', $membership->user()->email());
        self::assertSame('administrator', $membership->role()->code());
    }

    private function tenant(): Tenant
    {
        return new Tenant('Ammonite Bikes', new DateTimeImmutable('2026-09-07T08:00:00+00:00'));
    }

    private function role(): Role
    {
        return new Role('administrator', 'Administrator', ['app.administration:view']);
    }

    private function landlord(): User
    {
        return new User(
            'landlord@example.com',
            'not a real hash',
            'Bea Brachiopod',
            new DateTimeImmutable('2026-09-07T08:00:00+00:00'),
            landlord: true,
        );
    }

    private function member(): User
    {
        return new User(
            'member@example.com',
            'not a real hash',
            'Alice Ammonite',
            new DateTimeImmutable('2026-09-07T08:00:00+00:00'),
        );
    }
}

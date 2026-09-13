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
 * The one thing a membership will not be by accident.
 *
 * An account that administers the installation may hold a role in a business
 * as well - one person running one shop is both - but only when that is said
 * outright. The ordinary way refuses such an account and names the deliberate
 * one; the deliberate one takes such an account and nobody else. The refusal
 * is in the object and not in a form or in a service, because a membership is
 * made in more than one place and each of those would be a place to forget it
 * - and forgetting it produces a working account rather than an error, which
 * is the shape of mistake nobody finds.
 *
 * No database is involved on purpose. What is being asserted is that the object
 * cannot be built, which is exactly the point of putting it in the object:
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

    /**
     * The refusal says where the other way is. Somebody who meant the account
     * to be both is told which call says so; somebody who did not is told that
     * what they were about to do is a decision and not a side effect.
     */
    public function testTheOrdinaryWayNamesTheDeliberateOne(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('#forTheInstallationsAdministrator\(\)#');

        new Membership($this->tenant(), $this->landlord(), $this->role());
    }

    /**
     * Said outright, the account that administers the installation holds a
     * role in a business as well - which is what a simple installation, where
     * one person is both, is made of.
     */
    public function testTheDeliberateWayGivesTheInstallationsAdministratorOne(): void
    {
        $membership = Membership::forTheInstallationsAdministrator($this->tenant(), $this->landlord(), $this->role());

        self::assertSame('landlord@example.com', $membership->user()->email());
        self::assertTrue($membership->user()->isLandlord());
        self::assertSame('Ammonite Bikes', $membership->tenant()->name());
        self::assertSame('administrator', $membership->role()->code());
        self::assertNull($membership->id());
    }

    /**
     * And it is the way for that account and no other. A call that took
     * anybody would be the one every caller used so as not to have to think,
     * and the decision it exists to make visible would be made everywhere
     * without being made anywhere.
     */
    public function testTheDeliberateWayIsNotAWayRoundForAnOrdinaryAccount(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('#member@example\.com#');

        Membership::forTheInstallationsAdministrator($this->tenant(), $this->member(), $this->role());
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

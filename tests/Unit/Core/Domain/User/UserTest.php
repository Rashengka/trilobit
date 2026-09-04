<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Domain\User;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Domain\User\User;

/**
 * What an account is, before any database is involved.
 *
 * The one piece of behaviour worth stating here is that permissions are read
 * off the roles rather than stored on the account. An account that carried its
 * own copy would keep the permissions a role had on the day it was granted,
 * and changing the role would silently leave that account behind.
 */
#[CoversClass(User::class)]
#[CoversClass(Role::class)]
final class UserTest extends TestCase
{
    public function testANewAccountHasNoRoleAndNoPermission(): void
    {
        $account = $this->account();

        self::assertSame([], $account->roleCodes());
        self::assertSame([], $account->permissions());
    }

    public function testItReadsItsPermissionsOffTheRolesItWasGranted(): void
    {
        $account = $this->account();
        $account->grant(new Role('editor', 'Editor', ['content.write']));

        self::assertSame(['editor'], $account->roleCodes());
        self::assertSame(['content.write'], $account->permissions());
    }

    /**
     * Two roles may both carry the same permission, and the answer to "may
     * this account do it" is a yes or a no, not a count.
     */
    public function testAPermissionTwoRolesShareIsListedOnce(): void
    {
        $account = $this->account();
        $account->grant(new Role('editor', 'Editor', ['content.write', 'media.upload']));
        $account->grant(new Role('librarian', 'Librarian', ['media.upload']));

        self::assertSame(['content.write', 'media.upload'], $account->permissions());
    }

    public function testGrantingTheSameRoleTwiceLeavesOneOfIt(): void
    {
        $account = $this->account();
        $role = new Role('editor', 'Editor', ['content.write']);

        $account->grant($role);
        $account->grant($role);

        self::assertSame(['editor'], $account->roleCodes());
    }

    /**
     * A role is the same role when its code is, whichever object carries it -
     * two reads of the same row are two objects, and granting both would show
     * the role twice in the administration.
     */
    public function testARoleAlreadyHeldUnderTheSameCodeIsNotGrantedAgain(): void
    {
        $account = $this->account();
        $account->grant(new Role('editor', 'Editor', ['content.write']));
        $account->grant(new Role('editor', 'Editor', ['content.write']));

        self::assertSame(['editor'], $account->roleCodes());
    }

    public function testItRemembersWhenItLastSignedIn(): void
    {
        $account = $this->account();
        self::assertNull($account->lastLoginAt());

        $at = new DateTimeImmutable('2026-09-04T09:00:00+00:00');
        $account->signedIn($at);

        self::assertEquals($at, $account->lastLoginAt());
    }

    private function account(): User
    {
        return new User(
            'somebody@example.com',
            'not a real hash',
            'Alice Ammonite',
            new DateTimeImmutable('2026-09-01T08:00:00+00:00'),
        );
    }
}

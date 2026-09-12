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
    /**
     * The property saying which of the two scopes an account is in. Named once,
     * and asked of reflection before it is looked for in the source, so that
     * renaming it fails rather than emptying the search.
     */
    private const string FLAG = 'landlord';

    /**
     * The two ways of reaching a property of an object. Both are read, because
     * a rule that knew only about `->` would be one a `?->` walked past - and
     * the point of reading the source is that there is nothing to walk past.
     *
     * @var list<int>
     */
    private const array REACHES_A_PROPERTY = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR];

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

    /**
     * Administering the installation is the other scope, and an account is in
     * one of the two rather than at a level within one. An account nobody said
     * anything about is in the ordinary one.
     */
    public function testAnAccountIsNotTheInstallationAdministratorUnlessItWasMadeAsOne(): void
    {
        self::assertFalse($this->account()->isLandlord());
        self::assertTrue($this->landlord()->isLandlord());
    }

    /**
     * The half of "the two scopes never meet by accident" that a check could
     * not deliver.
     *
     * The other half is a refusal - Trilobit\Core\Domain\Tenancy\Membership
     * is not built the ordinary way for an account that administers the
     * installation, only through the one call that says so. This half is not a
     * refusal but the absence of anything to refuse: what an account is, is
     * said once, when it is made, and an account being made holds no
     * membership because nothing can point at a row that does not exist yet.
     * So a member cannot be made an installation administrator afterwards, and
     * that is true because there is no way to say it rather than because
     * somewhere it is checked.
     *
     * **It is read out of the source, and that is the whole of why it can be
     * trusted.** A rule anchored on a method name - "no method of this class is
     * called anything like landlord" - is satisfied by renaming, and a mutator
     * called promote() would walk past it while doing exactly the thing the
     * sentence above says cannot be said. So this reads what the class does to
     * the flag instead: the property is promoted, which means the one write
     * there is - the constructor's - is not written down anywhere, and every
     * other mention of it has to be the single line that gives it back. The
     * same reasoning as
     * Trilobit\Tests\Architecture\PermissionQuestions, which looks for the enum
     * rather than for isAllowed( and says why.
     *
     * Two things it deliberately does not pass over. A property reached by a
     * name it cannot read - `$this->{$whatever}` - is **reported** rather than
     * skipped, because skipping is how a guard comes to walk past the one place
     * that mattered. And the property being renamed is a failure here rather
     * than a widening, because the name is asked of reflection first.
     *
     * What it does not reach is code outside this class binding a closure to an
     * instance, which private visibility already makes deliberate rather than
     * accidental. **Exit condition:** a screen that appoints or dismisses an
     * installation administrator; the mutator then arrives with the reading of
     * every tenant's memberships that has to guard it, and this test is
     * rewritten around that reading rather than deleted.
     */
    public function testWhetherAnAccountAdministersTheInstallationCanOnlyBeSaidWhenItIsMade(): void
    {
        // Asked of reflection first, so that renaming the property fails here
        // rather than quietly emptying what the reading below looks for.
        $flag = new \ReflectionProperty(User::class, self::FLAG);
        self::assertTrue($flag->isPromoted(), 'the flag has to be a constructor parameter to be sayable only there');

        $mentions = $this->everyMentionOfTheFlagIn(User::class);

        self::assertSame(
            [],
            $mentions['unreadable'],
            'a property is reached here by a name this cannot read, so what is written to it is unknown',
        );
        self::assertSame(
            [],
            $mentions['other'],
            'the flag is touched somewhere other than the one line that gives it back',
        );
        self::assertCount(1, $mentions['read'], 'the one line that gives the flag back has gone');
    }

    /**
     * Every mention of the flag in the source of $class, sorted into the one
     * shape that is allowed and everything else.
     *
     * The allowed shape is `return $this->landlord;` and nothing wider: a
     * mention this cannot recognise letter for letter is reported, because the
     * claim being made is that there is only one, and "probably a read" is not
     * a claim.
     *
     * @param class-string $class
     *
     * @return array{read: list<string>, other: list<string>, unreadable: list<string>}
     *     each place written as the line it is on
     */
    private function everyMentionOfTheFlagIn(string $class): array
    {
        $tokens = $this->significant($class);
        $found = ['read' => [], 'other' => [], 'unreadable' => []];

        foreach ($tokens as $position => $token) {
            if (!is_array($token) || !in_array($token[0], self::REACHES_A_PROPERTY, true)) {
                continue;
            }

            $where = 'line ' . $token[2];
            $name = $tokens[$position + 1] ?? null;

            // `$something->{...}` and `$something->$name`: what is being
            // reached for is decided while the program runs, so this cannot say
            // whether it is the flag. Reported, never passed over.
            if (!is_array($name) || $name[0] !== T_STRING) {
                $found['unreadable'][] = $where;

                continue;
            }

            if ($name[1] !== self::FLAG) {
                continue;
            }

            $found[$this->isTheOneReading($tokens, $position) ? 'read' : 'other'][] = $where;
        }

        return $found;
    }

    /**
     * Whether the mention at $position is exactly `return $this->landlord;`.
     *
     * Everything else - an assignment, a compound assignment, an increment, a
     * reference, a destructuring, a read this did not expect - falls out as
     * "other" without any of them having to be listed, which is what keeps this
     * from being a list somebody has to remember to add to.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private function isTheOneReading(array $tokens, int $position): bool
    {
        $return = $tokens[$position - 2] ?? null;
        $subject = $tokens[$position - 1] ?? null;

        return is_array($return) && $return[0] === T_RETURN
            && is_array($subject) && $subject[0] === T_VARIABLE && $subject[1] === '$this'
            && ($tokens[$position + 2] ?? null) === ';';
    }

    /**
     * The source of $class as tokens, with whitespace and comments left out and
     * renumbered, so that "the next thing written" is the next index.
     *
     * The file comes from reflection rather than from a path written here: a
     * class that moves is still read, and a class that cannot be found at all
     * fails rather than being quietly read as nothing.
     *
     * @param class-string $class
     *
     * @return list<array{int, string, int}|string>
     */
    private function significant(string $class): array
    {
        $file = new \ReflectionClass($class)->getFileName();
        self::assertIsString($file, $class . ' has no source to read.');

        $source = file_get_contents($file);
        self::assertIsString($source, $file . ' could not be read.');

        $significant = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $significant[] = $token;
        }

        return $significant;
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

    private function landlord(): User
    {
        return new User(
            'landlord@example.com',
            'not a real hash',
            'Bea Brachiopod',
            new DateTimeImmutable('2026-09-01T08:00:00+00:00'),
            landlord: true,
        );
    }
}

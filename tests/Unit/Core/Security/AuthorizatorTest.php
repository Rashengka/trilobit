<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Security;

use Doctrine\ORM\EntityManagerInterface;
use Nette\Security\Authorizator as NetteAuthorizator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Security\Authorizator;
use Trilobit\Core\Security\Memberships;
use Trilobit\Core\Security\PermissionStructure;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;
use Trilobit\Core\Tenancy\Tenancy;
use Trilobit\Core\Tenancy\TenancyRefused;

/**
 * The shape of the framework's own question, which is where this class carries
 * its decisions.
 *
 * Everything below is about what may reach the answer at all, so none of it
 * needs a database: a call that is refused is refused before anything is read,
 * and that is itself one of the claims.
 *
 * The parameter types are the first of them and they are not decoration.
 * Nette\Security\Authorizator promises `?string` and an implementation may only
 * widen that, so three shapes are possible and they are not equivalent: `mixed`
 * lets an array, a number and somebody else's object in and leaves this class
 * to have an opinion about each; dropping the string would break the interface,
 * because Nette hands over `guest` for every visitor; and the union of the two
 * lets the engine refuse everything else before a line of this class runs. The
 * third is what is asserted, from the outside, so that a later widening to
 * `mixed` has to be an argument somebody makes rather than a keystroke.
 */
#[CoversClass(Authorizator::class)]
final class AuthorizatorTest extends TestCase
{
    public function testItIsTheFrameworksOwnAuthorizator(): void
    {
        self::assertInstanceOf(NetteAuthorizator::class, $this->authorizator());
    }

    /** The framework's string and nothing wider: a role is a name Nette hands over. */
    public function testARoleIsTheNameTheFrameworkHandsOver(): void
    {
        self::assertEqualsCanonicalizing(['string', 'null'], $this->typesOfParameter(0));
    }

    /**
     * The two halves of a pair are our enums, plus the string the interface
     * promises and cannot be narrowed away. `mixed` would be the easy way to
     * satisfy the interface and would let anything at all through to be sorted
     * out at run time.
     */
    public function testEachHalfOfThePairIsOurEnumOrTheStringTheInterfacePromises(): void
    {
        self::assertEqualsCanonicalizing(['string', Resource::class, 'null'], $this->typesOfParameter(1));
        self::assertEqualsCanonicalizing(['string', Privilege::class, 'null'], $this->typesOfParameter(2));
    }

    /** The tenant is not among them, which is the same sentence Permissions says. */
    public function testTheQuestionHasNoWayToNameATenant(): void
    {
        $named = [];
        foreach (new \ReflectionMethod(Authorizator::class, 'isAllowed')->getParameters() as $parameter) {
            $named[] = $parameter->getName();
        }

        self::assertSame(['role', 'resource', 'privilege'], $named);
    }

    /**
     * A resource written as a string is refused rather than answered. It would
     * be a question spelled by hand, and a name spelled by hand is the one thing
     * tests/Architecture/EveryPermissionQuestionIsPredefinedTest cannot read -
     * so a quiet answer here would be a question nothing checks.
     */
    public function testAResourceSpelledAsAStringIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('#Resource::Something#');

        $this->authorizator()->isAllowed('editor', 'content', Privilege::Edit);
    }

    public function testAPrivilegeSpelledAsAStringIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('#Privilege::Something#');

        $this->authorizator()->isAllowed('editor', Resource::Content, 'edit');
    }

    /** Nette's "all resources" is not a question this application has an answer for either. */
    public function testAskingAboutEverythingAtOnceIsRefused(): void
    {
        $this->expectException(\LogicException::class);

        $this->authorizator()->isAllowed('editor', null, null);
    }

    /**
     * The half Nette does not check. A privilege it does not know is written
     * into a rule nobody will ever match, or asked in a question no rule will
     * ever answer; either way the answer is a quiet "no" that reads exactly like
     * a decision somebody took.
     */
    public function testAPairTheStructureDoesNotOfferIsRefusedOutright(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('#force_redirect#');

        $this->authorizator()->isAllowed('editor', Resource::Account, Privilege::ForceRedirect);
    }

    /**
     * Asked before it is settled whose request this is, it refuses rather than
     * answering - because the answer would be built out of somebody else's rows.
     * The pair is checked first on purpose: a pair that could never be answered
     * is a mistake in the code rather than a question asked in the wrong place.
     */
    public function testAQuestionAskedBeforeATenantWasEnteredIsRefused(): void
    {
        $this->expectException(TenancyRefused::class);

        $this->authorizator()->isAllowed('editor', Resource::Content, Privilege::Edit);
    }

    /**
     * No role at all is Nette's way of saying "any role", and this application
     * has nobody it means. It is answered no rather than raised, because
     * Nette\Security\User reaches this with whatever getRoles() gave it, and it
     * is answered before the tenant is asked for, because nothing has to be read
     * to know that nobody is not somebody.
     */
    public function testNoRoleAtAllIsAnsweredNo(): void
    {
        self::assertFalse($this->authorizator()->isAllowed(null, Resource::Content, Privilege::Edit));
    }

    /**
     * The parameter's declared types, as a set of names with `null` spelled out,
     * so that `?string` and `string|null` cannot be told apart by punctuation.
     *
     * @return list<string>
     */
    private function typesOfParameter(int $position): array
    {
        $parameters = new \ReflectionMethod(Authorizator::class, 'isAllowed')->getParameters();
        $type = ($parameters[$position] ?? null)?->getType();

        $declared = $type instanceof \ReflectionUnionType ? $type->getTypes() : [$type];
        $names = [];
        foreach ($declared as $one) {
            $names[] = $one instanceof \ReflectionNamedType ? $one->getName() : (string) $one;
        }

        if ($type?->allowsNull() === true && !in_array('null', $names, true)) {
            $names[] = 'null';
        }

        return $names;
    }

    /**
     * The real structure and a database nothing here reaches. Every case above
     * is settled before a query would be made, which is the point of each of
     * them.
     */
    private function authorizator(): Authorizator
    {
        return new Authorizator(
            new Tenancy(self::createStub(EntityManagerInterface::class)),
            PermissionStructure::of(Bootstrap::rootDirectory()),
            new Memberships(self::createStub(EntityManagerInterface::class)),
        );
    }
}

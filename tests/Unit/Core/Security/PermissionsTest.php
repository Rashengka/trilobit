<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Security;

use Doctrine\ORM\EntityManagerInterface;
use Nette\Security\User as SignedIn;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Security\Permissions;
use Trilobit\Core\Security\PermissionStructure;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;
use Trilobit\Core\Tenancy\Tenancy;
use Trilobit\Core\Tenancy\TenancyRefused;

/**
 * The shape of the question, which is the part of this design that carries the
 * decision.
 *
 * What a permission service usually gets wrong is not the answer but the
 * question: an access list is a triple with no tenant in it, so the tenant is
 * added by the caller, and the day it is left out somewhere the application
 * answers with somebody else's rights. The fix asserted here is not a check
 * for a missing tenant - it is that there is nothing to leave out. The caller
 * says what and how, and where is not theirs to say.
 *
 * The two claims are separate on purpose. That the tenant cannot be passed is
 * a fact about the signature, so it is asked of the signature; that a question
 * asked outside a tenant is refused rather than answered is a fact about what
 * happens, so it is asked by asking one.
 */
#[CoversClass(Permissions::class)]
final class PermissionsTest extends TestCase
{
    public function testTheQuestionHasNoWayToNameATenant(): void
    {
        $parameters = new \ReflectionMethod(Permissions::class, 'isAllowed')->getParameters();

        $named = [];
        foreach ($parameters as $parameter) {
            $type = $parameter->getType();
            $named[] = $type instanceof \ReflectionNamedType ? $type->getName() : (string) $type;
        }

        self::assertSame([Resource::class, Privilege::class], $named);
    }

    /** A parameter with a default is a parameter that can be left out, so neither of these has one. */
    public function testNeitherHalfOfTheQuestionCanBeLeftOut(): void
    {
        foreach (new \ReflectionMethod(Permissions::class, 'isAllowed')->getParameters() as $parameter) {
            self::assertFalse($parameter->isOptional(), $parameter->getName());
            self::assertFalse($parameter->allowsNull(), $parameter->getName());
        }
    }

    public function testTheTenantIsTakenFromTheProcessRatherThanFromTheCaller(): void
    {
        $taken = [];
        foreach (new \ReflectionClass(Permissions::class)->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            $taken[] = $type instanceof \ReflectionNamedType ? $type->getName() : (string) $type;
        }

        self::assertContains(Tenancy::class, $taken);
    }

    public function testAQuestionAskedBeforeATenantWasEnteredIsRefused(): void
    {
        $this->expectException(TenancyRefused::class);

        $this->permissions()->isAllowed(Resource::Content, Privilege::Edit);
    }

    /**
     * The half Nette does not check. A resource it does not know raises inside
     * the framework; a privilege nobody predefined would be answered "no" for
     * ever by nothing in particular, so it is refused here instead - and
     * before the tenant is asked for, because a question that could never have
     * an answer is a mistake in the code rather than a request made in the
     * wrong place.
     */
    public function testAPairTheStructureDoesNotOfferIsRefusedOutright(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('#force_redirect#');

        $this->permissions()->isAllowed(Resource::Account, Privilege::ForceRedirect);
    }

    private function permissions(): Permissions
    {
        return new Permissions(
            new Tenancy(self::createStub(EntityManagerInterface::class)),
            PermissionStructure::of(Bootstrap::rootDirectory()),
            self::createStub(EntityManagerInterface::class),
            self::createStub(SignedIn::class),
        );
    }
}

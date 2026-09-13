<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Domain\User;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Domain\Tenancy\Tenant;
use Trilobit\Core\Domain\User\Role;

/**
 * Whose a role is: the application's, held in every business, or one
 * business's own, held there and nowhere else.
 *
 * The case worth most is the refusal. The owner's code is the one thing the
 * application decides about a role by its code alone - it holds the whole of
 * the application - so a business composing a role under that code would be
 * composing the owner. And inside one business a code names one role: the
 * access list is keyed by it, so a business's role and the application's under
 * the same code would quietly become one of the two.
 */
#[CoversClass(Role::class)]
final class RoleTest extends TestCase
{
    public function testARoleMadeTheOrdinaryWayIsTheApplications(): void
    {
        self::assertNull(new Role('editor', 'Editor')->business());
    }

    public function testARoleOfABusinessBelongsToIt(): void
    {
        $bikes = $this->business('Ammonite Bikes');

        $role = Role::ofBusiness($bikes, 'editor', 'Editor', ['app.administration.content:edit']);

        self::assertSame($bikes, $role->business());
        self::assertSame('editor', $role->code());
        self::assertSame('Editor', $role->name());
        self::assertSame(['app.administration.content:edit'], $role->permissions());
    }

    /**
     * The spellings the database cannot tell apart from the owner's code - its
     * collation ignores case - and neither can a person reading a list of
     * roles.
     *
     * @return iterable<string, array{string}>
     */
    public static function theOwnersCode(): iterable
    {
        yield 'as the application writes it' => [Role::OWNER];
        yield 'in capitals' => ['OWNER'];
        yield 'capitalised' => ['Owner'];
    }

    #[DataProvider('theOwnersCode')]
    public function testABusinessCannotMakeARoleUnderACodeTheApplicationDefines(string $code): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('#^' . preg_quote($code, '#') . ' is the code of a role the application#');

        Role::ofBusiness($this->business('Ammonite Bikes'), $code, 'Owner');
    }

    /** The refusal is about the code and nothing else, so any other code is taken. */
    public function testABusinessMakesARoleUnderAnyOtherCode(): void
    {
        self::assertSame('owners-helper', Role::ofBusiness($this->business('Ammonite Bikes'), 'owners-helper', 'Helper')->code());
    }

    public function testARoleOfTheApplicationMayBeHeldInEveryBusiness(): void
    {
        $role = new Role(Role::OWNER, 'Owner');

        self::assertTrue($role->mayBeHeldIn($this->business('Ammonite Bikes')));
        self::assertTrue($role->mayBeHeldIn($this->business('Trilobite Books')));
    }

    public function testARoleOfABusinessMayBeHeldThereAndNowhereElse(): void
    {
        $bikes = $this->business('Ammonite Bikes');
        $role = Role::ofBusiness($bikes, 'editor', 'Editor');

        self::assertTrue($role->mayBeHeldIn($bikes));
        self::assertFalse($role->mayBeHeldIn($this->business('Trilobite Books')));
    }

    private function business(string $name): Tenant
    {
        return new Tenant($name, new DateTimeImmutable('2026-09-13T08:00:00+00:00'));
    }
}

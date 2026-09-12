<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Security;

use Nette\Security\Permission;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Security\AccessComposition;
use Trilobit\Core\Security\PermissionStructure;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;

/**
 * What a role's pieces become in the list Nette is asked, over the structure
 * this build ships.
 *
 * Every resource is registered without a parent, so an answer of yes here is a
 * rule written on that very resource - which is what makes these cases about
 * the composition rather than about Nette's inheritance.
 */
#[CoversClass(AccessComposition::class)]
final class AccessCompositionTest extends TestCase
{
    /**
     * The answer Nette's inheritance used to give, given now as rules of their
     * own: opening the administration reaches its sections, and nothing that
     * does not fall under it.
     */
    public function testAPieceOnWhatASectionFallsUnderIsAllowedOnTheSectionAsWell(): void
    {
        $access = $this->composed(['administration:view']);

        self::assertTrue($this->allows($access, Resource::Administration, Privilege::View));
        self::assertTrue($this->allows($access, Resource::Content, Privilege::View));
        self::assertTrue($this->allows($access, Resource::Account, Privilege::View));
        self::assertFalse($this->allows($access, Resource::Redirection, Privilege::View));
        self::assertFalse($this->allows($access, Resource::Content, Privilege::Edit));
    }

    /** A piece on a section says nothing about what it falls under. */
    public function testAPieceOnASectionDoesNotReachUpwards(): void
    {
        $access = $this->composed(['content:view']);

        self::assertTrue($this->allows($access, Resource::Content, Privilege::View));
        self::assertFalse($this->allows($access, Resource::Administration, Privilege::View));
        self::assertFalse($this->allows($access, Resource::Account, Privilege::View));
    }

    /**
     * A pair the structure does not offer was dropped before it could be
     * inherited, and it still is: a section offering the privilege is not a
     * reason for a piece nobody could hold to start reaching it.
     */
    public function testAPieceTheResourceDoesNotOfferReachesNothingUnderIt(): void
    {
        $access = $this->composed(['administration:edit']);

        self::assertFalse($this->allows($access, Resource::Content, Privilege::Edit));
        self::assertFalse($this->allows($access, Resource::Account, Privilege::Edit));
    }

    public function testAPieceThisBuildNoLongerHasIsLeftOutAndTheRestHolds(): void
    {
        $access = $this->composed(['invoicing:view', 'content:edit', 'content:apostille']);

        self::assertTrue($this->allows($access, Resource::Content, Privilege::Edit));
        self::assertFalse($this->allows($access, Resource::Content, Privilege::Delete));
    }

    /** Nette refuses an empty name, so a role with none is left out rather than raised over. */
    public function testARoleWithAnEmptyCodeIsLeftOut(): void
    {
        $access = new AccessComposition(PermissionStructure::of(Bootstrap::rootDirectory()))->compose([
            ['code' => '', 'permissions' => ['content:edit']],
            ['code' => 'editor', 'permissions' => ['content:edit']],
        ]);

        self::assertSame(['editor'], $access->getRoles());
    }

    /** @param list<string> $pieces */
    private function composed(array $pieces): Permission
    {
        return new AccessComposition(PermissionStructure::of(Bootstrap::rootDirectory()))
            ->compose([['code' => 'editor', 'permissions' => $pieces]]);
    }

    private function allows(Permission $access, Resource $resource, Privilege $privilege): bool
    {
        return $access->isAllowed('editor', $resource->value, $privilege->value);
    }
}

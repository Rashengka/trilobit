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
 *
 * The cases are the table the rules were decided by: nothing concrete reaches
 * down, any right reaches up as far as `view` on everything above it, a whole
 * resource is granted only where the structure offers it as a bundle, and a
 * denial wins over all of it, doors included.
 */
#[CoversClass(AccessComposition::class)]
final class AccessCompositionTest extends TestCase
{
    /**
     * Opening the administration is opening the administration and nothing
     * else. It used to reach every section as well, which made a door into a
     * bundle: a role meant to let somebody in could read everything in.
     */
    public function testAPieceOnTheAdministrationSaysNothingAboutItsSections(): void
    {
        $access = $this->composed(['app.administration:view']);

        self::assertTrue($this->allows($access, Resource::Administration, Privilege::View));
        self::assertFalse($this->allows($access, Resource::Content, Privilege::View));
        self::assertFalse($this->allows($access, Resource::Account, Privilege::View));
        self::assertFalse($this->allows($access, Resource::Redirection, Privilege::View));
    }

    /**
     * A piece on a section opens what the section is a section of: a person
     * may work somewhere only if they may get there.
     */
    public function testAPieceOnASectionOpensTheAdministration(): void
    {
        $access = $this->composed(['app.administration.content:view']);

        self::assertTrue($this->allows($access, Resource::Content, Privilege::View));
        self::assertTrue($this->allows($access, Resource::Administration, Privilege::View));
        self::assertFalse($this->allows($access, Resource::Account, Privilege::View));
        self::assertFalse($this->allows($access, Resource::Content, Privilege::Edit));
    }

    /**
     * The door reaches every resource above the one the right is on, not only
     * the nearest: the administration, and the application it is part of.
     */
    public function testAPieceOnASectionOpensEveryDoorAboveIt(): void
    {
        $access = $this->composed(['app.administration.content:view']);

        self::assertTrue($this->allows($access, Resource::Administration, Privilege::View));
        self::assertTrue($this->allows($access, Resource::App, Privilege::View));
    }

    /**
     * Where a visitor is sent is under the application and not under the
     * administration, so a right on it opens the application and does not
     * let anybody into the administration.
     */
    public function testAPieceOnTheRedirectionOpensTheApplicationAndNotTheAdministration(): void
    {
        $access = $this->composed(['app.redirection:view']);

        self::assertTrue($this->allows($access, Resource::Redirection, Privilege::View));
        self::assertTrue($this->allows($access, Resource::App, Privilege::View));
        self::assertFalse($this->allows($access, Resource::Administration, Privilege::View));
    }

    /**
     * What opens is the door and only the door. Editing a section lets
     * somebody into the administration and still does not let them read the
     * section - that would be one privilege implying another, which is a
     * decision the structure has not made.
     */
    public function testEditingASectionOpensTheAdministrationAndDoesNotLetTheSectionBeRead(): void
    {
        $access = $this->composed(['app.administration.content:edit']);

        self::assertTrue($this->allows($access, Resource::Administration, Privilege::View));
        self::assertTrue($this->allows($access, Resource::Content, Privilege::Edit));
        self::assertFalse($this->allows($access, Resource::Content, Privilege::View));
    }

    /** Any privilege at all, one at a time, on either section. */
    public function testEveryPieceOfASectionOpensTheAdministration(): void
    {
        $structure = $this->shipped();

        foreach ([Resource::Content, Resource::Account] as $section) {
            foreach ($structure->privilegesOf($section) as $privilege) {
                $piece = $section->value . ':' . $privilege->value;

                self::assertTrue(
                    $this->allows($this->composed([$piece]), Resource::Administration, Privilege::View),
                    $piece . ' did not open the administration',
                );
            }
        }
    }

    /**
     * A door reaches every resource above the one the right is on and opens
     * nothing beside it: editing the accounts opens the administration and
     * the application, and neither reading the accounts nor the content.
     */
    public function testADoorOpensEverythingAboveAndNothingBeside(): void
    {
        $access = $this->composed(['app.administration.account:edit']);

        self::assertTrue($this->allows($access, Resource::Administration, Privilege::View));
        self::assertTrue($this->allows($access, Resource::App, Privilege::View));
        self::assertFalse($this->allows($access, Resource::Account, Privilege::View));
        self::assertFalse($this->allows($access, Resource::Content, Privilege::View));
    }

    /**
     * The whole administration is every privilege of it and of everything
     * under it - the accounts included, although they offer no bundle of their
     * own: what is asked is whether the resource the star is written on offers
     * one, and the administration does.
     */
    public function testTheWholeAdministrationIsEverythingUnderIt(): void
    {
        $structure = $this->shipped();
        $access = $this->composed(['app.administration:*']);

        foreach ([Resource::Administration, Resource::Content, Resource::Account] as $resource) {
            foreach ($structure->privilegesOf($resource) as $privilege) {
                self::assertTrue(
                    $this->allows($access, $resource, $privilege),
                    $resource->value . ':' . $privilege->value . ' is under the administration and was not given',
                );
            }
        }

        foreach ($structure->privilegesOf(Resource::Redirection) as $privilege) {
            self::assertFalse($this->allows($access, Resource::Redirection, $privilege));
        }
    }

    /**
     * The whole application is every pair this build offers, the redirection
     * included, because everything is under it.
     */
    public function testTheWholeApplicationIsEveryPairThisBuildOffers(): void
    {
        $answers = $this->everyAnswerOf($this->composed(['app:*']));

        self::assertNotContains(false, $answers, 'somebody holding the whole application was refused something');
    }

    /**
     * The whole of a resource that offers no bundle is left out, the way a
     * piece naming something this build does not have is left out: a doubt
     * takes the right away. It opens no door either, because nothing is left
     * of it to open one - and the rest of the role still holds.
     */
    public function testTheWholeOfAResourceOfferingNoBundleIsLeftOut(): void
    {
        $alone = $this->composed(['app.administration.account:*']);

        foreach ($this->shipped()->privilegesOf(Resource::Account) as $privilege) {
            self::assertFalse($this->allows($alone, Resource::Account, $privilege));
        }

        self::assertFalse($this->allows($alone, Resource::Administration, Privilege::View));

        $withTheRest = $this->composed(['app.administration.account:*', 'app.administration.content:edit']);

        self::assertFalse($this->allows($withTheRest, Resource::Account, Privilege::View));
        self::assertTrue($this->allows($withTheRest, Resource::Content, Privilege::Edit));
        self::assertTrue($this->allows($withTheRest, Resource::Administration, Privilege::View));
    }

    /**
     * A pair the structure does not offer is dropped before anything is
     * worked out from it, so it opens no door: being dropped is the whole of
     * what happens to it.
     */
    public function testAPieceTheSectionDoesNotOfferOpensNothing(): void
    {
        $access = $this->composed(['app.administration.content:send']);

        self::assertFalse($this->allows($access, Resource::Administration, Privilege::View));
    }

    /**
     * The administration without one of its sections: the section is taken
     * away whole, and the door stays, because what is left under the
     * administration still opens it.
     */
    public function testAWholeSectionTakenAwayLeavesTheRestOfTheAdministration(): void
    {
        $structure = $this->shipped();
        $access = $this->composed(['app.administration:*'], ['app.administration.content:*']);

        self::assertTrue($this->allows($access, Resource::Administration, Privilege::View));
        foreach ($structure->privilegesOf(Resource::Account) as $privilege) {
            self::assertTrue($this->allows($access, Resource::Account, $privilege));
        }

        foreach ($structure->privilegesOf(Resource::Content) as $privilege) {
            self::assertFalse($this->allows($access, Resource::Content, $privilege));
        }
    }

    /**
     * A denial wins over what is worked out as well as over what was written.
     * Taking the door away from somebody who holds a section keeps them out of
     * the administration; if the door came back from the section, the denial
     * would be one nobody could ever write.
     */
    public function testTakingTheDoorAwayTakesTheOneASectionOpensAsWell(): void
    {
        $access = $this->composed(['app.administration.content:view'], ['app.administration:view']);

        self::assertTrue($this->allows($access, Resource::Content, Privilege::View));
        self::assertFalse($this->allows($access, Resource::Administration, Privilege::View));
    }

    /**
     * A right that has been taken away opens nothing, because doors are
     * worked out from what is left once the denials are applied.
     */
    public function testARightTakenAwayOpensNoDoor(): void
    {
        $access = $this->composed(['app.administration.content:view'], ['app.administration.content:view']);

        self::assertFalse($this->allows($access, Resource::Content, Privilege::View));
        self::assertFalse($this->allows($access, Resource::Administration, Privilege::View));
        self::assertFalse($this->allows($access, Resource::App, Privilege::View));
    }

    /**
     * The whole of a resource may be denied whether it offers a bundle or
     * not. A new privilege is then denied as soon as it exists, which is the
     * direction a denial should fail in.
     */
    public function testTheWholeOfAResourceMayBeTakenAwayWithoutABundle(): void
    {
        $access = $this->composed(
            ['app.administration.account:view', 'app.administration.account:edit', 'app.administration.content:view'],
            ['app.administration.account:*'],
        );

        foreach ($this->shipped()->privilegesOf(Resource::Account) as $privilege) {
            self::assertFalse($this->allows($access, Resource::Account, $privilege));
        }

        self::assertTrue($this->allows($access, Resource::Content, Privilege::View));
        self::assertTrue($this->allows($access, Resource::Administration, Privilege::View));

        $nothingElse = $this->composed(['app.administration.account:view'], ['app.administration.account:*']);

        self::assertFalse($this->allows($nothingElse, Resource::Administration, Privilege::View));
    }

    /**
     * The denial of a whole resource reaches everything under it, as the
     * grant of one does.
     */
    public function testTheWholeAdministrationTakenAwayLeavesNothingUnderIt(): void
    {
        $access = $this->composed(
            ['app.administration.content:edit', 'app.administration.account:view'],
            ['app.administration:*'],
        );

        self::assertFalse($this->allows($access, Resource::Content, Privilege::Edit));
        self::assertFalse($this->allows($access, Resource::Account, Privilege::View));
        self::assertFalse($this->allows($access, Resource::Administration, Privilege::View));
    }

    /**
     * The order the pieces are written in changes nothing - which is the
     * reason denials are subtracted here rather than handed to Nette, where
     * the rule written last wins and "last" would be the order of rows.
     *
     * Asked of every pair the structure offers, so that a difference anywhere
     * is found rather than only where somebody thought to look; and asked
     * first that the answers are not all no, which every ordering would agree
     * on.
     */
    public function testTheOrderThePiecesAreWrittenInChangesNothing(): void
    {
        $granted = [
            'app.administration:*',
            'app.administration.content:view',
            'app.administration.account:*',
            'app.redirection:view',
            'app.administration.content:send',
        ];
        $denied = ['app.administration.content:*', 'app.administration:view', 'app.administration.account:purge'];

        $expected = $this->everyAnswerOf($this->composed($granted, $denied));
        self::assertContains(true, $expected, 'every answer was no, so no ordering could differ');
        self::assertContains(false, $expected, 'every answer was yes, so no ordering could differ');

        $orderings = [
            [array_reverse($granted), $denied],
            [$granted, array_reverse($denied)],
            [array_reverse($granted), array_reverse($denied)],
            [[...array_slice($granted, 2), ...array_slice($granted, 0, 2)], [...array_slice($denied, 1), $denied[0]]],
        ];

        foreach ($orderings as [$reorderedGrants, $reorderedDenials]) {
            self::assertSame($expected, $this->everyAnswerOf($this->composed($reorderedGrants, $reorderedDenials)));
        }
    }

    /**
     * A pair the structure does not offer was dropped before it could reach
     * anything, and it still is: a section offering the privilege is not a
     * reason for a piece nobody could hold to start reaching it.
     */
    public function testAPieceTheResourceDoesNotOfferReachesNothingUnderIt(): void
    {
        $access = $this->composed(['app.administration:edit']);

        self::assertFalse($this->allows($access, Resource::Content, Privilege::Edit));
        self::assertFalse($this->allows($access, Resource::Account, Privilege::Edit));
    }

    public function testAPieceThisBuildNoLongerHasIsLeftOutAndTheRestHolds(): void
    {
        $access = $this->composed(['invoicing:view', 'app.administration.content:edit', 'app.administration.content:apostille']);

        self::assertTrue($this->allows($access, Resource::Content, Privilege::Edit));
        self::assertFalse($this->allows($access, Resource::Content, Privilege::Delete));
    }

    /**
     * A piece written under the name an earlier build gave the resource is
     * one this build does not have, so it reaches nothing - which is why the
     * stored roles are migrated rather than read under both names.
     */
    public function testAPieceUnderANameAnEarlierBuildUsedReachesNothing(): void
    {
        $access = $this->composed(['content:edit', 'administration:*']);

        self::assertNotContains(true, $this->everyAnswerOf($access));
    }

    /** Nette refuses an empty name, so a role with none is left out rather than raised over. */
    public function testARoleWithAnEmptyCodeIsLeftOut(): void
    {
        $access = new AccessComposition($this->shipped())->compose([
            ['code' => '', 'permissions' => ['app.administration.content:edit']],
            ['code' => 'editor', 'permissions' => ['app.administration.content:edit']],
        ]);

        self::assertSame(['editor'], $access->getRoles());
    }

    /**
     * @param list<string> $pieces
     * @param list<string> $denials
     */
    private function composed(array $pieces, array $denials = []): Permission
    {
        return new AccessComposition($this->shipped())
            ->compose([['code' => 'editor', 'permissions' => $pieces, 'denials' => $denials]]);
    }

    private function shipped(): PermissionStructure
    {
        return PermissionStructure::of(Bootstrap::rootDirectory());
    }

    private function allows(Permission $access, Resource $resource, Privilege $privilege): bool
    {
        return $access->isAllowed('editor', $resource->value, $privilege->value);
    }

    /** @return array<string, bool> by the pair, for every pair the shipped structure offers */
    private function everyAnswerOf(Permission $access): array
    {
        $answers = [];
        foreach ($this->shipped()->everyPair() as $pair) {
            $privilege = $pair->privilege;
            self::assertInstanceOf(Privilege::class, $privilege);
            $answers[$pair->code()] = $this->allows($access, $pair->resource, $privilege);
        }

        return $answers;
    }
}

<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Security\PermissionStructure;
use Trilobit\Core\Security\Privilege;
use Trilobit\Tests\Double\Security\ResourcesOf;

/**
 * Every pair the code asks about is a pair the structure offers, and every
 * question is written so that a reader can tell which pair it is.
 *
 * The rule exists because of an asymmetry in the framework this is built on.
 * Nette\Security\Permission checks a role and checks a resource - both raise
 * when they are not known - and does not check a privilege at all: setRule()
 * normalises the privileges into an array and looks at them no further, and
 * neither addPrivilege() nor checkPrivilege() exists in the class. So the two
 * halves of a pair fail in opposite ways. A resource nobody predefined throws
 * at the first question, which locks a person out of the application; a
 * privilege nobody predefined is answered "no" for ever, by nobody, and looks
 * exactly like somebody having decided that.
 *
 * Neither of those is visible in a diff, so this is where they are made
 * visible - at build time, over the source, for code that has not been written
 * yet.
 *
 * **The structure asked is the widest build's** - every declared module
 * switched on - because a module brings resources of its own, and a question
 * a module asks is only predefined in a build that has the module.
 *
 * The other direction is deliberately not enforced. A pair may be offered
 * before anything asks about it: the pieces are the vocabulary a tenant
 * assembles its roles from, and a vocabulary is allowed to have a word in it
 * before the first sentence uses it.
 *
 * A rule that reports nothing reads the same whether the code is right or the
 * rule has stopped finding questions, so it is held from both sides. Over the
 * source it has to find some questions at all; over fixtures it has to pick out
 * the right ones of four - a question that is fine, one about a resource a
 * module brings, a pair nobody offers, and a question that cannot be read.
 */
#[CoversNothing]
final class EveryPermissionQuestionIsPredefinedTest extends TestCase
{
    public function testEveryPairTheApplicationAsksAboutIsOffered(): void
    {
        self::assertSame(
            [],
            $this->notOfferedIn(Bootstrap::rootDirectory() . '/src', WidestBuild::permissionStructure()),
        );
    }

    public function testEveryQuestionTheApplicationAsksCanBeRead(): void
    {
        self::assertSame(
            [],
            $this->unreadableIn(Bootstrap::rootDirectory() . '/src', WidestBuild::permissionStructure()),
        );
    }

    /**
     * The two tests above pass over a source in which the rule finds nothing,
     * so it has to be seen finding something there.
     */
    public function testTheRuleFindsTheQuestionsTheApplicationAsks(): void
    {
        self::assertNotSame([], PermissionQuestions::askedIn(
            Bootstrap::rootDirectory() . '/src',
            WidestBuild::permissionStructure()->resources(),
        ));
    }

    public function testTheRuleReportsAPairNobodyOffers(): void
    {
        self::assertSame(
            ['AskingAboutAPairNobodyOffers.php: app.administration.account, force_redirect'],
            $this->notOfferedIn($this->fixtures(), $this->fixtureStructure()),
        );
    }

    public function testTheRuleReportsAQuestionItCannotRead(): void
    {
        self::assertSame(['AskingInAWayNobodyCanRead.php'], $this->unreadableIn($this->fixtures(), $this->fixtureStructure()));
    }

    /**
     * The rule is only worth its two reports if it reads a question that is
     * there, so the fixtures have to come back as the pairs they ask about and
     * not merely be left out of the other two lists - a module's question
     * among them, read exactly as one about Core's.
     */
    public function testTheRuleReadsAQuestionThatIsWrittenPlainly(): void
    {
        $read = [];
        foreach (PermissionQuestions::askedIn($this->fixtures(), $this->fixtureStructure()->resources()) as $question) {
            if ($question['privilege'] instanceof Privilege) {
                $read[] = $this->fileOf($question['where'])
                    . ': ' . PermissionStructure::nameOf($question['resource']) . ', ' . $question['privilege']->value;
            }
        }

        self::assertSame(
            [
                'AskingAboutAModulesResource.php: app.administration.demo.ledger, edit',
                'AskingAboutAPairNobodyOffers.php: app.administration.account, force_redirect',
                'AskingAboutAPairThatIsOffered.php: app.administration.content, edit',
            ],
            $read,
        );
    }

    /** Nothing may be asked about a resource the build does not have, so the build has to have some. */
    public function testTheApplicationHasResourcesToAskAbout(): void
    {
        self::assertNotSame([], WidestBuild::permissionStructure()->resources());
    }

    /** @return list<string> */
    private function notOfferedIn(string $directory, PermissionStructure $structure): array
    {
        $refused = [];
        foreach (PermissionQuestions::askedIn($directory, $structure->resources()) as $question) {
            $privilege = $question['privilege'];
            if ($privilege instanceof Privilege && !$structure->offers($question['resource'], $privilege)) {
                $refused[] = $this->fileOf($question['where'])
                    . ': ' . PermissionStructure::nameOf($question['resource']) . ', ' . $privilege->value;
            }
        }

        return $refused;
    }

    /** @return list<string> */
    private function unreadableIn(string $directory, PermissionStructure $structure): array
    {
        $unreadable = [];
        foreach (PermissionQuestions::askedIn($directory, $structure->resources()) as $question) {
            if (!$question['privilege'] instanceof Privilege) {
                $unreadable[] = $this->fileOf($question['where']);
            }
        }

        return $unreadable;
    }

    /**
     * A report says the file and the line; what is asserted is the file alone,
     * so that adding a sentence to a fixture does not fail a rule about
     * permissions.
     */
    private function fileOf(string $where): string
    {
        return substr($where, 0, (int) strrpos($where, ':'));
    }

    private function fixtures(): string
    {
        return __DIR__ . '/Fixtures/Permissions';
    }

    /** Core's resources and those of the suites' own module, which one of the fixtures asks about. */
    private function fixtureStructure(): PermissionStructure
    {
        return PermissionStructure::of(Bootstrap::rootDirectory(), [ResourcesOf::demo()]);
    }
}

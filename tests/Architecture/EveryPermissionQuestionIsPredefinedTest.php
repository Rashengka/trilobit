<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Security\PermissionStructure;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;

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
 * The other direction is deliberately not enforced. A pair may be offered
 * before anything asks about it: the pieces are the vocabulary a tenant
 * assembles its roles from, and a vocabulary is allowed to have a word in it
 * before the first sentence uses it.
 *
 * **The application asks nothing yet**, which is why the rule is also run over
 * fixtures. A rule that reports nothing over an empty subject reports nothing
 * over a wrong one just as happily; the three fixtures are one of each kind -
 * a question that is fine, a pair nobody offers, and a question that cannot be
 * read - and the rule has to pick out the right one each time.
 */
#[CoversNothing]
final class EveryPermissionQuestionIsPredefinedTest extends TestCase
{
    public function testEveryPairTheApplicationAsksAboutIsOffered(): void
    {
        self::assertSame([], $this->notOfferedIn(Bootstrap::rootDirectory() . '/src'));
    }

    public function testEveryQuestionTheApplicationAsksCanBeRead(): void
    {
        self::assertSame([], $this->unreadableIn(Bootstrap::rootDirectory() . '/src'));
    }

    public function testTheRuleReportsAPairNobodyOffers(): void
    {
        self::assertSame(
            ['AskingAboutAPairNobodyOffers.php: account, force_redirect'],
            $this->notOfferedIn($this->fixtures()),
        );
    }

    public function testTheRuleReportsAQuestionItCannotRead(): void
    {
        self::assertSame(['AskingInAWayNobodyCanRead.php'], $this->unreadableIn($this->fixtures()));
    }

    /**
     * The rule is only worth its two reports if it reads a question that is
     * there, so the third fixture has to come back as the pair it asks about
     * and not merely be left out of the other two lists.
     */
    public function testTheRuleReadsAQuestionThatIsWrittenPlainly(): void
    {
        $read = [];
        foreach (PermissionQuestions::askedIn($this->fixtures()) as $question) {
            if ($question['privilege'] instanceof Privilege) {
                $read[] = $this->fileOf($question['where'])
                    . ': ' . $question['resource']->value . ', ' . $question['privilege']->value;
            }
        }

        self::assertSame(
            [
                'AskingAboutAPairNobodyOffers.php: account, force_redirect',
                'AskingAboutAPairThatIsOffered.php: content, edit',
            ],
            $read,
        );
    }

    /** Nothing may be asked about a resource the enum does not have, so the enum has to have some. */
    public function testTheApplicationHasResourcesToAskAbout(): void
    {
        self::assertNotSame([], Resource::cases());
    }

    /** @return list<string> */
    private function notOfferedIn(string $directory): array
    {
        $structure = PermissionStructure::of(Bootstrap::rootDirectory());

        $refused = [];
        foreach (PermissionQuestions::askedIn($directory) as $question) {
            $privilege = $question['privilege'];
            if ($privilege instanceof Privilege && !$structure->offers($question['resource'], $privilege)) {
                $refused[] = $this->fileOf($question['where'])
                    . ': ' . $question['resource']->value . ', ' . $privilege->value;
            }
        }

        return $refused;
    }

    /** @return list<string> */
    private function unreadableIn(string $directory): array
    {
        $unreadable = [];
        foreach (PermissionQuestions::askedIn($directory) as $question) {
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
}

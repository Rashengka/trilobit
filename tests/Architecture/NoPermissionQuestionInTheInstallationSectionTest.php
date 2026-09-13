<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;

/**
 * The two administrations meet nowhere, and this is where that is held down.
 *
 * **Why it has to be a rule and not a habit.** A permission question asked
 * inside the installation's own section would not raise - it would quietly
 * answer no. Measured, in nette/security 3.2: Nette\Security\User::isAllowed()
 * walks getRoles() and asks the authorizator about each one, and the identity
 * of somebody who administers the installation carries none, because a role is
 * held in a business and they are in none. The loop therefore does not run,
 * and false is returned without our authorizator - which does raise on a
 * question it cannot answer - ever being reached. So the loud failure this
 * project would prefer at run time is not available at run time: the framework
 * has already answered by the time anything of ours could object.
 *
 * That is the whole argument for the mechanism being here instead. What can be
 * made loud is the writing of such a question, at build time, over the source,
 * for code that has not been written yet - the same place and the same shape as
 * Trilobit\Tests\Architecture\EveryPermissionQuestionIsPredefinedTest, and
 * reading the enums rather than a method name for the same reason: a rule
 * anchored on `isAllowed(` is got round by renaming a method, and the enums
 * cannot be got round because they are the only way the string a question is
 * made of comes into existence.
 *
 * **What it catches:** a question written in this section's PHP with the two
 * enums, however it is spelled and whoever it is asked of; a mention of a
 * resource this could not read as a question at all (reported rather than
 * skipped); a page of the section standing behind a pair instead of behind the
 * person; and the reverse - the section's own declaration appearing on a page
 * outside the section.
 *
 * **What it cannot catch**, and this is not a to-do list but the shape of the
 * mechanism:
 *
 * - a question asked by a *service* the section calls. The rule reads this
 *   directory, and a class elsewhere that a page here happens to reach is
 *   somewhere else. The section calls no such service today, and the section
 *   being small is what makes that checkable by reading it.
 * - a question in a template that is not written out in words. The template
 *   half of the rule is lexical - a template is not PHP - so it finds
 *   `isAllowed`, `Resource::` and their kind, and not a question assembled from
 *   a variable or handed in by the presenter.
 * - a question about somebody else's rights that never mentions this section:
 *   nothing here says anything about code outside the section that happens to
 *   ask about a business while this section is being drawn.
 *
 * The first of those is the one to watch, and its exit condition is the day a
 * page in this section needs a service of its own: the rule then has to grow a
 * second directory or the service has to be one that cannot ask - and which of
 * the two is a decision, not a detail.
 */
#[CoversNothing]
final class NoPermissionQuestionInTheInstallationSectionTest extends TestCase
{
    private const string FIXTURE_NAMESPACE = 'Trilobit\\Tests\\Architecture\\Fixtures\\Installation\\';

    public function testTheSectionAsksNoPermissionQuestion(): void
    {
        self::assertSame([], InstallationSection::permissionQuestionsIn($this->section()));
    }

    public function testNoTemplateOfTheSectionAsksOneEither(): void
    {
        self::assertSame([], InstallationSection::templatesAskingIn($this->section()));
    }

    public function testEveryGateInTheSectionIsTheOneAboutThePerson(): void
    {
        self::assertSame(
            [],
            InstallationSection::gatesOfAnotherKindIn($this->section(), 'Trilobit\\Core\\Presentation\\Installation\\'),
        );
    }

    /**
     * The other direction: the declaration that admits the installation's
     * administrator appears in the section and nowhere else. Without this
     * half, the rule above would be satisfied by moving the page rather than
     * by fixing it.
     */
    public function testNoPageOutsideTheSectionIsGatedOnAdministeringTheInstallation(): void
    {
        self::assertSame(
            [],
            InstallationSection::pagesOutside(
                $this->section(),
                Bootstrap::rootDirectory() . '/src',
                'Trilobit\\',
            ),
        );
    }

    /**
     * The application contains none of these mistakes, so all four claims above
     * would hold just as well if the rule looked in the wrong place. Here it is
     * run over a directory that contains one of each, and it has to report them
     * and nothing else.
     */
    public function testTheRuleReportsAQuestionWrittenInASection(): void
    {
        self::assertSame(
            [
                'AskingWhatSomebodyMayDoPresenter.php:28: app.administration.content, view',
                // The declaration above the other fixture, and it is right
                // that this reports it: `#[Needs(Resource::…, Privilege::…)]`
                // *is* a permission question, written above a page instead of
                // inside one. The rule reads the enums and does not care what
                // surrounds them, which is what makes it worth having.
                'GatedTheOtherWayPresenter.php:20: app.administration.content, view',
            ],
            InstallationSection::permissionQuestionsIn($this->fixtures()),
        );
    }

    public function testTheRuleReportsATemplateOfASectionAskingOne(): void
    {
        self::assertSame(
            ['templates/AskingWhatSomebodyMayDo/default.latte: isAllowed'],
            InstallationSection::templatesAskingIn($this->fixtures()),
        );
    }

    public function testTheRuleReportsAPageOfASectionGatedOnAPairInstead(): void
    {
        self::assertSame(
            ['GatedTheOtherWayPresenter.php: default: Trilobit\\Core\\Security\\Needs'],
            InstallationSection::gatesOfAnotherKindIn($this->fixtures(), self::FIXTURE_NAMESPACE),
        );
    }

    /**
     * And the same for the reverse rule: run over a root whose section is
     * somewhere else, the fixture gated on administering the installation is
     * reported as being outside it.
     */
    public function testTheRuleReportsThatDeclarationOutsideTheSection(): void
    {
        self::assertSame(
            ['AskingWhatSomebodyMayDoPresenter.php'],
            InstallationSection::pagesOutside(
                $this->fixtures() . '/nowhere',
                $this->fixtures(),
                self::FIXTURE_NAMESPACE,
            ),
        );
    }

    /** A rule run over nothing reports nothing, so there has to be a section to run it over. */
    public function testTheApplicationHasASectionToRunTheRuleOver(): void
    {
        self::assertNotSame(
            [],
            AdministrationViews::in($this->section(), 'Trilobit\\Core\\Presentation\\Installation\\'),
        );
    }

    private function section(): string
    {
        return Bootstrap::rootDirectory() . '/' . InstallationSection::DIRECTORY;
    }

    private function fixtures(): string
    {
        return __DIR__ . '/Fixtures/Installation';
    }
}

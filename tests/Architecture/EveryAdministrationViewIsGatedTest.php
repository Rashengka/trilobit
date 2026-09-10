<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Security\OpenToEverybody;

/**
 * No page of the administration opens without something written above it
 * saying who may.
 *
 * The default is refusal and that is the whole mechanism. A page nobody
 * thought about carries no declaration, so the presenter refuses to draw it;
 * this asks the same question of every view at build time rather than leaving
 * it to be met by whoever opens the page first. Being open is possible and has
 * to be written down with the reason - which is the difference between a
 * decision and an omission, and is the same shape
 * Trilobit\Core\Tenancy\Shared has for entities.
 *
 * **What is asked about is a view and not a class.** A presenter draws a list,
 * a form and whatever else; a rule about classes would pass a presenter whose
 * class says something and whose second action says nothing, which is the
 * mistake that actually happens. See Trilobit\Tests\Architecture\AdministrationViews
 * for how the two halves of a view - the method and the template - are put
 * together.
 *
 * **The class-level declaration is separately required, and the reason is a
 * measurement rather than a preference.** Nette\Application\UI\Component::tryCall()
 * calls checkRequirements() for action*(), render*() and handle*() - and a
 * form submitted to a page arrives through processSignal(), which calls none
 * of them. What ran before it is the check made for the class. So a presenter
 * whose only declarations sit on its methods has its forms guarded by nothing,
 * and a rule that only looked at views would call that presenter gated.
 */
#[CoversNothing]
final class EveryAdministrationViewIsGatedTest extends TestCase
{
    private const string FIXTURE_NAMESPACE = 'Trilobit\\Tests\\Architecture\\Fixtures\\Gates\\';

    public function testEveryViewOfTheAdministrationIsBehindAGate(): void
    {
        self::assertSame([], $this->ungatedIn(Bootstrap::rootDirectory() . '/src', 'Trilobit\\'));
    }

    public function testEveryAdministrationPresenterDeclaresAGateOnItsClass(): void
    {
        self::assertSame([], $this->undeclaredIn(Bootstrap::rootDirectory() . '/src', 'Trilobit\\'));
    }

    /**
     * The application contains no such view, so both assertions above would
     * hold just as well if this rule looked in the wrong place. Here the same
     * rule is run over three fixtures - one gated on its class, one gated on
     * one action only, one carrying nothing at all - and it has to report the
     * views nobody declared anything for and no others.
     */
    public function testTheRuleReportsAViewNobodyDeclaredAnythingFor(): void
    {
        self::assertSame(
            [
                'GatedByNothingPresenter.php: default',
                'GatedOnOneActionOnlyPresenter.php: default',
            ],
            $this->ungatedIn($this->fixtures(), self::FIXTURE_NAMESPACE),
        );
    }

    /**
     * The other half, and the one a view-by-view rule cannot see: a presenter
     * whose actions are each declared for and whose class is not still has
     * every form on it submitted through a gate that is not there.
     */
    public function testTheRuleReportsAPresenterThatDeclaresNothingOnItsClass(): void
    {
        self::assertSame(
            [
                'GatedByNothingPresenter.php',
                'GatedOnOneActionOnlyPresenter.php',
            ],
            $this->undeclaredIn($this->fixtures(), self::FIXTURE_NAMESPACE),
        );
    }

    /**
     * The rule is only worth its reports if it reads a declaration that is
     * there, so the gated fixture has to come back gated rather than merely be
     * left out of the two lists above.
     */
    public function testTheRuleReadsADeclarationThatIsWrittenPlainly(): void
    {
        $gated = [];
        foreach (AdministrationViews::in($this->fixtures(), self::FIXTURE_NAMESPACE) as $view) {
            if ($view['gated']) {
                $gated[] = $view['where'] . ': ' . $view['view'];
            }
        }

        self::assertSame(
            [
                'GatedOnItsClassPresenter.php: default',
                'GatedOnOneActionOnlyPresenter.php: edit',
            ],
            $gated,
        );
    }

    /**
     * The views this build has, by name.
     *
     * Written out so that a page losing its template or its action is a
     * failing test rather than a passing one with less in it - the two rules
     * above are both satisfied by an administration with nothing in it.
     */
    public function testTheAdministrationDrawsTheViewsNamedHere(): void
    {
        $drawn = [];
        foreach (AdministrationViews::in(Bootstrap::rootDirectory() . '/src', 'Trilobit\\') as $view) {
            $drawn[] = $view['where'] . ': ' . $view['view'];
        }

        sort($drawn);

        self::assertSame(
            [
                'Cms/Presentation/Admin/MenuPresenter.php: add',
                'Cms/Presentation/Admin/MenuPresenter.php: default',
                'Cms/Presentation/Admin/MenuPresenter.php: edit',
                'Cms/Presentation/Admin/PagePresenter.php: add',
                'Cms/Presentation/Admin/PagePresenter.php: default',
                'Cms/Presentation/Admin/PagePresenter.php: edit',
                'Cms/Presentation/Admin/SignpostPresenter.php: default',
                'Core/Presentation/Admin/DashboardPresenter.php: default',
                // Coming in and nothing else. Ending a session is not a page of
                // the administration at all - one identity, one session, one
                // act, whoever is doing it - so it lives at
                // Trilobit\Core\Presentation\Session\SignOutPresenter and is
                // outside everything this rule is about.
                'Core/Presentation/Admin/SignPresenter.php: in',
                // The other administration: the section of the installation
                // itself, which is a directory of its own rather than two more
                // files beside the pages of a business's administration - see
                // Trilobit\Tests\Architecture\InstallationSection, whose rules
                // are about that place.
                'Core/Presentation/Installation/BusinessesPresenter.php: default',
                'Core/Presentation/Installation/SignpostPresenter.php: default',
            ],
            $drawn,
        );
    }

    /**
     * Saying a page is open to everybody is a decision, so it carries the
     * reason for it. An empty reason is the attribute used as a way past the
     * rule rather than as an answer to it.
     */
    public function testEveryPageOpenToEverybodySaysWhyItIs(): void
    {
        $silent = [];
        foreach (AdministrationViews::presentersUnder(Bootstrap::rootDirectory() . '/src', 'Trilobit\\') as $presenter) {
            foreach ([$presenter, ...$presenter->getMethods(\ReflectionMethod::IS_PUBLIC)] as $element) {
                foreach ($element->getAttributes(OpenToEverybody::class) as $attribute) {
                    if (trim($attribute->newInstance()->because) === '') {
                        $silent[] = $presenter->getName();
                    }
                }
            }
        }

        self::assertSame([], $silent);
    }

    /** A rule run over nothing reports nothing, so there has to be something to run it over. */
    public function testTheApplicationHasAnAdministrationToRunTheRuleOver(): void
    {
        self::assertNotSame([], AdministrationViews::in(Bootstrap::rootDirectory() . '/src', 'Trilobit\\'));
    }

    /** @return list<string> */
    private function ungatedIn(string $directory, string $namespace): array
    {
        $ungated = [];
        foreach (AdministrationViews::in($directory, $namespace) as $view) {
            if (!$view['gated']) {
                $ungated[] = $view['where'] . ': ' . $view['view'];
            }
        }

        return $ungated;
    }

    /** @return list<string> */
    private function undeclaredIn(string $directory, string $namespace): array
    {
        $undeclared = [];
        foreach (AdministrationViews::presentersUnder($directory, $namespace) as $file => $presenter) {
            if (AdministrationViews::declaredOn($presenter) === []) {
                $undeclared[] = substr($file, strlen($directory) + 1);
            }
        }

        return $undeclared;
    }

    private function fixtures(): string
    {
        return __DIR__ . '/Fixtures/Gates';
    }
}

<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Presentation\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Admin\AdminPresenter;
use Trilobit\Tests\Architecture\Fixtures\Gates\GatedByNothingPresenter;
use Trilobit\Tests\Architecture\Fixtures\Gates\GatedOnItsClassPresenter;
use Trilobit\Tests\Architecture\Fixtures\Gates\GatedOnOneActionOnlyPresenter;

/**
 * What the presenter does about a page nobody declared anything about.
 *
 * Trilobit\Tests\Architecture\EveryAdministrationViewIsGatedTest keeps that
 * page out of the build, so this is the branch nothing else can reach - and it
 * is the one that matters, because the failure it stands against is the quiet
 * one. A page that opened because nobody had written a gate for it would look
 * exactly like a page somebody had decided was open.
 *
 * The presenters are the same fixtures that rule is run over, rather than
 * copies of them: two shapes claiming to be the same mistake is one place for
 * them to stop being it.
 *
 * No container is built and none is needed. Both branches asserted here are
 * settled before anything is asked of the request - the first raises on
 * finding nothing declared, and the second finds a declaration that says it
 * needs neither an identity nor an access list to answer.
 */
#[CoversClass(AdminPresenter::class)]
final class GateEnforcementTest extends TestCase
{
    public function testAPresenterThatDeclaresNothingRefusesToDrawAnythingAtAll(): void
    {
        $presenter = new GatedByNothingPresenter();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('~says nothing about who may open~');

        $presenter->checkRequirements($presenter::getReflection());
    }

    /**
     * The declarations sitting on the actions of a presenter are not a
     * substitute for one above the presenter, and the reason is a measurement:
     * a submitted form arrives through processSignal(), which asks nothing of
     * any method. So this raises exactly as the one above does, even though
     * every action on it says what it needs.
     */
    public function testDeclaringOnEveryActionIsNotDeclaringOnThePresenter(): void
    {
        $presenter = new GatedOnOneActionOnlyPresenter();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('~says nothing about who may open~');

        $presenter->checkRequirements($presenter::getReflection());
    }

    /**
     * An action carrying nothing of its own is covered by what the presenter
     * says, so it must not raise - otherwise the ordinary case, a presenter
     * declared once at the top, would be the case that fails.
     */
    public function testAnActionThatDeclaresNothingLeansOnThePresenterItIsOn(): void
    {
        $this->expectNotToPerformAssertions();

        $presenter = new GatedOnOneActionOnlyPresenter();

        $presenter->checkRequirements($presenter::getReflection()->getMethod('renderDefault'));
    }

    /**
     * A presenter that declares something goes past that branch and gets as
     * far as asking who is signed in.
     *
     * Which, with no container behind it, is where it stops - and that is the
     * assertion. What it must not do is raise the sentence above: a rule that
     * caught a declared presenter as an undeclared one would report every page
     * in the build and be turned off within the hour.
     */
    public function testAPresenterThatDeclaresSomethingAsksWhoIsSignedInInstead(): void
    {
        $presenter = new GatedOnItsClassPresenter();

        $raised = null;

        try {
            $presenter->checkRequirements($presenter::getReflection());
        } catch (\Throwable $exception) {
            $raised = $exception->getMessage();
        }

        self::assertNotNull($raised, 'nothing was asked of the request at all');
        self::assertStringNotContainsString('says nothing about who may open', $raised);
    }
}

<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture;

use Nette\Application\Attributes\Requires;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Presentation\Admin\AdminPresenter;

/**
 * Every component and every signal of an administration page names the
 * actions it belongs to.
 *
 * **Why the action and not the handler.** A submitted form is a signal of the
 * form, and a signal is answered on whatever action the request names. The
 * gates in front of it are the one above the class and the one above that
 * action - never anything above the form or its handler, because
 * Nette\Application\UI\Presenter::processSignal() asks nothing of either. So
 * a form that can be made on the list is answered behind the list's gate,
 * whatever the page it is drawn on asks for; a page of the content
 * administration was written that way by somebody who could only read it.
 * Naming the actions - `#[Requires(actions: ...)]`, Nette's own declaration,
 * which the framework enforces when the component is made or the signal is
 * handled - is what makes the gate of the action the gate of the form, and it
 * is asked of the factory because the factory is the one place every
 * component of a presenter comes from, however its handlers are called.
 *
 * Trilobit\Core\Presentation\Admin\AdminPresenter raises on an undeclared
 * factory or signal while the page runs; this asks the same question of the
 * whole build, so that a form nobody has opened yet is caught as well.
 *
 * **A declaration naming an action the presenter does not draw is reported
 * too.** It is not a hole - the component would be refused everywhere - but
 * it is a form nobody can submit, and the likelier story behind it is a typo
 * in the one line this rule asks for.
 */
#[CoversNothing]
final class EveryAdministrationComponentSaysWhereItBelongsTest extends TestCase
{
    private const string FIXTURE_NAMESPACE = 'Trilobit\\Tests\\Architecture\\Fixtures\\Signals\\';

    public function testEveryComponentAndSignalOfTheAdministrationNamesTheActionsItBelongsTo(): void
    {
        self::assertSame([], $this->unplacedIn(Bootstrap::rootDirectory() . '/src', 'Trilobit\\'));
    }

    /**
     * The same rule over shapes the application does not contain, each broken
     * in a different way - no declaration, a declaration that says something
     * other than where, a signal rather than a component, and actions nothing
     * draws - and one written the way the rule wants, which must not appear.
     */
    public function testTheRuleReportsWhatNamesNoActionAndWhatNamesAnActionNothingDraws(): void
    {
        self::assertSame(
            [
                'PlacedWhereNothingIsDrawnPresenter.php: createComponentForm() names nowhere, which this presenter does not draw',
                'RequiresOnlyAMethodPresenter.php: createComponentForm() names no action',
                'UnplacedFormPresenter.php: createComponentForm() names no action',
                'UnplacedSignalPresenter.php: handleForget() names no action',
            ],
            $this->unplacedIn($this->fixtures(), self::FIXTURE_NAMESPACE),
        );
    }

    /** A rule that reads nothing reports nothing, so the fixture written correctly has to be read. */
    public function testTheRuleReadsTheComponentsAndSignalsOfAPresenterWrittenCorrectly(): void
    {
        $read = array_values(array_filter(
            $this->receiversIn($this->fixtures(), self::FIXTURE_NAMESPACE),
            static fn(string $receiver): bool => str_starts_with($receiver, 'PlacedPresenter.php'),
        ));

        self::assertSame(
            [
                'PlacedPresenter.php: createComponentForm()',
                'PlacedPresenter.php: handleRefresh()',
            ],
            $read,
        );
    }

    /**
     * The components and signals this build's administration has, by name.
     *
     * Written out so that the first assertion above cannot be satisfied by a
     * rule that stopped finding them. It is also the list of forms the rule
     * covers today, in Core and in every module.
     */
    public function testTheAdministrationHasTheComponentsAndSignalsNamedHere(): void
    {
        self::assertSame(
            [
                'Cms/Presentation/Admin/CategoryPresenter.php: createComponentCategory()',
                'Cms/Presentation/Admin/CategoryPresenter.php: handleSuggestSegment()',
                'Cms/Presentation/Admin/MenuPresenter.php: createComponentEntry()',
                'Cms/Presentation/Admin/PagePresenter.php: createComponentPage()',
                'Cms/Presentation/Admin/PagePresenter.php: createComponentPages()',
                'Cms/Presentation/Admin/PagePresenter.php: handleSuggestSegment()',
                'Core/Presentation/Admin/NavigationPresenter.php: createComponentArrangement()',
                'Core/Presentation/Admin/SignPresenter.php: createComponentSignIn()',
            ],
            $this->receiversIn(Bootstrap::rootDirectory() . '/src', 'Trilobit\\'),
        );
    }

    /** @return list<string> */
    private function unplacedIn(string $directory, string $namespace): array
    {
        $unplaced = [];
        foreach (AdministrationViews::presentersUnder($directory, $namespace) as $file => $presenter) {
            $where = substr($file, strlen($directory) + 1);
            $views = AdministrationViews::viewsOf($file, $presenter);
            foreach ($this->receiversOf($presenter) as $method) {
                $actions = $this->actionsNamedOn($method);
                if ($actions === []) {
                    $unplaced[] = sprintf('%s: %s() names no action', $where, $method->getName());

                    continue;
                }

                foreach (array_diff($actions, $views) as $nowhere) {
                    $unplaced[] = sprintf(
                        '%s: %s() names %s, which this presenter does not draw',
                        $where,
                        $method->getName(),
                        $nowhere,
                    );
                }
            }
        }

        sort($unplaced);

        return $unplaced;
    }

    /** @return list<string> */
    private function receiversIn(string $directory, string $namespace): array
    {
        $receivers = [];
        foreach (AdministrationViews::presentersUnder($directory, $namespace) as $file => $presenter) {
            foreach ($this->receiversOf($presenter) as $method) {
                $receivers[] = substr($file, strlen($directory) + 1) . ': ' . $method->getName() . '()';
            }
        }

        sort($receivers);

        return $receivers;
    }

    /**
     * Every method of $presenter something submitted to it can reach: the
     * factories its components come from, of any visibility, and the public
     * handle*() methods Nette calls for a signal of the presenter itself.
     *
     * Only what the application wrote is read. The framework's own
     * createComponent() is where factories are called from, not one of them.
     *
     * @param \ReflectionClass<AdminPresenter> $presenter
     *
     * @return list<\ReflectionMethod>
     */
    private function receiversOf(\ReflectionClass $presenter): array
    {
        $receivers = [];
        foreach ($presenter->getMethods() as $method) {
            $declaredBy = $method->getDeclaringClass();
            if ($declaredBy->getName() !== AdminPresenter::class && !$declaredBy->isSubclassOf(AdminPresenter::class)) {
                continue;
            }

            $name = $method->getName();
            $isFactory = strlen($name) > strlen('createComponent') && str_starts_with($name, 'createComponent');
            $isSignal = $method->isPublic() && strlen($name) > strlen('handle') && str_starts_with($name, 'handle');
            if ($isFactory || $isSignal) {
                $receivers[] = $method;
            }
        }

        return $receivers;
    }

    /** @return list<string> */
    private function actionsNamedOn(\ReflectionMethod $method): array
    {
        $actions = [];
        foreach ($method->getAttributes(Requires::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $actions = [...$actions, ...($attribute->newInstance()->actions ?? [])];
        }

        return $actions;
    }

    private function fixtures(): string
    {
        return __DIR__ . '/Fixtures/Signals';
    }
}

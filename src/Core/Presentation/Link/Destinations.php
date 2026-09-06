<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Link;

use Nette\Application\InvalidPresenterException;
use Nette\Application\IPresenterFactory;

/**
 * Whether this build has the page a stored destination names.
 *
 * It exists because some destinations outlive the module they point into.
 * Anything a module contributes to the application at compile time - a menu
 * entry, a signpost - disappears together with the module, so a link to it can
 * never be stale. A destination somebody *saved* is different: it is a row,
 * the module named in it can be switched off, and the row waits for it to come
 * back. The register of public addresses already answers that question for
 * content, by not routing an address whose type nothing draws
 * (Trilobit\Core\Content\ContentTypes::drawnBy()); this answers the same
 * question for a destination named as a presenter and an action.
 *
 * The failure it prevents is the quiet kind. Asking the framework for a link
 * into a module that is not here does not stop the page: Nette\Application\UI\
 * Presenter::link() hands an invalid link to handleInvalidLink(), which draws
 * a broken href or a "#error" and carries on. The page then looks finished and
 * the menu on it does not work - which is worse than an entry that is simply
 * not there, because nobody goes looking for it.
 *
 * What it answers about is the presenter, not the action. A presenter that is
 * here and an action on it that is not is a mistake inside one module, and one
 * module's own mistake is not what switching another one off produces.
 * **Exit condition:** the first time a stored destination names an action that
 * a module later removes while keeping the presenter.
 */
final readonly class Destinations
{
    public function __construct(
        private IPresenterFactory $presenters,
    ) {}

    /**
     * @param string $destination a presenter and an action, as a link names
     *     them: `Blog:Front:Article:default`, with or without the leading
     *     colon that makes it absolute.
     */
    public function drawnByThisBuild(string $destination): bool
    {
        $presenter = $this->presenterOf($destination);
        if ($presenter === '') {
            return false;
        }

        try {
            $this->presenters->getPresenterClass($presenter);
        } catch (InvalidPresenterException) {
            return false;
        }

        return true;
    }

    /** A destination names an action; the presenter is everything before it. */
    private function presenterOf(string $destination): string
    {
        $named = ltrim($destination, ':');
        $separator = strrpos($named, ':');

        return $separator === false ? '' : substr($named, 0, $separator);
    }
}

<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Front;

use Nette\Application\UI\Presenter;

/**
 * The base every public-facing page is built on, whichever module it belongs
 * to.
 *
 * All it adds is the shared layout. The framework looks for a layout beside
 * the presenter and then upwards through the directories above it, which finds
 * Core's own pages and nothing else - a module lives in a different tree
 * entirely. Extending this puts Core's layout at the end of that search, so a
 * module gets the site's chrome by inheriting a class rather than by copying a
 * template or by writing a path into every one of its own.
 *
 * The layout stays last in the list, so a module that does want its own layout
 * simply has one and it wins.
 */
abstract class FrontPresenter extends Presenter
{
    /** @return non-empty-list<string> */
    public function formatLayoutTemplateFiles(): array
    {
        $files = parent::formatLayoutTemplateFiles();
        $files[] = __DIR__ . '/templates/@layout.latte';

        return $files;
    }
}

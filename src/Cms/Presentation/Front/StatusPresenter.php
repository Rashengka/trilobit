<?php

declare(strict_types=1);

namespace Trilobit\Cms\Presentation\Front;

use Nette\Application\UI\Template;
use Trilobit\Core\Presentation\Front\FrontPresenter;

/**
 * The page that says the Cms module is part of this build.
 *
 * It is scaffolding and it is meant to be replaced by the module's real pages,
 * but not deleted: something here has to be reachable for the combination
 * suite to be able to ask whether this module answers, and a page that renders
 * proves rather more than a service that exists - the presenter mapping, the
 * template lookup and the shared layout all have to work for it.
 */
final class StatusPresenter extends FrontPresenter
{
    public function renderDefault(): void
    {
        $template = $this->getTemplate();
        if (!$template instanceof StatusDefaultTemplate) {
            throw new \LogicException(sprintf(
                'The template of %s has to be a %s.',
                self::class,
                StatusDefaultTemplate::class,
            ));
        }

        $template->pageTitle = 'Cms';
        $template->moduleName = 'Cms';
        $template->summary = 'Pages, menus and blocks live here.';
    }

    /**
     * The framework's getTemplate() is final, so the template class is chosen
     * here and checked where it is used. Naming the class is what lets the
     * template declare {templateType} and be analysed rather than guessed at.
     */
    protected function createTemplate(?string $class = null): Template
    {
        return parent::createTemplate($class ?? StatusDefaultTemplate::class);
    }
}

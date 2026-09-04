<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Front;

use Nette\Application\UI\Template;
use Trilobit\Core\Presentation\Front\Signpost\SignpostList;

/**
 * The one page the application has while it consists of Core alone.
 *
 * It exists so that every later suite has something to ask for: a build with
 * any set of modules has to answer here with 200 and with the layout around
 * it, whether or not any module is switched on.
 */
final class HomePresenter extends FrontPresenter
{
    private const array HIGHLIGHTS = [
        'Modules that can be switched on and off one at a time.',
        'A schema that is generated from the model, never written by hand.',
        'A gate that has to be green before anything is called done.',
    ];

    public function __construct(
        private readonly SignpostList $signposts,
    ) {
        parent::__construct();
    }

    public function renderDefault(): void
    {
        $template = $this->getTemplate();
        if (!$template instanceof HomeDefaultTemplate) {
            throw new \LogicException(sprintf(
                'The template of %s has to be a %s.',
                self::class,
                HomeDefaultTemplate::class,
            ));
        }

        $template->pageTitle = 'Home';
        $template->headline = 'Trilobit';
        $template->tagline = 'A modular e-shop, CRM and CMS built on Nette and Latte.';
        $template->highlights = self::HIGHLIGHTS;
        $template->signposts = $this->signposts->items();
    }

    /**
     * The framework's getTemplate() is final, so the template class is chosen
     * here and checked where it is used. Naming the class is what lets the
     * template declare {templateType} and be analysed rather than guessed at.
     */
    protected function createTemplate(?string $class = null): Template
    {
        return parent::createTemplate($class ?? HomeDefaultTemplate::class);
    }
}

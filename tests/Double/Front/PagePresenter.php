<?php

declare(strict_types=1);

namespace Trilobit\Tests\Double\Front;

use Nette\Application\UI\Template;
use Trilobit\Core\Content\Address;
use Trilobit\Core\Contract\Content\ContentLinkResolver;
use Trilobit\Core\Presentation\Front\ContentPresenter;
use Trilobit\Tests\Double\DemoModule;

/**
 * The pages of the modules that do not exist yet.
 *
 * Two actions rather than two presenters, so that the way a kind of content is
 * bound to an action - and not only to a presenter - is exercised as well.
 * Neither action knows the address it is answering at: it asks the register,
 * the way a real module's page will.
 *
 * The port is taken as an ordinary constructor dependency and never checked
 * for being there. That is the whole point of the null implementation Core
 * registers in its place: a page branches on whether a link came back, never
 * on whether anybody could have produced one.
 */
final class PagePresenter extends ContentPresenter
{
    public function __construct(private readonly ContentLinkResolver $links)
    {
        parent::__construct();
    }

    public function renderSection(): void
    {
        $template = $this->fillIn();
        $template->related = $this->links->resolve(DemoModule::relatedContent());
    }

    public function renderProduct(): void
    {
        $this->fillIn();
    }

    protected function createTemplate(?string $class = null): Template
    {
        return parent::createTemplate($class ?? DemoDefaultTemplate::class);
    }

    private function fillIn(): DemoDefaultTemplate
    {
        $template = $this->getTemplate();
        if (!$template instanceof DemoDefaultTemplate) {
            throw new \LogicException(sprintf('The template of %s has to be a %s.', self::class, DemoDefaultTemplate::class));
        }

        $address = $this->contentAddress();
        if (!$address instanceof Address) {
            $this->error('This page was not reached through the register of public addresses.');
        }

        $template->pageTitle = $address->label;
        $template->heading = $address->label;
        $template->contentId = $this->contentId();
        $template->contentPath = $address->path;

        return $template;
    }
}

<?php

declare(strict_types=1);

namespace Trilobit\Cms\Presentation\Front;

use Nette\Application\UI\Template;
use Trilobit\Cms\Application\Page\Pages;
use Trilobit\Cms\Domain\Page\Page;
use Trilobit\Core\Content\Address;
use Trilobit\Core\Presentation\Front\ContentPresenter;

/**
 * A page, drawn at whichever address the register says leads to it.
 *
 * There is no route for it. The address space is Core's register and this
 * presenter is what a row of type `cms.page` is drawn by, so /about-us and
 * /bikes/mountain-bike-x are neighbours at the root of the site without either
 * carrying the name of the module it belongs to (decision R8).
 *
 * **A page that is not published answers 404, not 403 and not a page saying
 * so.** The address stays claimed while the page is a draft - nothing else can
 * take it - but from outside the installation there is nothing there, which is
 * the only answer that does not tell a stranger that something is being
 * written.
 *
 * **The menu is not this page's to draw.** The entries somebody arranged are
 * in the site's navigation, which Core puts together and the layout draws
 * around every page (.ai/plans/10-menu-submenu-a-rozcestniky.md, M3); what
 * this module adds to it is Trilobit\Cms\Navigation\ArrangedEntries.
 */
final class PagePresenter extends ContentPresenter
{
    public function __construct(
        private readonly Pages $pages,
    ) {
        parent::__construct();
    }

    public function renderDefault(): void
    {
        $template = $this->getTemplate();
        if (!$template instanceof PageDefaultTemplate) {
            throw new \LogicException(sprintf(
                'The template of %s has to be a %s.',
                self::class,
                PageDefaultTemplate::class,
            ));
        }

        $address = $this->contentAddress();
        if (!$address instanceof Address) {
            $this->error('This page was not reached through the register of public addresses.');
        }

        $page = $this->publishedPage();

        $template->pageTitle = $page->seoTitle();
        $template->metaDescription = $page->seoDescription();
        $template->heading = $page->title();
        $template->perex = $page->perex();
        $template->body = $page->content();
    }

    /**
     * The framework's getTemplate() is final, so the template class is chosen
     * here and checked where it is used. Naming the class is what lets the
     * template declare {templateType} and be analysed rather than guessed at.
     */
    protected function createTemplate(?string $class = null): Template
    {
        return parent::createTemplate($class ?? PageDefaultTemplate::class);
    }

    /**
     * The page this address leads to, if a visitor may see it.
     *
     * The register outlives what it points at: a row whose page was deleted
     * outside this module's own way of deleting one would lead nowhere, and an
     * unpublished page is nothing to a visitor. Both are the same answer.
     */
    private function publishedPage(): Page
    {
        $page = $this->pages->find((int) $this->contentId());
        if (!$page instanceof Page || !$page->isPublished()) {
            $this->error('No published page answers at this address.');
        }

        return $page;
    }
}

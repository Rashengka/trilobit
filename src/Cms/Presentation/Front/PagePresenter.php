<?php

declare(strict_types=1);

namespace Trilobit\Cms\Presentation\Front;

use Nette\Application\UI\Template;
use Trilobit\Cms\Application\Page\Pages;
use Trilobit\Cms\Domain\Menu\MenuItem;
use Trilobit\Cms\Domain\Menu\MenuRepository;
use Trilobit\Cms\Domain\Menu\MenuTarget;
use Trilobit\Cms\Domain\Page\Page;
use Trilobit\Core\Content\Address;
use Trilobit\Core\Content\PublicPath;
use Trilobit\Core\Presentation\Front\ContentPresenter;
use Trilobit\Core\Presentation\Front\Navigation\NavigationItem;
use Trilobit\Core\Presentation\Link\Destinations;

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
 * **The menu is filtered before it is drawn, not while it is drawn.** An entry
 * naming a page of a module that is switched off is left out here, where there
 * is something that can ask whether this build has it; asking the framework
 * for the link instead would produce a broken href and a page that looks
 * finished. See Trilobit\Core\Presentation\Link\Destinations.
 */
final class PagePresenter extends ContentPresenter
{
    public function __construct(
        private readonly Pages $pages,
        private readonly MenuRepository $menu,
        private readonly Destinations $destinations,
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
        $template->menu = $this->siteMenu($page);
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

    /**
     * The site menu, as addresses, with every entry this build cannot draw
     * left out.
     *
     * @return list<NavigationItem>
     */
    private function siteMenu(Page $current): array
    {
        $items = [];
        foreach ($this->menu->topOf(MenuItem::MAIN) as $entry) {
            $href = $this->hrefOf($entry);
            if ($href === null) {
                continue;
            }

            $items[] = new NavigationItem(
                $entry->label(),
                $href,
                $entry->page() === $current,
                'cms-menu-' . PublicPath::normalize($entry->label()),
            );
        }

        return $items;
    }

    /**
     * Where one entry leads, or null when this build cannot say.
     *
     * Each kind fails in its own way and every one of them ends here rather
     * than in the template:
     *
     * - a page that was taken down, or never given an address, is not linked
     *   to, because a menu is part of what a visitor may see;
     * - an address somebody typed is theirs and is drawn as it stands;
     * - a route into a module this build does not have is left out, which is
     *   the whole reason this method returns null rather than a string.
     */
    private function hrefOf(MenuItem $entry): ?string
    {
        return match ($entry->targetType()) {
            MenuTarget::Page => $this->addressOfLinkedPage($entry->page()),
            MenuTarget::Url => $entry->target() === '' ? null : $entry->target(),
            MenuTarget::Route => $this->destinations->drawnByThisBuild($entry->target())
                ? $this->link(':' . ltrim($entry->target(), ':'))
                : null,
        };
    }

    private function addressOfLinkedPage(?Page $page): ?string
    {
        if (!$page instanceof Page || !$page->isPublished()) {
            return null;
        }

        $address = $this->pages->addressOf($page);

        return $address === null ? null : $this->getHttpRequest()->getUrl()->getBasePath() . $address;
    }
}

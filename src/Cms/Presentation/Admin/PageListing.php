<?php

declare(strict_types=1);

namespace Trilobit\Cms\Presentation\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Trilobit\Cms\Application\Page\Pages;
use Trilobit\Cms\Domain\Page\Page;
use Trilobit\Cms\Domain\Page\PageStatus;
use Trilobit\Core\Presentation\Form\FormFactory;
use Trilobit\Core\Presentation\Listing\Comparison;
use Trilobit\Core\Presentation\Listing\Filters;
use Trilobit\Core\Presentation\Listing\Listing;

/**
 * Every page of the business, filtered by its title and its state and paged,
 * as the administration lists them - the first listing on
 * Trilobit\Core\Presentation\Listing\Listing (.ai/plans/15, and C3 of
 * .ai/plans/11, where a long list of pages is where the need arose).
 *
 * The title is looked for anywhere in it, because an editor remembers a word
 * of a title rather than how it begins; the state is one of the two a page can
 * be in. Where each page answers is not a filter: it is a row in Core's
 * register rather than a field of the page, and a filter narrows a field of
 * the entity it lists.
 *
 * The query reads cms_page and nothing else, so Core's tenant filter scopes it
 * as it scopes every read of that table; what the filters add narrows what is
 * left.
 */
final class PageListing extends Listing
{
    public function __construct(
        FormFactory $forms,
        private readonly EntityManagerInterface $entityManager,
        private readonly Pages $pages,
    ) {
        parent::__construct($forms);
    }

    protected function configure(Filters $filters): void
    {
        $filters
            ->text('title', 'Title', Comparison::Contains)
            ->choice(
                'status',
                'State',
                [PageStatus::Draft->value => 'Draft', PageStatus::Published->value => 'Published'],
                Comparison::Equals,
                prompt: 'Any state',
            );
    }

    protected function query(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('page')
            ->from(Page::class, 'page')
            ->orderBy('page.title', 'ASC');
    }

    /**
     * Each page with where it answers and where it is edited - worked out here
     * rather than in the template, because an address comes from the register
     * and a link from the router, and a template asking either would be a
     * template holding a service.
     *
     * @param list<object> $items
     *
     * @return list<PageSummary>
     */
    protected function rows(array $items): array
    {
        $presenter = $this->getPresenter();
        $basePath = $presenter->getHttpRequest()->getUrl()->getBasePath();

        $summaries = [];
        foreach ($items as $page) {
            $id = $page instanceof Page ? $page->id() : null;
            if (!$page instanceof Page || $id === null) {
                continue;
            }

            $address = $this->pages->addressOf($page);

            $summaries[] = new PageSummary(
                $id,
                $page->title(),
                // Drawn with the leading slash a visitor would type, and said
                // in words where there is none: an empty cell beside a page
                // reads as a page at the root of the site.
                $address === null ? 'no address yet' : '/' . $address,
                $page->isPublished() ? 'Published' : 'Draft',
                $page->isPublished(),
                $presenter->link('edit', ['id' => $id]),
                $address === null ? '' : $basePath . $address,
            );
        }

        return $summaries;
    }

    protected function rowsTemplate(): string
    {
        return __DIR__ . '/templates/PageListing.latte';
    }

    protected function caption(): string
    {
        return 'Every page and where it answers';
    }

    protected function counted(int $count): string
    {
        return $count === 1 ? '1 page' : sprintf('%d pages', $count);
    }

    protected function nothingYet(): string
    {
        return 'Nothing has been written yet.';
    }

    protected function nothingMatches(): string
    {
        return 'No page matches the filters.';
    }

    protected function testId(): string
    {
        return 'cms-page-list';
    }
}

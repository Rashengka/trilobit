<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Component\Component;
use Trilobit\Core\Presentation\Component\ComponentRegistry;
use Trilobit\Core\Presentation\Content\ContentGroupRegistry;
use Trilobit\Core\Presentation\Form\FormElementRegistry;
use Trilobit\Core\Presentation\Styleguide\StyleguideGroup;
use Trilobit\Core\Presentation\Styleguide\StyleguidePages;
use Trilobit\Core\Routing\StyleguideRoutes;

/**
 * The Components group opens with a list of every component - its name, what
 * it is for, and the way to its page - the way Bootstrap's documentation keeps
 * one down the side.
 *
 * Read off the rendered page, and held to the register rather than to the
 * menu: the menu is drawn from the same list of pages and would agree with a
 * list that had lost a component along with it. The one kind of entry that is
 * not a component is a page about how components go together - Navbar, which
 * is c-site-header and c-nav - and only a page of the group showing no
 * component of its own may be that.
 */
#[CoversNothing]
final class StyleguideListsEveryComponentTest extends TestCase
{
    private const string PAGE = 'components/overview';

    public function testTheGroupOpensWithIt(): void
    {
        self::assertSame(self::PAGE, $this->componentsGroup()->pages[0]->path());
    }

    public function testItNamesEveryRegisteredComponentWithWhatItIsForAndLeadsToItsPage(): void
    {
        $list = $this->page()->querySelector('[data-testid="styleguide-component-index"]');
        self::assertInstanceOf(Element::class, $list, 'the page has no list of the components');

        $listed = [];
        foreach ($list->querySelectorAll('li') as $entry) {
            $link = $entry->querySelector('a[href]');
            self::assertInstanceOf(Element::class, $link, 'an entry of the list leads nowhere');

            $listed[trim($link->textContent ?? '')] = [
                'href' => $link->getAttribute('href'),
                'text' => (string) preg_replace('/\s+/', ' ', trim($entry->textContent ?? '')),
            ];
        }

        $registry = new ComponentRegistry();
        self::assertSame(
            $registry->names(),
            array_values(array_filter(array_keys($listed), static fn(string $name): bool => $registry->find($name) instanceof Component)),
            'the list and the register name different components',
        );

        // Beside the components the list may lead only to the pages of the
        // group that show how components go together (Navbar) - never to a
        // name that is neither.
        $together = [];
        foreach ($this->componentsGroup()->pages as $page) {
            if ($page->components === [] && $page->path() !== self::PAGE) {
                $together[] = $page->title;
            }
        }
        self::assertSame(
            $together,
            array_values(array_filter(array_keys($listed), static fn(string $name): bool => !$registry->find($name) instanceof Component)),
            'the list names something that is neither a component nor a page of the group',
        );

        foreach ($registry->all() as $component) {
            $slug = substr($component->name, strlen(ComponentRegistry::PREFIX));
            self::assertSame('/' . StyleguideRoutes::PATH . '/components/' . $slug, $listed[$component->name]['href']);
            self::assertStringContainsString($component->summary, $listed[$component->name]['text']);
        }
    }

    /** It is a way in and nothing else: a specimen here would be a second one to keep alike. */
    public function testItShowsNoSpecimen(): void
    {
        self::assertSame([], iterator_to_array($this->page()->querySelectorAll('[data-styleguide-variant]')));
    }

    private function componentsGroup(): StyleguideGroup
    {
        $components = array_values(array_filter(
            new StyleguidePages(
                new ContentGroupRegistry(),
                new FormElementRegistry(),
                new ComponentRegistry(),
            )->groups(),
            static fn(StyleguideGroup $group): bool => $group->name === 'components',
        ));

        self::assertCount(1, $components);

        return $components[0];
    }

    private function page(): HTMLDocument
    {
        $path = '/' . StyleguideRoutes::PATH . '/' . self::PAGE;
        $page = StyleguideSpecimens::everyPage()[$path] ?? null;
        self::assertNotNull($page, sprintf('%s was not rendered', $path));

        return $page;
    }
}

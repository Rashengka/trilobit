<?php

declare(strict_types=1);

namespace Trilobit\Cms\DI;

use Nette\DI\CompilerExtension;
use Trilobit\Cms\Admin\CmsMenu;
use Trilobit\Cms\Application\Page\Pages;
use Trilobit\Cms\Content\CmsContentTypes;
use Trilobit\Cms\Domain\Menu\MenuRepository;
use Trilobit\Cms\Domain\Page\PageRepository;
use Trilobit\Cms\Infrastructure\Doctrine\DoctrineMenuRepository;
use Trilobit\Cms\Infrastructure\Doctrine\DoctrinePageRepository;
use Trilobit\Cms\Presentation\Front\CmsSignpost;
use Trilobit\Cms\Routing\CmsRoutes;
use Trilobit\Core\DI\CoreExtension;

/**
 * Everything the Cms module puts into the container.
 *
 * Nothing below is conditional - the extension is either registered by the
 * boot or it is not, and a module the boot did not register contributes
 * nothing at all. That is what "switched off" means here: not a flag anything
 * reads, but services that are absent.
 *
 * The tags go on in loadConfiguration() rather than in beforeCompile(), because
 * Core reads them in its own beforeCompile(). Extensions all load before any of
 * them compile, so tagging early is the ordering that is guaranteed to work,
 * and tagging late is the ordering that works until somebody changes the order
 * modules are registered in.
 *
 * The tagged services are not autowired. Nothing asks for a route provider or
 * a content type provider by its type - Core collects them by tag - and
 * leaving three modules offering the same interface to autowiring would only
 * make an ambiguity for somebody to trip over later. The two repositories and
 * the writing side of pages are autowired, because this module's own
 * presenters do ask for them by type.
 */
final class CmsExtension extends CompilerExtension
{
    public function loadConfiguration(): void
    {
        $builder = $this->getContainerBuilder();

        $builder->addDefinition($this->prefix('routes'))
            ->setFactory(CmsRoutes::class)
            ->setAutowired(false)
            ->addTag(CoreExtension::TAG_ROUTE_PROVIDER);

        $builder->addDefinition($this->prefix('adminMenu'))
            ->setFactory(CmsMenu::class)
            ->setAutowired(false)
            ->addTag(CoreExtension::TAG_ADMIN_MENU_PROVIDER);

        $builder->addDefinition($this->prefix('signpost'))
            ->setFactory(CmsSignpost::class)
            ->setAutowired(false)
            ->addTag(CoreExtension::TAG_SIGNPOST_PROVIDER);

        // Which kind of content this module publishes, and therefore which
        // addresses in the register lead to one of its pages. A build without
        // this module registers none, so those addresses are simply not routed
        // and the rows wait for it to come back.
        $builder->addDefinition($this->prefix('contentTypes'))
            ->setFactory(CmsContentTypes::class)
            ->setAutowired(false)
            ->addTag(CoreExtension::TAG_CONTENT_TYPE_PROVIDER);

        // Where this module's rows are kept. The interface is what the rest of
        // the module names, and the implementation is the only place in it
        // that knows Doctrine exists.
        $builder->addDefinition($this->prefix('pageRepository'))
            ->setType(PageRepository::class)
            ->setFactory(DoctrinePageRepository::class);

        $builder->addDefinition($this->prefix('menuRepository'))
            ->setType(MenuRepository::class)
            ->setFactory(DoctrineMenuRepository::class);

        // The one place that knows a page is two rows - what it says here, and
        // where it answers in Core's register.
        $builder->addDefinition($this->prefix('pages'))
            ->setFactory(Pages::class);
    }
}

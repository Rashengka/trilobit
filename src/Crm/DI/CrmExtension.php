<?php

declare(strict_types=1);

namespace Trilobit\Crm\DI;

use Nette\DI\CompilerExtension;
use Trilobit\Core\DI\CoreExtension;
use Trilobit\Crm\Admin\CrmMenu;
use Trilobit\Crm\Presentation\Front\CrmSignpost;
use Trilobit\Crm\Routing\CrmRoutes;

/**
 * Everything the Crm module puts into the container.
 *
 * The module is empty on purpose: contacts, companies and activities arrive
 * later. What is here is the wiring every module has, and it is worth having
 * before there is anything to wire, because it is the part that decides what
 * "switched off" means. Nothing below is conditional - the extension is
 * either registered by the boot or it is not, and a module the boot did not
 * register contributes nothing at all.
 *
 * The tags go on in loadConfiguration() rather than in beforeCompile(), because
 * Core reads them in its own beforeCompile(). Extensions all load before any of
 * them compile, so tagging early is the ordering that is guaranteed to work,
 * and tagging late is the ordering that works until somebody changes the order
 * modules are registered in.
 *
 * The services are not autowired. Nothing asks for a route provider by its
 * type - Core collects them by tag - and leaving three modules offering the
 * same interface to autowiring would only make an ambiguity for somebody to
 * trip over later.
 */
final class CrmExtension extends CompilerExtension
{
    public function loadConfiguration(): void
    {
        $builder = $this->getContainerBuilder();

        $builder->addDefinition($this->prefix('routes'))
            ->setFactory(CrmRoutes::class)
            ->setAutowired(false)
            ->addTag(CoreExtension::TAG_ROUTE_PROVIDER);

        $builder->addDefinition($this->prefix('adminMenu'))
            ->setFactory(CrmMenu::class)
            ->setAutowired(false)
            ->addTag(CoreExtension::TAG_ADMIN_MENU_PROVIDER);

        $builder->addDefinition($this->prefix('signpost'))
            ->setFactory(CrmSignpost::class)
            ->setAutowired(false)
            ->addTag(CoreExtension::TAG_SIGNPOST_PROVIDER);
    }
}

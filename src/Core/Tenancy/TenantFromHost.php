<?php

declare(strict_types=1);

namespace Trilobit\Core\Tenancy;

use Nette\Http\IRequest;
use Trilobit\Core\Routing\SetupRoutes;

/**
 * Settles whose request this is, from the host it arrived at, before anything
 * else happens to it.
 *
 * It is hung on the application's onStartup, which the framework runs before
 * it asks the router what the path means. That ordering is the requirement
 * rather than a detail: Trilobit\Core\Routing\ContentRouter reads the register
 * of public addresses, and that register is one address space per tenant, so a
 * path resolved before the tenant was known would be resolved in nobody's
 * address space - or, worse, in the first one that happened to have a row.
 *
 * A host nobody claims is refused. There is deliberately no default tenant and
 * no fallback: serving an unknown host out of some tenant is the mix-up this
 * whole dimension exists to prevent, and it is the kind that looks like a
 * working page while it happens. Refusing is loud, and loud is the only thing
 * that gets noticed.
 *
 * Development and testing are not an exception to that and get no switch.
 * `localhost` is a host like any other and is written into core_domain like
 * any other, so the path a developer exercises is the path a visitor takes. A
 * flag saying "on this machine, any host will do" is a flag that is one
 * deployment away from being true in production.
 */
final readonly class TenantFromHost
{
    public function __construct(
        private IRequest $request,
        private HostTenants $hosts,
        private Tenancy $tenancy,
    ) {}

    /**
     * One path is let through without a business: the setup wizard's. A fresh
     * installation has no business at all, so no host is claimed by one, and
     * the wizard is how it gets its first - it could not answer at all
     * otherwise. It enters no business either, because there is none it could
     * be; it reads nothing that belongs to one, and what it writes it writes
     * outright. Once the installation holds an account or a business the
     * wizard answers as an address nobody claims, which is what an unrouted
     * path here would have got anyway. See Trilobit\Core\Routing\SetupRoutes.
     */
    public function __invoke(): void
    {
        $url = $this->request->getUrl();
        if (SetupRoutes::isTheWizard($url->getPathInfo())) {
            return;
        }

        $host = $url->getHost();

        $this->tenancy->enter(
            $this->hosts->tenantAt($host) ?? throw TenancyRefused::unknownHost($host),
        );
    }
}

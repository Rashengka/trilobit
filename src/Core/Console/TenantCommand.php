<?php

declare(strict_types=1);

namespace Trilobit\Core\Console;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Trilobit\Core\Domain\Tenancy\Domain;
use Trilobit\Core\Domain\Tenancy\Tenant;
use Trilobit\Core\Tenancy\HostTenants;

/**
 * Makes a business and the hosts it answers at, which a freshly installed
 * application otherwise has none of.
 *
 * Without one, every request is refused: a host that names no tenant is not
 * served by a default one, because a default one is the mix-up the whole
 * dimension exists to prevent. So this is the first command a new installation
 * runs, before there is an account to sign in with - `localhost` is written
 * down here like every other host, which is why a developer's machine takes
 * the same path a visitor does and needs no switch of its own.
 *
 * Run again for a tenant that already exists, it adds the hosts it does not
 * have yet rather than refusing, so that a deployment script may call it every
 * time. A host that already belongs to somebody else is refused, and that is
 * the one thing here that has to be refused: two tenants at one host is the
 * question "whose request is this" having two answers.
 */
#[AsCommand(
    name: 'app:tenant',
    description: 'Creates a business and the host names it answers at.',
)]
final class TenantCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        /**
         * Which tenant answers at a host, asked the one way that is not
         * scoped by a tenant - see the class. A command line is inside no
         * tenant, so it is also the only way this command can find out that a
         * host is already somebody else's.
         */
        private readonly HostTenants $hosts,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'What this business is called.');
        $this->addArgument(
            'hosts',
            InputArgument::IS_ARRAY | InputArgument::REQUIRED,
            'The host names it answers at, "localhost" included on a development machine.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);

        $name = $input->getArgument('name');
        if (!is_string($name) || trim($name) === '') {
            $style->error('The first argument has to be what the business is called.');

            return self::FAILURE;
        }

        $hosts = $input->getArgument('hosts');
        if (!is_array($hosts)) {
            $style->error('The remaining arguments have to be the host names this business answers at.');

            return self::FAILURE;
        }

        $tenants = $this->entityManager->getRepository(Tenant::class);
        $tenant = $tenants->findOneBy(['name' => $name]) ?? new Tenant($name, new DateTimeImmutable());
        $this->entityManager->persist($tenant);
        $this->entityManager->flush();

        $added = [];
        $all = [];
        foreach ($hosts as $host) {
            if (!is_string($host) || trim($host) === '') {
                $style->error('A host name cannot be empty.');

                return self::FAILURE;
            }

            $host = strtolower(trim($host));
            $all[] = $host;
            $standing = $this->hosts->tenantAt($host);

            if ($standing !== null) {
                if ($standing !== $tenant->id()) {
                    $style->error(sprintf(
                        "'%s' already answers for %s.",
                        $host,
                        $tenants->find($standing)?->name() ?? 'another business',
                    ));

                    return self::FAILURE;
                }

                continue;
            }

            $this->entityManager->persist(new Domain($host, $tenant));
            $this->entityManager->flush();
            $added[] = $host;
        }

        $style->success(sprintf('%s answers at %s.', $tenant->name(), implode(', ', $all)));
        if ($added !== []) {
            $style->writeln('Added: ' . implode(', ', $added));
        }

        return self::SUCCESS;
    }
}

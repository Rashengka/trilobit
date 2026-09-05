<?php

declare(strict_types=1);

namespace Trilobit\Tests;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nette\DI\Container;
use PHPUnit\Framework\Assert;
use Trilobit\Core\Domain\Tenancy\Domain;
use Trilobit\Core\Domain\Tenancy\Tenant;
use Trilobit\Core\Tenancy\Tenancy;

/**
 * A tenant for a test to work inside.
 *
 * Nothing tenanted can be read until it is settled whose it is, so a suite
 * touching any of it has to say. That is not scaffolding a test has to put up
 * with: it is the same sentence a request says by arriving at a host, made
 * explicit where there is no request.
 */
final class Tenants
{
    /** What a test's request arrives at unless it says otherwise. */
    public const string HOST = 'localhost';

    /**
     * A tenant, its hosts, and the process now working inside it.
     *
     * @param string ...$hosts the domains it answers at; none is right for a
     *     test that never routes a request
     */
    public static function enter(Container $container, string $name = 'Ammonite Bikes', string ...$hosts): Tenant
    {
        $tenant = self::create($container, $name, ...$hosts);
        self::switchTo($container, $tenant);

        return $tenant;
    }

    /** A tenant the process is not working inside, which is what a second one is for. */
    public static function create(Container $container, string $name, string ...$hosts): Tenant
    {
        $entityManager = $container->getByType(EntityManagerInterface::class);

        $tenant = new Tenant($name, new DateTimeImmutable('2026-09-05T08:00:00+00:00'));
        $entityManager->persist($tenant);
        foreach ($hosts as $host) {
            $entityManager->persist(new Domain($host, $tenant));
        }

        $entityManager->flush();

        return $tenant;
    }

    public static function switchTo(Container $container, Tenant $tenant): void
    {
        $id = $tenant->id();
        Assert::assertNotNull($id, 'a tenant has to be saved before anything can work inside it');

        $container->getByType(Tenancy::class)->enter($id);
    }
}

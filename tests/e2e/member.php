<?php

declare(strict_types=1);

/*
 * Makes somebody who belongs to the business at a host and holds a role of
 * that business's own, for a spec of the browser suite that has to sign in as
 * somebody who is not its owner.
 *
 *   php tests/e2e/member.php <host> <email> <name> <role code> [<piece> ...]
 *
 * A piece is what a role is written in, `app.administration.content:view`.
 * The role is made in that business with those pieces if it has none under the
 * code yet, the account if nobody signs in with the address yet, and the
 * membership if the account does not hold the role there yet. Whatever was
 * there is left as it was but for one thing - the account is given a newly
 * generated secret to sign in with, printed on a line of its own, indented
 * by two spaces as `app:account` prints one - so a spec run again signs in
 * with what this run printed.
 *
 * `app:account` makes the owner of a business and nobody else, and no command
 * or screen composes a role of a business yet, so this is made out of the
 * entities, the way the seed makes its people. It runs on a working copy only
 * (Trilobit\Core\Config\Mode::mayAlterData()), as the seed does, and the
 * addresses the suite passes are the reserved documentation domain.
 */

use Doctrine\ORM\EntityManagerInterface;
use Nette\Security\Passwords;
use Nette\Utils\Random;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Config\Mode;
use Trilobit\Core\Domain\Tenancy\Membership;
use Trilobit\Core\Domain\Tenancy\Tenant;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Security\Accounts;
use Trilobit\Core\Tenancy\HostTenants;
use Trilobit\Core\Tenancy\Tenancy;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$trilobitE2eHost = $argv[1] ?? '';
$trilobitE2eEmail = $argv[2] ?? '';
$trilobitE2eName = $argv[3] ?? '';
$trilobitE2eCode = $argv[4] ?? '';
$trilobitE2ePieces = array_slice($argv ?? [], 5);

if ($trilobitE2eHost === '' || $trilobitE2eEmail === '' || $trilobitE2eName === '' || $trilobitE2eCode === '') {
    fwrite(STDERR, "usage: php tests/e2e/member.php <host> <email> <name> <role code> [<piece> ...]\n");

    exit(2);
}

$trilobitE2eContainer = Bootstrap::boot();
if (!$trilobitE2eContainer->getByType(Mode::class)->mayAlterData()) {
    fwrite(STDERR, "tests/e2e/member.php runs on a working copy only.\n");

    exit(1);
}

$trilobitE2eBusiness = $trilobitE2eContainer->getByType(HostTenants::class)->tenantAt($trilobitE2eHost);
if ($trilobitE2eBusiness === null) {
    fwrite(STDERR, sprintf("No business answers at %s.\n", $trilobitE2eHost));

    exit(1);
}

$trilobitE2eContainer->getByType(Tenancy::class)->enter($trilobitE2eBusiness);
$trilobitE2eEntities = $trilobitE2eContainer->getByType(EntityManagerInterface::class);
$trilobitE2eTenant = $trilobitE2eEntities->find(Tenant::class, $trilobitE2eBusiness)
    ?? throw new LogicException('The business answering at the host cannot be read back.');

// Read through the tenant filter: this business's roles and the application's.
$trilobitE2eRole = $trilobitE2eEntities->getRepository(Role::class)->findOneBy(['code' => $trilobitE2eCode]);
if (!$trilobitE2eRole instanceof Role) {
    $trilobitE2eRole = Role::ofBusiness($trilobitE2eTenant, $trilobitE2eCode, ucfirst($trilobitE2eCode), $trilobitE2ePieces);
    $trilobitE2eEntities->persist($trilobitE2eRole);
}

$trilobitE2ePassword = Random::generate(20, 'a-zA-Z0-9');
$trilobitE2eHash = $trilobitE2eContainer->getByType(Passwords::class)->hash($trilobitE2ePassword);
$trilobitE2eAccounts = $trilobitE2eContainer->getByType(Accounts::class);
$trilobitE2eAccount = $trilobitE2eAccounts->withEmail($trilobitE2eEmail);
if ($trilobitE2eAccount instanceof User) {
    $trilobitE2eAccount->changePassword($trilobitE2eHash);
} else {
    $trilobitE2eAccount = new User($trilobitE2eEmail, $trilobitE2eHash, $trilobitE2eName, new DateTimeImmutable());
}

$trilobitE2eAccounts->save($trilobitE2eAccount);

$trilobitE2eHeld = $trilobitE2eEntities->getRepository(Membership::class)->findOneBy([
    'user' => $trilobitE2eAccount,
    'role' => $trilobitE2eRole,
]);
if (!$trilobitE2eHeld instanceof Membership) {
    $trilobitE2eEntities->persist(new Membership($trilobitE2eTenant, $trilobitE2eAccount, $trilobitE2eRole));
}

$trilobitE2eEntities->flush();

echo '  ', $trilobitE2ePassword, "\n";

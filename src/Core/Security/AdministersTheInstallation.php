<?php

declare(strict_types=1);

namespace Trilobit\Core\Security;

/**
 * What the section of the installation's own administrator needs of whoever
 * opens it: that they are that administrator.
 *
 * **It asks about a person and never about a resource and a privilege**, which
 * is the whole reason it is a second attribute rather than a
 * Trilobit\Core\Security\Needs with different arguments. Administering the
 * installation is not assembled out of pieces the way a role is - somebody
 * either does it or does not - so there is no pair to write and no structure
 * that could offer one. A pair like `installation:view` would say there is a
 * narrower one to be had, and there is not.
 *
 * **Nothing else about the gate changes, and that was the point of the seam.**
 * Trilobit\Core\Presentation\Admin\AdminPresenter::checkRequirements() reads
 * declarations by this interface and never by a list of attribute names, and a
 * gate is handed a Trilobit\Core\Security\Doorkeeper and nothing else - so this
 * is a class and one more method there, and not a line changed in any
 * declaration already written above a page, in the presenter that enforces
 * them, or in Trilobit\Tests\Architecture\AdministrationViews.
 *
 * **It carries no reason and Trilobit\Core\Security\OpenToEverybody does**,
 * which is not an inconsistency. A reason is asked for where a page is being
 * let out of the default, because "open to everybody" is the answer that has to
 * be justified once per page. This is the opposite: it is the narrowest
 * declaration the application has, and the section it stands over is the only
 * place it is allowed to appear at all - see
 * Trilobit\Tests\Architecture\NoPermissionQuestionInTheInstallationSectionTest,
 * which holds both halves of that.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class AdministersTheInstallation implements Gate
{
    /**
     * There is no such thing as an installation administered by nobody, so the
     * order is the same as everywhere else: a visitor who has not signed in is
     * sent to sign in rather than refused.
     */
    public function requiresIdentity(): bool
    {
        return true;
    }

    public function admits(Doorkeeper $doorkeeper): bool
    {
        return $doorkeeper->administersTheInstallation();
    }
}

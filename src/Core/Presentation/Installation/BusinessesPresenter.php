<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Installation;

use Nette\Application\UI\Template;
use Trilobit\Core\Domain\Tenancy\Tenant;
use Trilobit\Core\Presentation\Admin\AdminPresenter;
use Trilobit\Core\Presentation\Admin\Landing;
use Trilobit\Core\Security\AdministersTheInstallation;
use Trilobit\Core\Tenancy\Businesses;

/**
 * The businesses this installation runs, listed for the person who is over all
 * of them.
 *
 * It is the smallest content that makes the section worth having, and it is
 * the content that could not sit anywhere else: a business is not a thing one
 * business's administrator may see a list of, and the list is the same list
 * whichever host the request arrived at.
 *
 * **It reads through the ordinary mapper and needs no exception to the tenant
 * filter**, because Trilobit\Core\Domain\Tenancy\Tenant is
 * Trilobit\Core\Tenancy\Shared - it is what tenancy is measured against rather
 * than something measured by it. Where each business answers is left off the
 * page for the opposite reason; see Trilobit\Core\Tenancy\Businesses.
 *
 * Nothing here can be changed yet, and the page says so rather than offering a
 * button that does nothing. A business is made by `bin/trilobit app:tenant`.
 * **Exit condition:** the first time somebody has to make or rename one
 * without a shell on the machine.
 */
#[AdministersTheInstallation]
final class BusinessesPresenter extends AdminPresenter
{
    public function __construct(
        private readonly Businesses $businesses,
    ) {
        parent::__construct();
    }

    public function renderDefault(): void
    {
        $template = $this->getTemplate();
        if (!$template instanceof BusinessesTemplate) {
            throw new \LogicException(sprintf(
                'The template of %s has to be a %s.',
                self::class,
                BusinessesTemplate::class,
            ));
        }

        $businesses = array_map($this->summaryOf(...), $this->businesses->all());

        $template->pageTitle = 'Businesses';
        $template->headline = 'Businesses';
        $template->lead = 'Every business this installation runs, and nobody\'s content but their own.';
        $template->businesses = $businesses;
        $template->sectionUrl = $this->link(Landing::INSTALLATION);
    }

    /**
     * The framework's getTemplate() is final, so the template class is chosen
     * here and checked where it is used. Naming the class is what lets the
     * template declare {templateType} and be analysed rather than guessed at.
     */
    protected function createTemplate(?string $class = null): Template
    {
        return parent::createTemplate($class ?? BusinessesTemplate::class);
    }

    /**
     * A saved business always has an identifier; one that has not been saved
     * cannot have been read back out of the database, so the fallback is
     * unreachable rather than meaningful - and it is written as zero rather
     * than as an exception because a list is the wrong place to discover it.
     */
    private function summaryOf(Tenant $business): BusinessSummary
    {
        return new BusinessSummary(
            $business->id() ?? 0,
            $business->name(),
            $business->createdAt(),
            $business->languageStrategy()->value,
        );
    }
}

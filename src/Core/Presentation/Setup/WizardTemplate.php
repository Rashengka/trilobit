<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Setup;

use Nette\Application\IPresenter;
use Nette\Application\UI\Control;
use Nette\Bridges\ApplicationLatte\Template;
use Trilobit\Core\Preference\Preferences;
use Trilobit\Core\Setup\Attempt;
use Trilobit\Core\Setup\Step;

/**
 * What Core:Setup:Wizard renders with, and what its layout draws itself out
 * of.
 *
 * A class of its own rather than the administration's or the public side's,
 * because the wizard has neither's furniture: no business yet, so no
 * navigation of one, and nobody signed in, so no account menu.
 */
final class WizardTemplate extends Template
{
    public IPresenter $presenter;

    public Control $control;

    public string $baseUrl = '';

    public string $basePath = '';

    /** @var list<\stdClass> */
    public array $flashes = [];

    public string $siteName = 'Trilobit';

    public string $pageTitle = '';

    /** Written onto <html>, so that the wizard is drawn in the theme the device already chose. */
    public Preferences $preferences;

    public string $preferenceUrl = '';

    /** The wizard's own address, which the mark in the banner and "try again" lead to. */
    public string $setupUrl = '';

    public Step $step = Step::DatabaseUnreachable;

    /** The host the first business will answer at: the one this page was opened at. */
    public string $host = '';

    /** What was tried, where the database could not be reached and this build may say so; see Trilobit\Core\Setup\Progress. */
    public ?Attempt $attempt = null;
}

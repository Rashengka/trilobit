<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Front;

use Nette\Application\IPresenter;
use Nette\Application\UI\Control;
use Nette\Bridges\ApplicationLatte\Template;

/**
 * What every front template may rely on, and therefore what the layout may
 * rely on.
 *
 * The layout names this class in {templateType}, so a property added here is
 * available in the layout of every page, and a property a single page needs
 * belongs in that page's own template class instead.
 *
 * It extends the framework's abstract template rather than its DefaultTemplate,
 * which is final. The properties below are the ones the template factory fills
 * in when it finds them; it looks them up by name, so declaring them here is
 * what makes them arrive - and declaring them with types is what lets both the
 * analyser and the editor see what a template is working with.
 */
class FrontTemplate extends Template
{
    public IPresenter $presenter;

    public Control $control;

    public string $baseUrl = '';

    public string $basePath = '';

    /** @var list<\stdClass> */
    public array $flashes = [];

    public string $siteName = 'Trilobit';

    public string $pageTitle = '';
}

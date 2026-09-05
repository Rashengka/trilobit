<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Front;

use Nette\Application\UI\Template;
use Trilobit\Core\Content\Address;
use Trilobit\Core\Content\PathLookup;
use Trilobit\Core\Presentation\Front\Navigation\Crumb;
use Trilobit\Core\Routing\ContentRouter;

/**
 * The base every page reached through the register of public addresses is
 * built on, whichever module it belongs to.
 *
 * It fills in the two things such a page has and a static one does not: which
 * of its addresses is the permalink, and the trail back up from the address
 * the visitor actually arrived at.
 *
 * Both come from the same decision. A product in two categories answers at
 * two addresses with 200 rather than redirecting, because a redirect would
 * take away the context the link was given in - so the trail is drawn from
 * the address in the request, and the duplicate is dealt with by naming the
 * permalink in the head of the document instead of by moving the visitor.
 *
 * The dependency arrives through an inject method rather than the constructor,
 * so that a module's own presenter does not have to repeat it in a constructor
 * of its own. That is the same reason FrontPresenter takes its two that way.
 */
abstract class ContentPresenter extends FrontPresenter
{
    private PathLookup $paths;

    public function injectPaths(PathLookup $paths): void
    {
        $this->paths = $paths;
    }

    /** The owning module's own identifier for what this page is drawing. */
    protected function contentId(): string
    {
        $id = $this->getParameter(ContentRouter::CONTENT_ID);

        return is_string($id) ? $id : '';
    }

    /**
     * The address the visitor arrived at, as the register has it.
     *
     * Null means the page was reached some other way than through the
     * register - which a module's own page should treat as not found rather
     * than as a page with no trail.
     */
    protected function contentAddress(): ?Address
    {
        $path = $this->getParameter(ContentRouter::PATH);

        return is_string($path) ? $this->paths->find($path) : null;
    }

    /**
     * The template class a page with a public address renders with, unless it
     * names one of its own - which is what a module's page does, the way
     * HomePresenter does under FrontPresenter. Naming one here is what lets
     * TemplateContractTest check the two properties assigned below rather than
     * skip this class for having no template class to check them against.
     */
    protected function createTemplate(?string $class = null): Template
    {
        return parent::createTemplate($class ?? ContentTemplate::class);
    }

    protected function beforeRender(): void
    {
        parent::beforeRender();

        $template = $this->getTemplate();
        if (!$template instanceof ContentTemplate) {
            throw new \LogicException(sprintf(
                'The template of %s has to be a %s, because that is what a page with a public address is written against.',
                static::class,
                ContentTemplate::class,
            ));
        }

        $address = $this->contentAddress();
        if (!$address instanceof Address) {
            return;
        }

        $template->canonicalUrl = $this->absolutely($address->canonicalPath);
        $template->breadcrumbs = $this->trailTo($address);
    }

    /**
     * The trail from the root down to $address, drawn by walking the register
     * upwards from the address in the request.
     *
     * The labels are the register's own rather than something asked of the
     * module that owns each step: a trail crossing from one module's content
     * into another's would otherwise be a call into a module that may not be
     * in this build at all.
     *
     * @return list<Crumb>
     */
    private function trailTo(Address $address): array
    {
        $trail = [new Crumb($address->label, $this->within($address->path), isCurrent: true)];

        $parent = $address->parentPath;
        while ($parent !== null) {
            $step = $this->paths->find($parent);
            if (!$step instanceof Address) {
                break;
            }

            $trail[] = new Crumb($step->label, $this->within($step->path));
            $parent = $step->parentPath;
        }

        return array_reverse($trail);
    }

    /** An address as a link within this installation. */
    private function within(string $path): string
    {
        return $this->getHttpRequest()->getUrl()->getBasePath() . $path;
    }

    /** An address as a search engine has to be told it: absolute, host and all. */
    private function absolutely(string $path): string
    {
        return $this->getHttpRequest()->getUrl()->getBaseUrl() . $path;
    }
}

<?php

declare(strict_types=1);

namespace Trilobit\Tests\Double;

use Nette\Http\Request;
use Nette\Http\UrlScript;

/**
 * The request the visitor made, in a process that was started from a command
 * line.
 *
 * A suite that renders a page has to be able to say which address it was
 * reached at, and the framework's own request is built once from the
 * environment - which, under a test runner, is the runner's. Without this
 * every page would look as though it had been asked for at the root, and the
 * two claims that matter most about the address space would be worth nothing:
 * that a product reached through its second category comes back as a page
 * rather than a redirect, and that the permalink it names is absolute.
 *
 * It replaces the framework's http.request service rather than being handed to
 * a presenter afterwards, because a presenter takes its request once, when it
 * is constructed, and builds its link generator out of it there.
 */
final class StandInHttpRequest extends Request
{
    private UrlScript $arrivedAt;

    public function __construct()
    {
        $root = new UrlScript('http://localhost/', '/');
        parent::__construct($root);
        $this->arrivedAt = $root;
    }

    public function arriveAt(string $url): void
    {
        $this->arrivedAt = new UrlScript($url, '/');
    }

    public function getUrl(): UrlScript
    {
        return $this->arrivedAt;
    }
}

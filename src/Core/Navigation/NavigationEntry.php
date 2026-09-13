<?php

declare(strict_types=1);

namespace Trilobit\Core\Navigation;

/**
 * One entry a contributor puts into a menu, before anybody has made a link of
 * it.
 *
 * **Where it leads is one of three things, and exactly one.** A page of some
 * module, named the way a link names it, which the router turns into an
 * address and which is left out where this build does not have the page; an
 * address in the site's own register, which is already known and only needs
 * the site's base in front of it; or an address written out, which leads
 * wherever it says. The constructor is private and the three ways in are
 * named, so that an entry leading to two things, or to none, is a shape this
 * class has no way to make.
 *
 * Nothing here is a URL of the page being drawn, because a contributor does
 * not know which page that is - Core does, and it is Core that makes the links
 * and says which entry is current (Trilobit\Core\Presentation\Front\FrontPresenter).
 */
final readonly class NavigationEntry
{
    /**
     * @param string $key what the entry is called in the page's markup, unique
     *     among the entries of its contributor
     * @param list<NavigationEntry> $children the entries under this one, in order
     */
    private function __construct(
        public string $key,
        public string $label,
        public ?string $destination,
        public ?string $path,
        public ?string $url,
        public array $children,
    ) {}

    /**
     * @param string $destination a presenter and an action, as a link names them: `Core:Front:Home:default`
     * @param list<NavigationEntry> $children
     */
    public static function toDestination(string $key, string $label, string $destination, array $children = []): self
    {
        return new self($key, $label, $destination, null, null, $children);
    }

    /**
     * @param string $path an address of this site as the register holds it, without the site's base
     * @param list<NavigationEntry> $children
     */
    public static function toPath(string $key, string $label, string $path, array $children = []): self
    {
        return new self($key, $label, null, $path, null, $children);
    }

    /**
     * @param string $url an address written out, drawn as it stands
     * @param list<NavigationEntry> $children
     */
    public static function toUrl(string $key, string $label, string $url, array $children = []): self
    {
        return new self($key, $label, null, null, $url, $children);
    }
}

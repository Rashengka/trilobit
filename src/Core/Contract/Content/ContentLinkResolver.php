<?php

declare(strict_types=1);

namespace Trilobit\Core\Contract\Content;

/**
 * The port a module implements when it knows how to turn a reference to one of
 * its own pieces of content into a link somebody else may draw.
 *
 * This is how one module points at another's content without naming it. A page
 * that wants to link to a product stores a type and an identifier - never a
 * class, never a foreign key - and asks this at render time. deptrac is what
 * stops the shortcut; this is what makes the shortcut unnecessary.
 *
 * A caller takes it as a plain, always-present dependency rather than an
 * optional one: with no enabled module able to answer, Core registers
 * Trilobit\Core\Contract\Content\NullContentLinkResolver in its place, so the
 * caller branches on whether a link came back and never on whether anybody
 * could have produced one.
 *
 * Null therefore means "no link" and is an ordinary answer, not a failure. A
 * page whose link comes back null draws no anchor at all - not an empty one,
 * and certainly not an exception. That is the case worth testing, because it
 * is the one nobody sees while every module happens to be switched on.
 */
interface ContentLinkResolver
{
    public function resolve(ContentRef $ref): ?ContentLink;
}

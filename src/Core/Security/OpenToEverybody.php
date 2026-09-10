<?php

declare(strict_types=1);

namespace Trilobit\Core\Security;

/**
 * A page of the administration that answers to anybody, and the reason it has
 * to.
 *
 * It exists so that being open is a thing somebody wrote rather than a thing
 * nobody wrote. The default is refusal - a page carrying no gate at all does
 * not open - and without this attribute the one page that must answer a
 * visitor who has not signed in would have to be an exception listed
 * somewhere else: in the presenter, or in the rule that checks the presenters.
 * An exception list is a second place to keep in step with the first, and it
 * is not greppable from the page it is about. This is: whoever opens the
 * sign-in page reads, at the top of it, that it is open and why.
 *
 * **The reason is required and is never merely a comment.** It is the same
 * shape Trilobit\Core\Tenancy\Shared has for an entity that carries no tenant,
 * and for the same reason - an attribute with an empty reason is the rule
 * being got past rather than answered, so
 * Trilobit\Tests\Architecture\EveryAdministrationViewIsGatedTest refuses one.
 *
 * It admits everybody rather than admitting nobody and being skipped, so that
 * a page carrying it and a narrower declaration together is still narrowed -
 * gates are read as all of them having to admit, and an exemption that
 * short-circuited that would be a way of turning the others off.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class OpenToEverybody implements Gate
{
    public function __construct(
        public string $because,
    ) {}

    public function requiresIdentity(): bool
    {
        return false;
    }

    public function admits(Doorkeeper $doorkeeper): bool
    {
        return true;
    }
}

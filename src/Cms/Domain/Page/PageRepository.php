<?php

declare(strict_types=1);

namespace Trilobit\Cms\Domain\Page;

/**
 * Where pages are kept, as the rest of the module has to know it.
 *
 * It is an interface here and an implementation under Infrastructure/ so that
 * one rule - the domain does not know Doctrine - can be checked rather than
 * every class being judged one at a time (.ai/plans/01-architektura.md §1).
 *
 * Nothing in it says anything about a tenant. Every read of cms_page is scoped
 * by Trilobit\Core\Tenancy\TenantFilter, so a method taking a tenant would be
 * a second place the same thing is stated - and the second place is the one
 * somebody forgets.
 */
interface PageRepository
{
    public function find(int $id): ?Page;

    /** @return list<Page> in the order an editor reads them */
    public function all(): array;

    public function save(Page $page): void;

    public function remove(Page $page): void;
}

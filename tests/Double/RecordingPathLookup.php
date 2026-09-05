<?php

declare(strict_types=1);

namespace Trilobit\Tests\Double;

use Trilobit\Core\Content\Address;
use Trilobit\Core\Content\PathLookup;
use Trilobit\Core\Contract\Content\ContentRef;

/**
 * A register that holds nothing and remembers what it was asked.
 *
 * It exists for the claim that is otherwise unstatable: not what the catch-all
 * answers, but whether it consulted the register at all. An address under a
 * beginning something else answers at must never get that far, because it can
 * never be there - and the query would run on every request for it.
 */
final class RecordingPathLookup implements PathLookup
{
    /** @var list<string> */
    private array $asked = [];

    public function find(string $path): ?Address
    {
        $this->asked[] = $path;

        return null;
    }

    public function canonicalPathOf(ContentRef $ref): ?string
    {
        return null;
    }

    /** @return list<string> the addresses this was asked about, in order */
    public function asked(): array
    {
        return $this->asked;
    }
}

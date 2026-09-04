<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Contract\Party;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Contract\Party\NullPartyDirectory;
use Trilobit\Core\Contract\Party\PartyDraft;
use Trilobit\Core\Contract\Party\PartyLookup;

#[CoversClass(NullPartyDirectory::class)]
final class NullPartyDirectoryTest extends TestCase
{
    public function testNobodyIsEverFound(): void
    {
        $directory = new NullPartyDirectory();

        self::assertNull($directory->find(new PartyLookup(email: 'person@example.com')));
        self::assertNull($directory->find(new PartyLookup()));
    }

    public function testNobodyIsEverCreated(): void
    {
        $directory = new NullPartyDirectory();

        self::assertNull($directory->findOrCreate(
            new PartyLookup(email: 'person@example.com'),
            new PartyDraft('Jane', 'Doe'),
        ));
    }
}

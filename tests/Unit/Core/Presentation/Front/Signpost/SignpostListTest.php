<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Presentation\Front\Signpost;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Front\Signpost\Signpost;
use Trilobit\Core\Presentation\Front\Signpost\SignpostList;
use Trilobit\Core\Presentation\Front\Signpost\SignpostProvider;

#[CoversClass(SignpostList::class)]
#[CoversClass(Signpost::class)]
final class SignpostListTest extends TestCase
{
    public function testASignpostListWithoutProvidersIsEmpty(): void
    {
        self::assertSame([], new SignpostList([])->items());
    }

    public function testEveryProviderContributes(): void
    {
        $signposts = new SignpostList([
            $this->provider(new Signpost('Shop', 'Shop:Front:Status:default')),
            $this->provider(new Signpost('Cms', 'Cms:Front:Status:default')),
        ]);

        self::assertSame(['Cms', 'Shop'], array_map(
            static fn(Signpost $signpost): string => $signpost->label,
            $signposts->items(),
        ));
    }

    public function testItemsAreOrderedByLabel(): void
    {
        $signposts = new SignpostList([
            $this->provider(new Signpost('Shop', 'Shop:Front:Status:default'), new Signpost('Cms', 'Cms:Front:Status:default'), new Signpost('Crm', 'Crm:Front:Status:default')),
        ]);

        self::assertSame(['Cms', 'Crm', 'Shop'], array_map(
            static fn(Signpost $signpost): string => $signpost->label,
            $signposts->items(),
        ));
    }

    private function provider(Signpost ...$signposts): SignpostProvider
    {
        return new readonly class (array_values($signposts)) implements SignpostProvider {
            /** @param list<Signpost> $signposts */
            public function __construct(private array $signposts) {}

            public function provide(): iterable
            {
                return $this->signposts;
            }
        };
    }
}

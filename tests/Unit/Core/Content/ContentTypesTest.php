<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Content;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Content\ContentType;
use Trilobit\Core\Content\ContentTypeProvider;
use Trilobit\Core\Content\ContentTypes;

#[CoversClass(ContentTypes::class)]
#[CoversClass(ContentType::class)]
final class ContentTypesTest extends TestCase
{
    public function testAKindOfContentLeadsToThePageThatDrawsIt(): void
    {
        $types = new ContentTypes([$this->publishing(new ContentType('demo.article', 'Demo:Front:Article'))]);

        $drawnBy = $types->drawnBy('demo.article');
        self::assertNotNull($drawnBy);
        self::assertSame('Demo:Front:Article', $drawnBy->presenter);
        self::assertSame('default', $drawnBy->action);
        self::assertSame('Demo:Front:Article:default', $drawnBy->destination());
    }

    /**
     * The reverse lookup is what lets a link be generated for a page without
     * the presenter's name ever reaching the address - decision R8 in the
     * direction that is easy to forget.
     */
    public function testThePageThatDrawsAKindOfContentLeadsBackToIt(): void
    {
        $types = new ContentTypes([$this->publishing(new ContentType('demo.article', 'Demo:Front:Article', 'show'))]);

        self::assertSame('demo.article', $types->typeOf('Demo:Front:Article', 'show'));
        self::assertNull($types->typeOf('Demo:Front:Article', 'default'));
    }

    /**
     * A build with no module publishing anything draws nothing, and says so by
     * answering null rather than by there being no registry at all.
     */
    public function testABuildWithNoPublishingModuleDrawsNothing(): void
    {
        $types = new ContentTypes([]);

        self::assertSame([], $types->types());
        self::assertNull($types->drawnBy('demo.article'));
    }

    /**
     * Two modules claiming one kind of content is refused rather than
     * resolved: whichever won would be decided by the order they happen to be
     * registered in, and that order changes when one of them is switched off.
     */
    public function testTwoModulesClaimingOneKindOfContentIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("both publish content of the type 'demo.article'");

        new ContentTypes([
            $this->publishing(new ContentType('demo.article', 'Demo:Front:Article')),
            $this->publishing(new ContentType('demo.article', 'Other:Front:Article')),
        ]);
    }

    private function publishing(ContentType ...$types): ContentTypeProvider
    {
        return new readonly class (array_values($types)) implements ContentTypeProvider {
            /** @param list<ContentType> $types */
            public function __construct(private array $types) {}

            /** @return list<ContentType> */
            public function contentTypes(): array
            {
                return $this->types;
            }
        };
    }
}

<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Nette\Utils\FileSystem;
use Nette\Utils\Finder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Component\Component;
use Trilobit\Core\Presentation\Component\ComponentRegistry;
use Trilobit\Tests\Architecture\BaseCssHoldsNoLiteralsTest;

/**
 * Decision D5, first half: the register and the directory say the same thing.
 *
 * A component that exists as a file and not as a record is a component nobody
 * documented and nobody has to give an example of; a record with no file is a
 * catalogue entry for something that was deleted. Both are failures here, and
 * the second half - that every registered variant is actually shown on the
 * style guide page - is
 * Trilobit\Tests\Template\StyleguideShowsEveryComponentTest.
 *
 * The name is the only thing written twice, and everything else is derived from
 * it by the rules in Component. That is deliberate: a derived file name is what
 * turns "somebody forgot to register it" into a failure rather than into a
 * mismatch nobody notices.
 */
#[CoversClass(ComponentRegistry::class)]
#[CoversClass(Component::class)]
final class ComponentRegistryTest extends TestCase
{
    /** @return iterable<string, array{Component}> */
    public static function registered(): iterable
    {
        foreach (new ComponentRegistry()->all() as $component) {
            yield $component->name => [$component];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function componentFiles(): iterable
    {
        foreach (Finder::findFiles('*.latte')->in(self::directory()) as $file) {
            yield basename((string) $file) => [(string) $file];
        }
    }

    #[DataProvider('componentFiles')]
    public function testEveryFileIsRegistered(string $file): void
    {
        $registered = array_map(
            static fn(Component $component): string => $component->file(),
            new ComponentRegistry()->all(),
        );

        self::assertContains(
            basename($file),
            $registered,
            sprintf(
                '%s is a component with no record in %s, so nothing requires it to have an example.',
                basename($file),
                ComponentRegistry::class,
            ),
        );
    }

    #[DataProvider('registered')]
    public function testEveryRecordHasItsFile(Component $component): void
    {
        self::assertFileExists(
            self::directory() . '/' . $component->file(),
            sprintf('%s is registered and there is no file for it', $component->name),
        );
    }

    #[DataProvider('registered')]
    public function testTheFileDefinesTheBlockTheNameImplies(Component $component): void
    {
        $source = FileSystem::read(self::directory() . '/' . $component->file());

        self::assertSame(
            [$component->block()],
            $this->blocksIn($source),
            sprintf(
                '%s has to be one {define %s} and nothing else; a file with a second block is a second component.',
                $component->file(),
                $component->block(),
            ),
        );
    }

    /**
     * The name is a CSS class before it is anything else, so a component with
     * no rule in assets/base.css is markup styled by nothing. It is also what
     * stops base.css being emptied to satisfy
     * Trilobit\Tests\Architecture\BaseCssHoldsNoLiteralsTest, which would
     * otherwise pass on a file with nothing in it at all.
     */
    #[DataProvider('registered')]
    public function testItHasARuleInTheStylesheet(Component $component): void
    {
        self::assertStringContainsString(
            '.' . $component->name,
            BaseCssHoldsNoLiteralsTest::declarations(),
            sprintf('%s is registered as a component and assets/base.css has no rule for it', $component->name),
        );
    }

    #[DataProvider('registered')]
    public function testItsNameStartsWithThePrefix(Component $component): void
    {
        self::assertStringStartsWith(ComponentRegistry::PREFIX, $component->name);
    }

    #[DataProvider('registered')]
    public function testItHasAtLeastOneVariantToShow(Component $component): void
    {
        self::assertNotSame([], $component->variants, $component->name . ' declares no variant');
        self::assertSame(
            array_values(array_unique($component->variants)),
            $component->variants,
            $component->name . ' lists the same variant twice',
        );
    }

    public function testNamesAreUnique(): void
    {
        $names = new ComponentRegistry()->names();

        self::assertSame(array_values(array_unique($names)), $names);
    }

    /**
     * Every parameter of every component block carries a type.
     *
     * This is the claim TemplateContractTest makes about a page's template and
     * cannot make about a component: there is no template class to compare
     * against, so the types have to be on the block itself. Without them a
     * caller passing the wrong thing finds out by looking at the page.
     */
    #[DataProvider('componentFiles')]
    public function testEveryParameterIsTyped(string $file): void
    {
        $source = FileSystem::read($file);

        if (preg_match('/\{define\s+[A-Za-z0-9_]+\s*,(.*?)\}/s', $source, $match) !== 1) {
            // A block with no parameters at all has nothing to type.
            self::assertMatchesRegularExpression('/\{define\s+[A-Za-z0-9_]+\s*\}/', $source);

            return;
        }

        foreach (explode(',', $match[1]) as $parameter) {
            $parameter = trim($parameter);
            if ($parameter === '' || !str_contains($parameter, '$')) {
                // A default value that itself contained a comma; the type sat
                // with the name, in the piece before it.
                continue;
            }

            self::assertMatchesRegularExpression(
                '/^\??[A-Za-z_][A-Za-z0-9_|]*\s+\$/',
                $parameter,
                sprintf('%s declares %s without a type', basename($file), $parameter),
            );
        }
    }

    private static function directory(): string
    {
        return dirname(__DIR__, 2) . '/' . ComponentRegistry::DIRECTORY;
    }

    /** @return list<string> */
    private function blocksIn(string $source): array
    {
        preg_match_all('/\{define\s+([A-Za-z0-9_]+)/', $source, $matches);

        return $matches[1];
    }
}

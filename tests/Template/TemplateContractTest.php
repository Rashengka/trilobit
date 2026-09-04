<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Nette\Utils\FileSystem;
use Nette\Utils\Finder;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * CLAUDE.md §8: a template a presenter renders carries {templateType}, and
 * the class it names is the one the presenter actually hands to Latte.
 *
 * @layout.latte is excluded throughout: nothing renders it directly, a
 * presenter's own template does by way of FrontPresenter::
 * formatLayoutTemplateFiles(), and it draws on FrontTemplate rather than a
 * template of its own.
 */
#[CoversNothing]
final class TemplateContractTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function presenterTemplates(): iterable
    {
        $root = dirname(__DIR__, 2);

        foreach (Finder::findFiles('*.latte')->exclude('@layout.latte')->from($root . '/src') as $file) {
            $path = (string) $file;
            yield substr($path, strlen($root) + 1) => [$path];
        }
    }

    #[DataProvider('presenterTemplates')]
    public function testItDeclaresATemplateType(string $file): void
    {
        self::assertMatchesRegularExpression(
            '/\{templateType\s+[A-Za-z0-9_\\\\]+\}/',
            FileSystem::read($file),
            sprintf('%s has no {templateType}', $file),
        );
    }

    #[DataProvider('presenterTemplates')]
    public function testTheDeclaredClassExists(string $file): void
    {
        if (preg_match('/\{templateType\s+([A-Za-z0-9_\\\\]+)\}/', FileSystem::read($file), $match) !== 1) {
            self::fail(sprintf('%s has no {templateType}', $file));
        }

        self::assertTrue(
            class_exists($match[1]),
            sprintf('%s declares {templateType %s}, and that class does not exist', $file, $match[1]),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function presenters(): iterable
    {
        $root = dirname(__DIR__, 2);

        foreach (Finder::findFiles('*Presenter.php')->from($root . '/src') as $file) {
            $path = (string) $file;
            yield substr($path, strlen($root) + 1) => [$path];
        }
    }

    /**
     * A presenter's createTemplate() override names the template class its
     * render methods are written against (see e.g. HomePresenter). Every
     * property one of those methods assigns on $template has to be a public
     * property that class actually declares - the case {templateType} alone
     * cannot catch is a presenter assigning $template->typo, which Latte
     * would otherwise resolve to null at render time and say nothing about.
     */
    #[DataProvider('presenters')]
    public function testAssignedPropertiesExistOnTheTemplateClass(string $file): void
    {
        $source = FileSystem::read($file);

        if (preg_match('/class\s+([A-Za-z0-9_]+)\s+extends/', $source, $classMatch) !== 1) {
            self::fail(sprintf('%s does not declare a class', $file));
        }

        $namespace = $this->namespaceOf($source);
        $presenterClass = $namespace . '\\' . $classMatch[1];
        self::assertTrue(class_exists($presenterClass), sprintf('%s does not autoload as %s', $file, $presenterClass));

        if (preg_match('/\?\?\s*([A-Za-z0-9_\\\\]+)::class/', $source, $templateMatch) !== 1) {
            // A presenter with no createTemplate() override renders with the
            // framework's own Template and has no properties to check.
            self::markTestSkipped(sprintf('%s does not override createTemplate()', $presenterClass));
        }

        $templateClass = $this->resolve($templateMatch[1], $namespace, $source);
        self::assertTrue(class_exists($templateClass), sprintf('%s names %s, which does not exist', $file, $templateClass));

        $public = [];
        foreach (new ReflectionClass($templateClass)->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $public[] = $property->getName();
        }

        preg_match_all('/\$template->([A-Za-z_][A-Za-z0-9_]*)\s*=[^=]/', $source, $assignments);

        foreach (array_unique($assignments[1]) as $property) {
            self::assertContains(
                $property,
                $public,
                sprintf('%s assigns $template->%s, which %s has no public property for', $file, $property, $templateClass),
            );
        }
    }

    private function namespaceOf(string $source): string
    {
        if (preg_match('/namespace\s+([A-Za-z0-9_\\\\]+);/', $source, $match) !== 1) {
            self::fail('a presenter file with no namespace');
        }

        return $match[1];
    }

    /**
     * $templateMatch[1] is either already fully qualified (it came from a
     * `use` import Latte's own resolution does not see) or a bare class name
     * that PHP resolves against the file's own namespace - the same rule
     * `use` statements exist to shortcut.
     */
    private function resolve(string $name, string $namespace, string $source): string
    {
        if (str_contains($name, '\\')) {
            return ltrim($name, '\\');
        }

        if (preg_match('/use\s+([A-Za-z0-9_\\\\]+\\\\' . preg_quote($name, '/') . ');/', $source, $match) === 1) {
            return $match[1];
        }

        return $namespace . '\\' . $name;
    }
}

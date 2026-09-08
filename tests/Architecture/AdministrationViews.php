<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture;

use Nette\Utils\Finder;
use Trilobit\Core\Presentation\Admin\AdminPresenter;
use Trilobit\Core\Security\Gate;

/**
 * Every page of the administration a build has, and whether anything says who
 * may open it.
 *
 * **The unit is the view rather than the presenter, and that is the whole
 * point of this class.** A presenter is a file; a page is what somebody types
 * an address for, and one file draws several of them - a list, a form, a
 * confirmation. A rule asked of classes would be satisfied by a presenter
 * whose class carries a declaration and whose second action quietly does not,
 * which is the mistake worth catching rather than the one nobody makes.
 *
 * A view is therefore taken from both halves the framework takes it from: a
 * public action*() or render*() method, and a template named after it in the
 * presenter's own templates directory. Either half alone is a view - Nette
 * renders a template with no render method beside it, and an action that ends
 * in a redirect has no template - so the two are put together rather than
 * intersected. Intersecting them would let a page be hidden from this by
 * leaving out whichever half it did not need.
 *
 * What counts as a declaration is any attribute implementing
 * Trilobit\Core\Security\Gate, read off the class or off either of the view's
 * two methods. It is read as an interface and never as a list of attribute
 * names, so a third kind of gate - the one that asks
 * Trilobit\Core\Security\Landlords rather than a resource and a privilege -
 * is covered by this the day it is written, without a line of it being
 * changed.
 */
final class AdministrationViews
{
    /**
     * Every administration view whose source is under $directory, in the order
     * a report reads best: by file, then by view.
     *
     * The namespace is passed in rather than read out of the files because
     * that is what makes the same rule runnable over a directory of fixtures:
     * the application's own presenters and a shape it deliberately does not
     * contain are found the same way, so a rule that reports nothing over the
     * first can be shown to be a rule that looked.
     *
     * @param string $namespace what the directory maps onto, ending in a
     *     backslash - `Trilobit\` for src, the fixtures' own namespace for
     *     fixtures
     *
     * @return list<array{where: string, view: string, gated: bool}>
     */
    public static function in(string $directory, string $namespace): array
    {
        $views = [];
        foreach (self::presentersUnder($directory, $namespace) as $file => $presenter) {
            $where = substr($file, strlen($directory) + 1);
            foreach (self::viewsOf($file, $presenter) as $view) {
                $views[] = [
                    'where' => $where,
                    'view' => $view,
                    'gated' => self::gatesOn($presenter, $view) !== [],
                ];
            }
        }

        return $views;
    }

    /**
     * Every administration presenter under $directory, by the file it is
     * written in.
     *
     * @return array<string, \ReflectionClass<AdminPresenter>>
     */
    public static function presentersUnder(string $directory, string $namespace): array
    {
        $presenters = [];
        foreach (self::filesUnder($directory) as $file) {
            $class = $namespace . str_replace('/', '\\', substr($file, strlen($directory) + 1, -strlen('.php')));
            if (!class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract() || !$reflection->isSubclassOf(AdminPresenter::class)) {
                continue;
            }

            /** @var \ReflectionClass<AdminPresenter> $reflection */
            $presenters[$file] = $reflection;
        }

        return $presenters;
    }

    /**
     * Every gate that has to admit somebody before $view is drawn: the ones
     * written above the class, and the ones written above either of the two
     * methods the framework may call for that view.
     *
     * They are gathered rather than chosen between, because a narrower
     * declaration on one action of a presenter is meant to be read on top of
     * the presenter's own and never instead of it.
     *
     * @param \ReflectionClass<AdminPresenter> $presenter
     *
     * @return list<Gate>
     */
    public static function gatesOn(\ReflectionClass $presenter, string $view): array
    {
        $gates = self::declaredOn($presenter);
        foreach (['action', 'render'] as $prefix) {
            $method = $prefix . ucfirst($view);
            if ($presenter->hasMethod($method)) {
                $gates = [...$gates, ...self::declaredOn($presenter->getMethod($method))];
            }
        }

        return $gates;
    }

    /**
     * @param \ReflectionClass<AdminPresenter>|\ReflectionMethod $element
     *
     * @return list<Gate>
     */
    public static function declaredOn(\ReflectionClass|\ReflectionMethod $element): array
    {
        $gates = [];
        foreach ($element->getAttributes(Gate::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $gates[] = $attribute->newInstance();
        }

        return $gates;
    }

    /**
     * The views one presenter draws, from its methods and from its templates.
     *
     * @param \ReflectionClass<AdminPresenter> $presenter
     *
     * @return list<string> sorted, so that a report reads the same twice
     */
    private static function viewsOf(string $file, \ReflectionClass $presenter): array
    {
        $views = [];
        foreach ($presenter->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (preg_match('~^(?:action|render)(?<view>.+)$~', $method->getName(), $named) === 1) {
                $views[lcfirst($named['view'])] = true;
            }
        }

        $templates = dirname($file) . '/templates/' . substr($presenter->getShortName(), 0, -strlen('Presenter'));
        if (is_dir($templates)) {
            foreach (Finder::findFiles('*.latte')->in($templates) as $template) {
                $views[$template->getBasename('.latte')] = true;
            }
        }

        $named = array_keys($views);
        sort($named);

        return $named;
    }

    /** @return list<string> sorted, so that a report reads the same twice */
    private static function filesUnder(string $directory): array
    {
        $files = [];
        foreach (Finder::findFiles('*.php')->from($directory) as $file) {
            $files[] = (string) $file;
        }

        sort($files);

        return $files;
    }
}

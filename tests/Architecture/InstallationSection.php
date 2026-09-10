<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture;

use Nette\Utils\Finder;
use Trilobit\Core\Security\AdministersTheInstallation;
use Trilobit\Core\Security\Gate;

/**
 * The section of the installation's own administrator, as a place rather than
 * as a naming habit.
 *
 * **The section is a directory and that is the mechanism.** A rule that had to
 * recognise the section by the name of a class or by what a presenter happened
 * to declare would be a rule the next page could be written outside of by
 * accident; a directory is something a file is either in or not, and the answer
 * is the same for the reader, for grep and for this.
 */
final class InstallationSection
{
    /** Under the project root. */
    public const string DIRECTORY = 'src/Core/Presentation/Installation'; // check-leaks:allow rule=high_entropy reason=a directory path that happens to be long enough to read as an opaque literal

    /**
     * Every permission question written in $directory, as
     * Trilobit\Tests\Architecture\PermissionQuestions reads them: by the two
     * enums, and never by the name of a method or of the service it is asked
     * of.
     *
     * @return list<string> where each one is, ready to be read in a report
     */
    public static function permissionQuestionsIn(string $directory): array
    {
        $asked = [];
        foreach (PermissionQuestions::askedIn($directory) as $question) {
            $privilege = $question['privilege'];
            $asked[] = $question['where'] . ': ' . $question['resource']->value
                . ($privilege === null ? '' : ', ' . $privilege->value);
        }

        return $asked;
    }

    /**
     * Every template under $directory that mentions asking what somebody may
     * do.
     *
     * **This half is lexical and is meant to be read as such.** A template is
     * not PHP, so the token-level reading the rule above does is not available
     * over one; what is left is looking for the words. It catches the question
     * written out - `$user->isAllowed(...)`, an enum named in a condition - and
     * it does not catch one asked through a variable or behind a filter. It is
     * a second net under the first, not a second copy of it.
     *
     * @return list<string>
     */
    public static function templatesAskingIn(string $directory): array
    {
        $asking = [];
        foreach (Finder::findFiles('*.latte')->from($directory) as $file) {
            $path = (string) $file;
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new \RuntimeException(sprintf('%s could not be read.', $path));
            }

            foreach (['isAllowed', 'Resource::', 'Privilege::', 'Permissions'] as $word) {
                if (str_contains($contents, $word)) {
                    $asking[] = substr($path, strlen($directory) + 1) . ': ' . $word;
                }
            }
        }

        sort($asking);

        return $asking;
    }

    /**
     * Every gate declared in $directory that is not the one about the person,
     * by the page it stands over.
     *
     * @return list<string>
     */
    public static function gatesOfAnotherKindIn(string $directory, string $namespace): array
    {
        $wrong = [];
        foreach (AdministrationViews::in($directory, $namespace) as $view) {
            foreach (self::gatesOn($directory, $namespace, $view['where'], $view['view']) as $gate) {
                if (!$gate instanceof AdministersTheInstallation) {
                    $wrong[] = $view['where'] . ': ' . $view['view'] . ': ' . $gate::class;
                }
            }
        }

        return $wrong;
    }

    /**
     * Every page outside $directory that declares the gate this section's
     * pages are behind, by the file it is written in.
     *
     * It is the other half of the same rule and it is the half that keeps the
     * section a section: a page anywhere else carrying this declaration would
     * be a second installation-wide page nobody had decided to have, and it
     * would be inside the administration of a business.
     *
     * @return list<string>
     */
    public static function pagesOutside(string $directory, string $root, string $namespace): array
    {
        $outside = [];
        foreach (AdministrationViews::presentersUnder($root, $namespace) as $file => $presenter) {
            if (str_starts_with($file, $directory . '/')) {
                continue;
            }

            foreach ([$presenter, ...$presenter->getMethods(\ReflectionMethod::IS_PUBLIC)] as $element) {
                if ($element->getAttributes(AdministersTheInstallation::class) !== []) {
                    $outside[] = substr($file, strlen($root) + 1);
                }
            }
        }

        return array_values(array_unique($outside));
    }

    /**
     * @return list<Gate>
     */
    private static function gatesOn(string $directory, string $namespace, string $where, string $view): array
    {
        foreach (AdministrationViews::presentersUnder($directory, $namespace) as $file => $presenter) {
            if (substr($file, strlen($directory) + 1) === $where) {
                return AdministrationViews::gatesOn($presenter, $view);
            }
        }

        return [];
    }
}

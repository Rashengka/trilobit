<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture;

use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\ResourceName;

/**
 * Every permission question written in a directory of source, read out of the
 * source itself.
 *
 * It looks for the enums rather than for a method name, and that is the whole
 * of why it can be trusted. A rule anchored on `isAllowed(` would be satisfied
 * by renaming the method or by asking a Nette\Security\Permission directly;
 * an enum cannot be got round, because it is the only way the string a
 * question is made of comes into existence - see
 * Trilobit\Core\Security\Resource.
 *
 * **Which enums, it is told**: the resources of a build, Core's
 * Trilobit\Core\Security\Resource and every module's own enum among them. A
 * module's question is read exactly as Core's is - `ShopResource::Catalogue,
 * Privilege::Edit` - and an enum of resources no build brings is one this would
 * not know to read; Trilobit\Tests\Architecture\EveryResourceEnumIsContributedTest
 * is what makes sure there is none.
 *
 * So the rule it enforces is stronger than "the pairs are predefined": every
 * mention of a resource has to be a question a machine can read, spelled
 * `SomeResourceEnum::Something, Privilege::Something`. A question assembled out
 * of variables would be one this could not check, and something it cannot check
 * has to be reported rather than skipped - skipping is how a guard comes to
 * pass over the one place that mattered.
 *
 * An enum is recognised by its short name, the way it is written after an
 * import, or by the whole name written out. Two enums of resources with one
 * short name would be told apart only when written out in full - so the
 * modules' enums carry the module in their name (`ShopResource`), which is
 * also what makes a question readable where it is written.
 */
final class PermissionQuestions
{
    /**
     * @param list<ResourceName> $resources every resource a question may be
     *     about - the widest build's, so that no module's enum is left unread
     *
     * @return list<array{where: string, resource: ResourceName, privilege: Privilege|null}>
     *     in the order they are written; a null privilege is a mention this
     *     could not read as a question
     */
    public static function askedIn(string $directory, array $resources): array
    {
        $byEnum = [];
        foreach ($resources as $resource) {
            $byEnum[$resource::class][$resource->name] = $resource;
        }

        $questions = [];
        foreach (self::filesUnder($directory) as $file) {
            foreach (self::inFile($file, $byEnum) as $question) {
                $questions[] = [
                    'where' => substr($file, strlen($directory) + 1) . ':' . $question['line'],
                    'resource' => $question['resource'],
                    'privilege' => $question['privilege'],
                ];
            }
        }

        return $questions;
    }

    /**
     * @param array<string, array<string, ResourceName>> $byEnum each enum's
     *     cases by their names, by the enum
     *
     * @return list<array{line: int, resource: ResourceName, privilege: Privilege|null}>
     */
    private static function inFile(string $file, array $byEnum): array
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new \RuntimeException(sprintf('%s could not be read.', $file));
        }

        $tokens = self::significant(token_get_all($contents));
        $questions = [];

        foreach (array_keys($tokens) as $position) {
            $resource = self::resourceAt($tokens, $position, $byEnum);
            if (!$resource instanceof ResourceName) {
                continue;
            }

            $token = $tokens[$position];
            $questions[] = [
                'line' => is_array($token) ? $token[2] : 0,
                'resource' => $resource,
                'privilege' => ($tokens[$position + 3] ?? null) === ','
                    ? self::privilegeAt($tokens, $position + 4)
                    : null,
            ];
        }

        return $questions;
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     * @param array<string, array<string, ResourceName>> $byEnum
     */
    private static function resourceAt(array $tokens, int $position, array $byEnum): ?ResourceName
    {
        foreach ($byEnum as $enum => $cases) {
            $case = self::caseNameAt($tokens, $position, $enum);
            if ($case !== null && isset($cases[$case])) {
                return $cases[$case];
            }
        }

        return null;
    }

    /** @param list<array{int, string, int}|string> $tokens */
    private static function privilegeAt(array $tokens, int $position): ?Privilege
    {
        $case = self::caseNameAt($tokens, $position, Privilege::class);
        foreach (Privilege::cases() as $privilege) {
            if ($privilege->name === $case) {
                return $privilege;
            }
        }

        return null;
    }

    /**
     * What is written after `$enum::` at $position, or null when what is
     * written there is not that enum at all. A static call such as cases()
     * comes back as its own name and matches no case, which is how those are
     * left alone.
     *
     * @param list<array{int, string, int}|string> $tokens
     * @param string $enum the enum's whole name
     */
    private static function caseNameAt(array $tokens, int $position, string $enum): ?string
    {
        $name = $tokens[$position] ?? null;
        $colons = $tokens[$position + 1] ?? null;
        $case = $tokens[$position + 2] ?? null;

        $separator = strrpos($enum, '\\');
        $short = $separator === false ? $enum : substr($enum, $separator + 1);

        // Written out in full or imported, both are the same question. A
        // fully qualified name is one token of its own kind, so leaving that
        // kind out would leave a way of asking that nothing reads.
        $spelled = is_array($name)
            && in_array($name[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
            && ($name[1] === $short || str_ends_with($name[1], '\\' . $short));

        if (!$spelled) {
            return null;
        }

        if (!is_array($colons) || $colons[0] !== T_DOUBLE_COLON) {
            return null;
        }

        return is_array($case) && $case[0] === T_STRING ? $case[1] : null;
    }

    /**
     * The tokens with whitespace and comments left out, renumbered, so that
     * "the next thing written" is the next index rather than a search.
     *
     * @param list<array{int, string, int}|string> $tokens
     *
     * @return list<array{int, string, int}|string>
     */
    private static function significant(array $tokens): array
    {
        $significant = [];
        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $significant[] = $token;
        }

        return $significant;
    }

    /** @return list<string> sorted, so that a report reads the same twice */
    private static function filesUnder(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}

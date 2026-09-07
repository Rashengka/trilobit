<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture;

use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;

/**
 * Every permission question written in a directory of source, read out of the
 * source itself.
 *
 * It looks for the enum rather than for a method name, and that is the whole
 * of why it can be trusted. A rule anchored on `isAllowed(` would be satisfied
 * by renaming the method or by asking a Nette\Security\Permission directly;
 * the enum cannot be got round, because it is the only way the string a
 * question is made of comes into existence - see
 * Trilobit\Core\Security\Resource.
 *
 * So the rule it enforces is stronger than "the pairs are predefined": every
 * mention of a resource has to be a question a machine can read, spelled
 * `Resource::Something, Privilege::Something`. A question assembled out of
 * variables would be one this could not check, and something it cannot check
 * has to be reported rather than skipped - skipping is how a guard comes to
 * pass over the one place that mattered.
 */
final class PermissionQuestions
{
    /**
     * @return list<array{where: string, resource: Resource, privilege: Privilege|null}>
     *     in the order they are written; a null privilege is a mention this
     *     could not read as a question
     */
    public static function askedIn(string $directory): array
    {
        $questions = [];
        foreach (self::filesUnder($directory) as $file) {
            foreach (self::inFile($file) as $question) {
                $questions[] = [
                    'where' => substr($file, strlen($directory) + 1) . ':' . $question['line'],
                    'resource' => $question['resource'],
                    'privilege' => $question['privilege'],
                ];
            }
        }

        return $questions;
    }

    /** @return list<array{line: int, resource: Resource, privilege: Privilege|null}> */
    private static function inFile(string $file): array
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new \RuntimeException(sprintf('%s could not be read.', $file));
        }

        $tokens = self::significant(token_get_all($contents));
        $questions = [];

        foreach (array_keys($tokens) as $position) {
            $resource = self::resourceAt($tokens, $position);
            if (!$resource instanceof Resource) {
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

    /** @param list<array{int, string, int}|string> $tokens */
    private static function resourceAt(array $tokens, int $position): ?Resource
    {
        $case = self::caseNameAt($tokens, $position, 'Resource');
        foreach (Resource::cases() as $resource) {
            if ($resource->name === $case) {
                return $resource;
            }
        }

        return null;
    }

    /** @param list<array{int, string, int}|string> $tokens */
    private static function privilegeAt(array $tokens, int $position): ?Privilege
    {
        $case = self::caseNameAt($tokens, $position, 'Privilege');
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
     */
    private static function caseNameAt(array $tokens, int $position, string $enum): ?string
    {
        $name = $tokens[$position] ?? null;
        $colons = $tokens[$position + 1] ?? null;
        $case = $tokens[$position + 2] ?? null;

        // Written out in full or imported, both are the same question. A
        // fully qualified name is one token of its own kind, so leaving that
        // kind out would leave a way of asking that nothing reads.
        $spelled = is_array($name)
            && in_array($name[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
            && ($name[1] === $enum || str_ends_with($name[1], '\\' . $enum));

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

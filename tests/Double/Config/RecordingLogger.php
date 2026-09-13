<?php

declare(strict_types=1);

namespace Trilobit\Tests\Double\Config;

use Tracy\ILogger;

/**
 * Tracy's logger, standing in for the one that writes to var/log, so that a
 * test can read what was logged without searching a file other runs write to
 * as well.
 */
final class RecordingLogger implements ILogger
{
    /** @var list<array{mixed, string}> */
    public array $entries = [];

    public function log(mixed $value, string $level = self::INFO): void
    {
        $this->entries[] = [$value, $level];
    }

    /** @return list<string> what was logged at $level, as text */
    public function at(string $level): array
    {
        $found = [];
        foreach ($this->entries as [$value, $logged]) {
            if ($logged === $level) {
                $found[] = is_string($value) ? $value : get_debug_type($value);
            }
        }

        return $found;
    }
}

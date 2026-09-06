<?php

declare(strict_types=1);

namespace Trilobit\Core\Admin\Menu;

/**
 * One entry of the administration menu.
 *
 * The destination is a presenter name and nothing else, so that Core never has
 * to know which module the entry came from, and so that an entry pointing into
 * a module that is switched off can be recognised and dropped rather than
 * rendered into a link that throws.
 */
final readonly class MenuItem
{
    public function __construct(
        public string $label,
        public string $destination,
        public int $weight = 100,
    ) {}

    /**
     * The module this entry belongs to: the first segment of the destination,
     * lower-cased.
     *
     * It is the whole of what Core knows about where an entry leads, and the
     * same segment Trilobit\Core\Doctrine\TableName reads off a table name on
     * the other side of the application - see also
     * tests/Combination/AllModuleCombinationsTest::modulesOf(), which took this
     * step first and is left as it was rather than made to call this, since a
     * test asserting a production class's own answer should not share the
     * method it is checking.
     */
    public function module(): string
    {
        return strtolower(explode(':', $this->destination, 2)[0]);
    }
}

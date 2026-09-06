<?php

declare(strict_types=1);

namespace Trilobit\Core\Security;

/**
 * One piece a role is put together out of: a resource and something that may
 * be done to it.
 *
 * It is what a row in core_role.permissions says, and this class is the only
 * place that knows how it is written down. Two places knowing a format is how
 * the two stop agreeing, and here the symptom of disagreement is a permission
 * that silently never matches.
 *
 * **Reading one back may fail, and failing is an answer.** What is stored was
 * written by an earlier build, so it may name a resource that no longer
 * exists or a privilege that was renamed. Such a piece is dropped rather than
 * refused: Nette throws on a resource it does not know, so carrying an
 * outdated name as far as the question would not deny that person something,
 * it would stop them using the application at all - see
 * Trilobit\Core\Security\Resource. A dropped piece takes a right away, which
 * is the direction a doubt should fall.
 */
final readonly class Grant
{
    /**
     * A colon, because a resource's own value may contain a dot - a section
     * and a decision point inside it read naturally that way - and no
     * privilege contains either.
     */
    private const string SEPARATOR = ':';

    public function __construct(
        public Resource $resource,
        public Privilege $privilege,
    ) {}

    /** Null when this build has no such resource or no such privilege; see the class. */
    public static function parse(string $written): ?self
    {
        $separator = strrpos($written, self::SEPARATOR);
        if ($separator === false) {
            return null;
        }

        $resource = Resource::tryFrom(substr($written, 0, $separator));
        $privilege = Privilege::tryFrom(substr($written, $separator + 1));

        return $resource instanceof Resource && $privilege instanceof Privilege
            ? new self($resource, $privilege)
            : null;
    }

    public function code(): string
    {
        return $this->resource->value . self::SEPARATOR . $this->privilege->value;
    }
}

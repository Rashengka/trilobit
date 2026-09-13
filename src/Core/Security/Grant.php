<?php

declare(strict_types=1);

namespace Trilobit\Core\Security;

/**
 * One piece a role is put together out of: a resource and something that may
 * be done to it - or the whole of the resource, written
 * `app.administration.content:*`.
 *
 * It is what a row in core_role.permissions says, and this class is the only
 * place that knows how it is written down. Two places knowing a format is how
 * the two stop agreeing, and here the symptom of disagreement is a permission
 * that silently never matches.
 *
 * **The whole of a resource is a piece and never a question.** It is how a
 * role is written, not how anything is asked: Trilobit\Core\Security\Needs and
 * both services that answer take a Privilege and nothing else, so "may they do
 * everything to this?" cannot be spelled. Whether a whole piece is honoured is
 * not this class's decision either - that is what the structure's `bundle`
 * says, and Trilobit\Core\Security\AccessComposition asks it.
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
     * A colon, because a resource's own value is a path and its segments are
     * separated by dots, and no privilege contains either.
     */
    private const string SEPARATOR = ':';

    /** In place of the privilege, and nowhere else: a star for the resource would be a piece about everything. */
    private const string WHOLE = '*';

    /**
     * @param Privilege|null $privilege null for the whole of the resource. It
     *     has no default on purpose: a piece written without one would be the
     *     widest piece there is, and the widest one must be written on purpose.
     */
    public function __construct(
        public Resource $resource,
        public ?Privilege $privilege,
    ) {}

    /** Null when this build has no such resource or no such privilege; see the class. */
    public static function parse(string $written): ?self
    {
        $separator = strrpos($written, self::SEPARATOR);
        if ($separator === false) {
            return null;
        }

        $resource = Resource::tryFrom(substr($written, 0, $separator));
        if (!$resource instanceof Resource) {
            return null;
        }

        $privilege = substr($written, $separator + 1);
        if ($privilege === self::WHOLE) {
            return new self($resource, null);
        }

        $named = Privilege::tryFrom($privilege);

        return $named instanceof Privilege ? new self($resource, $named) : null;
    }

    public function isWhole(): bool
    {
        return !$this->privilege instanceof Privilege;
    }

    public function code(): string
    {
        return $this->resource->value . self::SEPARATOR . ($this->privilege->value ?? self::WHOLE);
    }
}

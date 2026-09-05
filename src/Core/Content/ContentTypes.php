<?php

declare(strict_types=1);

namespace Trilobit\Core\Content;

/**
 * Which kinds of content this build can draw, read both ways.
 *
 * Forwards, an address that has been turned into a type is turned into a
 * presenter. Backwards, a link being generated for a presenter is turned back
 * into a type, so that the address can be looked up rather than built out of
 * the presenter's name - decision R8 says a presenter's name never appears in
 * a URL, and this is the pair of lookups that keeps that true in both
 * directions.
 *
 * Two modules claiming one type is refused rather than resolved. Whichever
 * won would be decided by the order the modules happen to be registered in,
 * and that order changes when one of them is switched off.
 */
final readonly class ContentTypes
{
    /** @var array<string, ContentType> */
    private array $byType;

    /** @var array<string, string> destination => type */
    private array $byDestination;

    /** @param iterable<ContentTypeProvider> $providers */
    public function __construct(iterable $providers)
    {
        $byType = [];
        $byDestination = [];

        foreach ($providers as $provider) {
            foreach ($provider->contentTypes() as $contentType) {
                if (isset($byType[$contentType->type])) {
                    throw new \InvalidArgumentException(sprintf(
                        "Two enabled modules both publish content of the type '%s'; %s is the second.",
                        $contentType->type,
                        $contentType->destination(),
                    ));
                }

                $byType[$contentType->type] = $contentType;
                $byDestination[$contentType->destination()] = $contentType->type;
            }
        }

        $this->byType = $byType;
        $this->byDestination = $byDestination;
    }

    /** The page that draws $type, or null when nothing in this build does. */
    public function drawnBy(string $type): ?ContentType
    {
        return $this->byType[$type] ?? null;
    }

    /** The kind of content $presenter draws, or null when it draws none. */
    public function typeOf(string $presenter, string $action): ?string
    {
        return $this->byDestination[$presenter . ':' . $action] ?? null;
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys($this->byType);
    }
}

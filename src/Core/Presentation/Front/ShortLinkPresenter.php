<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Front;

use Nette\Application\UI\Presenter;

/**
 * The short address of a record: `/c/12`, and nothing but a permanent redirect
 * to where that record really lives.
 *
 * It exists for one reason - a short address can be pasted into a message or
 * read aloud - and a redirect is the whole of what that needs. Drawing the
 * record here instead would make it two addresses for one thing, which is two
 * pages to a search engine, two entries in a cache and two bookmarks that
 * drift apart; decision R5 forbids it, and this is the shape that keeps the
 * convenience without the cost.
 *
 * Where it leads is written into the route by whichever module owns the
 * record, so Core never knows what kind of record a prefix stands for; see
 * Trilobit\Core\Routing\ShortLinks. Every prefix permanently takes a beginning
 * out of the public address space, which is why the same class reserves it.
 */
final class ShortLinkPresenter extends Presenter
{
    /** As the presenter mapping in config/common.neon names this class. */
    public const string NAME = 'Core:Front:ShortLink';

    /** Where the short address leads, as a presenter and an action. */
    public const string DESTINATION = 'destination';

    /** The record's own identifier, handed on to the destination unchanged. */
    public const string ID = 'id';

    public function actionDefault(string $destination, string $id): void
    {
        // The leading colon makes the destination absolute. Without it the
        // framework would resolve it inside Core:Front, the module this
        // presenter happens to live in, and a short address would only ever
        // be able to point at a neighbour of its own redirect.
        $this->redirectPermanent(':' . ltrim($destination, ':'), [self::ID => $id]);
    }
}

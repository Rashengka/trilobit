<?php

declare(strict_types=1);

namespace Trilobit\Core\Routing;

use Nette\Http\IRequest;
use Nette\Http\UrlScript;
use Nette\Routing\Router;
use Trilobit\Core\Content\Address;
use Trilobit\Core\Content\ContentType;
use Trilobit\Core\Content\ContentTypes;
use Trilobit\Core\Content\PathLookup;
use Trilobit\Core\Content\PublicPath;
use Trilobit\Core\Content\ReservedSegments;
use Trilobit\Core\Contract\Content\ContentRef;
use Trilobit\Core\Presentation\Front\RedirectPresenter;

/**
 * The last router in the list: the one that asks the register of public
 * addresses what a path means.
 *
 * It is what lets a page and a product live side by side at the root of the
 * site without either carrying the name of the module it belongs to. A path is
 * turned into a type and an identifier by the register, and the type is turned
 * into a presenter by Trilobit\Core\Content\ContentTypes - so the name of a
 * presenter never appears in a URL and the nesting of modules has nothing to
 * do with the nesting of addresses.
 *
 * It answers three ways and refuses in three more.
 *
 * - A live address of a type this build can draw becomes a request for that
 *   page, carrying both the address the visitor used and the identifier.
 * - An address that has moved, and any other spelling of an address that
 *   answers, becomes a permanent redirect - see
 *   Trilobit\Core\Presentation\Front\RedirectPresenter.
 * - A reserved beginning is refused before the database is touched. Nothing
 *   under one can be content, so a request for a switched-off module's own
 *   path costs no query and comes back unrouted, the way it did before there
 *   was a catch-all at all.
 * - So is an address nobody claims, and one whose type no enabled module
 *   draws: the row stays in the register, waiting for the module to come back,
 *   and until then the address is not routed rather than routed to an error.
 *
 * Generating a link runs the same two lookups backwards. A link made while
 * standing on one of several addresses of the same content keeps that address,
 * which is what stops the framework's own canonicalisation from redirecting a
 * visitor away from the category they arrived through; every other link goes
 * to the permalink.
 */
final readonly class ContentRouter implements Router
{
    /** The address the visitor used, carried into the page so that it can draw the trail it was reached by. */
    public const string PATH = 'contentPath';

    /** The owning module's own identifier for what is being drawn. */
    public const string CONTENT_ID = 'contentId';

    public function __construct(
        private PathLookup $paths,
        private ContentTypes $types,
        private ReservedSegments $reserved,
    ) {}

    /** @return array<string, mixed>|null */
    public function match(IRequest $httpRequest): ?array
    {
        $url = $httpRequest->getUrl();
        $basePath = $url->getBasePath();
        if (!str_starts_with($url->getPath(), $basePath)) {
            return null;
        }

        $requested = rawurldecode(substr($url->getPath(), strlen($basePath)));
        $path = PublicPath::normalize($requested);
        if ($path === '') {
            // The root is a static route rather than a row in the register,
            // so the empty address is never content.
            return null;
        }

        if ($this->reserved->isReserved(PublicPath::firstSegment($path))) {
            return null;
        }

        $address = $this->paths->find($path);
        if (!$address instanceof Address) {
            return null;
        }

        if ($path !== $requested) {
            // Decision R5: one spelling answers and the others lead to it.
            // The redirect is only offered once something is known to answer
            // at the normalised address, so a mistyped one is a plain 404
            // rather than a redirect to a second mistake.
            return $this->permanentlyTo($path);
        }

        if ($address->hasMoved()) {
            return $this->permanentlyTo($address->movedTo ?? $path);
        }

        $contentType = $this->types->drawnBy($address->ref->type);

        return $contentType instanceof ContentType ? [
            'presenter' => $contentType->presenter,
            'action' => $contentType->action,
            self::PATH => $address->path,
            self::CONTENT_ID => $address->ref->id,
        ] : null;
    }

    /** @param array<string, mixed> $params */
    public function constructUrl(array $params, UrlScript $refUrl): ?string
    {
        $presenter = $params['presenter'] ?? null;
        $action = $params['action'] ?? 'default';
        if (!is_string($presenter) || !is_string($action)) {
            return null;
        }

        $type = $this->types->typeOf($presenter, $action);
        if ($type === null) {
            return null;
        }

        $id = $params[self::CONTENT_ID] ?? null;
        if (!is_string($id) && !is_int($id)) {
            return null;
        }

        $ref = new ContentRef($type, (string) $id);
        $path = $this->addressUsed($params, $ref) ?? $this->paths->canonicalPathOf($ref);
        if ($path === null) {
            return null;
        }

        $query = array_filter(
            array_diff_key($params, array_flip(['presenter', 'action', self::PATH, self::CONTENT_ID])),
            static fn(mixed $value): bool => $value !== null,
        );

        return $refUrl->getBaseUrl() . $path . ($query === [] ? '' : '?' . http_build_query($query));
    }

    /**
     * The address named in $params, if it is one this content really answers
     * at. Anything else - a stale address, one belonging to something else -
     * is ignored in favour of the permalink.
     *
     * @param array<string, mixed> $params
     */
    private function addressUsed(array $params, ContentRef $ref): ?string
    {
        $path = $params[self::PATH] ?? null;
        if (!is_string($path)) {
            return null;
        }

        $address = $this->paths->find($path);

        return $address instanceof Address && !$address->hasMoved() && $address->ref->equals($ref) ? $path : null;
    }

    /** @return array<string, mixed> */
    private function permanentlyTo(string $path): array
    {
        return [
            'presenter' => RedirectPresenter::NAME,
            'action' => 'default',
            RedirectPresenter::TARGET => $path,
        ];
    }
}

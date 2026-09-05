<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Routing;

use Nette\Http\Request;
use Nette\Http\UrlScript;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Content\Address;
use Trilobit\Core\Content\ContentTypes;
use Trilobit\Core\Content\PathLookup;
use Trilobit\Core\Content\ReservedSegments;
use Trilobit\Core\Contract\Content\ContentRef;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Core\Routing\AdminRoutes;
use Trilobit\Core\Routing\ContentRouter;
use Trilobit\Core\Routing\StyleguideRoutes;
use Trilobit\Tests\Double\RecordingPathLookup;

/**
 * When the catch-all is allowed to reach the register, and when it must not
 * be.
 *
 * The register is a database table, so "must not be" is a claim about a query
 * that has to not happen - which is why the lookup below refuses to answer at
 * all rather than answering emptily. An address under a beginning something
 * else answers at can never be in the register, so asking is not merely
 * wasteful: it is a query on every request for a path that will never be
 * content, and in a build whose schema is not there yet it turns a page that
 * should end as 404 into an error.
 *
 * That is not hypothetical. It is the regression this class exists for: the
 * style guide's own path begins with an underscore, on purpose, so that no
 * page or product can ever be called that - and normalising an address rewrites
 * anything outside the English alphabet, so `_styleguide` reached the reserved
 * list spelled `styleguide` and stopped matching it.
 */
#[CoversClass(ContentRouter::class)]
final class ContentRouterTest extends TestCase
{
    public function testAReservedBeginningIsRefusedWithoutTouchingTheRegister(): void
    {
        $router = $this->router($this->lookupThatRefusesToBeAsked());

        self::assertNull($router->match($this->request('/' . StyleguideRoutes::PATH)));
        self::assertNull($router->match($this->request('/' . AdminRoutes::PATH)));
        self::assertNull($router->match($this->request('/' . AdminRoutes::PATH . '/reports')));
    }

    /**
     * Including in the spellings that are answered with a redirect elsewhere.
     * A reserved beginning is reserved however it was typed, or the check
     * would be one capital letter away from being skipped.
     */
    public function testAReservedBeginningIsRefusedInAnySpelling(): void
    {
        $router = $this->router($this->lookupThatRefusesToBeAsked());

        self::assertNull($router->match($this->request('/' . strtoupper(AdminRoutes::PATH))));
        self::assertNull($router->match($this->request('/' . StyleguideRoutes::PATH . '/')));
        self::assertNull($router->match($this->request('/' . StyleguideRoutes::PATH . '/anything')));
    }

    /**
     * The other half, so that the two above are not passing because the router
     * refuses everything: an address nobody reserved is looked up, and the
     * lookup is what decides.
     */
    public function testAnAddressNobodyReservedIsLookedUp(): void
    {
        $register = new RecordingPathLookup();

        self::assertNull($this->router($register)->match($this->request('/bikes/mountain')));
        self::assertSame(['bikes/mountain'], $register->asked());
    }

    /** The root is a static route, never a row, so it is not looked up either. */
    public function testTheRootIsNotLookedUp(): void
    {
        self::assertNull($this->router($this->lookupThatRefusesToBeAsked())->match($this->request('/')));
    }

    private function router(PathLookup $paths): ContentRouter
    {
        return new ContentRouter(
            $paths,
            new ContentTypes([]),
            ReservedSegments::of(ModuleList::of([], dirname(__DIR__, 5)), []),
        );
    }

    private function request(string $path): Request
    {
        return new Request(new UrlScript('http://localhost' . $path, '/'));
    }

    /**
     * A register that cannot be read, so that reaching it is a failure with a
     * sentence on it rather than an empty answer that looks like agreement.
     */
    private function lookupThatRefusesToBeAsked(): PathLookup
    {
        return new class implements PathLookup {
            public function find(string $path): ?Address
            {
                throw new \LogicException(sprintf("the register was asked about '%s', and must not have been", $path));
            }

            public function canonicalPathOf(ContentRef $ref): ?string
            {
                throw new \LogicException('the register was asked for a permalink, and must not have been');
            }
        };
    }
}

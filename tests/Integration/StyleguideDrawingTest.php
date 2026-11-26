<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration;

use Nette\Application\BadRequestException;
use Nette\Application\IPresenterFactory;
use Nette\Application\Request;
use Nette\Application\Response;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Presenter;
use Nette\DI\Container;
use Nette\Http\IResponse;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Double\RecordingHttpResponse;

/**
 * The invented pictures the style guide draws for the specimens of
 * c-lightbox, through a signal of its own presenter: a picture it draws is
 * answered as an SVG file, and a shape it does not draw is not found rather
 * than drawn as something else.
 */
#[CoversNothing]
final class StyleguideDrawingTest extends TestCase
{
    private const string PRESENTER = 'Core:Styleguide:Overview';

    private ?Container $container = null;

    protected function tearDown(): void
    {
        $this->container = null;
    }

    public function testAPictureItDrawsIsAnsweredAsAnSvgFile(): void
    {
        $response = $this->drawing('upright');

        self::assertInstanceOf(TextResponse::class, $response);
        $source = $response->getSource();
        self::assertIsString($source);
        self::assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg" width="1000" height="1600"', $source);

        $sent = $this->container()->getByType(IResponse::class);
        self::assertInstanceOf(RecordingHttpResponse::class, $sent);
        self::assertStringStartsWith('image/svg+xml', (string) $sent->getHeader('Content-Type'));
    }

    public function testAShapeItDoesNotDrawIsNotFound(): void
    {
        try {
            $this->drawing('round');
            self::fail('a shape the guide does not draw was answered');
        } catch (BadRequestException $notFound) {
            self::assertSame(404, $notFound->getHttpCode());
        }
    }

    private function drawing(string $shape): Response
    {
        $presenter = $this->container()->getByType(IPresenterFactory::class)->createPresenter(self::PRESENTER);
        self::assertInstanceOf(Presenter::class, $presenter);
        $presenter->autoCanonicalize = false;

        return $presenter->run(new Request(self::PRESENTER, 'GET', ['action' => 'default', 'do' => 'drawing', 'shape' => $shape]));
    }

    private function container(): Container
    {
        return $this->container ??= Boot::container(
            ModuleList::of([], Bootstrap::rootDirectory()),
            styleguide: true,
            config: ['services' => ['http.response' => ['factory' => RecordingHttpResponse::class]]],
        );
    }
}

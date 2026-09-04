<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Presentation\Design;

use Nette\Utils\FileSystem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Presentation\Design\DesignSystem;

#[CoversClass(DesignSystem::class)]
final class DesignSystemTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/trilobit-design-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        FileSystem::delete($this->root);
    }

    public function testItReadsTheThemesOffTheFilesystem(): void
    {
        $this->givenThemes('atrium', 'ledger');

        self::assertSame(['atrium', 'ledger'], DesignSystem::of($this->root, 'atrium')->themes);
    }

    public function testTheThemesComeBackInAStableOrder(): void
    {
        $this->givenThemes('zircon', 'amber', 'ledger');

        self::assertSame(['amber', 'ledger', 'zircon'], DesignSystem::of($this->root, 'amber')->themes);
    }

    public function testTheDefaultIsTheOneThatWasAskedFor(): void
    {
        $this->givenThemes('atrium', 'ledger');

        self::assertSame('ledger', DesignSystem::of($this->root, 'ledger')->defaultTheme);
    }

    /**
     * A default naming a theme that is not there would leave every page with no
     * token declared at all - which is not an error in CSS, only a page with no
     * colours. Refusing at startup is the difference between a message and a
     * mystery.
     */
    public function testADefaultThatNamesNoThemeIsRefused(): void
    {
        $this->givenThemes('atrium');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("The default theme is 'midnight'");

        DesignSystem::of($this->root, 'midnight');
    }

    public function testADirectoryWithNoThemeIsRefused(): void
    {
        $this->givenThemes();

        $this->expectException(\RuntimeException::class);

        DesignSystem::of($this->root, 'atrium');
    }

    /** The real assets/themes/ is what the application actually starts with. */
    public function testTheRealDirectoryHoldsTheConfiguredDefault(): void
    {
        $design = DesignSystem::of(Bootstrap::rootDirectory(), 'atrium');

        self::assertContains('atrium', $design->themes);
        self::assertContains('ledger', $design->themes);
    }

    private function givenThemes(string ...$names): void
    {
        FileSystem::createDir($this->root . '/' . DesignSystem::DIRECTORY);

        foreach ($names as $name) {
            FileSystem::write(
                $this->root . '/' . DesignSystem::DIRECTORY . '/' . $name . '.css',
                sprintf("[data-theme='%s'] { --color-canvas: #fff; }\n", $name),
            );
        }
    }
}

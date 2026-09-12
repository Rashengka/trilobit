<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture;

use Nette\Utils\FileSystem;
use Nette\Utils\Finder;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;

/**
 * The control that deletes something is drawn as c-button's danger variant,
 * wherever a form has one.
 *
 * Deleting cannot be taken back, and a delete button drawn as the quiet
 * alternative beside Save looks like one more harmless choice. The danger
 * variant exists for exactly this control, so every form's delete button -
 * found by the name the form gives it, n:name="delete" - has to carry it, and
 * the next form that grows one is held to the same.
 *
 * The rule reads the templates rather than rendered pages: a form's controls
 * are its own elements and carry the component's class as written (see the
 * comment beside them in the CMS templates), so the source is where the class
 * is decided. The last case runs the rule over a button drawn the old way.
 */
#[CoversNothing]
final class DeletingIsDrawnAsDangerTest extends TestCase
{
    public function testEveryDeleteButtonIsDrawnAsDanger(): void
    {
        $found = [];
        $wrong = [];
        foreach (Finder::findFiles('*.latte')->from(Bootstrap::rootDirectory() . '/src') as $file) {
            foreach ($this->deleteButtonsIn(FileSystem::read((string) $file)) as $button) {
                $found[] = $button;
                if (!$this->isDanger($button)) {
                    $wrong[] = substr((string) $file, strlen(Bootstrap::rootDirectory()) + 1) . ': ' . $button;
                }
            }
        }

        self::assertNotSame([], $found, 'no template has a delete button, so the rule looked in the wrong place');
        self::assertSame([], $wrong);
    }

    public function testTheRuleReportsADeleteButtonDrawnAsQuiet(): void
    {
        $buttons = $this->deleteButtonsIn(
            '<button n:name="send" class="c-button">Save</button>'
            . '<button n:name="delete" class="c-button c-button--quiet">Delete this page</button>',
        );

        self::assertCount(1, $buttons);
        self::assertFalse($this->isDanger($buttons[0]));
        self::assertTrue($this->isDanger('<button n:name="delete" class="c-button c-button--danger">'));
    }

    /** @return list<string> the opening tag of every button the form names delete */
    private function deleteButtonsIn(string $source): array
    {
        preg_match_all('/<button\b[^>]*\bn:name="delete"[^>]*>/', $source, $matches);

        return $matches[0];
    }

    private function isDanger(string $button): bool
    {
        return preg_match('/\bclass="[^"]*\bc-button--danger\b[^"]*"/', $button) === 1;
    }
}

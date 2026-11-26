<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Component\ComponentRegistry;

/**
 * c-lightbox is a list of small pictures, each a button opening the
 * browser's own dialog with the picture large in it. What the markup has to
 * carry for that to work with no script is held here: every button names its
 * dialog and the command, every dialog is named by its caption and its place,
 * is drawn closed, and leads to the picture at full size. Going from one to
 * another, and the focus coming back, is measured in tests/e2e/lightbox.spec.ts.
 */
#[CoversNothing]
final class LightboxTest extends TestCase
{
    private const string COMPONENT = 'c-lightbox';

    private const string PICTURE = "new Trilobit\\Core\\Presentation\\Component\\LightboxPicture('%s', 'small-%1\$s.jpg', 24, 16, 'large-%1\$s.jpg', 1600, 1000, '%s', '%s', 'full-%1\$s.jpg')";

    public function testThePicturesAreAnOrderedListNamedByTheCaller(): void
    {
        $list = $this->lightbox()->querySelector('.c-lightbox__items');

        self::assertNotNull($list);
        self::assertSame('ol', $list->localName);
        self::assertSame('Specimens', $list->getAttribute('aria-label'));
        self::assertCount(3, $this->all($list, '.c-lightbox__item'));
    }

    /** With Invoker Commands the button opens its dialog without any script; see assets/dialog.ts for without. */
    public function testEverySmallPictureIsAButtonOpeningItsOwnDialog(): void
    {
        $lightbox = $this->lightbox();

        foreach ($this->all($lightbox, '.c-lightbox__opener') as $opener) {
            self::assertSame('button', $opener->localName);
            self::assertSame('button', $opener->getAttribute('type'));
            self::assertSame('show-modal', $opener->getAttribute('command'));

            $dialog = $lightbox->querySelector('#' . $opener->getAttribute('commandfor'));
            self::assertNotNull($dialog, 'a button opens a dialog that is not there');
            self::assertSame('dialog', $dialog->localName);
        }
    }

    /** Named for what pressing it does, and by its place where nothing says what the picture is. */
    public function testEverySmallPictureIsNamedForWhatPressingItDoes(): void
    {
        self::assertSame(
            ['Enlarge: Laid flat', 'Enlarge: Standing up', 'Enlarge picture 3 of 3'],
            array_map(static fn(Element $opener): ?string => $opener->getAttribute('aria-label'), $this->all($this->lightbox(), '.c-lightbox__opener')),
        );
    }

    /** A dialog drawn open would not be a modal: nothing behind it inert, and Escape doing nothing. */
    public function testEveryDialogIsDrawnClosedAndNamedByItsCaptionAndItsPlace(): void
    {
        $document = $this->document();
        $names = [];
        foreach ($this->all($document, 'dialog.c-lightbox__dialog') as $dialog) {
            self::assertFalse($dialog->hasAttribute('open'), 'a dialog is drawn open');

            $name = [];
            foreach (explode(' ', (string) $dialog->getAttribute('aria-labelledby')) as $id) {
                $name[] = trim((string) $document->getElementById($id)?->textContent);
            }

            $names[] = implode(', ', $name);
        }

        self::assertSame(['Laid flat, 1 of 3', 'Standing up, 2 of 3', 'Picture, 3 of 3'], $names);
    }

    public function testEveryDialogShowsThePictureLargeAndLeadsToItAtFullSize(): void
    {
        $dialogs = $this->all($this->lightbox(), 'dialog.c-lightbox__dialog');

        self::assertSame(['large-flat.jpg', 'large-upright.jpg', 'large-square.jpg'], array_map(
            static fn(Element $dialog): ?string => $dialog->querySelector('img.c-lightbox__image')?->getAttribute('src'),
            $dialogs,
        ));
        self::assertSame(['full-flat.jpg', 'full-upright.jpg', 'full-square.jpg'], array_map(
            static fn(Element $dialog): ?string => $dialog->querySelector('a.c-lightbox__full')?->getAttribute('href'),
            $dialogs,
        ));
        self::assertSame(['A specimen laid flat', 'The specimen standing', ''], array_map(
            static fn(Element $dialog): ?string => $dialog->querySelector('img.c-lightbox__image')?->getAttribute('alt'),
            $dialogs,
        ));
    }

    /** The cross is a close command for its own dialog, and the focus starts on it. */
    public function testEveryDialogIsClosedByItsCross(): void
    {
        foreach ($this->all($this->lightbox(), 'dialog.c-lightbox__dialog') as $dialog) {
            $close = $dialog->querySelector('.c-close');
            self::assertNotNull($close);
            self::assertSame('close', $close->getAttribute('command'));
            self::assertSame($dialog->getAttribute('id'), $close->getAttribute('commandfor'));
            self::assertTrue($close->hasAttribute('autofocus'));
        }
    }

    public function testTheWaysBackAndOnAreButtonsNamedForWhereTheyGo(): void
    {
        foreach ($this->all($this->lightbox(), 'dialog.c-lightbox__dialog') as $dialog) {
            foreach (['.c-lightbox__previous' => 'Previous picture', '.c-lightbox__next' => 'Next picture'] as $selector => $name) {
                $button = $dialog->querySelector($selector);
                self::assertNotNull($button, 'there is no ' . $selector);
                self::assertSame('button', $button->getAttribute('type'));
                self::assertSame($name, trim((string) preg_replace('/\s+/', ' ', $button->textContent ?? '')));
            }
        }
    }

    /** A single picture has no other to go to, and is not offered a way to one. */
    public function testASinglePictureHasNoWayToAnother(): void
    {
        $lightbox = ComponentRendering::render(
            'lightbox.latte',
            sprintf("{include lightbox, id: 'shown', label: 'One', pictures: [%s]}", sprintf(self::PICTURE, 'flat', 'A specimen', 'Laid flat')),
        )->querySelector('.c-lightbox');

        self::assertNotNull($lightbox);
        self::assertNull($lightbox->querySelector('.c-lightbox__controls'));
        self::assertNotNull($lightbox->querySelector('dialog .c-lightbox__full'));
    }

    /** What the caller hangs under a picture is drawn under it, knowing which picture and which place. */
    public function testWhatTheCallerDrawsUnderEachPictureKnowsWhichItIs(): void
    {
        $document = ComponentRendering::render(
            'lightbox.latte',
            "{embed block lightbox, id: 'shown', label: 'Specimens', pictures: " . $this->pictures() . '}'
                . '{block lightboxItem}<p class="under">{$position}: {$picture->key}</p>{/block}'
                . '{/embed}',
        );

        self::assertSame(['1: flat', '2: upright', '3: square'], array_map(
            static fn(Element $under): string => trim((string) $under->textContent),
            $this->all($document, '.c-lightbox__item .under'),
        ));
    }

    public function testTheStyleGuideShowsEveryVariant(): void
    {
        self::assertSame(
            ['default', 'with something under each picture', 'with a single picture', 'with a long caption', 'with more pictures than a row holds'],
            new ComponentRegistry()->find(self::COMPONENT)?->variants,
        );
    }

    private function lightbox(): Element
    {
        $lightbox = $this->document()->querySelector('.c-lightbox');
        self::assertNotNull($lightbox, 'c-lightbox drew nothing carrying .c-lightbox');

        return $lightbox;
    }

    private function document(): HTMLDocument
    {
        return ComponentRendering::render(
            'lightbox.latte',
            "{include lightbox, id: 'shown', label: 'Specimens', pictures: " . $this->pictures() . '}',
        );
    }

    /** Three pictures as a Latte expression: two captioned, the last saying nothing of what it shows. */
    private function pictures(): string
    {
        return '[' . implode(', ', [
            sprintf(self::PICTURE, 'flat', 'A specimen laid flat', 'Laid flat'),
            sprintf(self::PICTURE, 'upright', 'The specimen standing', 'Standing up'),
            sprintf(self::PICTURE, 'square', '', ''),
        ]) . ']';
    }

    /** @return list<Element> */
    private function all(Element|HTMLDocument $root, string $selector): array
    {
        $found = [];
        foreach ($root->querySelectorAll($selector) as $element) {
            $found[] = $element;
        }

        return $found;
    }
}

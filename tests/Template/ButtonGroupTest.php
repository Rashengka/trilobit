<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Component\Choices;

/**
 * c-button-group as markup, both ways it comes.
 *
 * A group of buttons is a group with a name, and the buttons in it are c-button.
 * A group of choices, of which one is on, is a set of radio buttons - the real
 * ones, so that the arrow keys, the form it is in and a screen reader all know
 * what it is without a line of script. Drawing buttons and telling assistive
 * technology they are radios would be a copy of all of that, kept in step by
 * hand. What the keys do is measured in tests/e2e/components-static.spec.ts.
 */
#[CoversClass(Choices::class)]
final class ButtonGroupTest extends TestCase
{
    public function testAGroupOfButtonsIsANamedGroup(): void
    {
        $page = ComponentRendering::render(
            'button-group.latte',
            <<<'LATTE'
                {import 'button.latte'}
                {embed block buttonGroup, label: 'Order', testId: 'order'}
                    {block buttonGroupBody}
                        {include button, label: 'Save', variant: 'quiet'}
                        {include button, label: 'Send', variant: 'quiet'}
                    {/block}
                {/embed}
                LATTE,
        );
        $group = $this->group($page);

        self::assertSame('group', $group->getAttribute('role'));
        self::assertSame('Order', $group->getAttribute('aria-label'));
        self::assertSame('order', $group->getAttribute('data-testid'));
        self::assertCount(2, $page->querySelectorAll('.c-button-group > button.c-button'));
        self::assertNull($group->querySelector('input'));
    }

    public function testAGroupOfChoicesIsARadioGroupOfRealRadios(): void
    {
        $group = $this->group($this->choices());

        self::assertSame('radiogroup', $group->getAttribute('role'));
        self::assertSame('Alignment', $group->getAttribute('aria-label'));

        $inputs = $group->querySelectorAll('input');
        self::assertCount(3, $inputs);
        foreach ($inputs as $input) {
            self::assertSame('radio', $input->getAttribute('type'));
            self::assertSame('alignment', $input->getAttribute('name'), 'radios under different names are not one choice');
        }

        self::assertNull($group->querySelector('[role="radio"], [aria-pressed], [aria-checked]'), 'a radio drawn with ARIA');
    }

    /** Each answer is written inside its label, which is what a click on the words selects and a screen reader names it by. */
    public function testEveryRadioIsNamedByTheLabelItIsIn(): void
    {
        $labels = [];
        foreach ($this->group($this->choices())->querySelectorAll('label') as $label) {
            self::assertNotNull($label->querySelector('input[type="radio"]'));
            $labels[] = trim((string) $label->textContent);
        }

        self::assertSame(['Left', 'Centre', 'Right'], $labels);
    }

    public function testTheAnswerGivenIsTheOneChecked(): void
    {
        $checked = $this->group($this->choices())->querySelectorAll('input[checked]');

        self::assertCount(1, $checked);
        $input = $checked->item(0);
        self::assertInstanceOf(Element::class, $input);
        self::assertSame('centre', $input->getAttribute('value'));
    }

    /**
     * A key PHP turned into a number is still the answer that was asked for,
     * so a set of choices counted 1, 2, 3 checks the one it was told to.
     */
    public function testANumericAnswerIsCheckedToo(): void
    {
        $page = ComponentRendering::render(
            'button-group.latte',
            "{include buttonGroup, label: 'Stars', choices: \$choices}",
            ['choices' => new Choices('stars', ['1' => 'One', '2' => 'Two'], '2')],
        );

        $input = $page->querySelector('input[checked]');
        self::assertNotNull($input);
        self::assertSame('2', $input->getAttribute('value'));
    }

    /**
     * Radios with no name are not one choice: a browser lets every one of them
     * be checked at once, and the group looks exactly like one that works.
     */
    public function testChoicesWithoutANameAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Choices('', ['s' => 'Small']);
    }

    /** An answer said to be on that is not one of the answers would leave none of them on. */
    public function testAnAnswerThatIsNotOneOfThemIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Choices('size', ['s' => 'Small', 'm' => 'Medium'], 'xl');
    }

    public function testChoicesWithNothingToChooseAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Choices('size', []);
    }

    private function choices(): HTMLDocument
    {
        return ComponentRendering::render(
            'button-group.latte',
            "{include buttonGroup, label: 'Alignment', choices: \$choices}",
            ['choices' => new Choices('alignment', ['left' => 'Left', 'centre' => 'Centre', 'right' => 'Right'], 'centre')],
        );
    }

    private function group(HTMLDocument $page): Element
    {
        $group = $page->querySelector('.c-button-group');
        self::assertNotNull($group, 'c-button-group drew nothing with its class');

        return $group;
    }
}

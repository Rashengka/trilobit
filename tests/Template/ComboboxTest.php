<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * c-combobox writes a plain select and marks it for assets/combobox.ts, and
 * writes nothing the caller did not ask for.
 *
 * The control in front of the select is drawn in the browser, so what the
 * server is responsible for is the select alone: every choice with the value
 * it sends, the one chosen, and the few attributes the script reads - the
 * mark, whether to offer a line to search by, and what describes the control.
 * An attribute written empty would be one the script reads as said: an empty
 * aria-describedby names nothing, and an empty data-combobox-search is
 * neither "on" nor "off".
 */
#[CoversNothing]
final class ComboboxTest extends TestCase
{
    public function testItWritesASelectMarkedForTheScriptWithEveryChoice(): void
    {
        $select = $this->selectIn($this->draw(
            "{include combobox, id: 'sample-period', name: 'period', "
            . "choices: ['' => '--- none', 'cambrian' => 'Cambrian', 'ordovician' => 'Ordovician'], "
            . "chosen: 'ordovician'}",
        ));

        self::assertSame('sample-period', $select->getAttribute('id'));
        self::assertSame('period', $select->getAttribute('name'));
        self::assertTrue($select->hasAttribute('data-combobox'));

        $choices = [];
        $chosen = [];
        foreach ($select->querySelectorAll('option') as $option) {
            $choices[$option->getAttribute('value') ?? ''] = trim($option->textContent ?? '');
            if ($option->hasAttribute('selected')) {
                $chosen[] = $option->getAttribute('value');
            }
        }

        self::assertSame(['' => '--- none', 'cambrian' => 'Cambrian', 'ordovician' => 'Ordovician'], $choices);
        self::assertSame(['ordovician'], $chosen);
    }

    public function testNothingTheCallerDidNotAskForIsWritten(): void
    {
        $select = $this->selectIn($this->draw(
            "{include combobox, id: 'sample-period', name: 'period', choices: ['cambrian' => 'Cambrian']}",
        ));

        foreach (['data-combobox-search', 'aria-describedby', 'aria-invalid', 'disabled', 'data-testid'] as $attribute) {
            self::assertFalse($select->hasAttribute($attribute), $attribute . ' was written without being asked for');
        }

        self::assertFalse($select->querySelector('option')?->hasAttribute('selected') ?? true);
    }

    public function testWhatTheCallerAsksForIsWritten(): void
    {
        $select = $this->selectIn($this->draw(
            "{include combobox, id: 'sample-drawer', name: 'drawer', choices: ['a1' => 'Drawer A1'], "
            . "search: 'off', describedBy: 'sample-error sample-hint', invalid: true, disabled: true, "
            . "testId: 'sample-combobox'}",
        ));

        self::assertSame('off', $select->getAttribute('data-combobox-search'));
        self::assertSame('sample-error sample-hint', $select->getAttribute('aria-describedby'));
        self::assertSame('true', $select->getAttribute('aria-invalid'));
        self::assertTrue($select->hasAttribute('disabled'));
        self::assertSame('sample-combobox', $select->getAttribute('data-testid'));
    }

    private function draw(string $call): HTMLDocument
    {
        return ComponentRendering::render('combobox.latte', $call);
    }

    private function selectIn(HTMLDocument $document): Element
    {
        $select = $document->querySelector('select');
        self::assertNotNull($select, 'c-combobox drew no select');

        return $select;
    }
}

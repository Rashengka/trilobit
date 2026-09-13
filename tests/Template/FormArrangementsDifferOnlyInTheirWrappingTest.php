<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Element;
use Dom\HTMLDocument;
use Latte\Engine;
use Nette\Application\UI\Form;
use Nette\Bridges\ApplicationLatte\LatteFactory;
use Nette\Forms\Controls\BaseControl;
use Nette\Forms\Rendering\DefaultFormRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Form\ArrangedFormRenderer;
use Trilobit\Core\Presentation\Form\ArrangementTemplate;
use Trilobit\Core\Presentation\Form\FormFactory;
use Trilobit\Core\Presentation\Form\FormField;
use Trilobit\Core\Presentation\Form\HorizontalFormRenderer;
use Trilobit\Core\Presentation\Form\InlineFormRenderer;
use Trilobit\Core\Presentation\Form\VerticalFormRenderer;
use Trilobit\Tests\Boot;

/**
 * The arrangements of a generated form differ in how the controls are laid out,
 * and in nothing else.
 *
 * Each arrangement is a template of its own (.ai/plans/19, variant C), which is
 * what lets each have the structure it needs - and also what would let three
 * templates drift apart without anything failing: one forgetting to say why an
 * answer was refused, another dropping a hidden input into its grid. So one
 * form, with every kind of control the design system draws, is drawn in every
 * arrangement, and what a person or the server can tell from it - which
 * controls there are and under which names, what they are called, what is said
 * about them and joined to them, which are required, which hidden inputs go
 * with them and where - is read back and compared with what the form holds.
 * Each arrangement against the form rather than only against the others, so
 * that three arrangements all losing the same thing cannot pass by agreeing.
 *
 * The last cases run the reading over arrangements built to make the mistakes
 * it is there for (tests/Template/Fixtures/Form/): a gate that reports nothing
 * reads the same whether it works or looks in the wrong place.
 */
#[CoversClass(FormFactory::class)]
#[CoversClass(ArrangedFormRenderer::class)]
#[CoversClass(FormField::class)]
#[CoversClass(ArrangementTemplate::class)]
#[CoversClass(InlineFormRenderer::class)]
#[CoversClass(VerticalFormRenderer::class)]
#[CoversClass(HorizontalFormRenderer::class)]
final class FormArrangementsDifferOnlyInTheirWrappingTest extends TestCase
{
    /**
     * What the sample form below says to anybody reading it, whichever way it
     * is laid out. Written out rather than read off one arrangement, so that
     * the arrangement is what is being checked and not what the check is made
     * of.
     */
    private const array EXPECTED = [
        // Every control, in the order of the form: element, type, name and -
        // for one choice among several - its value.
        'controls' => [
            'input[text] name',
            'input[email] curator',
            'input[number] bodyLength',
            'input[number] bodyWidth',
            'select period',
            'textarea notes',
            'input[checkbox] displayed',
            'input[radio] state=complete',
            'input[radio] state=fragment',
            'button[submit] save',
        ],
        // What each control is called: its label, or the words of the label
        // it is written inside, or the words on the button.
        'names' => [
            'name' => 'Name of the specimen',
            'curator' => "Curator's address",
            // In a row, the second one's label out of sight - and still its name.
            'bodyLength' => 'Length in millimetres',
            'bodyWidth' => 'Width in millimetres',
            'period' => 'Period',
            'notes' => 'Notes',
            'displayed' => 'On public display',
            'state=complete' => 'Complete',
            'state=fragment' => 'A fragment',
            'save' => 'Save',
        ],
        // A set of choices is named as a whole as well as one by one.
        'groups' => ['radiogroup: State of the specimen'],
        // The sentences each control names in aria-describedby, read off the
        // elements the ids lead to: a reason nobody is pointed at is lost to
        // somebody who cannot see that it is under the control.
        'descriptions' => [
            'curator' => ['Where questions about the specimen are sent.'],
            'bodyLength' => ['Measured along the axis of the body.'],
            'bodyWidth' => ['A width has to be at least one millimetre.'],
            'period' => ['No drawer in the collection holds that period.'],
        ],
        'invalid' => ['bodyWidth', 'period'],
        'required' => ['name', 'bodyWidth', 'state=complete', 'state=fragment'],
        // Said about the whole form, above every arrangement.
        'reasons' => ['The catalogue could not be saved just now.'],
        // Where the hidden inputs went: every one of them after the
        // arrangement, as a child of the form, and none inside it.
        'hidden' => ['drawer=B-12'],
        'hiddenInsideTheArrangement' => [],
        'hiddenBeforeTheArrangement' => [],
    ];

    /** @return iterable<string, array{string, \Closure(FormFactory): Form}> */
    public static function arrangements(): iterable
    {
        yield 'inline' => ['l-form--inline', static fn(FormFactory $forms): Form => $forms->createInline()];
        yield 'inline, with the labels unseen' => [
            'l-form--inline',
            static fn(FormFactory $forms): Form => $forms->createInline(labelsShown: false),
        ];
        yield 'vertical' => ['l-form--vertical', static fn(FormFactory $forms): Form => $forms->createVertical()];
        yield 'horizontal' => ['l-form--horizontal', static fn(FormFactory $forms): Form => $forms->createHorizontal()];
    }

    /** @param \Closure(FormFactory): Form $create */
    #[DataProvider('arrangements')]
    public function testItSaysWhatTheFormHolds(string $arrangement, \Closure $create): void
    {
        $page = $this->draw($this->sample($create($this->forms())));

        self::assertSame(self::EXPECTED, $this->read($page));
    }

    /**
     * The one thing that may differ is the wrapping, and it has to: an
     * arrangement drawn in the wrapping of another is the mistake nobody sees
     * until the page is open.
     *
     * @param \Closure(FormFactory): Form $create
     */
    #[DataProvider('arrangements')]
    public function testItIsWrappedInItsOwnArrangement(string $arrangement, \Closure $create): void
    {
        $page = $this->draw($this->sample($create($this->forms())));

        $wrappings = $page->querySelectorAll('form > .l-form');
        self::assertCount(1, $wrappings, 'the form is not one arrangement');

        $wrapping = $wrappings->item(0);
        self::assertInstanceOf(Element::class, $wrapping);
        self::assertSame('l-form ' . $arrangement, $wrapping->getAttribute('class'));
    }

    /**
     * Unseen is not gone: a label taken off the page for the eye stays in it
     * for everybody else. The reading above already holds that every control
     * is still named; this holds that the labels really are out of sight -
     * and that they are only where they were asked to be.
     *
     * @param \Closure(FormFactory): Form $create
     */
    #[DataProvider('arrangements')]
    public function testTheLabelsAreUnseenOnlyWhereTheyWereAskedToBe(string $arrangement, \Closure $create): void
    {
        $page = $this->draw($this->sample($create($this->forms())));

        // Inside a row every label is out of sight in every arrangement, which
        // testARowIsNamedByTheLabelOfItsFirstControl() holds; here it is the
        // labels the arrangement decides about.
        $unseen = [];
        foreach ($page->querySelectorAll('.c-field__label') as $label) {
            if (trim($label->textContent ?? '') === '' || $label->closest('.l-form__row') instanceof Element) {
                continue;
            }

            $unseen[] = $label->classList->contains('u-visually-hidden');
        }

        self::assertNotSame([], $unseen, 'no field carries a label to look at');
        self::assertSame(
            array_fill(0, count($unseen), $this->dataName() === 'inline, with the labels unseen'),
            $unseen,
        );
    }

    /**
     * The three required fields of the sample - a line of text, a row whose
     * second control is required, and a set of choices, each with a label
     * that can be seen - carry a mark, whether or not the arrangement draws it
     * where it can be seen: the mark lives inside the same element as the
     * label, so it is only ever hidden along with it, never on its own.
     *
     * The sentence explaining what the mark means is drawn once, above the
     * fields, and only where at least one mark can be seen - a form whose
     * labels are all out of sight has no mark to explain either.
     *
     * @param \Closure(FormFactory): Form $create
     */
    #[DataProvider('arrangements')]
    public function testRequiredFieldsCarryAMarkAndTheFormExplainsItWhereItCanBeSeen(
        string $arrangement,
        \Closure $create,
    ): void {
        $page = $this->draw($this->sample($create($this->forms())));
        $labelsShown = $this->dataName() !== 'inline, with the labels unseen';

        $marks = $page->querySelectorAll('.c-field__required');
        self::assertCount(3, $marks, 'the three required, labelled fields do not each carry one mark');
        foreach ($marks as $mark) {
            self::assertInstanceOf(Element::class, $mark);
            self::assertSame('true', $mark->getAttribute('aria-hidden'), 'the mark says its own word to a screen reader');

            $wrapper = $mark->closest('.c-field__label');
            self::assertInstanceOf(Element::class, $wrapper, 'the mark is not inside the label it belongs to');
            self::assertSame($labelsShown, !$wrapper->classList->contains('u-visually-hidden'));
        }

        self::assertCount($labelsShown ? 1 : 0, $page->querySelectorAll('.c-field-required-note'));
    }

    /**
     * A row: controls laid out beside each other as one field, under the
     * label of the first (.ai/plans/19, step 5), written with the framework's
     * own nextTo option - the one DefaultFormRenderer draws the same way.
     *
     * Every control in it keeps a name of its own: the label of every other
     * one is taken out of sight and not out of the page. What is said about a
     * control stays with that control - under it, inside the row, and named in
     * its aria-describedby, which the reading above already holds - rather
     * than being gathered under the whole row, where it would say nothing
     * about which control it is about to somebody looking at it. And the
     * label that can be seen carries the mark where any control in the row
     * is required, because it is the only label the eye has for the row.
     *
     * @param \Closure(FormFactory): Form $create
     */
    #[DataProvider('arrangements')]
    public function testARowIsNamedByTheLabelOfItsFirstControl(string $arrangement, \Closure $create): void
    {
        $page = $this->draw($this->sample($create($this->forms())));
        $labelsShown = $this->dataName() !== 'inline, with the labels unseen';

        $rows = $page->querySelectorAll('form > .l-form > .c-field > .c-field__control > .l-form__row');
        self::assertCount(1, $rows, 'the row is not drawn as one field among the others');
        $row = $rows->item(0);
        self::assertInstanceOf(Element::class, $row);

        // Both controls, in the order of the form, and nothing else.
        $inRow = [];
        foreach ($row->querySelectorAll('input, select, textarea') as $control) {
            $inRow[] = $control->getAttribute('name');
        }
        self::assertSame(['bodyLength', 'bodyWidth'], $inRow);

        // The field of the row is labelled by the first control's label,
        // marked because the second one is required, and seen where the
        // arrangement shows labels at all.
        $field = $row->parentElement?->parentElement;
        self::assertInstanceOf(Element::class, $field);
        $label = $this->childOf($field, 'c-field__label');
        self::assertSame('Length in millimetres*', $this->text($label));
        self::assertSame($labelsShown, !$label->classList->contains('u-visually-hidden'));
        self::assertCount(1, $label->querySelectorAll('.c-field__required'));

        $length = $row->querySelector('[name="bodyLength"]');
        self::assertInstanceOf(Element::class, $length);
        $pointing = $page->querySelectorAll(sprintf('label[for="%s"]', $length->getAttribute('id')));
        self::assertCount(1, $pointing, 'the first control is not named by one label');
        self::assertSame($label, $pointing->item(0)?->parentElement, 'the first control is named by a label other than the row\'s');

        // Every label inside the row is out of sight, and none of them is
        // marked - a mark in a label nobody sees is a mark nobody sees.
        foreach ($row->querySelectorAll('.c-field__label') as $inside) {
            self::assertTrue($inside->classList->contains('u-visually-hidden'), 'a label inside the row can be seen');
        }
        self::assertCount(0, $row->querySelectorAll('.c-field__required'));

        // The reason and the hint are each under their own control, in the
        // same field of the row, and not under the row as a whole.
        foreach (['bodyLength' => 'c-field__hint', 'bodyWidth' => 'c-field__error'] as $name => $sentence) {
            $control = $row->querySelector(sprintf('[name="%s"]', $name));
            self::assertInstanceOf(Element::class, $control);
            $own = $control->closest('.c-field');
            self::assertInstanceOf(Element::class, $own);
            self::assertSame($row, $own->parentElement, sprintf('%s is not a field of the row', $name));
            $this->childOf($own, $sentence);
        }
        foreach (['c-field__error', 'c-field__hint'] as $sentence) {
            foreach ($this->childrenOf($field) as $child) {
                self::assertFalse($child->classList->contains($sentence), 'something is said under the row as a whole');
            }
        }
    }

    /**
     * A row that cannot be drawn as one is refused where it is drawn, and by
     * the name of what is wrong with it. Each of these would otherwise be
     * drawn as something - a control twice, a control nowhere, a button among
     * the fields - with nothing on the page saying why.
     *
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function rowsThatCannotBeDrawn(): iterable
    {
        yield 'next to nothing' => [['name' => 'nobody'], 'nobody'];
        yield 'next to a button' => [['name' => 'save'], 'save'];
        yield 'a button next to a control' => [['save' => 'name'], 'save'];
        yield 'next to a hidden input' => [['name' => 'drawer'], 'drawer'];
        yield 'two controls next to one' => [['name' => 'notes', 'curator' => 'notes'], 'notes'];
        yield 'next to each other in a circle' => [['name' => 'curator', 'curator' => 'name'], 'curator'];
    }

    /** @param array<string, string> $nextTo which control is to be next to which */
    #[DataProvider('rowsThatCannotBeDrawn')]
    public function testARowThatCannotBeDrawnIsRefused(array $nextTo, string $named): void
    {
        $form = $this->sample($this->forms()->createVertical());
        foreach ($nextTo as $name => $next) {
            $control = $form->getComponent($name);
            self::assertInstanceOf(BaseControl::class, $control);
            $control->setOption('nextTo', $next);
        }

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches(sprintf('/\b%s\b/', preg_quote($named, '/')));

        $this->draw($form);
    }

    /** Two forms made by the factory are two forms, not one handed out twice. */
    public function testEveryFormItMakesIsANewOne(): void
    {
        $forms = $this->forms();

        self::assertNotSame($forms->createVertical(), $forms->createVertical());
        self::assertNotSame($forms->create(), $forms->create());
    }

    /**
     * create() is the framework's own form, drawn by the framework's own
     * renderer - the arrangements are something asked for, not something every
     * form is quietly given.
     */
    public function testThePlainFormKeepsTheFrameworksRenderer(): void
    {
        self::assertInstanceOf(
            DefaultFormRenderer::class,
            $this->forms()->create()->getRenderer(),
        );
    }

    public function testTheReadingReportsAnArrangementThatForgetsTheReason(): void
    {
        $read = $this->read($this->draw($this->sample($this->broken('forgets-the-reason.latte'))));

        // The controls still say they were refused, and the sentences saying
        // why are nowhere their aria-describedby leads.
        self::assertNotSame(self::EXPECTED, $read);
        self::assertSame(['bodyWidth', 'period'], $read['invalid']);
        self::assertArrayNotHasKey('bodyWidth', $read['descriptions']);
        self::assertArrayNotHasKey('period', $read['descriptions']);
    }

    /**
     * A control an arrangement leaves out is refused where it is drawn rather
     * than left for this reading to find: a form that silently does not send
     * one of its answers is the kind of failure nobody reports, because
     * nothing on the page looks wrong.
     */
    public function testAControlAnArrangementLeavesOutIsRefused(): void
    {
        $form = $this->sample($this->broken('leaves-a-control-out.latte'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/\bnotes\b/');

        $this->draw($form);
    }

    /**
     * The form every arrangement is asked to draw: one control of every kind
     * the design system has an opinion about, two refused, two with a hint,
     * three required, one hidden, two in a row - the first with a hint, the
     * second required and refused - and a reason about the form as a whole.
     *
     * The content is invented and has to stay invented.
     */
    private function sample(Form $form): Form
    {
        $form->addText('name', 'Name of the specimen')
            ->setRequired('A specimen has to be called something.');
        $form->addEmail('curator', "Curator's address")
            ->setOption('description', 'Where questions about the specimen are sent.');
        $form->addInteger('bodyLength', 'Length in millimetres')
            ->setOption('description', 'Measured along the axis of the body.')
            ->setOption('nextTo', 'bodyWidth');
        $width = $form->addInteger('bodyWidth', 'Width in millimetres')
            ->setRequired('Say how wide the specimen is.');
        $period = $form->addSelect('period', 'Period', ['cambrian' => 'Cambrian', 'ordovician' => 'Ordovician']);
        $form->addTextArea('notes', 'Notes');
        $form->addCheckbox('displayed', 'On public display');
        $form->addRadioList('state', 'State of the specimen', ['complete' => 'Complete', 'fragment' => 'A fragment'])
            ->setRequired('Say what state the specimen is in.');
        $form->addHidden('drawer', 'B-12');
        $form->addSubmit('save', 'Save');

        $width->addError('A width has to be at least one millimetre.');
        $period->addError('No drawer in the collection holds that period.');
        $form->addError('The catalogue could not be saved just now.');

        return $form;
    }

    /** The one child of $element carrying $class. */
    private function childOf(Element $element, string $class): Element
    {
        $found = array_values(array_filter(
            $this->childrenOf($element),
            static fn(Element $child): bool => $child->classList->contains($class),
        ));

        self::assertCount(1, $found, sprintf('the field has no %s of its own', $class));

        return $found[0];
    }

    /** @return list<Element> the elements directly inside $element */
    private function childrenOf(Element $element): array
    {
        $children = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof Element) {
                $children[] = $child;
            }
        }

        return $children;
    }

    /**
     * Everything the reading compares, out of one drawn form.
     *
     * @return array{
     *     controls: list<string>,
     *     names: array<string, string>,
     *     groups: list<string>,
     *     descriptions: array<string, list<string>>,
     *     invalid: list<string>,
     *     required: list<string>,
     *     reasons: list<string>,
     *     hidden: list<string>,
     *     hiddenInsideTheArrangement: list<string>,
     *     hiddenBeforeTheArrangement: list<string>,
     * }
     */
    private function read(HTMLDocument $page): array
    {
        $form = $page->querySelector('form');
        self::assertInstanceOf(Element::class, $form, 'nothing was drawn as a form');

        $controls = $names = $descriptions = $invalid = $required = [];
        foreach ($form->querySelectorAll('input:not([type="hidden"]), select, textarea, button') as $control) {
            $key = $this->keyOf($control);

            $controls[] = $this->kindOf($control) . ' ' . $key;
            $names[$key] = $this->nameOf($control, $page);

            $described = $this->describedBy($control, $page);
            if ($described !== []) {
                $descriptions[$key] = $described;
            }

            if ($control->getAttribute('aria-invalid') === 'true') {
                $invalid[] = $key;
            }

            if ($control->hasAttribute('required')) {
                $required[] = $key;
            }
        }

        $groups = [];
        foreach ($form->querySelectorAll('[role="radiogroup"], [role="group"]') as $group) {
            $groups[] = $group->getAttribute('role') . ': ' . $this->textOfIds($group->getAttribute('aria-labelledby'), $page);
        }

        $reasons = [];
        foreach ($form->querySelectorAll('.l-form__errors .c-notice') as $notice) {
            $reasons[] = $this->text($notice);
        }

        $hidden = $inside = $before = [];
        $arrangementPassed = false;
        foreach ($form->childNodes as $child) {
            if (!$child instanceof Element) {
                continue;
            }

            if ($child->classList->contains('l-form')) {
                $arrangementPassed = true;

                continue;
            }

            if ($child->tagName === 'INPUT' && $child->getAttribute('type') === 'hidden') {
                if ($arrangementPassed) {
                    $hidden[] = $child->getAttribute('name') . '=' . $child->getAttribute('value');
                } else {
                    $before[] = $child->getAttribute('name') ?? '';
                }
            }
        }

        foreach ($form->querySelectorAll('.l-form input[type="hidden"]') as $input) {
            $inside[] = $input->getAttribute('name') ?? '';
        }

        return [
            'controls' => $controls,
            'names' => $names,
            'groups' => $groups,
            'descriptions' => $descriptions,
            'invalid' => $invalid,
            'required' => $required,
            'reasons' => $reasons,
            'hidden' => $hidden,
            'hiddenInsideTheArrangement' => $inside,
            'hiddenBeforeTheArrangement' => $before,
        ];
    }

    /** A control by its name, and by its value where it is one choice among several sent under that name. */
    private function keyOf(Element $control): string
    {
        $name = $control->getAttribute('name') ?? '';
        $type = $control->getAttribute('type');

        return $control->tagName === 'INPUT' && $type === 'radio'
            ? $name . '=' . $control->getAttribute('value')
            : $name;
    }

    private function kindOf(Element $control): string
    {
        $tag = strtolower($control->tagName);

        return in_array($tag, ['input', 'button'], true)
            ? sprintf('%s[%s]', $tag, $control->getAttribute('type') ?? '')
            : $tag;
    }

    /**
     * What the control is called, the three ways a form names one: a label
     * pointing at it, a label it is written inside, and the words on a button.
     */
    private function nameOf(Element $control, HTMLDocument $page): string
    {
        $id = $control->getAttribute('id');
        if ($id !== null && $id !== '') {
            $label = $page->querySelector(sprintf('label[for="%s"]', $id));
            if ($label instanceof Element) {
                return $this->text($label);
            }
        }

        $around = $control->closest('label');
        if ($around instanceof Element) {
            return $this->text($around);
        }

        return $control->tagName === 'BUTTON' ? $this->text($control) : '';
    }

    /** @return list<string> the text of every element the control names in aria-describedby, in order */
    private function describedBy(Element $control, HTMLDocument $page): array
    {
        $texts = [];
        foreach ($this->idsIn($control->getAttribute('aria-describedby')) as $id) {
            $described = $page->getElementById($id);
            $texts[] = $described instanceof Element ? $this->text($described) : '';
        }

        return array_values(array_filter($texts, static fn(string $text): bool => $text !== ''));
    }

    /** @return list<string> the ids an attribute such as aria-describedby names, in order */
    private function idsIn(?string $ids): array
    {
        $split = preg_split('/\s+/', $ids ?? '', -1, PREG_SPLIT_NO_EMPTY);

        return $split === false ? [] : $split;
    }

    private function textOfIds(?string $ids, HTMLDocument $page): string
    {
        $texts = [];
        foreach ($this->idsIn($ids) as $id) {
            $element = $page->getElementById($id);
            $texts[] = $element instanceof Element ? $this->text($element) : '';
        }

        return implode(' ', $texts);
    }

    private function text(Element $element): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $element->textContent ?? ''));
    }

    private function draw(Form $form): HTMLDocument
    {
        return HTMLDocument::createFromString(
            '<!DOCTYPE html><html lang="en"><body>' . $form->getRenderer()->render($form) . '</body></html>',
            LIBXML_NOERROR,
        );
    }

    private function forms(): FormFactory
    {
        return Boot::container()->getByType(FormFactory::class);
    }

    /** A form drawn by an arrangement written to make one mistake, out of tests/Template/Fixtures/Form/. */
    private function broken(string $file): Form
    {
        $latte = Boot::container()->getByType(LatteFactory::class)->create();
        $template = __DIR__ . '/Fixtures/Form/' . $file;

        $form = new Form();
        $form->setRenderer(new class ($latte, $template) extends ArrangedFormRenderer {
            public function __construct(Engine $latte, private readonly string $file)
            {
                parent::__construct($latte);
            }

            protected function template(): string
            {
                return $this->file;
            }
        });

        return $form;
    }
}

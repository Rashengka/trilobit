<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Element;
use Dom\HTMLDocument;
use Latte\Engine;
use Nette\Application\UI\Form;
use Nette\Bridges\ApplicationLatte\LatteFactory;
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
            'period' => ['No drawer in the collection holds that period.'],
        ],
        'invalid' => ['period'],
        'required' => ['name', 'state=complete', 'state=fragment'],
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

        $unseen = [];
        foreach ($page->querySelectorAll('.c-field__label') as $label) {
            if (trim($label->textContent ?? '') === '') {
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

        // The control still says it was refused, and the sentence saying why
        // is nowhere its aria-describedby leads.
        self::assertNotSame(self::EXPECTED, $read);
        self::assertSame(['period'], $read['invalid']);
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
     * the design system has an opinion about, one refused, one with a hint,
     * two required, one hidden - and a reason about the form as a whole.
     *
     * The content is invented and has to stay invented.
     */
    private function sample(Form $form): Form
    {
        $form->addText('name', 'Name of the specimen')
            ->setRequired('A specimen has to be called something.');
        $form->addEmail('curator', "Curator's address")
            ->setOption('description', 'Where questions about the specimen are sent.');
        $period = $form->addSelect('period', 'Period', ['cambrian' => 'Cambrian', 'ordovician' => 'Ordovician']);
        $form->addTextArea('notes', 'Notes');
        $form->addCheckbox('displayed', 'On public display');
        $form->addRadioList('state', 'State of the specimen', ['complete' => 'Complete', 'fragment' => 'A fragment'])
            ->setRequired('Say what state the specimen is in.');
        $form->addHidden('drawer', 'B-12');
        $form->addSubmit('save', 'Save');

        $period->addError('No drawer in the collection holds that period.');
        $form->addError('The catalogue could not be saved just now.');

        return $form;
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

<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Form;

use Latte\Engine;
use Nette\Application\UI\Form;
use Nette\Bridges\ApplicationLatte\LatteFactory;
use Nette\Forms\FormRenderer;

/**
 * Where a form comes from: the framework's own, or one already laid out in
 * one of the arrangements of the design system.
 *
 * The arrangement is chosen by which method is called and by nothing else, so
 * that moving a form from one arrangement to another is one word in the
 * presenter (.ai/plans/19, the condition the whole plan is finished on). The
 * renderer needs the Latte engine the application draws with - the
 * arrangements are templates that include the same components the pages do -
 * and handing it over is this class's job rather than every presenter's.
 *
 * create() is the framework's form with the framework's renderer, which is
 * what a form drawn by hand in its template (n:name, as the sign-in page does)
 * wants: a renderer is never asked there, so none is chosen for it.
 *
 * Every form is a new one: a form is a component of the presenter that makes
 * it, and one handed out twice would belong to two.
 */
final class FormFactory
{
    private ?Engine $latte = null;

    public function __construct(private readonly LatteFactory $latteFactory) {}

    public function create(): Form
    {
        return new Form();
    }

    /**
     * The controls side by side in a row, wrapping where it runs out - the
     * shape of a filter over a table.
     *
     * @param bool $labelsShown whether the labels are drawn over the controls;
     *     unseen labels stay in the page for somebody who cannot see it, so a
     *     control is named either way
     */
    public function createInline(bool $labelsShown = true): Form
    {
        return $this->arranged(new InlineFormRenderer($this->latte(), $labelsShown));
    }

    /** Every label over its control, the fields one under another. */
    public function createVertical(): Form
    {
        return $this->arranged(new VerticalFormRenderer($this->latte()));
    }

    /** Every label beside its control, the labels in one column and the controls in another. */
    public function createHorizontal(): Form
    {
        return $this->arranged(new HorizontalFormRenderer($this->latte()));
    }

    private function arranged(FormRenderer $renderer): Form
    {
        $form = new Form();
        $form->setRenderer($renderer);

        return $form;
    }

    /**
     * One engine for every form this factory makes, made the first time one
     * is drawn in an arrangement - a presenter that only ever asks for
     * create() never pays for it.
     */
    private function latte(): Engine
    {
        return $this->latte ??= $this->latteFactory->create();
    }
}

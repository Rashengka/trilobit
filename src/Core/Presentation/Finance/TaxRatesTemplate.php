<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Finance;

use Trilobit\Core\Presentation\Admin\AdminTemplate;

/**
 * What the views of Core:Finance:TaxRates render with: the list, and the page
 * a rate is added or changed on.
 *
 * One class for both, as the list of people and its forms share one: whichever
 * view is drawn reads the properties that mean something to it, and the
 * others keep their empty defaults.
 */
final class TaxRatesTemplate extends AdminTemplate
{
    public string $headline = '';

    public string $lead = '';

    /** Where the section begins. */
    public string $sectionUrl = '';

    /** Where a rate is added, or '' for somebody who may not add one. */
    public string $addUrl = '';

    public string $listUrl = '';

    /** Whether the page is for a rate that does not exist yet. */
    public bool $isNew = true;

    /** Whether the rate shown is the business's default. */
    public bool $isDefault = false;

    public bool $isArchived = false;

    /** Whether the form that changes the rate shown is drawn: the person reading may change it, and it is not archived. */
    public bool $mayEdit = false;

    /** The two letters of the country of the rate shown, or '' for none. */
    public string $country = '';

    /** The kind of the rate shown in words, or '' for none. */
    public string $kind = '';

    /** Whether the rate shown can be made the default from here. */
    public bool $mayMakeDefault = false;

    /** Whether the rate shown can be archived from here. */
    public bool $mayArchive = false;

    /**
     * What is said beside the facts of a rate the person reading is shown no
     * form for - archived, or not theirs to change - or '' when the form is shown.
     */
    public string $kept = '';

    /** That the rate shown is the default, said for what the person reading may do about it; '' when it is not. */
    public string $defaultNote = '';

    /** Whether the rate shown can be restored from here. */
    public bool $mayRestore = false;
}

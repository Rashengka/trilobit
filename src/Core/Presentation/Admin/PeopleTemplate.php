<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Admin;

/**
 * What the three views of Core:Admin:People render with: the list, the form
 * for somebody new, and the page about one person.
 *
 * One class for the three, as the list of pages and the form one is written
 * in share one: whichever view is drawn reads the properties that mean
 * something to it, and the others keep their empty defaults.
 */
final class PeopleTemplate extends AdminTemplate
{
    public string $headline = '';

    public string $lead = '';

    /** Where somebody new is added, or '' for a person who may not add anybody. */
    public string $addUrl = '';

    public string $listUrl = '';

    /** Whether there is any role the person reading the form may give; see People::rolesOffered(). */
    public bool $offersARole = false;

    public string $personEmail = '';

    /** One of the words of PersonSummary. */
    public string $personState = '';

    /** @var list<HeldRole> */
    public array $held = [];

    /**
     * Why the person reading the page may not change what this person holds,
     * or null when they may. Said once, above what is not offered.
     */
    public ?string $unchangeable = null;

    /** Whether the invitation can be sent again from here: nobody has set a password yet, and it may be sent. */
    public bool $mayResend = false;

    /** Whether a role can be given from here. */
    public bool $mayGive = false;
}

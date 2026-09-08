<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture\Fixtures\Gates;

use Trilobit\Core\Presentation\Admin\AdminPresenter;

/**
 * The mistake the rule exists for: a page of the administration with nothing
 * written above it.
 *
 * Nothing about it looks wrong. It is a page like the others, it renders, and
 * the only difference is an absence - which is exactly what nobody spots in a
 * diff, and exactly what happens when somebody adds a section and forgets.
 *
 * It has no render method either. The view it draws is its template and
 * nothing else, which is how a page can exist without a method being written
 * for it - and therefore how one could be hidden from a rule that only read
 * methods.
 */
final class GatedByNothingPresenter extends AdminPresenter {}

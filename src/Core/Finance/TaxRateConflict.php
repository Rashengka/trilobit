<?php

declare(strict_types=1);

namespace Trilobit\Core\Finance;

/**
 * A write of Trilobit\Core\Finance\TaxRates the database refused, because
 * somebody else changed the rates between this request reading them and
 * writing - and the sentence saying so.
 *
 * Nothing of the write is kept, but Doctrine closes the entity manager when a
 * transaction fails, so the request that caught this reads and writes nothing
 * more through it: a page answers with a redirect and the sentence as a flash
 * message, and the next request starts with an entity manager of its own.
 */
final class TaxRateConflict extends TaxRateRefused {}

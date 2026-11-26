<?php

declare(strict_types=1);

namespace Trilobit\Core\Domain\Finance;

/**
 * What kind of rate a rate of tax is in the country it belongs to: the
 * standard one, a reduced one, a super-reduced one, a parking one - a reduced
 * rate a country kept from before the rules of the European Union allowed it -
 * or the zero rate.
 *
 * It is the category a rate keeps when it is compared with another country's:
 * the reduced rate of one country is a different number from the reduced rate
 * of the next, and the kind is what says the two stand for the same thing. A
 * rate a business made up for itself may have no kind, which is why the column
 * carrying it is nullable.
 *
 * The values are what the database keeps, so a case is renamed only with a
 * migration of the rows holding it.
 */
enum TaxRateKind: string
{
    case Standard = 'standard';

    case Reduced = 'reduced';

    case SuperReduced = 'super_reduced';

    case Parking = 'parking';

    case Zero = 'zero';

    /** What a person reads for it. */
    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Standard',
            self::Reduced => 'Reduced',
            self::SuperReduced => 'Super-reduced',
            self::Parking => 'Parking',
            self::Zero => 'Zero',
        };
    }

    /** @return array<string, string> every kind by its value, for a form to offer */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::cases() as $kind) {
            $choices[$kind->value] = $kind->label();
        }

        return $choices;
    }
}

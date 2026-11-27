<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Finance;

use DateTimeImmutable;
use DateTimeInterface;
use Nette\Neon\Exception;
use Nette\Neon\Neon;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Domain\Finance\PriceRefused;
use Trilobit\Core\Domain\Finance\TaxRate;
use Trilobit\Core\Domain\Finance\TaxRateKind;
use Trilobit\Core\Domain\Finance\VatRate;
use Trilobit\Core\Finance\TaxRateTemplates;

/**
 * Every template of rates of tax this repository ships, read as the file it is
 * rather than through Trilobit\Core\Finance\TaxRateTemplates, so that what is
 * asked of a file does not rest on the code that reads it.
 *
 * A template is a statement about a law, so each says when it was last checked
 * and where: a rate nobody can trace to a source is a rate nobody can check.
 */
#[CoversNothing]
final class TaxRateTemplateFilesTest extends TestCase
{
    /** The countries a template is shipped for. More may follow; none of these may go. */
    private const array SHIPPED = ['AT', 'CZ', 'DE', 'HU', 'PL', 'SK'];

    public function testATemplateIsShippedForEachOfTheCountriesPromised(): void
    {
        $countries = array_map(
            static fn(string $file): string => strtoupper(basename($file, '.neon')),
            self::files(),
        );
        sort($countries);

        self::assertSame(self::SHIPPED, array_values(array_intersect($countries, self::SHIPPED)));
        $entries = scandir(TaxRateTemplates::DIRECTORY);
        self::assertIsArray($entries);
        $others = array_filter(
            $entries,
            static fn(string $entry): bool => !in_array($entry, ['.', '..'], true) && !str_ends_with($entry, '.neon'),
        );
        self::assertSame([], array_values($others), 'something other than a template lies among them');
    }

    /** The Czech rates are the ones the seed adopts, so they are asked for by name and number. */
    public function testTheCzechTemplateHoldsItsThreeRates(): void
    {
        $czech = Neon::decodeFile(TaxRateTemplates::DIRECTORY . '/cz.neon');
        self::assertIsArray($czech);
        self::assertIsArray($czech['rates'] ?? null);

        $percents = [];
        foreach ($czech['rates'] as $key => $rate) {
            self::assertIsArray($rate);
            self::assertIsInt($rate['percent']);
            $percents[$key] = (string) $rate['percent'];
        }

        self::assertSame(['standard' => '21', 'reduced' => '12', 'zero' => '0'], $percents);
    }

    #[DataProvider('templates')]
    public function testATemplateSaysWhichCountryWhenAndWhere(string $file): void
    {
        $template = Neon::decodeFile($file);
        self::assertIsArray($template);
        self::assertSame(['country', 'verifiedAt', 'sources', 'rates'], array_keys($template));

        self::assertSame(strtoupper(basename($file, '.neon')), $template['country'], 'the file is not named after its country');
        self::assertMatchesRegularExpression('/^[a-z]{2}\.neon$/', basename($file));

        $verified = $template['verifiedAt'];
        self::assertInstanceOf(DateTimeInterface::class, $verified, 'verifiedAt is not a date');
        self::assertSame('00:00:00', $verified->format('H:i:s'), 'verifiedAt is a date, not a moment');
        self::assertLessThanOrEqual(new DateTimeImmutable(), $verified, 'verifiedAt is in the future');

        self::assertIsList($template['sources']);
        self::assertNotSame([], $template['sources'], 'a template says where its rates come from');
        foreach ($template['sources'] as $source) {
            self::assertIsString($source);
            self::assertMatchesRegularExpression('~^https://[^\s/]+/\S*$~', $source);
        }
    }

    #[DataProvider('templates')]
    public function testEveryRateOfATemplateIsOneTheApplicationCanKeep(string $file): void
    {
        $template = Neon::decodeFile($file);
        self::assertIsArray($template);
        self::assertIsArray($template['rates']);
        self::assertNotSame([], $template['rates']);
        self::assertFalse(array_is_list($template['rates']), 'the rates of a template are a mapping by their keys');

        $kinds = [];
        foreach ($template['rates'] as $key => $rate) {
            self::assertIsString($key);
            self::assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $key);
            self::assertLessThanOrEqual(TaxRate::MAX_TEMPLATE_KEY_LENGTH, strlen('xx.' . $key));

            self::assertIsArray($rate);
            self::assertSame(['kind', 'percent', 'label'], array_keys($rate), $key);
            self::assertIsString($rate['kind']);
            $kinds[] = TaxRateKind::tryFrom($rate['kind']) ?? self::fail(sprintf("%s: '%s' is not a kind of rate", $key, $rate['kind']));

            self::assertTrue(is_int($rate['percent']) || is_float($rate['percent']), $key . ': the percent is not a number');
            try {
                VatRate::ofPercent((string) $rate['percent']);
            } catch (PriceRefused $refused) {
                self::fail($key . ': ' . $refused->getMessage());
            }

            self::assertIsString($rate['label']);
            self::assertNotSame('', trim($rate['label']));
            self::assertLessThanOrEqual(TaxRate::MAX_NAME_LENGTH, mb_strlen($rate['label']));
        }

        self::assertSame(1, count(array_filter($kinds, static fn(TaxRateKind $kind): bool => $kind === TaxRateKind::Standard)), 'a country has one standard rate');
    }

    /**
     * NEON refuses a key written twice in one mapping, which is what keeps the
     * keys of a template's rates - and so the template keys a business holds -
     * unique. Asked here so that it is not merely assumed.
     */
    public function testARateKeyWrittenTwiceIsRefusedByTheLanguage(): void
    {
        $this->expectException(Exception::class);

        Neon::decode("rates:\n\tstandard: {kind: standard, percent: 21, label: A}\n\tstandard: {kind: reduced, percent: 12, label: B}\n");
    }

    /** @return iterable<string, array{string}> */
    public static function templates(): iterable
    {
        foreach (self::files() as $file) {
            yield basename($file) => [$file];
        }
    }

    /** @return list<string> */
    private static function files(): array
    {
        $files = glob(TaxRateTemplates::DIRECTORY . '/*.neon');

        return $files === false ? [] : $files;
    }
}

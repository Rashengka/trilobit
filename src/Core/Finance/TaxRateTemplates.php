<?php

declare(strict_types=1);

namespace Trilobit\Core\Finance;

use DateTimeImmutable;
use DateTimeInterface;
use Nette\Neon\Exception as NeonException;
use Nette\Neon\Neon;
use Trilobit\Core\Domain\Finance\PriceRefused;
use Trilobit\Core\Domain\Finance\TaxRate;
use Trilobit\Core\Domain\Finance\TaxRateKind;
use Trilobit\Core\Domain\Finance\VatRate;

/**
 * The templates of rates of tax a business can take rates from: one file per
 * country in the directory templates/ beside this class, `cz.neon`, read here
 * and nowhere else.
 *
 * **A template is data of the application, not of a business.** It says what
 * a country's law sets, so it is the same for every business and changes with
 * the law, in a commit - not with anything a business does. A business that
 * takes a rate makes a row of its own of it (TaxRates::adopt()), and the row
 * is the business's from then on: a template changed afterwards does not
 * change it. That is why the templates are files in the repository rather
 * than rows: there is nobody whose they would be.
 *
 * **What a file says is checked when it is read**, and a file that says
 * something wrongly is refused whole, as an exception naming it: a rate read
 * wrongly would end up in a business's list, where nothing would say it is
 * wrong. A file is refused for a name that is not a country's two letters, a
 * country other than its name, a key nobody reads, verifiedAt that is not a
 * date, no source or one not over https, no rates, and a rate whose key,
 * kind, percent or label is not one. A key written twice is refused by NEON
 * itself.
 *
 * What each key means is in README.md, under "Templates of rates of tax".
 */
final class TaxRateTemplates
{
    /** Where the shipped templates are. */
    public const string DIRECTORY = __DIR__ . '/templates';

    private const array KEYS = ['country', 'verifiedAt', 'sources', 'rates'];

    private const array RATE_KEYS = ['kind', 'percent', 'label'];

    /** A key of a rate in its file: small letters and digits, a hyphen between words - `reduced-2`. */
    private const string RATE_KEY = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** @var array<string, TaxRateTemplate>|null by country, once read */
    private ?array $read = null;

    public function __construct(private readonly string $directory = self::DIRECTORY) {}

    /**
     * Every template, in the order of their countries.
     *
     * @return list<TaxRateTemplate>
     */
    public function all(): array
    {
        return array_values($this->byCountry());
    }

    /** The template of $country, written in either case, or null when there is none. */
    public function of(string $country): ?TaxRateTemplate
    {
        return $this->byCountry()[strtoupper($country)] ?? null;
    }

    /** The rate a business keeps under $templateKey - `cz.standard` - or null when no template has it. */
    public function rate(string $templateKey): ?TemplateRate
    {
        $country = strstr($templateKey, '.', true);
        if ($country === false || $country !== strtolower($country)) {
            return null;
        }

        foreach ($this->of($country)->rates ?? [] as $rate) {
            if ($rate->templateKey === $templateKey) {
                return $rate;
            }
        }

        return null;
    }

    /** @return array<string, TaxRateTemplate> */
    private function byCountry(): array
    {
        if ($this->read !== null) {
            return $this->read;
        }

        $files = glob($this->directory . '/*.neon');
        if ($files === false || $files === []) {
            throw new \RuntimeException(sprintf('There is no template of rates of tax in %s.', $this->directory));
        }

        $read = [];
        foreach ($files as $file) {
            $template = $this->readFile($file);
            $read[$template->country] = $template;
        }

        ksort($read);

        return $this->read = $read;
    }

    private function readFile(string $file): TaxRateTemplate
    {
        $name = basename($file, '.neon');
        if (preg_match('/^[a-z]{2}$/', $name) !== 1) {
            throw $this->refusal($file, 'is not named after a country: a template is named by the two small letters of its country, such as cz.neon');
        }

        try {
            $declared = Neon::decodeFile($file);
        } catch (NeonException $unreadable) {
            throw $this->refusal($file, 'cannot be read: ' . $unreadable->getMessage());
        }

        if (!is_array($declared)) {
            throw $this->refusal($file, 'says nothing');
        }

        $unknown = array_diff(array_map(strval(...), array_keys($declared)), self::KEYS);
        if ($unknown !== []) {
            throw $this->refusal($file, sprintf('says %s, and a template says %s and nothing else', implode(', ', $unknown), implode(', ', self::KEYS)));
        }

        $country = $declared['country'] ?? null;
        if ($country !== strtoupper($name)) {
            throw $this->refusal($file, sprintf('says it is of %s; the template of a country is named after it, and this one is named %s', is_scalar($country) ? (string) $country : 'no country', $name));
        }

        return new TaxRateTemplate(
            $country,
            $this->verifiedAt($file, $declared['verifiedAt'] ?? null),
            $this->sources($file, $declared['sources'] ?? null),
            $this->rates($file, $country, $declared['rates'] ?? null),
        );
    }

    private function verifiedAt(string $file, mixed $verifiedAt): DateTimeImmutable
    {
        if (!$verifiedAt instanceof DateTimeInterface || $verifiedAt->format('H:i:s') !== '00:00:00') {
            throw $this->refusal($file, 'has no verifiedAt that is a date, such as 2026-11-27: the day its rates were last checked against the sources');
        }

        return DateTimeImmutable::createFromInterface($verifiedAt);
    }

    /** @return list<string> */
    private function sources(string $file, mixed $sources): array
    {
        if (!is_array($sources) || !array_is_list($sources) || $sources === []) {
            throw $this->refusal($file, 'names no source: a template says where its rates can be checked');
        }

        foreach ($sources as $source) {
            if (!is_string($source) || preg_match('~^https://[^\s/]+/\S*$~', $source) !== 1) {
                throw $this->refusal($file, sprintf('names %s as a source, and a source is an https address', var_export($source, true)));
            }
        }

        return $sources;
    }

    /** @return non-empty-list<TemplateRate> */
    private function rates(string $file, string $country, mixed $rates): array
    {
        if (!is_array($rates) || $rates === [] || array_is_list($rates)) {
            throw $this->refusal($file, 'has no rates: they are written under rates, each by a key of its own');
        }

        $read = [];
        foreach ($rates as $key => $rate) {
            $key = (string) $key;
            $templateKey = strtolower($country) . '.' . $key;
            if (preg_match(self::RATE_KEY, $key) !== 1 || strlen($templateKey) > TaxRate::MAX_TEMPLATE_KEY_LENGTH) {
                throw $this->refusal($file, sprintf("has a rate under '%s', and a rate's key is small letters and digits with a hyphen between words, such as reduced-2", $key));
            }

            if (!is_array($rate)) {
                throw $this->refusal($file, sprintf('says nothing about the rate %s', $key));
            }

            $unknown = array_diff(array_map(strval(...), array_keys($rate)), self::RATE_KEYS);
            if ($unknown !== []) {
                throw $this->refusal($file, sprintf('says %s of the rate %s, and a rate is %s and nothing else', implode(', ', $unknown), $key, implode(', ', self::RATE_KEYS)));
            }

            $kind = is_string($rate['kind'] ?? null) ? TaxRateKind::tryFrom($rate['kind']) : null;
            if (!$kind instanceof TaxRateKind) {
                throw $this->refusal($file, sprintf(
                    "says the rate %s is of the kind %s; '%s' is not a kind, and a kind is one of %s",
                    $key,
                    var_export($rate['kind'] ?? null, true),
                    is_scalar($rate['kind'] ?? null) ? (string) $rate['kind'] : '',
                    implode(', ', array_column(TaxRateKind::cases(), 'value')),
                ));
            }

            $label = is_string($rate['label'] ?? null) ? trim($rate['label']) : '';
            if ($label === '' || mb_strlen($label) > TaxRate::MAX_NAME_LENGTH) {
                throw $this->refusal($file, sprintf('has no label for the rate %s that a business could call it by, of at most %d characters', $key, TaxRate::MAX_NAME_LENGTH));
            }

            $read[] = new TemplateRate($templateKey, $country, $kind, $this->percent($file, $key, $rate['percent'] ?? null), $label);
        }

        return $read;
    }

    private function percent(string $file, string $key, mixed $percent): VatRate
    {
        if (!is_int($percent) && !is_float($percent)) {
            throw $this->refusal($file, sprintf('gives the rate %s no percent that is a number', $key));
        }

        try {
            return VatRate::ofPercent((string) $percent);
        } catch (PriceRefused $refused) {
            throw $this->refusal($file, sprintf('gives the rate %s a percent that is not a rate of tax: %s', $key, $refused->getMessage()));
        }
    }

    private function refusal(string $file, string $what): \RuntimeException
    {
        return new \RuntimeException(sprintf('The template of rates of tax %s %s.', $file, $what));
    }
}

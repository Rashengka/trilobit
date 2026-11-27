<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Finance;

use Nette\Utils\FileSystem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Domain\Finance\TaxRateKind;
use Trilobit\Core\Finance\TaxRateTemplate;
use Trilobit\Core\Finance\TaxRateTemplates;
use Trilobit\Core\Finance\TemplateRate;

/**
 * The templates of rates of tax as the application reads them: what the
 * shipped ones hold, and what a template written wrongly is refused for.
 *
 * Most of it is refusal, for the reason Trilobit\Tests\Unit\Core\Security\
 * PermissionStructureTest's is: a template read wrongly would put a wrong
 * rate into a business's list, where nothing would say it is wrong.
 */
#[CoversClass(TaxRateTemplates::class)]
#[CoversClass(TaxRateTemplate::class)]
#[CoversClass(TemplateRate::class)]
final class TaxRateTemplatesTest extends TestCase
{
    private const string VALID = <<<'NEON'
        country: XA
        verifiedAt: 2026-11-27
        sources:
        	- https://example.org/a-law
        rates:
        	standard: {kind: standard, percent: 20, label: Standard rate}
        	reduced-2: {kind: reduced, percent: 4.9, label: Second reduced rate}
        NEON;

    private string $directory = '';

    protected function tearDown(): void
    {
        if ($this->directory !== '') {
            FileSystem::delete($this->directory);
            $this->directory = '';
        }
    }

    public function testTheShippedTemplatesAreReadInTheOrderOfTheirCountries(): void
    {
        $countries = array_map(
            static fn(TaxRateTemplate $template): string => $template->country,
            new TaxRateTemplates()->all(),
        );

        self::assertSame(['AT', 'CZ', 'DE', 'HU', 'PL', 'SK'], $countries);
    }

    public function testTheCzechTemplateIsFoundByItsCountryInEitherCase(): void
    {
        $templates = new TaxRateTemplates();
        $czech = $templates->of('cz') ?? self::fail('there is no Czech template');

        self::assertSame($czech, $templates->of('CZ'));
        self::assertSame('2026-11-27', $czech->verifiedAt->format('Y-m-d'));
        self::assertSame(
            [
                ['cz.standard', 'standard', 2100, 'Standard rate'],
                ['cz.reduced', 'reduced', 1200, 'Reduced rate'],
                ['cz.zero', 'zero', 0, 'Zero rate (books and the like)'],
            ],
            array_map(
                static fn(TemplateRate $rate): array => [$rate->templateKey, $rate->kind->value, $rate->rate->basisPoints(), $rate->label],
                $czech->rates,
            ),
        );
        self::assertNull($templates->of('XX'));
    }

    /** A rate is found by the key a business keeps it under, and a key of no rate is not. */
    public function testARateIsFoundByItsTemplateKey(): void
    {
        $templates = new TaxRateTemplates();

        // Under 5 %, so super-reduced, as the rules of the European Union call a rate that low.
        $austrian = $templates->rate('at.super-reduced') ?? self::fail('the Austrian super-reduced rate is not found');
        self::assertSame(490, $austrian->rate->basisPoints());
        self::assertSame('AT', $austrian->country);
        self::assertSame(TaxRateKind::SuperReduced, $austrian->kind);

        foreach (['at.nothing', 'xx.standard', 'standard', '', 'AT.standard'] as $none) {
            self::assertNull($templates->rate($none), $none);
        }
    }

    public function testAValidTemplateIsReadAsWritten(): void
    {
        $template = $this->templatesOf(['xa.neon' => self::VALID])->of('XA') ?? self::fail('the template is not read');

        self::assertSame(['https://example.org/a-law'], $template->sources);
        self::assertSame(['xa.standard', 'xa.reduced-2'], array_map(static fn(TemplateRate $rate): string => $rate->templateKey, $template->rates));
        self::assertSame(490, $template->rates[1]->rate->basisPoints());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function mistakes(): iterable
    {
        yield 'a file not named after its country' => ['xb.neon', self::VALID, 'says it is of XA'];
        yield 'a name that is not a country' => ['xa1.neon', self::VALID, 'is not named'];
        yield 'no date' => ['xa.neon', str_replace("verifiedAt: 2026-11-27\n", '', self::VALID), 'verifiedAt'];
        yield 'a date that is not one' => ['xa.neon', str_replace('2026-11-27', 'last week', self::VALID), 'verifiedAt'];
        yield 'a moment instead of a date' => ['xa.neon', str_replace('2026-11-27', '2026-11-27 10:00', self::VALID), 'verifiedAt'];
        yield 'no source' => ['xa.neon', str_replace("\t- https://example.org/a-law", '', self::VALID), 'source'];
        yield 'a source not over https' => ['xa.neon', str_replace('https://', 'http://', self::VALID), 'https'];
        yield 'no rates' => ['xa.neon', substr(self::VALID, 0, (int) strpos(self::VALID, 'rates:')) . "rates: []\n", 'no rates'];
        yield 'a kind that is not one' => ['xa.neon', str_replace('kind: reduced', 'kind: lowered', self::VALID), "'lowered' is not a kind"];
        yield 'a percent that is not one' => ['xa.neon', str_replace('percent: 20', 'percent: twenty', self::VALID), 'percent'];
        yield 'a percent over a hundred' => ['xa.neon', str_replace('percent: 20', 'percent: 120', self::VALID), 'percent'];
        yield 'a percent of three decimals' => ['xa.neon', str_replace('percent: 4.9', 'percent: 4.995', self::VALID), 'percent'];
        yield 'no label' => ['xa.neon', str_replace('label: Standard rate', "label: ''", self::VALID), 'label'];
        yield 'a key no rate is written with' => ['xa.neon', str_replace('label: Standard rate', 'label: Standard rate, note: x', self::VALID), 'note'];
        yield 'a key no template is written with' => ['xa.neon', self::VALID . "\nnote: x\n", 'note'];
        yield 'a rate key that is not one' => ['xa.neon', str_replace('reduced-2:', 'Reduced 2:', self::VALID), "'Reduced 2'"];
        yield 'a rate key written twice' => ['xa.neon', str_replace('reduced-2:', 'standard:', self::VALID), 'Duplicated key'];
    }

    #[DataProvider('mistakes')]
    public function testATemplateWrittenWronglyIsRefusedWhenItIsRead(string $file, string $neon, string $said): void
    {
        $templates = $this->templatesOf([$file => $neon]);

        try {
            $templates->all();
        } catch (\RuntimeException $refused) {
            self::assertStringContainsString($said, $refused->getMessage());

            return;
        }

        self::fail('the template was read');
    }

    public function testADirectoryWithNoTemplatesIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);

        new TaxRateTemplates(sys_get_temp_dir() . '/trilobit-no-such-templates')->all();
    }

    /** @param array<string, string> $files */
    private function templatesOf(array $files): TaxRateTemplates
    {
        $this->directory = sys_get_temp_dir() . '/trilobit-tax-rate-templates-' . bin2hex(random_bytes(6));
        foreach ($files as $name => $neon) {
            FileSystem::write($this->directory . '/' . $name, $neon);
        }

        return new TaxRateTemplates($this->directory);
    }
}

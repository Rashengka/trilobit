<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Dom\Element;
use Dom\HTMLDocument;
use Nette\Application\IPresenterFactory;
use Nette\Application\Request;
use Nette\Application\Response;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Presenter;
use Nette\DI\Container;
use Nette\Security\Passwords;
use Nette\Security\User as SignedIn;
use Nette\Utils\FileSystem;
use Nette\Utils\Finder;
use Nette\Utils\Random;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Domain\Tenancy\Membership;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Core\Security\Accounts;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * A hand-written field's required mark and its control's own required
 * attribute say the same fact in two places: a presenter's setRequired() and
 * the required parameter a template passes to c-field (which cannot read
 * isRequired() itself, because the control is the template's own element and
 * not this component's - see the docblock of
 * src/Core/Presentation/components/field.latte). A generated field cannot
 * drift, because Trilobit\Core\Presentation\Form\FormField reads the one off
 * the other; a hand-written one can, silently - a mark left on a field whose
 * control stopped being required, or a required control whose field never
 * got the mark, and nothing about the page looks wrong either way.
 *
 * The templates this holds are found rather than named: any *.latte under
 * src/ that draws a field through {embed block field} and writes n:name on
 * an element inside it is a hand-written form over c-field, whether or not
 * this suite was told about it. That excludes the arrangements
 * (src/Core/Presentation/Form/templates/), which draw c-field too but never
 * write n:name - every field there is a FormField, held by
 * Trilobit\Tests\Template\FormArrangementsDifferOnlyInTheirWrappingTest - and
 * the style guide's specimens, which draw c-field over invented markup with
 * no control of its own to be required.
 *
 * Finding a template is not knowing how to render it: that takes a presenter,
 * a route and - for three of the four - somebody signed in, which cannot be
 * derived from the file. So a template found above and missing from
 * recipes() is a failure of its own (testEveryHandWrittenFormTemplateHasARecipe)
 * rather than one silently skipped.
 */
#[CoversNothing]
final class HandWrittenFormsMarkRequiredConsistentlyTest extends TestCase
{
    private string $schema = '';

    private ?Container $container = null;

    private string $generatedPassword = '';

    private bool $loggedIn = false;

    protected function tearDown(): void
    {
        $this->container?->getByType(SignedIn::class)->logout(true);
        $this->container = null;
        $this->loggedIn = false;

        if ($this->schema !== '') {
            Database::drop($this->schema);
            $this->schema = '';
        }
    }

    public function testEveryHandWrittenFormTemplateHasARecipeToRenderIt(): void
    {
        self::assertSame(
            self::discover(),
            array_keys($this->recipes()),
            'a template draws a field by hand through c-field and this suite does not know how to render it - '
            . 'add a recipe in ' . self::class . '::recipes()',
        );
    }

    /**
     * Every field of the rendered page, checked one by one: the mark is there
     * exactly where a control inside it carries required, never where none
     * does and never missing where one does.
     */
    #[DataProvider('handWrittenFormTemplates')]
    public function testTheMarkAgreesWithTheControlsRequiredAttribute(string $template): void
    {
        $recipes = $this->recipes();
        self::assertArrayHasKey($template, $recipes, $template . ' has no recipe to render it');

        $document = $recipes[$template]();

        $checked = 0;
        foreach ($document->querySelectorAll('.c-field') as $field) {
            self::assertInstanceOf(Element::class, $field);

            $controls = $field->querySelectorAll('.c-field__control input, .c-field__control select, .c-field__control textarea');
            if (count($controls) === 0) {
                // Nothing a screen reader or a browser could call required - a
                // button, or a field whose control is drawn by a script.
                continue;
            }

            $checked++;
            $isRequired = false;
            foreach ($controls as $control) {
                self::assertInstanceOf(Element::class, $control);
                if ($control->hasAttribute('required')) {
                    $isRequired = true;

                    break;
                }
            }

            $hasMark = $field->querySelector('.c-field__required') instanceof Element;
            $label = trim((string) $field->querySelector('.c-field__label')?->textContent);

            self::assertSame(
                $isRequired,
                $hasMark,
                sprintf(
                    '%s: the field labelled "%s" has a control that is %srequired but %scarries the mark',
                    $template,
                    $label,
                    $isRequired ? '' : 'not ',
                    $hasMark ? '' : 'not ',
                ),
            );
        }

        self::assertGreaterThan(0, $checked, $template . ' drew no field with a control to check');
    }

    /** @return iterable<string, array{string}> */
    public static function handWrittenFormTemplates(): iterable
    {
        foreach (self::discover() as $template) {
            yield $template => [$template];
        }
    }

    /**
     * @return array<string, \Closure(): HTMLDocument> keyed exactly the way
     *     discover() names the same template, so
     *     testEveryHandWrittenFormTemplateHasARecipeToRenderIt can compare
     *     the two lists directly
     */
    private function recipes(): array
    {
        // Keyed in the order discover() sorts its own list into, so
        // testEveryHandWrittenFormTemplateHasARecipeToRenderIt can compare the
        // two lists directly rather than sorting either one again.
        return [
            'src/Cms/Presentation/Admin/templates/Category/edit.latte' => fn(): HTMLDocument => $this->cmsPage('Cms:Admin:Category'),
            'src/Cms/Presentation/Admin/templates/Menu/edit.latte' => fn(): HTMLDocument => $this->cmsPage('Cms:Admin:Menu'),
            'src/Cms/Presentation/Admin/templates/Page/edit.latte' => fn(): HTMLDocument => $this->cmsPage('Cms:Admin:Page'),
            'src/Core/Presentation/Admin/templates/Sign/in.latte' => $this->signInPage(...),
        ];
    }

    /**
     * Every *.latte under src/ that hand-writes a field through c-field: it
     * embeds the field block and, inside it, names an element with n:name
     * rather than handing the block a FormField.
     *
     * @return list<string> paths relative to the project root, sorted
     */
    private static function discover(): array
    {
        $root = dirname(__DIR__, 2);
        $templates = [];

        // ->from(), not ->in(): the latter does not descend into subdirectories,
        // and every template this looks for is nested several deep.
        foreach (Finder::findFiles('*.latte')->from($root . '/src') as $file) {
            $path = (string) $file;
            $source = FileSystem::read($path);

            if (str_contains($source, '{embed block field') && str_contains($source, 'n:name=')) {
                $templates[] = substr($path, strlen($root) + 1);
            }
        }

        sort($templates);

        return $templates;
    }

    /** Rendered signed out, the only way it can be: signed in, it redirects to the landing page instead. */
    private function signInPage(): HTMLDocument
    {
        return $this->pageOf($this->runRequest('Core:Admin:Sign', 'in'));
    }

    /** The fresh, empty form a new record starts from - nothing filled in and nothing yet refused. */
    private function cmsPage(string $presenterName): HTMLDocument
    {
        $this->login();

        return $this->pageOf($this->runRequest($presenterName, 'add'));
    }

    private function runRequest(string $presenterName, string $action): Response
    {
        $presenter = $this->container()->getByType(IPresenterFactory::class)->createPresenter($presenterName);
        self::assertInstanceOf(Presenter::class, $presenter);
        $presenter->autoCanonicalize = false;

        return $presenter->run(new Request($presenterName, 'GET', ['action' => $action]));
    }

    private function pageOf(Response $response): HTMLDocument
    {
        self::assertInstanceOf(TextResponse::class, $response);
        $source = $response->getSource();
        self::assertInstanceOf(\Stringable::class, $source);

        return HTMLDocument::createFromString((string) $source, LIBXML_NOERROR);
    }

    private function login(): void
    {
        if ($this->loggedIn) {
            return;
        }

        $this->container()->getByType(SignedIn::class)->login('alice@example.com', $this->generatedPassword);
        $this->loggedIn = true;
    }

    private function container(): Container
    {
        if ($this->container instanceof Container) {
            return $this->container;
        }

        $this->schema = Database::schemaFor(self::class);
        $container = Boot::container(ModuleList::of(
            ['cms' => true, 'crm' => false, 'shop' => false],
            Bootstrap::rootDirectory(),
        ));
        Migrations::run($container);
        $tenant = Tenants::enter($container, 'Ammonite Bikes', Tenants::HOST);

        $this->generatedPassword = Random::generate(24, 'a-zA-Z0-9');
        $account = new User(
            'alice@example.com',
            $container->getByType(Passwords::class)->hash($this->generatedPassword),
            'Alice Ammonite',
            new DateTimeImmutable('2026-09-13T08:00:00+00:00'),
        );
        $container->getByType(Accounts::class)->save($account);

        $entityManager = $container->getByType(EntityManagerInterface::class);
        $role = new Role(Role::OWNER, 'Owner', ['app:*']);
        $entityManager->persist($role);
        $entityManager->persist(new Membership($tenant, $account, $role));
        $entityManager->flush();

        return $this->container = $container;
    }
}

<?php

declare(strict_types=1);

namespace Trilobit\Core\Console;

use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Tools\Console\Command\DiffCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Trilobit\Core\Doctrine\TableName;
use Trilobit\Core\Module\ModuleList;

/**
 * Doctrine's migration generator, with the two things a build made of modules
 * has to settle before it may run.
 *
 * **A diff needs every module.** The mapping of a module that is switched off
 * is not loaded, so a change to its entities is not in the comparison and the
 * migration that comes out looks finished. Nobody reviewing it can see what is
 * missing, because what is missing left no trace. The filter over the tables
 * the schema tools may see protects the customer's data from that; this
 * protects the commit, and both are needed.
 *
 * **A migration belongs to one module.** Doctrine's own --namespace decides
 * which directory the file is written to and nothing else: asked for a
 * migration in one module's namespace it writes every table in the mapping
 * into it. In a build with several modules that means one module's migration
 * creating another one's tables - and the build that later switches that
 * module off runs that migration all the same. So the namespace picks the
 * comparison as well, by way of the table prefix the module owns.
 *
 * Both are refusals rather than warnings. A warning on a generator is read
 * once and then never again, and what it would have prevented is a file
 * somebody commits.
 *
 * The generator itself is Doctrine's, wrapped rather than rewritten - it is
 * declared final, and reimplementing it here to get two checks in front of it
 * would be forty lines of somebody else's code to keep in step.
 */
#[AsCommand(
    name: 'migrations:diff',
    description: 'Generate a migration for one module by comparing its mapping to the database.',
)]
final class MigrationsDiffCommand extends Command
{
    /**
     * A migrations namespace is the module's namespace and nothing deeper, so
     * that one directory holds one module's migrations and the module a
     * migration belongs to can be read off its name.
     */
    private const string NAMESPACE_PATTERN = '#^Trilobit\\\\([A-Z][A-Za-z0-9]*)\\\\Migrations$#';

    private readonly DiffCommand $generator;

    public function __construct(
        DependencyFactory $dependencyFactory,
        private readonly ModuleList $modules,
    ) {
        parent::__construct();

        // Built here rather than taken from the container, because a second
        // service of its type would be a second command answering to
        // migrations:diff, and which of the two the console picked would come
        // down to the order they happened to be defined in.
        $this->generator = new DiffCommand($dependencyFactory);

        // The same definition object, not a copy: the generator is handed the
        // input this command was given, and it binds it to its own definition
        // again before running. Sharing is what makes the two bindings agree.
        $this->setDefinition($this->generator->getDefinition());
        $this->setHelp($this->generator->getHelp());
    }

    /**
     * The regular expression restricting a diff for $namespace to the tables
     * of the module it names, or null when it names no module.
     *
     * Doctrine takes this as --filter-expression and applies it to both sides
     * of the comparison, which is what is wanted: the module's tables as the
     * mapping has them against the module's tables as the database has them,
     * and nothing else in either.
     */
    public static function filterFor(string $namespace): ?string
    {
        if (preg_match(self::NAMESPACE_PATTERN, $namespace, $match) !== 1) {
            return null;
        }

        return '/^' . preg_quote(TableName::prefixOf(strtolower($match[1])), '/') . '/';
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);

        $switchedOff = array_values(array_diff($this->modules->names(), $this->modules->enabledNames()));
        if ($switchedOff !== []) {
            $style->error(sprintf(
                'A migration cannot be generated from a build that is missing %s.',
                implode(' and ', $switchedOff),
            ));
            $style->writeln(
                'The mapping of a module that is switched off is not loaded, so a change to its entities would be '
                . 'absent from the comparison and the migration would look complete without being it. Switch every '
                . 'module on in config/modules.neon, generate, and switch back afterwards.',
            );

            return self::FAILURE;
        }

        $namespace = $input->getOption('namespace');
        $filter = is_string($namespace) ? self::filterFor($namespace) : null;

        if ($filter === null) {
            $style->error(
                is_string($namespace) && $namespace !== ''
                    ? sprintf('%s does not name the migrations of one module.', $namespace)
                    : '--namespace is required and has to name the migrations of one module.',
            );
            $style->writeln(
                'A migration belongs to exactly one module and is generated into that module\'s own namespace, '
                . 'which is the word Trilobit, the module\'s name with a capital letter, and Migrations.',
            );

            return self::FAILURE;
        }

        // Set as the option's default rather than as its value, because the
        // generator binds the input to the definition once more before it
        // runs, and binding parses the command line again from scratch - a
        // value set here would not survive it, and a default is what a parse
        // falls back to. Left alone when one was given: somebody passing an
        // expression is narrowing the comparison further, and the check above
        // has already established that the build is complete.
        if ($input->getOption('filter-expression') === null) {
            $this->getDefinition()->getOption('filter-expression')->setDefault($filter);
        }

        // The application merges its own options into this command's
        // definition, replacing it with a new one - the "command" argument
        // among them. The generator is holding the definition from before that
        // happened, and binding the input to it would fail on an argument it
        // has never heard of, so it is given the one actually in use.
        $this->generator->setDefinition($this->getDefinition());

        return $this->generator->run($input, $output);
    }
}

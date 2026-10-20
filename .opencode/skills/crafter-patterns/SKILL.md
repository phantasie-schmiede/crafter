---
name: crafter-patterns
description: Code pattern quick reference for the PSBits Crafter generator codebase: generator service pattern, Symfony Console command pattern, readonly data blueprint pattern. Use when writing new generators, commands, or blueprints in the crafter repository.
---

# Crafter code patterns

Quick reference for the established patterns in this repository. Conventions (strict types,
license header, yoda conditions, alphabetical ordering, `protected readonly` constructor
promotion) live in `AGENTS.md`.

## Generator service pattern

Generators are stateless readonly services receiving a blueprint and returning
`WriteResult[]`.

```php
readonly class SomeGenerator
{
    public function __construct(
        protected ConfigurationService $configurationService,
        protected FileWriter           $fileWriter,
        protected PathResolver         $pathResolver,
        protected SkeletonRenderer     $skeletonRenderer,
    ) {
    }

    public function generate(SomeBlueprint $blueprint): array
    {
        // resolve paths, render skeletons, write files
        return $this->fileWriter->getResults();
    }
}
```

## Command pattern

Commands extend `AbstractCrafterCommand` and use the injected Symfony Console IO.

```php
final class SomeCommand extends AbstractCrafterCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addFileOptions();
        // add command-specific options
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = $this->getIo($input, $output);
        // ... orchestrate generators, show results
        return Command::SUCCESS;
    }
}
```

## Data blueprint pattern

Blueprints are readonly data objects with no logic beyond what is needed.

```php
readonly class SomeBlueprint
{
    public function __construct(
        public readonly string $name,
        // ...plain data, no logic beyond what's needed
    ) {
    }
}
```

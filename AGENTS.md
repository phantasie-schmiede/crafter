# PSBits Crafter

Instructions for AI coding agents working in this repository. Read this file at session start and follow it.

## 1. Think Before Coding

**Don't assume. Don't hide confusion. Surface tradeoffs.**

Before implementing:

- State your assumptions explicitly. If uncertain, ask.
- If multiple interpretations exist, present them - don't pick silently.
- If a simpler approach exists, say so. Push back when warranted.
- If something is unclear, stop. Name what's confusing. Ask.

## 2. Simplicity First

**Minimum code that solves the problem. Nothing speculative.**

- No features beyond what was asked.
- No abstractions for single-use code.
- No "flexibility" or "configurability" that wasn't requested.
- No error handling for impossible scenarios.
- If you write 200 lines and it could be 50, rewrite it.

Ask yourself: "Would a senior engineer say this is overcomplicated?" If yes, simplify.

## 3. Surgical Changes

**Touch only what you must. Clean up only your own mess.**

When editing existing code:

- Don't "improve" adjacent code, comments, or formatting.
- Don't refactor things that aren't broken.
- Match existing style, even if you'd do it differently.
- If you notice unrelated dead code, mention it - don't delete it.

When your changes create orphans:

- Remove imports/variables/functions that YOUR changes made unused.
- Don't remove pre-existing dead code unless asked.

The test: Every changed line should trace directly to the user's request.

## 4. Goal-Driven Execution

**Define success criteria. Loop until verified.**

Transform tasks into verifiable goals:

- "Add validation" → "Write tests for invalid inputs, then make them pass"
- "Fix the bug" → "Write a test that reproduces it, then make it pass"
- "Refactor X" → "Ensure tests pass before and after"

For this repository, tests are golden master tests that compare generated output byte by byte with reviewed fixtures.
Express verification as one of:

- `composer test:unit` passes
- `composer lint:all` passes
- `CRAFTER_UPDATE_FIXTURES=1 composer test:unit` + review `git diff Tests/Fixtures/Expected`

For multi-step tasks, state a brief plan:

```
1. [Step] → verify: [check]
2. [Step] → verify: [check]
3. [Step] → verify: [check]
```

## Repository purpose

TYPO3 extension (`type: typo3-cms-extension`) providing a code generator for scaffolding TYPO3 extensions,
domain models, controllers, services, event listeners, and view helpers. PHP namespace: `PSBits\Crafter\`.

## Key locations

- `Classes/Command/` — Symfony Console commands (`crafter:make:*`, `crafter:audit`, `crafter:skeleton:*`, `crafter:config:dump`).
- `Classes/Service/` — Generator and infrastructure services (skeleton rendering, configuration merging, file writing,
  code injection, auditing).
- `Classes/Data/` — Data objects (blueprints, write results, audit findings).
- `Classes/Enum/` — Enums (`AuditSeverity`, `WriteStatus`).
- `Classes/Event/` — PSR-14 events for customization hooks.
- `Classes/Exception/` — `CrafterException`.
- `Classes/Utility/` — Naming and PHP utility helpers.
- `Configuration/Crafter.yaml` — Shipped defaults for the 5-layer configuration cascade.
- `Configuration/Services.yaml` — Symfony DI autowiring.
- `Resources/Private/Skeletons/` — `.stub` files with `{{ placeholder }}` markers, the templates for generated code.
- `Tests/Fixtures/Expected/` — Golden master fixtures for generator output.
- `Tests/Unit/` — Unit tests wiring the real service graph against throwaway project directories.

## Architecture and conventions

- Strongly typed PHP (`declare(strict_types=1);`) is standard for classes. Every class file starts with the
  "This file is part of PSBits Crafter." license header block.
- Service wiring uses Symfony DI autowiring in `Configuration/Services.yaml`.
- Generators are stateless services; they receive blueprints (readonly data objects) and return `WriteResult[]`.
- The 5-layer configuration cascade (`Crafter.yaml` → project → extension → CLI → events) is merged by
  `ConfigurationService`. Changing configuration behavior means adding a YAML entry, not writing PHP.
- Skeletons are `.stub` files with no logic — loops and ordering happen in PHP services.
- Events in `Classes/Event/` are registered via `#[AsEventListener]` attributes, not `Services.yaml`.
- Test coverage uses golden master tests: generated output is compared byte by byte against fixtures in
  `Tests/Fixtures/Expected/`. After intended changes, update fixtures with `composer test:unit:update-fixtures`
  and review the diff.
- Use yoda conditions (enforced by php-cs-fixer: `yoda_style`).
- Use alphabetical ordering where possible (e.g., in arrays, attributes, imports, properties, variable declarations).
  The `lint:classes` script additionally enforces Bootstrap breakpoint ordering inside single `class` attributes:
  `xs < sm < md < lg < xl < xxl`.
- Constructor promotion with `protected readonly` is the default for service dependencies.

### Pattern quick reference

The generator service, command, and data blueprint patterns are documented in the
`crafter-patterns` skill (`.opencode/skills/crafter-patterns/SKILL.md`).

## Safe change workflow

1. Identify the layer to change first:
    - new generator → add `Classes/Command/`, `Classes/Service/`, `Classes/Data/` + skeletons
    - configuration behavior → `Configuration/Crafter.yaml` (data) or `Classes/Service/ConfigurationService.php` (logic)
    - customization hooks → `Classes/Event/` + dispatch points in generators
    - generated output shape → skeleton `.stub` files in `Resources/Private/Skeletons/`
    - tests → `Tests/Unit/` + `Tests/Fixtures/Expected/`
2. Keep edits surgical and consistent with existing code style.
3. When changing generator output, update the corresponding golden master fixtures.
4. Run `composer test:unit` to verify nothing broke.

If you have questions about architecture, conventions, or best practices, ask before implementing. If you notice
inconsistencies between the codebase and these instructions, mention them - don't fix them unless asked.

## Validation

Repository quality checks are defined in `composer.json`, `package.json`, and `Build/` configuration files.

- `fix:all` **rewrites source files** (runs class attribute linting, Prettier for XML/XLF, php-cs-fixer);
  `lint:all` is the read-only verification.
- A pre-commit hook (`Build/GitHooks/pre-commit`, auto-installed by `composer post-install-cmd`) runs `composer lint:all`.
- Individual checks: `lint:php`, `analyze:php` (PHPStan), `lint:classes`, `lint:htmlxml`, `lint:yaml`,
  `audit:composer`, `validate:composer`.
- Unit tests: `composer test:unit` (golden master tests, no database needed).
- Update fixtures after intended output changes: `composer test:unit:update-fixtures`, then review
  `git diff Tests/Fixtures/Expected`.

## Do not modify

- `Resources/Private/Skeletons/` — only modify intentionally (changes affect all generated extensions).
- `node_modules/`, `.Build/`, locked files (`composer.lock`, `package-lock.json`).
- `Build/Quality/.phpstan/` — PHPStan cache.

## Onboarding run notes

- Running QA locally requires installed dependencies (`composer install` / `composer update`, plus npm dependencies via
  Composer post-install) and the `yamllint` CLI for `lint:yaml`.
- In restricted environments, Composer dependency installation may fail if GitHub authentication for dist downloads is
  unavailable.
- Tests run against a throwaway project directory — no database or running TYPO3 installation is required.

# PSBits Crafter

Code generator for TYPO3 extensions.

## Installation

```bash
composer require psbits/crafter
```

## Commands

| Command                           | Purpose                                                                  |
|-----------------------------------|--------------------------------------------------------------------------|
| `crafter:audit`                   | Checks an extension against the conventions Crafter generates by         |
| `crafter:config:dump`             | Shows the effective configuration; `--origins` reveals the winning layer |
| `crafter:list`                    | Lists all crafter commands with a short description                      |
| `crafter:make:backend-controller` | Creates a backend module controller and registers the module             |
| `crafter:make:controller`         | Creates a frontend controller with templates, language files and plugin  |
| `crafter:make:event-listener`     | Creates a PSR-14 event listener                                          |
| `crafter:make:extension`          | Creates the base structure of a new TYPO3 extension                      |
| `crafter:make:model`              | Creates a domain model and optionally its repository and controller      |
| `crafter:make:repository`         | Creates a repository for an existing domain model                        |
| `crafter:make:service`            | Creates a service class with its constructor dependencies                |
| `crafter:make:view-helper`        | Creates a Fluid view helper                                              |
| `crafter:skeleton:list`           | Lists all skeletons and the root path each one is loaded from            |
| `crafter:skeleton:publish`        | Copies a skeleton into an overriding root path for customization         |
| `crafter:skeleton:show`           | Renders a skeleton with the effective variables without writing a file   |
| `crafter:skeleton:where`          | Shows all lookup locations of a skeleton and which one wins              |
| `crafter:sync`                    | Brings the toolchain files of an existing extension in sync              |

All commands accept `--extension|-e` to resolve configuration and skeletons for a specific extension. Every command
that writes files supports `--dry-run` and `--force`.

The extension ships a `vendor/bin/crafter` wrapper as the entry point for all commands. It suggests close matches
for an unknown name. Every known command is delegated to the regular `vendor/bin/typo3` CLI with all arguments and
options, so the wrapper never changes what a command does. The `crafter:` prefix is optional:

```bash
vendor/bin/crafter                  # lists all commands
vendor/bin/crafter make:model ...   # same as vendor/bin/typo3 crafter:make:model ...
```

All examples in this document use the wrapper.

## Creating an Extension

```bash
vendor/bin/crafter make:extension budget_tracker
```

The command asks for vendor, title, description, author, license, state, version and which file groups to create.
Every question can be answered upfront with the matching option, and `--no-interaction` runs it fully unattended:

```bash
vendor/bin/crafter make:extension budget_tracker \
    --vendor=PSBits --description="Budget tracking" --groups=core,license,quality --no-interaction
```

Which files belong to which group is defined in `crafter.extensionFiles` and can be changed like any other
configuration value. The group `core` is always created:

| Group        | Contents                                                                                                |
|--------------|---------------------------------------------------------------------------------------------------------|
| `core`       | `composer.json`, `ext_emconf.php`, `ExtensionInformation`, `AGENTS.md`, `CLAUDE.md`, `Services.yaml`, … |
| `license`    | `LICENSE.txt`                                                                                           |
| `typoScript` | `Configuration/TypoScript/constants.typoscript` and `setup.typoscript`                                  |
| `quality`    | `Build/Quality/*`, `Build/GitHooks/pre-commit`, `package.json`                                          |
| `ci`         | `.github/workflows/quality.yml` (toolchain checks, support matrix, TER publishing)                      |

New extensions are written to `crafter.extensionRootPath` (`packages/` by default).

### CI Workflow of Generated Extensions

The `quality.yml` workflow runs the toolchain checks on one PHP version, a support matrix that verifies the
supported TYPO3/PHP combinations still resolve their dependencies, and a `publish-to-ter` job that publishes the
extension to TER on semver tags. Both the matrix and the publish job are configured under `crafter.ci`:

```yaml
crafter:
  ci:
    matrix:
      - {lowest: true, php: '8.3', typo3: '13.4'}
      - {php: '8.5', typo3: '14.3'}
    publishToTer: true
```

Setting the matrix to an empty list disables the job; `publishToTer: false` skips the publishing job. Publishing
requires the `TYPO3_API_TOKEN` repository secret in the extension's repository.

## Creating a Domain Model

```bash
vendor/bin/crafter make:model Product -e budget_tracker
```

The command asks for the properties one after another and afterwards whether the matching repository should be
created as well. Non-interactively every property is passed as a comma separated list of `key=value` pairs:

```bash
vendor/bin/crafter make:model Product -e budget_tracker --with-repository --no-interaction \
    --property="name=title,type=string,required=1" \
    --property="name=price,type=decimal" \
    --property="name=images,type=file" \
    --property="name=variants,type=inline,linkedClass=Variant" \
    --property="name=categories,type=relationMultiple,linkedClass=Category"
```

Supported keys are `name`, `type`, `required`, `linkedClass` and `foreignField`. `--no-repository` skips the
follow-up question.

The generator writes a complete Extbase entity: TCA attributes, typed properties in alphabetical order, fluent
setters, `isXxx()` getters for booleans, and `initializeObject()` plus adders and removers for every
`ObjectStorage`. The TCA is derived from the attributes on the model, so nothing has to be added in
`Configuration/TCA`.

### Field Types

The available types live in `crafter.fieldTypes` and ship with `boolean`, `color`, `date`, `dateTime`, `decimal`,
`email`, `enum`, `file`, `inline`, `integer`, `link`, `relationMultiple`, `relationSingle`, `richText`, `slug`,
`string` and `text`.

Each type is pure configuration, so an own type is added without touching any PHP:

```yaml
crafter:
  fieldTypes:
    percentage:
      attributes:
        - 'Number(format: NumberFormat::decimal, range: [''lower'' => 0, ''upper'' => 100])'
      defaultValue: '0.0'
      imports:
        - 'PSBits\Foundation\Attribute\TCA\ColumnType\Number'
        - 'PSBits\Foundation\Enum\NumberFormat'
      phpType: 'float'
```

Within `attributes`, `docBlock` and `phpType` the placeholders `{foreignField}`, `{linkedClass}`, `{mmTable}`,
`{propertyName}` and `{tableName}` are resolved per property. `crafter.requiredProperty` defines what is added on
top when a property is marked as required, and `crafter.modelCtrl` defines the `#[Ctrl]` attribute of the class.

## Creating a Controller

```bash
vendor/bin/crafter make:controller Product -e my_extension --actions=crud
```

`--actions` takes a comma separated list of actions, `crud` being the shortcut for all of them. Without the option
the actions are picked interactively. `crafter:make:model` offers to create a controller right away, so a model,
its repository and a full CRUD controller can be created in a single run.

Besides the controller class the command creates the Fluid templates, one language file per action and per
configured language, the form fields partial and the plugin registration. Each part can be skipped with
`--no-templates`, `--no-language` or `--no-plugin`. The plugin is declared by adding an `addPlugin()` call to the
existing `ExtensionInformation` class; the actions themselves are registered through the `#[PluginAction]`
attributes on the controller.

Only what was asked for is generated: a controller limited to `list,show` gets no form fields partial, and its
templates contain no links to actions that do not exist. The translation identifiers of the language files are
read back from the rendered templates, so they stay correct even for overridden templates.

The available actions are defined in `crafter.controllerActions`. Every entry names the skeleton for the method
body, the template and its dependencies:

```yaml
crafter:
  controllerActions:
    list:
      attributes:
        - 'PluginAction(default: true)'
      bodySkeleton: 'Php/Action/List'
      returnType: 'ResponseInterface'
      template: 'Fluid/Template/List'
```

`crafter.actionLabels` holds the default texts of the translation identifiers, where `{modelName}` and
`{pluralName}` are resolved per model, and `crafter.formFields` maps a field type to the ViewHelper used in the
form. Setting a type to `~` excludes it from generated forms.

## Creating Supporting Classes

```bash
vendor/bin/crafter make:service Import -e my_extension -d ProductRepository -d PersistenceManager
vendor/bin/crafter make:event-listener ProductEnricher -e my_extension -E 'TYPO3\CMS\Core\Database\Event\AlterTableDefinitionStatementsEvent'
vendor/bin/crafter make:view-helper Teaser -e my_extension --sub-path=Product -a 'product:object:1:The product' -r array
```

`--dependency|-d` may be repeated and takes either a fully qualified class name or a short one. Short names are
resolved through `crafter.dependencies`: `aliases` maps well known classes, `suffixes` maps a class name suffix to
the sub namespace it lives in, so `ProductRepository` becomes `<Extension>\Domain\Repository\ProductRepository`.

Event listeners are registered through the `#[AsEventListener]` attribute, so `Configuration/Services.yaml` stays
untouched. View helper arguments are given as `name[:type[:required[:description]]]`, `--return-type|-r` selects
the return type of `render()`. `--sub-path` puts the class into a sub directory and the matching sub namespace.

What these classes look like is defined in `crafter.classTypes`. Each entry names the parent class, the interfaces,
the traits, the class attributes, the class keywords and the target path, so an own base class or a different
directory layout is pure configuration:

```yaml
crafter:
  classTypes:
    service:
      extends: 'MyVendor\MyExtension\Service\AbstractService'
      keywords: 'readonly'
      pathType: 'service'
      subNamespace: 'Service'
      suffix: 'Service'
```

## Creating a Backend Module

```bash
vendor/bin/crafter make:backend-controller Import -e my_extension -a showUploadForm,import -d ImportService
```

The command creates the controller extending Foundation's `AbstractModuleController`, one Fluid template and one
language file per action, the module label file and the module registration. The first action given becomes the
default action, the methods themselves are written alphabetically.

The module is registered by adding `addMainModule()` and `addModule()` to the existing `ExtensionInformation` and by
creating `Configuration/Backend/Modules.php`, which TYPO3 reads to discover the modules. That file is a one-liner
that hands the `ExtensionInformation` to Foundation's `ModuleService`, so it never has to be touched again and is
only written when it does not exist yet. Running the command again for another controller only adds the missing
`addModule()` call. `--module-name`, `--no-templates`, `--no-language` and `--no-module` control the details. The
three module labels are configured in `crafter.moduleLabels`.

## Customization

Nothing has to be forked. There are three independent layers: skeletons, configuration and events.

### 1. Skeletons

Skeletons are `.stub` files with `{{ placeholder }}` markers and no logic. Loops and ordering happen in PHP, every
repeatable block is a skeleton of its own. A placeholder that sits alone on a line is treated as a block: its
indentation is applied to every inserted line and, if the value is empty, the whole line is removed.

They are looked up in prioritised root paths, the highest priority containing the file wins:

```yaml
skeletonRootPaths:
  0: 'EXT:crafter/Resources/Private/Skeletons/'              # shipped
  10: 'FILE:config/Crafter/Skeletons/'                       # project wide
  20: 'EXT:{targetExtension}/Resources/Private/Skeletons/'   # single extension
```

To use an own copyright block in every generated PHP class, publish and edit a single file:

```bash
vendor/bin/crafter skeleton:publish Php/FileHeader
```
```
/*
 * This file is part of {{ packageTitle }}.
 *
 * (c) {{ year }} {{ copyrightHolder }} <{{ authorEmail }}>
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */
```

Use `crafter:skeleton:show Php/FileHeader -e my_extension` to preview the result and
`crafter:skeleton:show <identifier> --variables` to list all available placeholders.

### 2. Configuration

Values, target paths and defaults are merged from up to five layers, later ones win:

1. `EXT:crafter/Configuration/Crafter.yaml` (shipped defaults)
2. `config/crafter.yaml` (project wide)
3. `EXT:<extension>/Configuration/Crafter.yaml` (single extension)
4. CLI options
5. `ModifyConfigurationEvent` (programmatic, see below)

Maps are merged recursively, lists are replaced completely. Deviating folder structures are pure configuration:

```yaml
crafter:
  paths:
    repository: 'Classes/Repository/{name}Repository.php'
  defaults:
    languages: ['en', 'de', 'fr']
  variables:
    supportUrl: 'https://phantasie-schmiede.de/support'
```

Everything below `variables` is available as `{{ placeholder }}` in every skeleton. `crafter:config:dump --origins`
shows which layer provided which value.

### 3. Events

Whatever cannot be expressed statically is covered by five PSR-14 events. All of them live in
`PSBits\Crafter\Event` and are registered with the `#[AsEventListener]` attribute.

| Event                       | Dispatched                          | Can change                                  |
|-----------------------------|-------------------------------------|---------------------------------------------|
| `ModifyConfigurationEvent`  | after the configuration was merged  | the effective configuration                 |
| `BeforeSkeletonRenderEvent` | before a stub is read               | the skeleton identifier and the variables   |
| `AfterSkeletonRenderEvent`  | after all placeholders were replaced| the rendered content                        |
| `BeforeFileWriteEvent`      | before a file is written            | target path and content, or skips the file  |
| `AfterGenerationEvent`      | when a command has written its files| nothing, it only reports                    |

`ModifyConfigurationEvent` is the topmost configuration layer and is the right place for decisions a YAML file
cannot make, e.g. a different copyright holder per vendor directory:

```php
#[AsEventListener(identifier: 'my_extension/crafterConfiguration')]
public function __invoke(ModifyConfigurationEvent $event): void
{
    if (str_starts_with((string)$event->getExtensionKey(), 'client_')) {
        $event->addOverrides(['defaults' => ['copyrightHolder' => 'Client Ltd.']]);
    }
}
```

Overrides are merged like a regular layer, so `crafter:config:dump --origins` reports them with the origin `event`.

`AfterGenerationEvent` is meant for everything that happens around a generation run, e.g. formatting the result:

```php
#[AsEventListener(identifier: 'my_extension/crafterFormat')]
public function __invoke(AfterGenerationEvent $event): void
{
    if (true === $event->isDryRun()) {
        return;
    }

    foreach ($event->getChangedPaths() as $path) {
        // run a formatter, add the file to the index, ...
    }
}
```

Note that `BeforeSkeletonRenderEvent` and `AfterSkeletonRenderEvent` fire for every stub, including the nested ones
a generator assembles a file from. Check `$event->getIdentifier()` before acting.

## Auditing

```bash
vendor/bin/crafter audit my_extension
vendor/bin/crafter audit my_extension -c language -c orphan --strict
```

`crafter:audit` compares an existing extension against the conventions Crafter generates by. It never writes
anything and derives every expected path from the same `paths` configuration the generators use, so an extension
with an own directory layout is audited against that layout.

| Category       | Checks                                                                                       |
|----------------|:---------------------------------------------------------------------------------------------|
| `structure`    | files listed in `crafter.audit.requiredFiles` exist                                          |
| `model`        | every domain model has a repository                                                          |
| `controller`   | every action that renders something has a template                                           |
| `language`     | labels used in a template exist in the language file, translations exist                     |
| `registration` | every controller is registered as plugin or backend module, module configuration file exists |
| `orphan`       | templates without action, language files without template, repositories without model        |
| `toolchain`    | renewable toolchain files exist and match the generated output                               |

The expected labels are read from the template files themselves, so overridden templates are checked against their
own content instead of a fixed list. Findings are reported with a severity and, where possible, the command that
resolves them. The command exits with a failure code as soon as one error was found, `--strict` extends that to
warnings, which makes it usable as a CI step.

Everything is configurable:

```yaml
crafter:
  audit:
    categories: ['structure', 'model', 'controller', 'language', 'registration', 'orphan', 'toolchain']
    languages: ['de', 'fr']
    requiredFiles:
      - 'Classes/Data/ExtensionInformation.php'
      - 'Configuration/Services.yaml'
      - 'composer.json'
      - 'ext_emconf.php'
```

## Syncing the Toolchain

Every generated extension ships a quality toolchain: the composer scripts, `package.json`, the `Build/Quality/*`
configuration, the pre-commit hook, the CI workflow and the agent instructions (`AGENTS.md`/`CLAUDE.md`). It is
derived from `crafter.tools`, and a tool is enabled when the extension contains one of its signals:

| Tool             | Signals              | Contributes                                            |
|------------------|----------------------|--------------------------------------------------------|
| `classLint`      | `html`               | class attribute lint (Node)                            |
| `composerAudit`  | always               | `composer audit` + `roave/security-advisories`         |
| `phpCsFixer`     | `php`                | `lint:php`/`fix:php`                                   |
| `phpStan`        | `php`                | `analyze:php`                                          |
| `prettier`       | `html`, `xml`, `xlf` | HTML/XML/XLF formatting (Node)                         |
| `typoScriptLint` | `typoscript`         | `lint:typoscript`                                      |
| `yamlLint`       | `yaml`               | `lint:yaml`                                            |

The signals are derived from the extension's files, so a PHP only extension gets the PHP tools and nothing else.
An extension's own `Build/` and `vendor/` are excluded from the scan, so the quality files can never turn a tool on.

`crafter:sync` computes that toolchain for an existing extension and writes everything that is missing or has
drifted:

```bash
vendor/bin/crafter sync budget_tracker
vendor/bin/crafter sync budget_tracker --dry-run
```

Which files are managed and how is defined in `crafter.sync.renewable`:

- `createIfMissing` (`AGENTS.md`, `CLAUDE.md`): written when absent, existing user edits are preserved
- `merge` (`composer.json`, `package.json`): the managed keys are merged, user owned scripts and dependencies survive
- `overwrite` (`Build/*`, workflow files): always brought back to the current generator output

Merge files are compared semantically, so reformatting or reordering an existing `composer.json` or `package.json`
never counts as drift. The toolchain is also kept in sync automatically: every `crafter:make:*` command aligns
these files after writing, and `crafter:audit -c toolchain` reports missing or outdated toolchain files.

## Quality Tools

```bash
composer lint:all
composer test:all
composer qa
```

`composer qa` runs all fixes, all lint checks and the unit tests. `lint:yaml` requires the `yamllint` CLI.

#### Code coverage

CI measures the line coverage of the unit suite once per run (job "Code coverage", TYPO3 13.4 / PHP 8.4 with pcov)
and uploads the clover/cobertura/html reports as the `coverage` artifact. The suite's coverage must not drop below
the baseline in `Build/Quality/coverage-baseline.json` (tolerance: 0.5 points to absorb small drift between PHP
versions); the job fails when it does.

- `composer test:coverage`: runs the unit suite with coverage and checks the baseline
- `composer test:coverage:update-baseline`: runs the unit suite and rewrites `Build/Quality/coverage-baseline.json`
  with the measured value

Both require a coverage driver (pcov or xdebug); without one the suite runs without coverage.

## Tests

The unit tests wire the real service graph against a throwaway project directory, so no database and no running
TYPO3 installation are required. The suite supports TYPO3 13.4 and 14.3; CI runs a matrix over both cores (PHP
8.3/8.4/8.5, plus a lowest-dependency leg) and static analysis separately per core (jobs `qa-v13` and `qa-v14`).
Locally, switch the installed core with `composer update --with "typo3/cms-core:^13.4" -W` (or `^14.3`).

```bash
composer test:unit
```

The generators are covered by golden master tests: every generated file is compared byte by byte with a reviewed
fixture in `Tests/Fixtures/Expected/`. After an intended change of the generated output, rewrite the fixtures and
review the resulting diff before committing it:

```bash
composer test:unit:update-fixtures
git diff Tests/Fixtures/Expected
```

Values that change with every run — the `date` attribute of XLIFF files and the copyright year — are replaced by a
placeholder before the comparison and are stored that way in the fixtures, so the fixtures stay stable over time.

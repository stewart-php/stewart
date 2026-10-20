---
title: 'Console and configuration'
description: 'From bin/stewart to a running broker: container profiles, configuration, app discovery and the run command.'
---

## The entry point

`bin/stewart` finds Composer's autoloader (its own bin proxy path first, then the checkout and vendor layouts) and
keeps the `ClassLoader` as a value. Without one it prints `Run: composer install` and exits 1. It then calls
`ConsoleLauncher::createForInstalledProject($loader)->launchConsole()`.

`Kernel/ConsoleLauncher` takes the project directory from Composer's root package. `launchConsole()` loads `.env`
with `Dotenv::usePutenv()` only if the file exists, so a container that sets its environment directly needs no
`.env`; a malformed one prints `Could not read …` and returns 1. Then it builds the application:
`new ConsoleKernel($projectDir, $loader->getPrefixesPsr4())->createApplication()->run()`.

## Container profiles

Every process is wired by Symfony DI, compiled fresh each time; there is no container cache. `Kernel/ContainerProfile`
names the three profiles and what they load:

| Profile | Config files | Optional packages |
|---|---|---|
| `Console` | `control.php`, `console.php` | codegen |
| `Broker` | `common.php`, `control.php`, `broker.php` | store-redis, mqtt |
| `Worker` | `common.php`, `worker.php` | store-redis |

`Kernel/ProfileCompiler::compileProfileContainer()` loads the profile's files from `config/`, then each optional
package's own `config/services.php`, but only when Composer reports the package installed. That is the whole plugin
mechanism: no install, no services.

Values known only at run time enter in two ways:

- **`SyntheticServices`**: an immutable map from id to instance. It declares the ids as synthetic and public before
  compiling (public, so an unused one survives compilation and can still be set), and sets the instances after
  compiling (setting them earlier drops the definition).
- **`NamedArguments`** through `NamedArgumentsPass`: `$name` bindings on every autowired definition. They are tracked
  bindings, so compiling fails if no service uses a bound name. They exist because the config DSL rejects object
  values under `_defaults`.

`Container/TypedContainer::resolveService()` fetches a service and checks its type once.

## The console

`ConsoleKernel::createApplication()` compiles the `Console` profile with three synthetics (`ProjectRoot`, `Psr4Map`,
`StewartVersion`) and two named arguments (`$appsDirectory`, `$userServicesFile`). `config/console.php` loads
`src/Console/`, `src/Config/` and `src/Control/Client/` as autowired services and tags every `Command`.
`AddConsoleCommandPass` turns the `#[AsCommand]` names into `console.command_loader`, so a command is only constructed
when it runs. Codegen's `GenerateCommand` arrives through codegen's own `config/services.php`.

`Console/StewartApplication` makes `run` the default command, and overrides `getCommandName()` so options placed
before the command name (`stewart -c x.yaml`) belong to `run` instead of being read as a command name. `--help` with no
command name runs `list`, where Symfony would show `run`'s help.

## Configuration

`Config/ConfigLoader::mergeConfigTree()` feeds four inputs, last wins, through Symfony's `Processor`:

1. the tree from `StewartConfigSchema::buildConfigTree()`, which holds every default and validation rule;
2. `stewart.yaml`, read by `ConfigFileReader`, with `${VAR}` and `${VAR:-default}` resolved by
   `EnvironmentPlaceholders`;
3. the `STEWART_*` variables, as overrides built by `EnvironmentOverlay`;
4. command-line overrides (only `--log-level`).

A missing default `stewart.yaml` is an empty input (container setups configure everything through the environment);
a missing `--config` file is an error. An `InvalidConfigurationException` becomes
`ConfigurationException(SchemaViolation)`. `loadConfig()` returns `StewartConfig::fromSection()`, a tree of typed
config objects. `ConfigSection` re-checks types the schema already proved; a mismatch there is a bug, not a user
error, and says so.

`EnvironmentOverlay::collectEnvironmentOverrides()` walks the built node tree rather than knowing any setting's name:

- `EnvironmentVariables::filterByPrefix()` reads `STEWART_*` and skips empty values, which is how "empty means unset"
  works (Docker Compose writes an unset passthrough as an empty string). `STEWART_BOOT_*` and Kubernetes service-link
  variables are skipped.
- A name splits on `__`. Under a node with fixed children, a segment must name a child, or the error suggests the
  closest name (`ClosestNameFinder`). Under `apps` and `options`, where the keys are the user's, a segment matches
  the keys already in the file, treating `-` and `_` alike and folding `MOTION_SENSOR` to `motionSensor`. That is
  why an app ID with `-` needs an entry in the file before a variable can reach it.
- Going past a leaf throws `EnvironmentVariableTooDeep`; two variables for one path throw
  `EnvironmentVariableConflict`.
- A `_FILE` suffix reads the value through `SecretFileReader`, unless the file already names a key ending in
  `_file`.
- `Environment/EnvironmentValueParser` types the value by the node's exact class. A free-form value (an app option)
  takes inline YAML, and a number round-trips through a string compare, so `"007"` stays a string.

In the schema, `normalizeKeys(false)` on `apps` is load-bearing: Symfony would otherwise rewrite `hall-light` to
`hall_light` and orphan its logs and stored data. `home_assistant` is optional in the tree, so `status` and
`config:dump` work without it; `run` and `generate` call `StewartConfig::requireHomeAssistant()`.

## App discovery

`App/AppDiscovery::discover()` scans `apps/`; a missing directory is an empty result.

- `Config/Psr4Map::findAutoloadRootContaining()` inverts Composer's PSR-4 map to find the namespace of `apps/`
  (longest matching root wins, with a separator guard); none is `DirectoryNotAutoloadable`.
- Files come from `Finder`, sorted by name. `AutoloadRoot::findClassNameForFile()` derives the class name from the
  path, trusting PSR-4 instead of parsing PHP; `class_exists()` decides.
- A file that throws while loading is recorded as unloadable. An instantiable `App` without `#[Automation]` is
  recorded as unmarked. A marked class that is not an `App`, not instantiable, or has an invalid ID throws, and two
  IDs that collide once `-` and `_` are equal throw `DuplicateId`.
- `DiscoveredAppCollection::fromApps()` sorts by ID, so worker placement does not depend on the filesystem.

`App/AppCatalogResolver::resolveCatalog()` merges discovery with `apps:` from the config. Discovery is the source of
truth: an override naming no discovered app is kept as unmatched, and `run` and `check` warn about it. `--only`
admits an app even when `enabled: false`. A pinned worker beyond `workers` is an error. The result is an
`AppCatalog` of `AppDefinition`s (ID, class, options, worker, starts paused).

`Console/CheckCommand` resolves the same catalog and plans worker slots with the broker's `AppPlacement`, which the
console profile registers with its `CpuCountDetector`. With `workers: 0` the pool size is the CPU count where `check`
runs, which matches the daemon's in Compose and in a Git-deploy pod.

## The run command

`Console/RunCommand::prepareDaemon()` does, in order: load the config; require `home_assistant` (before a logger
exists, so it fails as a config error); discover; apply `--only`; resolve the catalog; create the stdout logger; log
the discovery warnings (unmarked classes, unloadable files, unmatched overrides, no apps at all), the dry-run warning
and the `--only` notice; then `Kernel/BrokerKernel::createBroker()`.

`BrokerKernel` warns about inconsistent timeouts, compiles the `Broker` profile with the config objects, the catalog
and the logger as synthetics, and resolves `BrokerLifecycle`. The broker container is built after the config is known,
so there is no chicken-and-egg: two containers, each compiled once.

`execute()` runs `$broker->run()`. `BrokerStopOutcome::RestartRequested` becomes exit code 75, which tells the
container's entrypoint to switch to a staged Git release.

Worker placement happens in the broker container: `config/broker.php` builds the `WorkerSlotCollection` with
`Broker/AppPlacement::planWorkerSlots()`, round-robin over unpinned apps in ID order, with a pool of `workers` or
`min(4, CpuCountDetector::detectCpuCount())` (which honours a cgroup CPU quota). Only workers with apps get a slot.

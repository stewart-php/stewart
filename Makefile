SHELL := /bin/bash
.DEFAULT_GOAL := help
.NOTPARALLEL:

export UID := $(shell id -u)
export GID := $(shell id -g)

DOCKER  := docker $(if $(DOCKER_CONTEXT),--context $(DOCKER_CONTEXT))
DC      := $(DOCKER) compose
# Most targets are toolchain only; RUN_APP is for the ones that need Valkey up.
RUN     := $(DC) run --rm --no-deps php
RUN_APP := $(DC) run --rm php
RUNTIME_IMAGE ?= stewart-runtime:local
HELM    := $(DOCKER) run --rm -v "$(CURDIR):/work" -w /work -u "$(UID):$(GID)" -e HOME=/tmp alpine/helm:4.3.0
LINT_RUN := $(DOCKER) run --rm -v "$(CURDIR):/repo" -w /repo
# The skeleton is its own repository; inside this one, actionlint would resolve its ./.github/workflows/ci.yml to ours.
SKELETON_LINT_RUN := $(DOCKER) run --rm -v "$(CURDIR)/skeleton:/repo" -w /repo
WORKFLOWS := .github/workflows/*.yml skeleton/.github/workflows/*.yml
SKELETON_WORKFLOWS := $(patsubst skeleton/%,%,$(wildcard skeleton/.github/workflows/*.yml))
ACTIONS := .github/actions/*/action.yml
DOCKERFILES := .docker/php/Dockerfile .docker/ha/Dockerfile skeleton/Dockerfile
KUBECONFORM := $(DOCKER) run --rm -i ghcr.io/yannh/kubeconform:v0.8.0 -schema-location default \
	-schema-location 'https://raw.githubusercontent.com/datreeio/CRDs-catalog/main/{{.Group}}/{{.ResourceKind}}_{{.ResourceAPIVersion}}.json'

.PHONY: help
help: ## Show this help
	@grep -hE '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

.PHONY: setup
setup: build ha-build install doctor ## First run: build the images, install dependencies, verify the container

.PHONY: build
build: ## Build the dev image, pulling the latest PHP patch
	$(DC) build --pull php

.PHONY: install
install: ## composer install, including the isolated tools under tools/
	$(RUN) composer install --no-interaction
	@for tool in tools/*/; do $(RUN) composer install --no-interaction --working-dir=$$tool || exit 1; done

.PHONY: update
update: ## composer update
	$(RUN) composer update --no-interaction

.PHONY: sh
sh: ## Interactive shell in the dev container
	$(RUN) sh

.PHONY: doctor
doctor: ## Verify the container has what Stewart needs
	$(RUN) php vendor/bin/stewart doctor --offline

.PHONY: loop-driver
loop-driver: ## Print the event loop driver Revolt picks; REVOLT_DRIVER=<class> forces one
	$(RUN) php -r 'require "vendor/autoload.php"; echo get_class(Revolt\EventLoop::getDriver()), PHP_EOL;'

.PHONY: down
down: ## Stop everything
	$(DC) down --remove-orphans

DOCS_RUN := $(DC) run --rm --no-deps docs
DOCS_USER_PORT := 4321
DOCS_DEV_PORT := 4322
SITE ?= user

.PHONY: docs
docs: docs-user ## Live-preview the user documentation

.PHONY: docs-install
docs-install: ## Install the documentation site dependencies from the lockfile
	$(DOCS_RUN) npm ci

.PHONY: docs-diagrams
docs-diagrams: ## Render the PlantUML sources to SVG
	$(DOCKER) run --rm \
		-v "$(CURDIR)/docs/dev/diagrams:/data" \
		-u "$(UID):$(GID)" \
		plantuml/plantuml -tsvg "/data/*.puml"
	@echo "Diagrams rendered to docs/dev/diagrams/*.svg"

.PHONY: docs-clean
docs-clean: ## Remove the documentation site build output and caches
	@rm -rf docs/site/dist docs/site/.astro docs/site/node_modules/.astro
	@echo "Documentation build output and caches removed"

.PHONY: docs-user
docs-user: ## Serve the user documentation at http://localhost:4321/docs/
	$(DC) run --rm --no-deps -p 127.0.0.1:$(DOCS_USER_PORT):$(DOCS_USER_PORT) docs npm run dev:user -- --host 0.0.0.0

.PHONY: docs-dev
docs-dev: docs-diagrams ## Serve the developer documentation at http://localhost:4322/docs-dev/
	$(DC) run --rm --no-deps -p 127.0.0.1:$(DOCS_DEV_PORT):$(DOCS_DEV_PORT) docs npm run dev:dev -- --host 0.0.0.0

.PHONY: docs-build
docs-build: docs-clean docs-diagrams ## Build both documentation sites into docs/site/dist, validating every link
	$(DOCS_RUN) npm run build

.PHONY: docs-build-next
docs-build-next: docs-clean ## Build the unreleased user documentation for /docs/next/
	$(DOCS_RUN) npm run build:next

.PHONY: docs-preview
docs-preview: ## Serve a built documentation site [SITE=user|dev]
	$(DC) run --rm --no-deps -p 127.0.0.1:$(if $(filter dev,$(SITE)),$(DOCS_DEV_PORT):$(DOCS_DEV_PORT),$(DOCS_USER_PORT):$(DOCS_USER_PORT)) docs npm run preview:$(SITE) -- --host 0.0.0.0

# --- quality --------------------------------------------------------------

.PHONY: check
check: stan cs test test-docs test-integration test-process test-persistence test-architecture ha-check ## Run every check

.PHONY: lint-ci
lint-ci: ## Lint and security-audit the GitHub workflows, lint the Dockerfiles
	$(LINT_RUN) rhysd/actionlint:1.7.12 -no-color .github/workflows/*.yml
	$(SKELETON_LINT_RUN) rhysd/actionlint:1.7.12 -no-color $(SKELETON_WORKFLOWS)
	$(LINT_RUN) -e GH_TOKEN ghcr.io/zizmorcore/zizmor:1.30.1 --format=$(if $(GITHUB_ACTIONS),github,plain) $(WORKFLOWS) $(ACTIONS)
	$(LINT_RUN) hadolint/hadolint:v2.15.1 hadolint --no-color $(DOCKERFILES)

.PHONY: stan
stan: ## PHPStan at max level and dependency check (phpat)
	$(RUN) vendor/bin/phpstan analyse --memory-limit=1G

.PHONY: cs
cs: ## Check code style
ifdef GITHUB_ACTIONS
	$(RUN) sh -c 'set -o pipefail; vendor/bin/php-cs-fixer check --format=checkstyle | vendor/bin/cs2pr'
else
	$(RUN) vendor/bin/php-cs-fixer check --diff
endif

.PHONY: cs-fix
cs-fix: ## Fix code style
	$(RUN) vendor/bin/php-cs-fixer fix

.PHONY: test
test: ## Unit tests (no Home Assistant needed)
	$(RUN) vendor/bin/phpunit --testsuite=unit --log-junit var/reports/junit/unit.xml

.PHONY: test-docs
test-docs: ## Tests of the example apps the user documentation includes, and their generated classes
	$(RUN) packages/runtime/bin/stewart generate --config docs/examples/stewart.yaml --snapshot-in docs/examples/snapshot.json --check
	$(RUN) php bin/docs-reference.php --check
	$(RUN) vendor/bin/phpunit --testsuite=docs --log-junit var/reports/junit/docs.xml

.PHONY: docs-reference
docs-reference: ## Write the generated reference pages of the user documentation from the code
	$(RUN) php bin/docs-reference.php

.PHONY: docs-examples-generate
docs-examples-generate: ## Regenerate the typed classes the documentation examples use, from docs/examples/snapshot.json
	$(RUN) packages/runtime/bin/stewart generate --config docs/examples/stewart.yaml --snapshot-in docs/examples/snapshot.json

.PHONY: test-process
test-process: ## Worker process smoke tests against Valkey (no Home Assistant needed)
	$(RUN_APP) vendor/bin/phpunit --testsuite=process --log-junit var/reports/junit/process.xml

.PHONY: test-persistence
test-persistence: ## Tests against the Valkey and Mosquitto services (no Home Assistant needed)
	$(RUN_APP) vendor/bin/phpunit --testsuite=persistence --log-junit var/reports/junit/persistence.xml

.PHONY: test-integration
test-integration: ## Cross-package tests over real sockets (no Home Assistant needed)
	$(RUN) vendor/bin/phpunit --testsuite=integration --log-junit var/reports/junit/integration.xml

.PHONY: test-architecture
test-architecture: ## Repository-wide guards over every package (monorepo only)
	$(RUN) vendor/bin/phpunit --testsuite=architecture --log-junit var/reports/junit/architecture.xml

PACKAGES := $(notdir $(wildcard packages/*))

.PHONY: test-package
test-package: ## Install one package on its own, run its tests and check its declared dependencies (PKG=name [SUITE=unit] [LOWEST=1])
	$(RUN_APP) env SUITE=$(SUITE) LOWEST=$(LOWEST) sh bin/test-package.sh $(PKG)

.PHONY: coverage
coverage: ## Coverage of every suite but architecture: clover, HTML and the XML and JUnit logs Infection reads
	$(RUN_APP) php -d pcov.enabled=1 -d pcov.directory=/app/packages -d 'pcov.exclude=~/(tests|vendor)/~' vendor/bin/phpunit \
		--testsuite=unit,integration,process,persistence \
		--coverage-clover var/reports/coverage/clover.xml \
		--coverage-html var/reports/coverage/html \
		--coverage-xml var/reports/coverage/coverage-xml \
		--log-junit var/reports/coverage/junit.xml \
		--coverage-text=var/reports/coverage/summary.txt --only-summary-for-coverage-text

.PHONY: infection
infection: ## Mutation testing over the coverage run; INFECTION_BASE=origin/main limits it to lines changed since that ref
	$(RUN_APP) tools/infection/vendor/bin/infection --coverage=var/reports/coverage --skip-initial-tests --threads=max --no-progress \
		$(if $(INFECTION_BASE),--git-diff-lines --git-diff-base=$(INFECTION_BASE)) $(if $(GITHUB_ACTIONS),--logger-github)

.PHONY: metrics
metrics: ## phpmetrics HTML, JSON and violation reports; on CI, METRICS_BASE=<ref> annotates the violations in files changed since that ref
	@mkdir -p var/reports/metrics
	$(RUN) php -d 'error_reporting=E_ALL & ~E_DEPRECATED' tools/phpmetrics/vendor/bin/phpmetrics packages \
		--report-html=var/reports/metrics/html \
		--report-json=var/reports/metrics/metrics.json \
		--report-summary-json=var/reports/metrics/summary.json \
		--report-violations=var/reports/metrics/violations.xml
ifdef GITHUB_ACTIONS
ifdef METRICS_BASE
	$(RUN) php bin/annotate-metric-violations.php var/reports/metrics/violations.xml $(METRICS_BASE)
endif
endif

.PHONY: quality-summary
quality-summary: ## Headline numbers from var/reports into summary.json and summary.md; QUALITY_BASELINE=<summary.json> adds changes
	$(RUN) php bin/quality-summary.php var/reports $(QUALITY_BASELINE)

.PHONY: bc-check
bc-check: ## Public API backwards-compatibility breaks in contracts and testing since BC_BASE (default: the latest tag)
	$(RUN) env BC_FORMAT=$(if $(GITHUB_ACTIONS),github-actions,console) sh bin/bc-check.sh $(or $(BC_BASE),$(shell git describe --tags --abbrev=0))

.PHONY: audit
audit: ## Report known vulnerabilities and abandoned packages in the locked dependencies
	$(RUN) composer audit --locked --abandoned=report

.PHONY: test-packages
test-packages: ## Run test-package for every package
	@for package in $(PACKAGES); do $(MAKE) --no-print-directory test-package PKG=$$package || exit 1; done

.PHONY: image
image: ## Build the runtime image as published, and its -dev variant [RUNTIME_IMAGE=stewart-runtime:local]
	$(DOCKER) build --pull -f .docker/php/Dockerfile --target runtime -t $(RUNTIME_IMAGE) .
	$(DOCKER) build --pull -f .docker/php/Dockerfile --target runtime-dev -t $(RUNTIME_IMAGE)-dev .

.PHONY: test-image
test-image: image ## Smoke-test the runtime image: a skeleton project mounted, then cloned at boot
	RUNTIME_IMAGE=$(RUNTIME_IMAGE) sh bin/test-image.sh

.PHONY: chart-lint
chart-lint: ## Lint the Helm chart and validate what it renders for each charts/stewart/ci/*-values.yaml
	@for values in charts/stewart/ci/*-values.yaml; do \
		echo "--- $$values"; \
		$(HELM) lint --strict charts/stewart -f $$values || exit 1; \
		$(HELM) template stewart charts/stewart -f $$values | $(KUBECONFORM) -strict -summary || exit 1; \
	done

.PHONY: test-skeleton
test-skeleton: ## Install skeleton/ against this checkout and run a new project's first steps
	$(RUN) sh bin/test-skeleton.sh

.PHONY: test-upgrade
test-upgrade: ## Upgrade a 1.8.0 project to this checkout with the changelog's upgrading commands, then check it
	$(RUN) sh bin/test-upgrade.sh

# --- home assistant integration -------------------------------------------

# HA=min tests against the oldest supported Home Assistant, HA=latest (default) against the newest pinned one.
export HA ?= latest
HA_RUN := $(DC) run --rm ha
HASSFEST_IMAGE := ghcr.io/home-assistant/hassfest@sha256:c79061c27ff0b4c43534d9a0ae67b19fe320261be8c0aded32b117e0272237e3
# The Home Assistant core that requirements/ha-<HA>.txt resolves to.
HA_E2E_IMAGE_min := ghcr.io/home-assistant/home-assistant:2026.4.4@sha256:c1e5f0147f4cb51ccb05bb30b62a1269cc1bd48a6274792d3b38a77ab274dfd2
HA_E2E_IMAGE_latest := ghcr.io/home-assistant/home-assistant:2026.10.0@sha256:1b64d38f38d922bf9d59336451fd6453e1d614f934456af4ee3d2a51061be3a4
HA_E2E_DRIVER := /app/.docker/ha-e2e

.PHONY: ha-build
ha-build: ## Build the integration's Python toolchain image [HA=min|latest]
	$(DC) build ha

.PHONY: ha-sh
ha-sh: ## Shell in the integration's toolchain [HA=min|latest]
	$(HA_RUN) sh

.PHONY: ha-check
ha-check: ## Lint, type-check and test the Home Assistant integration [HA=min|latest]
	$(HA_RUN) sh -c 'ruff check && ruff format --check && mypy && pytest'
	$(HA_RUN) sh -c 'ruff check --config pyproject.toml $(HA_E2E_DRIVER) && ruff format --config pyproject.toml --check $(HA_E2E_DRIVER) && mypy $(HA_E2E_DRIVER)'

.PHONY: ha-hassfest
ha-hassfest: ## Validate the integration with Home Assistant's hassfest
	$(DOCKER) run --rm -v "$(CURDIR)/integrations/home-assistant:/github/workspace:ro" $(HASSFEST_IMAGE)

.PHONY: test-ha-e2e
test-ha-e2e: ## Boot a real Home Assistant with the integration and smoke-test exposure [HA=min|latest]
	HA_E2E_IMAGE=$(HA_E2E_IMAGE_$(HA)) sh bin/test-ha-e2e.sh

# --- demo -----------------------------------------------------------------

# var/demo is the skeleton linked to this checkout; package edits show up without reinstalling.
DEMO := $(DC) run --rm -w /app/var/demo

.PHONY: demo
demo: demo-create ## Run the demo project in var/demo [ONLY="hello porch", DRY_RUN=1]
	$(DEMO) php vendor/bin/stewart run $(addprefix --only=,$(ONLY))$(if $(DRY_RUN), --dry-run)

.PHONY: demo-create
demo-create: ## Create var/demo from skeleton/ unless it exists; apps/ and .env come back from the last demo-clean
	@test -d var/demo/vendor || $(RUN) sh bin/demo.sh create

.PHONY: demo-sh
demo-sh: demo-create ## Shell in var/demo with Valkey and Mosquitto up
	$(DEMO) php sh

.PHONY: demo-clean
demo-clean: ## Delete var/demo and stop its services, keeping apps/ and .env for the next demo [PURGE=1 drops them]
	$(RUN) env PURGE=$(PURGE) sh bin/demo.sh clean
	$(DC) stop valkey mosquitto

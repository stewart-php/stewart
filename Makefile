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
KUBECONFORM := $(DOCKER) run --rm -i ghcr.io/yannh/kubeconform:v0.8.0 -schema-location default \
	-schema-location 'https://raw.githubusercontent.com/datreeio/CRDs-catalog/main/{{.Group}}/{{.ResourceKind}}_{{.ResourceAPIVersion}}.json'

.PHONY: help
help: ## Show this help
	@grep -hE '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

.PHONY: setup
setup: build install doctor ## First run: build the image, install dependencies, verify the container

.PHONY: build
build: ## Build the dev image
	$(DC) build php

.PHONY: install
install: ## composer install
	$(RUN) composer install --no-interaction

.PHONY: update
update: ## composer update
	$(RUN) composer update --no-interaction

.PHONY: sh
sh: ## Interactive shell in the dev container
	$(RUN) sh

.PHONY: doctor
doctor: ## Verify the container has what Stewart needs
	$(RUN) php vendor/bin/stewart doctor

.PHONY: down
down: ## Stop everything
	$(DC) down --remove-orphans

DOCS_RUN := $(DC) run --rm --no-deps docs

.PHONY: docs
docs: docs-serve ## Live-preview the documentation site

.PHONY: docs-install
docs-install: ## Install the documentation site dependencies
	$(DOCS_RUN) npm install

.PHONY: docs-diagrams
docs-diagrams: ## Render the PlantUML sources to SVG
	@mkdir -p docs/pages/diagrams
	$(DOCKER) run --rm \
		-v "$(CURDIR)/docs/diagrams:/data" \
		-v "$(CURDIR)/docs/pages/diagrams:/out" \
		-u "$(UID):$(GID)" \
		plantuml/plantuml -tsvg -o /out "/data/*.puml"
	@echo "Diagrams rendered to docs/pages/diagrams/*.svg"

.PHONY: docs-clean
docs-clean: ## Remove the documentation site build output and caches
	@rm -rf docs/site/dist docs/site/.astro docs/site/node_modules/.astro
	@echo "Documentation build output and caches removed"

.PHONY: docs-serve
docs-serve: docs-diagrams ## Serve the documentation site at http://localhost:4321
	$(DC) run --rm --no-deps --service-ports docs npm run dev -- --host 0.0.0.0

.PHONY: docs-build
docs-build: docs-clean docs-diagrams ## Build the documentation site, validating every link
	$(DOCS_RUN) npm run build

.PHONY: docs-preview
docs-preview: ## Serve the built documentation site
	$(DC) run --rm --no-deps --service-ports docs npm run preview -- --host 0.0.0.0

# --- quality --------------------------------------------------------------

.PHONY: check
check: stan cs test test-integration test-process test-persistence test-architecture ## Run every check

.PHONY: stan
stan: ## PHPStan at max level and dependency check (phpat)
	$(RUN) vendor/bin/phpstan analyse --memory-limit=1G

.PHONY: cs
cs: ## Check code style
	$(RUN) vendor/bin/php-cs-fixer check --diff

.PHONY: cs-fix
cs-fix: ## Fix code style
	$(RUN) vendor/bin/php-cs-fixer fix

.PHONY: test
test: ## Unit tests (no Home Assistant needed)
	$(RUN) vendor/bin/phpunit --testsuite=unit

.PHONY: test-process
test-process: ## Worker process smoke tests against Valkey (no Home Assistant needed)
	$(RUN_APP) vendor/bin/phpunit --testsuite=process

.PHONY: test-persistence
test-persistence: ## Tests against the Valkey and Mosquitto services (no Home Assistant needed)
	$(RUN_APP) vendor/bin/phpunit --testsuite=persistence

.PHONY: test-integration
test-integration: ## Cross-package tests over real sockets (no Home Assistant needed)
	$(RUN) vendor/bin/phpunit --testsuite=integration

.PHONY: test-architecture
test-architecture: ## Repository-wide guards over every package (monorepo only)
	$(RUN) vendor/bin/phpunit --testsuite=architecture

PACKAGES := $(notdir $(wildcard packages/*))

.PHONY: test-package
test-package: ## Install one package on its own and run its tests (PKG=name [SUITE=unit] [LOWEST=1])
	$(RUN_APP) env SUITE=$(SUITE) LOWEST=$(LOWEST) sh bin/test-package.sh $(PKG)

.PHONY: test-packages
test-packages: ## Run test-package for every package
	@for package in $(PACKAGES); do $(MAKE) --no-print-directory test-package PKG=$$package || exit 1; done

.PHONY: image
image: ## Build the runtime image as published, and its -dev variant [RUNTIME_IMAGE=stewart-runtime:local]
	$(DOCKER) build -f .docker/php/Dockerfile --target runtime -t $(RUNTIME_IMAGE) .
	$(DOCKER) build -f .docker/php/Dockerfile --target runtime-dev -t $(RUNTIME_IMAGE)-dev .

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

# --- demo -----------------------------------------------------------------

# var/demo is the skeleton linked to this checkout; package edits show up without reinstalling.
DEMO := $(DC) run --rm -w /app/var/demo

.PHONY: demo
demo: demo-create ## Run the demo project in var/demo [ONLY="hello porch", DRY_RUN=1]
	$(DEMO) $(if $(DRY_RUN),-e STEWART_SERVICE_CALLS__DRY_RUN=true) php vendor/bin/stewart run $(addprefix --only=,$(ONLY))

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

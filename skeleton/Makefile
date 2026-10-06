SHELL := /bin/bash
.DEFAULT_GOAL := help
.NOTPARALLEL:

export UID := $(shell id -u)
export GID := $(shell id -g)

# Development runs with compose.dev.yaml on top; the server targets use compose.yaml alone.
DEV     := docker compose -f compose.yaml -f compose.dev.yaml
RUN     := $(DEV) run --rm --no-deps stewart
RUN_APP := $(DEV) run --rm
SERVER  := docker compose

.PHONY: help
help: ## Show this help
	@grep -hE '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

# --- development -------------------------------------------------------

.PHONY: setup
setup: pull env install doctor ## First run: pull the images, create .env, install dependencies, verify

.PHONY: pull
pull: ## Pull the runtime images
	$(DEV) pull

.PHONY: env
env: ## Create .env from .env.example with a fresh control token, unless it exists
	@test -f .env || $(RUN) composer run-script create-env

.PHONY: install
install: ## composer install
	$(RUN) composer install --no-interaction

.PHONY: update
update: ## composer update
	$(RUN) composer update --no-interaction

.PHONY: upgrade
upgrade: ## Move to another Stewart release line, then pull its images and install it [VERSION=0.6]
	$(RUN) sh bin/upgrade-stewart.sh $(VERSION)
	$(DEV) pull
	$(RUN) composer update 'stewart-php/*' --with-all-dependencies --no-interaction

.PHONY: sh
sh: ## Shell in the development container
	$(RUN) sh

.PHONY: doctor
doctor: ## Check the container can run Stewart
	$(RUN) stewart doctor

.PHONY: generate
generate: ## Write typed entity and service classes from Home Assistant into generated/ [ARGS=--allow-shrink]
	$(RUN) stewart generate $(ARGS)

.PHONY: run
run: ## Run in the foreground [ONLY="hello porch" to run only those apps, DRY_RUN=1 to send no service calls]
	$(RUN_APP) $(if $(DRY_RUN),-e STEWART_SERVICE_CALLS__DRY_RUN=true) stewart run $(addprefix --only=,$(ONLY))

.PHONY: debug
debug: ## Run in the foreground with Xdebug
	$(RUN_APP) -e XDEBUG_MODE=debug,develop -e XDEBUG_TRIGGER=1 stewart run $(addprefix --only=,$(ONLY))

.PHONY: config
config: ## Show the effective config, secrets masked
	$(RUN) stewart config:dump

.PHONY: config-reference
config-reference: ## Show every supported config option
	$(RUN) stewart config:reference

.PHONY: test
test: ## Run the tests
	$(RUN) vendor/bin/phpunit

.PHONY: stan
stan: ## Static analysis
	$(RUN) vendor/bin/phpstan analyse

# --- server ---------------------------------------------------------------

.PHONY: up
up: ## Start the daemon in the background
	$(SERVER) up -d

.PHONY: down
down: ## Stop everything
	$(SERVER) down --remove-orphans

.PHONY: deploy
deploy: ## Pull the latest commit and restart; changed dependencies are installed on start
	git pull --ff-only
	$(SERVER) up -d --force-recreate stewart

.PHONY: logs
logs: ## Follow the daemon logs
	$(SERVER) logs -f stewart

.PHONY: status
status: ## One-shot status of the running daemon
	$(SERVER) run --rm --no-deps stewart stewart status

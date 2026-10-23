# stewart-php/phpstan

PHPStan rules for a Stewart project: entity IDs and action names written in the code are checked against the classes
`stewart generate` wrote, so `getState('light.kitchn')` or `callAction('light.turn_onn')` fails `make stan` instead of
an automation at run time.

```bash
composer config allow-plugins.phpstan/extension-installer true
composer require --dev stewart-php/phpstan phpstan/extension-installer
```

Part of [Stewart](https://github.com/stewart-php/stewart): Home Assistant automations in PHP. Start a project from
[stewart-php/skeleton](https://github.com/stewart-php/skeleton) rather than requiring packages one by one.

Documentation: [stewart-php.github.io/docs](https://stewart-php.github.io/docs/guides/codegen/#typos-caught-by-phpstan).

This repository is a read-only split of the monorepo. Open issues and pull requests at
[stewart-php/stewart](https://github.com/stewart-php/stewart).

## License

MIT; see [LICENSE](LICENSE).

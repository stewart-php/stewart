# stewart-php/mqtt

MQTT 3.1.1 client that connects Stewart apps to an MQTT server. Non-blocking, over amphp.

```bash
composer require stewart-php/mqtt
```

Set `mqtt.url` in `stewart.yaml`, then take `Stewart\Contracts\Mqtt\Mqtt` in an app's constructor to publish and to
watch topic filters (`+` and `#`).

Part of [Stewart](https://github.com/stewart-php/stewart): Home Assistant automations in PHP. Start a project from
[stewart-php/skeleton](https://github.com/stewart-php/skeleton) rather than requiring packages one by one.

This repository is a read-only split of the monorepo. Open issues and pull requests at
[stewart-php/stewart](https://github.com/stewart-php/stewart).

## License

MIT; see [LICENSE](LICENSE).

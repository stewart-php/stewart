# stewart-php/testing

Test Stewart apps without Home Assistant. `AppHarness` builds an app with its options and recording fakes, and
runs timers, schedules and waits on virtual time.

```bash
composer require --dev stewart-php/testing
```

```php
$harness = new AppHarness();
$harness->ha->seedState('binary_sensor.front_door', 'off');
$harness->startApp(DoorAlarmApp::class, ['door' => 'binary_sensor.front_door']);

$harness->ha->pushState('binary_sensor.front_door', 'on');
$harness->advanceTime(Duration::minutes(5));

self::assertSame('notify.notify', $harness->ha->getLastCall()->getServiceName());
```

Part of [Stewart](https://github.com/stewart-php/stewart): Home Assistant automations in PHP. Start a project from
[stewart-php/skeleton](https://github.com/stewart-php/skeleton) rather than requiring packages one by one.

This repository is a read-only split of the monorepo. Open issues and pull requests at
[stewart-php/stewart](https://github.com/stewart-php/stewart).

## License

MIT; see [LICENSE](LICENSE).

# Changelog

Every package, the `ghcr.io/stewart-php/runtime` image, the Helm chart and the skeleton share one version. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). On 0.x, a minor release may break; its "Upgrading"
section says what to change.

## [Unreleased]

### Added

- MQTT 3.1.1 support in the new `stewart-php/mqtt` package: apps take `Mqtt` to publish and to watch `+`/`#` topic
  filters; configured under `mqtt` in `stewart.yaml`. QoS 0 and 1, retain, TLS, last will, reconnect with resubscribe.

## [0.1.0] - 2026-10-03

First release.

### Added

- First dev-only version release

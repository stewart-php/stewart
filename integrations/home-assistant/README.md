# Stewart for Home Assistant

Lets [Stewart](https://github.com/stewart-php/stewart) apps create and drive their own Home Assistant entities. Stewart
finds the integration by itself on every connect; there is nothing to configure on either side.

This repository is a read-only mirror of `integrations/home-assistant` in
[stewart-php/stewart](https://github.com/stewart-php/stewart). Report issues and send changes there.

## Requirements

- Home Assistant 2026.4 or newer.
- Stewart of the same version as the integration.
- Stewart's access token belongs to an administrator.

## Installation

### HACS

1. In HACS, open the menu, choose *Custom repositories*, and add `https://github.com/stewart-php/hass-stewart` with
   the category *Integration*.
2. Download *Stewart* and restart Home Assistant.

### Manually

1. Download `stewart.zip` from the [release](https://github.com/stewart-php/hass-stewart/releases) that matches your
   Stewart version.
2. Unpack it into `<config>/custom_components/stewart/` and restart Home Assistant.

## Setup

Go to *Settings → Devices & services → Add integration*, pick *Stewart* and confirm. One entry serves every Stewart
instance that connects to this Home Assistant.

## Protocol

Stewart talks to the integration over Home Assistant's WebSocket API. [PROTOCOL.md](PROTOCOL.md) specifies every
message.

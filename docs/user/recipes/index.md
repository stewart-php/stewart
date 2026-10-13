---
title: Recipes
description: Complete, tested automations to copy into your project and adapt.
sidebar:
  order: 1
---

Each recipe is a complete app with its test. Copy the app into `apps/` and the test into `tests/`, set the options to
your own entity IDs in `stewart.yaml`, and run `make test`. Every recipe runs in Stewart's own test suite, so the code
on these pages works with the current version.

| Recipe | Does |
|---|---|
| [Motion light](motion-light.md) | Turns a light on with motion after dark, dimmed late at night, and off when the motion stops |
| [Heating by presence](presence-heating.md) | Lowers the heating when everyone has left, and raises it when someone comes home |
| [Door left open](door-left-open.md) | Reminds you about an open door every few minutes until it closes |
| [Blinds against the sun](sun-blinds.md) | Closes the blinds when the sun shines on the window on a hot day, and opens them at sunset |
| [Daily energy summary](daily-energy.md) | Sends today's and the week's energy use every evening |
| [Zigbee remote over MQTT](zigbee-remote.md) | Controls a light from a Zigbee2MQTT remote, without a Home Assistant automation |
| [Guest mode switch](guest-mode.md) | A switch in Home Assistant that warms the guest room and tells your other apps |

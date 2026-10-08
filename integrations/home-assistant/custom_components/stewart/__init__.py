from homeassistant.config_entries import ConfigEntry
from homeassistant.core import HomeAssistant

from .websocket import async_register_commands

type StewartConfigEntry = ConfigEntry[None]


async def async_setup_entry(hass: HomeAssistant, entry: StewartConfigEntry) -> bool:  # noqa: ARG001
    async_register_commands(hass)
    return True


async def async_unload_entry(hass: HomeAssistant, entry: StewartConfigEntry) -> bool:  # noqa: ARG001
    return True

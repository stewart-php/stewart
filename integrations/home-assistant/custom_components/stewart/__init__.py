from homeassistant.config_entries import ConfigEntry
from homeassistant.core import HomeAssistant

type StewartConfigEntry = ConfigEntry[None]


async def async_setup_entry(hass: HomeAssistant, entry: StewartConfigEntry) -> bool:  # noqa: ARG001
    return True


async def async_unload_entry(hass: HomeAssistant, entry: StewartConfigEntry) -> bool:  # noqa: ARG001
    return True

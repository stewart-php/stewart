from homeassistant.core import HomeAssistant

from .session import SessionRegistry, StewartConfigEntry
from .websocket import async_register_commands


async def async_setup_entry(hass: HomeAssistant, entry: StewartConfigEntry) -> bool:
    entry.runtime_data = SessionRegistry()
    async_register_commands(hass)
    return True


async def async_unload_entry(hass: HomeAssistant, entry: StewartConfigEntry) -> bool:  # noqa: ARG001
    entry.runtime_data.end_all_sessions()
    return True

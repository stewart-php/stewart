from homeassistant.core import HomeAssistant

from .runtime import StewartConfigEntry, StewartRuntime
from .session import SessionRegistry
from .websocket import async_register_commands


async def async_setup_entry(hass: HomeAssistant, entry: StewartConfigEntry) -> bool:
    entry.runtime_data = StewartRuntime(sessions=SessionRegistry(hass))
    async_register_commands(hass)
    return True


async def async_unload_entry(hass: HomeAssistant, entry: StewartConfigEntry) -> bool:  # noqa: ARG001
    entry.runtime_data.sessions.end_all_sessions()
    return True

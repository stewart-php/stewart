from homeassistant.core import HomeAssistant
from homeassistant.helpers import entity_registry as er

from .const import PLATFORMS
from .devices import DeviceDirectory
from .exposure import ExposedEntities
from .runtime import StewartConfigEntry, StewartRuntime
from .session import SessionRegistry
from .websocket import async_register_commands


async def async_setup_entry(hass: HomeAssistant, entry: StewartConfigEntry) -> bool:
    sessions = SessionRegistry(hass)
    entities = ExposedEntities(sessions, DeviceDirectory(hass, entry.entry_id), er.async_get(hass))
    entry.runtime_data = StewartRuntime(sessions=sessions, entities=entities)
    entry.async_on_unload(hass.bus.async_listen(er.EVENT_ENTITY_REGISTRY_UPDATED, entities.forget_removed_entry))
    async_register_commands(hass)
    await hass.config_entries.async_forward_entry_setups(entry, PLATFORMS)
    return True


async def async_unload_entry(hass: HomeAssistant, entry: StewartConfigEntry) -> bool:
    entry.runtime_data.sessions.end_all_sessions()
    return await hass.config_entries.async_unload_platforms(entry, PLATFORMS)

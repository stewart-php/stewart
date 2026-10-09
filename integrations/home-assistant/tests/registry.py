from homeassistant.core import HomeAssistant
from homeassistant.helpers import device_registry as dr
from homeassistant.helpers.device_registry import DeviceEntry
from pytest_homeassistant_custom_component.common import MockConfigEntry

from custom_components.stewart.const import DOMAIN


def find_device(hass: HomeAssistant, entry: MockConfigEntry, identifier: str) -> DeviceEntry | None:
    devices = dr.async_entries_for_config_entry(dr.async_get(hass), entry.entry_id)
    return next((device for device in devices if (DOMAIN, identifier) in device.identifiers), None)

from typing import Any

from homeassistant.components import websocket_api
from homeassistant.core import HomeAssistant
from homeassistant.helpers import device_registry as dr
from homeassistant.helpers.device_registry import DeviceEntry
from pytest_homeassistant_custom_component.common import MockConfigEntry
from pytest_homeassistant_custom_component.typing import MockHAClientWebSocket

from custom_components.stewart.const import DOMAIN
from tests.exchange import send_request


def find_device(hass: HomeAssistant, entry: MockConfigEntry, identifier: str) -> DeviceEntry | None:
    devices = dr.async_entries_for_config_entry(dr.async_get(hass), entry.entry_id)
    return next((device for device in devices if (DOMAIN, identifier) in device.identifiers), None)


# Home Assistant 2026.4 knows only remove_config_entry.
async def remove_device(client: MockHAClientWebSocket, entry: MockConfigEntry, device_id: str) -> dict[str, Any]:
    response = await send_request(client, {"type": "config/device_registry/remove", "device_id": device_id})
    if response.get("error", {}).get("code") != websocket_api.ERR_UNKNOWN_COMMAND:
        return response
    return await send_request(
        client,
        {
            "type": "config/device_registry/remove_config_entry",
            "config_entry_id": entry.entry_id,
            "device_id": device_id,
        },
    )

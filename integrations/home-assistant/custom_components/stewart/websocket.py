from typing import Any

import voluptuous as vol
from homeassistant.components import websocket_api
from homeassistant.core import HomeAssistant, callback
from homeassistant.loader import async_get_integration

from .const import DOMAIN, PROTOCOL


@callback
def async_register_commands(hass: HomeAssistant) -> None:
    websocket_api.async_register_command(hass, websocket_version)


@websocket_api.websocket_command({vol.Required("type"): "stewart/version"})
@websocket_api.require_admin
@websocket_api.async_response
async def websocket_version(
    hass: HomeAssistant, connection: websocket_api.ActiveConnection, msg: dict[str, Any]
) -> None:
    if not _is_entry_loaded(hass, connection, msg):
        return
    integration = await async_get_integration(hass, DOMAIN)
    connection.send_result(msg["id"], {"component_version": str(integration.version), "protocol": PROTOCOL})


# Commands outlive an unload; answering like a missing integration lets Stewart detect both the same way.
def _is_entry_loaded(hass: HomeAssistant, connection: websocket_api.ActiveConnection, msg: dict[str, Any]) -> bool:
    if hass.config_entries.async_loaded_entries(DOMAIN):
        return True
    connection.send_error(msg["id"], websocket_api.ERR_UNKNOWN_COMMAND, "Unknown command.")
    return False

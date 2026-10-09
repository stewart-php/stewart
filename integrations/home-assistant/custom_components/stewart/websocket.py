from functools import partial
from typing import Any

import voluptuous as vol
from homeassistant.components import websocket_api
from homeassistant.core import HomeAssistant, callback
from homeassistant.loader import async_get_integration

from .const import DOMAIN, ERR_PROTOCOL_MISMATCH, INSTANCE_PATTERN, MAX_COMMAND_TIMEOUT_SECONDS, PROTOCOL
from .runtime import StewartConfigEntry
from .session import Session


@callback
def async_register_commands(hass: HomeAssistant) -> None:
    websocket_api.async_register_command(hass, websocket_version)
    websocket_api.async_register_command(hass, websocket_session_subscribe)


@websocket_api.websocket_command({vol.Required("type"): "stewart/version"})
@websocket_api.require_admin
@websocket_api.async_response
async def websocket_version(
    hass: HomeAssistant, connection: websocket_api.ActiveConnection, msg: dict[str, Any]
) -> None:
    if _find_loaded_entry(hass, connection, msg) is None:
        return
    integration = await async_get_integration(hass, DOMAIN)
    connection.send_result(msg["id"], {"component_version": str(integration.version), "protocol": PROTOCOL})


@websocket_api.websocket_command(
    {
        vol.Required("type"): "stewart/session/subscribe",
        vol.Required("instance"): vol.Match(INSTANCE_PATTERN),
        vol.Required("protocol"): int,
        vol.Required("stewart_version"): str,
        vol.Required("command_timeout"): vol.All(
            vol.Coerce(float), vol.Range(min=0, min_included=False, max=MAX_COMMAND_TIMEOUT_SECONDS)
        ),
    }
)
@websocket_api.require_admin
@callback
def websocket_session_subscribe(
    hass: HomeAssistant, connection: websocket_api.ActiveConnection, msg: dict[str, Any]
) -> None:
    if (entry := _find_loaded_entry(hass, connection, msg)) is None:
        return
    if msg["protocol"] != PROTOCOL:
        connection.send_error(
            msg["id"],
            ERR_PROTOCOL_MISMATCH,
            f"Stewart speaks protocol {msg['protocol']}, but this component speaks protocol {PROTOCOL}.",
        )
        return

    session = Session(
        instance=msg["instance"],
        connection=connection,
        subscription_id=msg["id"],
        stewart_version=msg["stewart_version"],
        command_timeout=msg["command_timeout"],
    )
    connection.subscriptions[msg["id"]] = partial(entry.runtime_data.sessions.close_session, session)
    entry.runtime_data.sessions.open_session(session)
    connection.send_result(msg["id"])


# Commands outlive an unload; answering like a missing integration lets Stewart detect both the same way.
def _find_loaded_entry(
    hass: HomeAssistant, connection: websocket_api.ActiveConnection, msg: dict[str, Any]
) -> StewartConfigEntry | None:
    if entries := hass.config_entries.async_loaded_entries(DOMAIN):
        return entries[0]
    connection.send_error(msg["id"], websocket_api.ERR_UNKNOWN_COMMAND, "Unknown command.")
    return None

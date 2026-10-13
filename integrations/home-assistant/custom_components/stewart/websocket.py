from collections.abc import Awaitable, Callable
from functools import partial, wraps
from typing import Any

import voluptuous as vol
from homeassistant.components import websocket_api
from homeassistant.core import HomeAssistant, callback
from homeassistant.loader import async_get_integration

from .action import ACTION_PLATFORM, ActionConfig
from .change import EntityChange, EntityUpsert
from .command import CommandAnswer
from .const import (
    APP_PATTERN,
    DEVICE_IDENTIFIER_PATTERN,
    DOMAIN,
    ERR_PROTOCOL_MISMATCH,
    INSTANCE_PATTERN,
    KEY_PATTERN,
    MAX_COMMAND_TIMEOUT_SECONDS,
    PROTOCOL,
)
from .errors import ExposureError
from .identity import EntityAddress, KeptEntities
from .runtime import StewartConfigEntry, StewartRuntime
from .session import Session

ENTITY_ADDRESS_SCHEMA = {
    vol.Required("instance"): vol.Match(INSTANCE_PATTERN),
    vol.Required("app"): vol.Match(APP_PATTERN),
    vol.Required("key"): vol.Match(KEY_PATTERN),
}

KEPT_ENTITY_SCHEMA = vol.Schema(
    {
        vol.Required("app"): vol.Match(APP_PATTERN),
        vol.Required("key"): vol.Match(KEY_PATTERN),
    }
)

DEVICE_SCHEMA = vol.Schema(
    {
        vol.Required("identifier"): vol.Match(DEVICE_IDENTIFIER_PATTERN),
        vol.Required("name"): str,
        vol.Optional("manufacturer"): str,
        vol.Optional("model"): str,
        vol.Optional("suggested_area"): str,
    }
)

type ExposureHandler = Callable[[StewartRuntime, dict[str, Any]], Awaitable[dict[str, Any] | None]]


def _exposure_command(handler: ExposureHandler) -> websocket_api.AsyncWebSocketCommandHandler:
    @wraps(handler)
    async def answer(hass: HomeAssistant, connection: websocket_api.ActiveConnection, msg: dict[str, Any]) -> None:
        if (entry := _find_loaded_entry(hass, connection, msg)) is None:
            return
        try:
            entry.runtime_data.sessions.require_session(msg["instance"], connection)
            result = await handler(entry.runtime_data, msg)
        except ExposureError as error:
            connection.send_error(msg["id"], error.code, error.message)
            return
        connection.send_result(msg["id"], result)

    return answer


@callback
def async_register_commands(hass: HomeAssistant) -> None:
    websocket_api.async_register_command(hass, websocket_version)
    websocket_api.async_register_command(hass, websocket_session_subscribe)
    websocket_api.async_register_command(hass, websocket_entity_upsert)
    websocket_api.async_register_command(hass, websocket_entity_state)
    websocket_api.async_register_command(hass, websocket_entity_remove)
    websocket_api.async_register_command(hass, websocket_entity_reconcile)
    websocket_api.async_register_command(hass, websocket_command_result)


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


@websocket_api.websocket_command(
    {
        vol.Required("type"): "stewart/entity/upsert",
        **ENTITY_ADDRESS_SCHEMA,
        vol.Required("platform"): str,
        vol.Required("config"): dict,
        vol.Optional("device"): DEVICE_SCHEMA,
        vol.Optional("state"): object,
        vol.Optional("attributes"): dict,
        vol.Optional("available"): bool,
    }
)
@websocket_api.require_admin
@websocket_api.async_response
@_exposure_command
async def websocket_entity_upsert(runtime: StewartRuntime, msg: dict[str, Any]) -> dict[str, Any]:
    upsert = EntityUpsert.from_message(msg)
    if upsert.platform == ACTION_PLATFORM:
        config = ActionConfig.read_from(upsert.config)
        runtime.entities.remove(upsert.address)
        return {"service": runtime.actions.register(upsert.address, config)}
    snapshot = await runtime.entities.upsert(upsert)
    runtime.actions.remove(upsert.address)
    return snapshot.as_result()


@websocket_api.websocket_command(
    {
        vol.Required("type"): "stewart/entity/state",
        **ENTITY_ADDRESS_SCHEMA,
        vol.Optional("state"): object,
        vol.Optional("attributes"): dict,
        vol.Optional("available"): bool,
    }
)
@websocket_api.require_admin
@websocket_api.async_response
@_exposure_command
async def websocket_entity_state(runtime: StewartRuntime, msg: dict[str, Any]) -> None:
    address = EntityAddress.from_message(msg)
    if not runtime.actions.holds(address):
        runtime.entities.update_state(address, EntityChange.from_message(msg))


@websocket_api.websocket_command({vol.Required("type"): "stewart/entity/remove", **ENTITY_ADDRESS_SCHEMA})
@websocket_api.require_admin
@websocket_api.async_response
@_exposure_command
async def websocket_entity_remove(runtime: StewartRuntime, msg: dict[str, Any]) -> dict[str, Any]:
    address = EntityAddress.from_message(msg)
    return {"removed": runtime.actions.remove(address) or runtime.entities.remove(address)}


@websocket_api.websocket_command(
    {
        vol.Required("type"): "stewart/entity/reconcile",
        vol.Required("instance"): vol.Match(INSTANCE_PATTERN),
        vol.Required("keep"): [KEPT_ENTITY_SCHEMA],
        vol.Required("keep_apps"): [vol.Match(APP_PATTERN)],
    }
)
@websocket_api.require_admin
@websocket_api.async_response
@_exposure_command
async def websocket_entity_reconcile(runtime: StewartRuntime, msg: dict[str, Any]) -> dict[str, Any]:
    kept = KeptEntities.from_message(msg)
    runtime.actions.reconcile(msg["instance"], kept)
    return {"removed": runtime.entities.reconcile(msg["instance"], kept)}


@websocket_api.websocket_command(
    {
        vol.Required("type"): "stewart/command/result",
        vol.Required("command_id"): str,
        vol.Required("ok"): bool,
        vol.Optional("message"): str,
    }
)
@websocket_api.require_admin
@callback
def websocket_command_result(
    hass: HomeAssistant, connection: websocket_api.ActiveConnection, msg: dict[str, Any]
) -> None:
    if (entry := _find_loaded_entry(hass, connection, msg)) is None:
        return
    try:
        entry.runtime_data.sessions.answer_command(connection, CommandAnswer.from_message(msg))
    except ExposureError as error:
        connection.send_error(msg["id"], error.code, error.message)
        return
    connection.send_result(msg["id"])


# Commands outlive an unload; answering like a missing integration lets Stewart detect both the same way.
def _find_loaded_entry(
    hass: HomeAssistant, connection: websocket_api.ActiveConnection, msg: dict[str, Any]
) -> StewartConfigEntry | None:
    if entries := hass.config_entries.async_loaded_entries(DOMAIN):
        return entries[0]
    connection.send_error(msg["id"], websocket_api.ERR_UNKNOWN_COMMAND, "Unknown command.")
    return None

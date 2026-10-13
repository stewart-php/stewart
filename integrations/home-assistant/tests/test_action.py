import asyncio
from typing import Any

import pytest
import voluptuous as vol
from homeassistant.core import HomeAssistant
from homeassistant.exceptions import HomeAssistantError
from pytest_homeassistant_custom_component.common import MockConfigEntry
from pytest_homeassistant_custom_component.typing import MockHAClientWebSocket

from custom_components.stewart.const import DOMAIN
from tests.commands import command_result, receive_command, without_ids
from tests.exchange import send_request
from tests.golden import Golden

SERVICE = "heating_boost"


def action_upsert(**overrides: object) -> dict[str, Any]:
    return {**Golden.load("entity-upsert.action").request, **overrides}


def call_boost(hass: HomeAssistant, data: dict[str, Any]) -> asyncio.Task[Any]:
    return hass.async_create_task(hass.services.async_call(DOMAIN, SERVICE, data, blocking=True))


async def test_action_upsert_matches_golden(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    golden = Golden.load("entity-upsert.action")

    response = await send_request(session_client, golden.request)

    assert response["result"] == golden.result
    assert hass.services.has_service(DOMAIN, SERVICE)


async def test_action_call_matches_golden(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    golden = Golden.load("event-command.action")
    await send_request(session_client, action_upsert())

    call = call_boost(hass, {"room": "office", "minutes": 45})
    event = await receive_command(session_client)
    await send_request(session_client, command_result(event["command_id"]))
    await call

    assert without_ids(event) == without_ids(golden.event or {})


async def test_refused_call_fails_with_message(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    await send_request(session_client, action_upsert())

    call = call_boost(hass, {"room": "office"})
    event = await receive_command(session_client)
    await send_request(session_client, command_result(event["command_id"], "command-result.rejected"))

    with pytest.raises(HomeAssistantError, match="alarm is armed"):
        await call


async def test_call_is_validated_before_it_reaches_stewart(
    hass: HomeAssistant, session_client: MockHAClientWebSocket
) -> None:
    await send_request(session_client, action_upsert())

    with pytest.raises(vol.Invalid):
        await hass.services.async_call(DOMAIN, SERVICE, {"room": "attic"}, blocking=True)


async def test_action_takes_no_state(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    await send_request(session_client, action_upsert())

    response = await send_request(
        session_client,
        {"type": "stewart/entity/state", "instance": "default", "app": "heating", "key": "boost", "available": False},
    )

    assert response["success"]
    assert hass.services.has_service(DOMAIN, SERVICE)


async def test_remove_drops_the_service(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    await send_request(session_client, action_upsert())

    response = await send_request(
        session_client, {"type": "stewart/entity/remove", "instance": "default", "app": "heating", "key": "boost"}
    )

    assert response["result"] == {"removed": True}
    assert not hass.services.has_service(DOMAIN, SERVICE)


async def test_reconcile_drops_services_not_kept(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    await send_request(session_client, action_upsert())

    response = await send_request(
        session_client, {**Golden.load("entity-reconcile").request, "keep": [], "keep_apps": []}
    )

    assert response["result"] == {"removed": []}
    assert not hass.services.has_service(DOMAIN, SERVICE)


async def test_action_of_another_instance_is_refused(
    hass: HomeAssistant, session_client: MockHAClientWebSocket
) -> None:
    golden = Golden.load("entity-upsert.action-taken")
    await send_request(session_client, action_upsert())
    await send_request(session_client, {**Golden.load("session-subscribe").request, "instance": "upstairs"})

    response = await send_request(session_client, golden.request)

    assert response["error"]["code"] == golden.error_code


async def test_action_replaces_entity_of_the_same_key(
    hass: HomeAssistant, session_client: MockHAClientWebSocket
) -> None:
    switch = Golden.load("entity-upsert.switch").request
    await send_request(session_client, switch)

    await send_request(session_client, action_upsert(app=switch["app"], key=switch["key"]))

    assert hass.states.get("switch.stewart_lights_night_mode") is None
    assert hass.services.has_service(DOMAIN, "lights_night_mode")


async def test_select_field_needs_options(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    response = await send_request(
        session_client, action_upsert(config={"fields": [{"name": "room", "type": "select"}]})
    )

    assert response["error"]["code"] == "invalid_config"
    assert not hass.services.has_service(DOMAIN, SERVICE)


async def test_unload_drops_the_service(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, loaded_entry: MockConfigEntry
) -> None:
    await send_request(session_client, action_upsert())

    assert await hass.config_entries.async_unload(loaded_entry.entry_id)

    assert not hass.services.has_service(DOMAIN, SERVICE)

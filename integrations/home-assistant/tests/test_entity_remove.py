from typing import Any

from homeassistant.core import HomeAssistant
from homeassistant.helpers import entity_registry as er
from pytest_homeassistant_custom_component.common import MockConfigEntry
from pytest_homeassistant_custom_component.typing import MockHAClientWebSocket, WebSocketGenerator

from tests.exchange import send_request
from tests.golden import Golden

NIGHT_MODE_ENTITY_ID = "sensor.stewart_lights_night_mode"


def night_mode_upsert() -> dict[str, Any]:
    return {
        **Golden.load("entity-upsert.sensor").request,
        "app": "lights",
        "key": "night_mode",
        "config": {"name": "Night mode"},
        "state": "on",
    }


async def test_remove_matches_golden(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    golden = Golden.load("entity-remove")
    await send_request(session_client, night_mode_upsert())

    response = await send_request(session_client, golden.request)
    await hass.async_block_till_done()

    assert response["result"] == golden.result
    assert hass.states.get(NIGHT_MODE_ENTITY_ID) is None
    assert er.async_get(hass).async_get(NIGHT_MODE_ENTITY_ID) is None


async def test_remove_of_unknown_entity_matches_golden(
    hass: HomeAssistant, session_client: MockHAClientWebSocket
) -> None:
    golden = Golden.load("entity-remove.missing")

    response = await send_request(session_client, golden.request)

    assert response["result"] == golden.result


async def test_remove_clears_entry_from_before_restart(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, loaded_entry: MockConfigEntry
) -> None:
    er.async_get(hass).async_get_or_create(
        "sensor", "stewart", "stewart-default-lights-night_mode", config_entry=loaded_entry
    )

    response = await send_request(session_client, Golden.load("entity-remove").request)

    assert response["result"] == {"removed": True}
    assert er.async_get(hass).async_get(NIGHT_MODE_ENTITY_ID) is None


async def test_state_after_remove_is_not_found(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    await send_request(session_client, night_mode_upsert())
    await send_request(session_client, Golden.load("entity-remove").request)

    response = await send_request(session_client, {**Golden.load("entity-state.not-found").request, "app": "lights"})

    assert response["error"]["code"] == "not_found"


async def test_upsert_after_user_deleted_entity_recreates_it(
    hass: HomeAssistant, session_client: MockHAClientWebSocket
) -> None:
    await send_request(session_client, night_mode_upsert())
    er.async_get(hass).async_remove(NIGHT_MODE_ENTITY_ID)
    await hass.async_block_till_done()

    response = await send_request(session_client, night_mode_upsert())

    assert response["result"]["entity_id"] == NIGHT_MODE_ENTITY_ID
    state = hass.states.get(NIGHT_MODE_ENTITY_ID)
    assert state is not None
    assert state.state == "on"


async def test_remove_needs_session(
    hass: HomeAssistant, hass_ws_client: WebSocketGenerator, loaded_entry: MockConfigEntry
) -> None:
    response = await send_request(await hass_ws_client(hass), Golden.load("entity-remove").request)

    assert response["error"]["code"] == "no_session"

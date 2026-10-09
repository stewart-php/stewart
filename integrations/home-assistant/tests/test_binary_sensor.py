from typing import Any

import pytest
from homeassistant.core import HomeAssistant
from homeassistant.helpers import entity_registry as er
from pytest_homeassistant_custom_component.typing import MockHAClientWebSocket

from custom_components.stewart.change import JsonValue
from tests.exchange import send_request
from tests.golden import Golden

BINARY_SENSOR_ENTITY_ID = "binary_sensor.stewart_presence_anyone_home"


def binary_sensor_upsert(**overrides: object) -> dict[str, Any]:
    return {**Golden.load("entity-upsert.binary-sensor").request, **overrides}


async def test_binary_sensor_upsert_matches_golden(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    golden = Golden.load("entity-upsert.binary-sensor")

    response = await send_request(session_client, golden.request)

    assert response["result"] == golden.result
    state = hass.states.get(BINARY_SENSOR_ENTITY_ID)
    assert state is not None
    assert state.state == "off"
    assert state.attributes["device_class"] == "occupancy"


@pytest.mark.parametrize(("wire_state", "ha_state"), [(True, "on"), (False, "off"), (None, "unknown")])
async def test_binary_sensor_shows_state(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, wire_state: JsonValue, ha_state: str
) -> None:
    await send_request(session_client, binary_sensor_upsert(state=wire_state))

    state = hass.states.get(BINARY_SENSOR_ENTITY_ID)
    assert state is not None
    assert state.state == ha_state


async def test_binary_sensor_refuses_invalid_state(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    golden = Golden.load("entity-upsert.invalid-state")

    response = await send_request(session_client, golden.request)

    assert response["error"]["code"] == golden.error_code


@pytest.mark.parametrize("config", [{"device_class": "temperature"}, {"unit_of_measurement": "%"}])
async def test_binary_sensor_refuses_config(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, config: dict[str, Any]
) -> None:
    response = await send_request(session_client, binary_sensor_upsert(config=config))

    assert response["error"]["code"] == "invalid_config"


async def test_platform_change_replaces_entity(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    sensor = {**Golden.load("entity-upsert.sensor").request, "app": "presence", "key": "anyone_home"}
    old_entity_id = (await send_request(session_client, sensor))["result"]["entity_id"]

    response = await send_request(session_client, binary_sensor_upsert())
    await hass.async_block_till_done()

    assert response["result"]["entity_id"] == BINARY_SENSOR_ENTITY_ID
    assert hass.states.get(old_entity_id) is None
    assert er.async_get(hass).async_get(old_entity_id) is None


async def test_refused_platform_change_keeps_entity(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    sensor = {**Golden.load("entity-upsert.sensor").request, "app": "presence", "key": "anyone_home"}
    old_entity_id = (await send_request(session_client, sensor))["result"]["entity_id"]

    await send_request(session_client, binary_sensor_upsert(state="yes"))
    await hass.async_block_till_done()

    assert hass.states.get(old_entity_id) is not None

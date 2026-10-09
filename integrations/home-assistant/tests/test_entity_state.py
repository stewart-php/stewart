from typing import Any

import pytest
from homeassistant.core import HomeAssistant
from pytest_homeassistant_custom_component.common import MockConfigEntry
from pytest_homeassistant_custom_component.typing import MockHAClientWebSocket, WebSocketGenerator

from custom_components.stewart.change import JsonValue
from tests.exchange import send_request
from tests.golden import Golden

SENSOR_ENTITY_ID = "sensor.stewart_climate_average_temperature"


def state_request(**overrides: object) -> dict[str, Any]:
    return {**Golden.load("entity-state").request, **overrides}


@pytest.fixture
async def sensor_client(session_client: MockHAClientWebSocket) -> MockHAClientWebSocket:
    assert (await send_request(session_client, Golden.load("entity-upsert.sensor").request))["success"]
    return session_client


async def test_state_matches_golden(hass: HomeAssistant, sensor_client: MockHAClientWebSocket) -> None:
    golden = Golden.load("entity-state")

    response = await send_request(sensor_client, golden.request)

    assert response["success"]
    assert response["result"] == golden.result
    state = hass.states.get(SENSOR_ENTITY_ID)
    assert state is not None
    assert state.state == "21.9"
    assert state.attributes["sources"] == golden.request["attributes"]["sources"]


async def test_state_without_attributes_keeps_them(hass: HomeAssistant, sensor_client: MockHAClientWebSocket) -> None:
    request = state_request(state=19)
    del request["attributes"]

    await send_request(sensor_client, request)

    state = hass.states.get(SENSOR_ENTITY_ID)
    assert state is not None
    assert state.state == "19"
    assert state.attributes["sources"] == Golden.load("entity-upsert.sensor").request["attributes"]["sources"]


async def test_state_without_state_keeps_it(hass: HomeAssistant, sensor_client: MockHAClientWebSocket) -> None:
    request = state_request(attributes={})
    del request["state"]

    await send_request(sensor_client, request)

    state = hass.states.get(SENSOR_ENTITY_ID)
    assert state is not None
    assert state.state == "21.4"
    assert "sources" not in state.attributes


async def test_state_can_mark_unavailable(hass: HomeAssistant, sensor_client: MockHAClientWebSocket) -> None:
    await send_request(sensor_client, {**state_request(), "available": False})

    state = hass.states.get(SENSOR_ENTITY_ID)
    assert state is not None
    assert state.state == "unavailable"


@pytest.mark.parametrize("wire_state", ["warm", True, [21]])
async def test_state_refuses_unfit_state(
    hass: HomeAssistant, sensor_client: MockHAClientWebSocket, wire_state: JsonValue
) -> None:
    response = await send_request(sensor_client, state_request(state=wire_state, attributes={}))

    assert response["error"]["code"] == "invalid_state"
    state = hass.states.get(SENSOR_ENTITY_ID)
    assert state is not None
    assert state.state == "21.4"
    assert "sources" in state.attributes


async def test_state_of_unknown_entity_is_not_found(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    golden = Golden.load("entity-state.not-found")

    response = await send_request(session_client, golden.request)

    assert response["error"]["code"] == golden.error_code


async def test_state_needs_session(
    hass: HomeAssistant, hass_ws_client: WebSocketGenerator, loaded_entry: MockConfigEntry
) -> None:
    response = await send_request(await hass_ws_client(hass), state_request())

    assert response["error"]["code"] == "no_session"

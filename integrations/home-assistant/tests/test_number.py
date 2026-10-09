from typing import Any

import pytest
from homeassistant.components.number import DOMAIN as NUMBER_DOMAIN
from homeassistant.components.number import SERVICE_SET_VALUE
from homeassistant.core import HomeAssistant
from homeassistant.exceptions import HomeAssistantError
from pytest_homeassistant_custom_component.typing import MockHAClientWebSocket

from custom_components.stewart.change import JsonValue
from tests.commands import call_service, command_result, receive_command, without_ids
from tests.exchange import send_request
from tests.golden import Golden

NUMBER_ENTITY_ID = "number.stewart_climate_target_offset"


def number_upsert(**overrides: object) -> dict[str, Any]:
    return {**Golden.load("entity-upsert.number").request, **overrides}


def number_config(**overrides: object) -> dict[str, Any]:
    return {**Golden.load("entity-upsert.number").request["config"], **overrides}


async def test_number_upsert_matches_golden(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    golden = Golden.load("entity-upsert.number")

    response = await send_request(session_client, golden.request)

    assert response["result"] == golden.result
    state = hass.states.get(NUMBER_ENTITY_ID)
    assert state is not None
    assert state.state == "0.5"


async def test_number_shows_config(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    await send_request(session_client, number_upsert())

    state = hass.states.get(NUMBER_ENTITY_ID)
    assert state is not None
    assert state.attributes["min"] == -3
    assert state.attributes["max"] == 3
    assert state.attributes["step"] == 0.5
    assert state.attributes["mode"] == "slider"
    assert state.attributes["device_class"] == "temperature"
    assert state.attributes["unit_of_measurement"] == "°C"


@pytest.mark.parametrize(("wire_state", "ha_state"), [(-3, "-3.0"), (3, "3.0"), (None, "unknown")])
async def test_number_shows_state(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, wire_state: JsonValue, ha_state: str
) -> None:
    await send_request(session_client, number_upsert(state=wire_state))

    state = hass.states.get(NUMBER_ENTITY_ID)
    assert state is not None
    assert state.state == ha_state


@pytest.mark.parametrize("wire_state", [3.5, -4, "1", True])
async def test_number_refuses_state(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, wire_state: JsonValue
) -> None:
    response = await send_request(session_client, number_upsert(state=wire_state))

    assert response["error"]["code"] == "invalid_state"


@pytest.mark.parametrize(
    "config",
    [
        {"name": "Target offset", "max": 3, "step": 0.5},
        number_config(min=4),
        number_config(step=0),
        number_config(max="3"),
        number_config(mode="dial"),
        number_config(unit_of_measurement="%"),
        number_config(options=["low"]),
    ],
)
async def test_number_refuses_config(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, config: dict[str, Any]
) -> None:
    response = await send_request(session_client, number_upsert(config=config))

    assert response["error"]["code"] == "invalid_config"


async def test_set_value_sends_golden_command(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    await send_request(session_client, number_upsert())
    call = call_service(hass, NUMBER_DOMAIN, SERVICE_SET_VALUE, NUMBER_ENTITY_ID, value=1.5)

    event = await receive_command(session_client)
    await send_request(session_client, command_result(event["command_id"]))
    await call

    golden = Golden.load("event-command.number").event
    assert golden is not None
    assert without_ids(event) == without_ids(golden)
    state = hass.states.get(NUMBER_ENTITY_ID)
    assert state is not None
    assert state.state == "1.5"


async def test_rejected_set_value_keeps_state(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    await send_request(session_client, number_upsert())
    call = call_service(hass, NUMBER_DOMAIN, SERVICE_SET_VALUE, NUMBER_ENTITY_ID, value=1.5)

    event = await receive_command(session_client)
    await send_request(session_client, command_result(event["command_id"], "command-result.rejected"))

    with pytest.raises(HomeAssistantError):
        await call
    state = hass.states.get(NUMBER_ENTITY_ID)
    assert state is not None
    assert state.state == "0.5"

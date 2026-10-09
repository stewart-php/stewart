from typing import Any

import pytest
from homeassistant.components.select import DOMAIN as SELECT_DOMAIN
from homeassistant.components.select import SERVICE_SELECT_OPTION
from homeassistant.core import HomeAssistant
from homeassistant.exceptions import HomeAssistantError
from pytest_homeassistant_custom_component.typing import MockHAClientWebSocket

from custom_components.stewart.change import JsonValue
from tests.commands import call_service, command_result, receive_command, without_ids
from tests.exchange import send_request
from tests.golden import Golden

SELECT_ENTITY_ID = "select.stewart_heating_mode"


def select_upsert(**overrides: object) -> dict[str, Any]:
    return {**Golden.load("entity-upsert.select").request, **overrides}


async def test_select_upsert_matches_golden(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    golden = Golden.load("entity-upsert.select")

    response = await send_request(session_client, golden.request)

    assert response["result"] == golden.result
    state = hass.states.get(SELECT_ENTITY_ID)
    assert state is not None
    assert state.state == "eco"
    assert state.attributes["options"] == ["eco", "comfort", "away"]


async def test_select_shows_null_state_as_unknown(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    await send_request(session_client, select_upsert(state=None))

    state = hass.states.get(SELECT_ENTITY_ID)
    assert state is not None
    assert state.state == "unknown"


@pytest.mark.parametrize("wire_state", ["boost", 1, True])
async def test_select_refuses_state(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, wire_state: JsonValue
) -> None:
    response = await send_request(session_client, select_upsert(state=wire_state))

    assert response["error"]["code"] == "invalid_state"


@pytest.mark.parametrize(
    "config",
    [{"name": "Mode"}, {"options": []}, {"options": ["eco", "eco"]}, {"options": ["eco", 1]}, {"options": "eco"}],
)
async def test_select_refuses_config(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, config: dict[str, Any]
) -> None:
    response = await send_request(session_client, select_upsert(config=config, state=None))

    assert response["error"]["code"] == "invalid_config"


async def test_select_option_sends_golden_command(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    await send_request(session_client, select_upsert())
    call = call_service(hass, SELECT_DOMAIN, SERVICE_SELECT_OPTION, SELECT_ENTITY_ID, option="comfort")

    event = await receive_command(session_client)
    await send_request(session_client, command_result(event["command_id"]))
    await call

    golden = Golden.load("event-command.select").event
    assert golden is not None
    assert without_ids(event) == without_ids(golden)
    state = hass.states.get(SELECT_ENTITY_ID)
    assert state is not None
    assert state.state == "comfort"


async def test_rejected_select_option_keeps_state(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    await send_request(session_client, select_upsert())
    call = call_service(hass, SELECT_DOMAIN, SERVICE_SELECT_OPTION, SELECT_ENTITY_ID, option="away")

    event = await receive_command(session_client)
    await send_request(session_client, command_result(event["command_id"], "command-result.rejected"))

    with pytest.raises(HomeAssistantError):
        await call
    state = hass.states.get(SELECT_ENTITY_ID)
    assert state is not None
    assert state.state == "eco"

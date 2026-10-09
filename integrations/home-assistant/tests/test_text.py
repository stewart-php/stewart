from typing import Any

import pytest
from homeassistant.components.text import DOMAIN as TEXT_DOMAIN
from homeassistant.components.text import SERVICE_SET_VALUE
from homeassistant.core import HomeAssistant
from homeassistant.exceptions import HomeAssistantError
from pytest_homeassistant_custom_component.typing import MockHAClientWebSocket

from custom_components.stewart.change import JsonValue
from tests.commands import call_service, command_result, receive_command, without_ids
from tests.exchange import send_request
from tests.golden import Golden

TEXT_ENTITY_ID = "text.stewart_notify_greeting"


def text_upsert(**overrides: object) -> dict[str, Any]:
    return {**Golden.load("entity-upsert.text").request, **overrides}


def text_config(**overrides: object) -> dict[str, Any]:
    return {**Golden.load("entity-upsert.text").request["config"], **overrides}


async def test_text_upsert_matches_golden(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    golden = Golden.load("entity-upsert.text")

    response = await send_request(session_client, golden.request)

    assert response["result"] == golden.result
    state = hass.states.get(TEXT_ENTITY_ID)
    assert state is not None
    assert state.state == "Hello"
    assert state.attributes["min"] == 1
    assert state.attributes["max"] == 40
    assert state.attributes["pattern"] == "^[A-Za-z ,!]+$"
    assert state.attributes["mode"] == "text"


async def test_text_defaults_to_full_length(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    await send_request(session_client, text_upsert(config={}, state=""))

    state = hass.states.get(TEXT_ENTITY_ID)
    assert state is not None
    assert state.attributes["min"] == 0
    assert state.attributes["max"] == 255
    assert state.attributes["mode"] == "text"


@pytest.mark.parametrize("wire_state", ["", "x" * 41, "Hello 2", 5, True])
async def test_text_refuses_state(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, wire_state: JsonValue
) -> None:
    response = await send_request(session_client, text_upsert(state=wire_state))

    assert response["error"]["code"] == "invalid_state"


@pytest.mark.parametrize(
    "config",
    [
        text_config(min=41),
        text_config(max=256),
        text_config(max=-1),
        text_config(pattern="[a-"),
        text_config(mode="secret"),
        text_config(step=1),
    ],
)
async def test_text_refuses_config(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, config: dict[str, Any]
) -> None:
    response = await send_request(session_client, text_upsert(config=config, state=None))

    assert response["error"]["code"] == "invalid_config"


async def test_set_value_sends_golden_command(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    await send_request(session_client, text_upsert())
    call = call_service(hass, TEXT_DOMAIN, SERVICE_SET_VALUE, TEXT_ENTITY_ID, value="Good morning")

    event = await receive_command(session_client)
    await send_request(session_client, command_result(event["command_id"]))
    await call

    golden = Golden.load("event-command.text").event
    assert golden is not None
    assert without_ids(event) == without_ids(golden)
    state = hass.states.get(TEXT_ENTITY_ID)
    assert state is not None
    assert state.state == "Good morning"


async def test_rejected_set_value_keeps_text(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    await send_request(session_client, text_upsert())
    call = call_service(hass, TEXT_DOMAIN, SERVICE_SET_VALUE, TEXT_ENTITY_ID, value="Bye")

    event = await receive_command(session_client)
    await send_request(session_client, command_result(event["command_id"], "command-result.rejected"))

    with pytest.raises(HomeAssistantError):
        await call
    state = hass.states.get(TEXT_ENTITY_ID)
    assert state is not None
    assert state.state == "Hello"

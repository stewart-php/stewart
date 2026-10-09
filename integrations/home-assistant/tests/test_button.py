from typing import Any

import pytest
from homeassistant.components.button import DOMAIN as BUTTON_DOMAIN
from homeassistant.components.button import SERVICE_PRESS
from homeassistant.const import ATTR_ENTITY_ID, STATE_UNKNOWN
from homeassistant.core import HomeAssistant
from homeassistant.exceptions import HomeAssistantError
from pytest_homeassistant_custom_component.typing import MockHAClientWebSocket

from tests.exchange import send_request
from tests.golden import Golden

BUTTON_ENTITY_ID = "button.stewart_lights_all_off"


def button_upsert(**overrides: object) -> dict[str, Any]:
    return {**Golden.load("entity-upsert.button").request, **overrides}


async def test_button_upsert_matches_golden(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    golden = Golden.load("entity-upsert.button")

    response = await send_request(session_client, golden.request)

    assert response["result"] == golden.result
    state = hass.states.get(BUTTON_ENTITY_ID)
    assert state is not None
    assert state.state == STATE_UNKNOWN


async def test_button_shows_device_class(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    await send_request(session_client, button_upsert(config={"device_class": "restart"}))

    state = hass.states.get(BUTTON_ENTITY_ID)
    assert state is not None
    assert state.attributes["device_class"] == "restart"


@pytest.mark.parametrize("wire_state", [None, "2026-10-09T12:00:00+00:00"])
async def test_button_upsert_refuses_state(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, wire_state: str | None
) -> None:
    response = await send_request(session_client, button_upsert(state=wire_state))

    assert response["error"]["code"] == "invalid_state"
    assert hass.states.get(BUTTON_ENTITY_ID) is None


async def test_button_state_change_matches_golden(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    golden = Golden.load("entity-state.button")
    await send_request(session_client, button_upsert())

    response = await send_request(session_client, golden.request)

    assert response["error"]["code"] == golden.error_code


async def test_button_takes_attributes(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    await send_request(session_client, button_upsert())

    response = await send_request(
        session_client,
        {
            "type": "stewart/entity/state",
            "instance": "default",
            "app": "lights",
            "key": "all_off",
            "attributes": {"zone": "upstairs"},
        },
    )

    assert response["success"]
    state = hass.states.get(BUTTON_ENTITY_ID)
    assert state is not None
    assert state.attributes["zone"] == "upstairs"


async def test_button_refuses_presses(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    await send_request(session_client, button_upsert())

    with pytest.raises(HomeAssistantError):
        await hass.services.async_call(BUTTON_DOMAIN, SERVICE_PRESS, {ATTR_ENTITY_ID: BUTTON_ENTITY_ID}, blocking=True)

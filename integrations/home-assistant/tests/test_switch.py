from typing import Any

import pytest
from homeassistant.components.switch import DOMAIN as SWITCH_DOMAIN
from homeassistant.const import ATTR_ENTITY_ID, SERVICE_TURN_ON
from homeassistant.core import HomeAssistant
from homeassistant.exceptions import HomeAssistantError
from pytest_homeassistant_custom_component.typing import MockHAClientWebSocket

from custom_components.stewart.change import JsonValue
from tests.exchange import send_request
from tests.golden import Golden

SWITCH_ENTITY_ID = "switch.stewart_lights_night_mode"


def switch_upsert(**overrides: object) -> dict[str, Any]:
    return {**Golden.load("entity-upsert.switch").request, **overrides}


async def test_switch_upsert_matches_golden(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    golden = Golden.load("entity-upsert.switch")

    response = await send_request(session_client, golden.request)

    assert response["result"] == golden.result
    state = hass.states.get(SWITCH_ENTITY_ID)
    assert state is not None
    assert state.state == "on"


@pytest.mark.parametrize(("wire_state", "ha_state"), [(True, "on"), (False, "off"), (None, "unknown")])
async def test_switch_shows_state(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, wire_state: JsonValue, ha_state: str
) -> None:
    await send_request(session_client, switch_upsert(state=wire_state))

    state = hass.states.get(SWITCH_ENTITY_ID)
    assert state is not None
    assert state.state == ha_state


async def test_switch_shows_device_class(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    await send_request(session_client, switch_upsert(config={"device_class": "outlet"}))

    state = hass.states.get(SWITCH_ENTITY_ID)
    assert state is not None
    assert state.attributes["device_class"] == "outlet"


async def test_switch_refuses_non_bool_state(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    response = await send_request(session_client, switch_upsert(state="on"))

    assert response["error"]["code"] == "invalid_state"


@pytest.mark.parametrize("config", [{"device_class": "occupancy"}, {"unit_of_measurement": "%"}])
async def test_switch_refuses_config(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, config: dict[str, Any]
) -> None:
    response = await send_request(session_client, switch_upsert(config=config))

    assert response["error"]["code"] == "invalid_config"


async def test_switch_refuses_service_calls(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    await send_request(session_client, switch_upsert())

    with pytest.raises(HomeAssistantError):
        await hass.services.async_call(
            SWITCH_DOMAIN, SERVICE_TURN_ON, {ATTR_ENTITY_ID: SWITCH_ENTITY_ID}, blocking=True
        )

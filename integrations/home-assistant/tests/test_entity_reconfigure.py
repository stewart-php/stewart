from typing import Any

from homeassistant.core import HomeAssistant
from pytest_homeassistant_custom_component.typing import MockHAClientWebSocket

from tests.exchange import send_request
from tests.golden import Golden

NUMBER_ENTITY_ID = "number.stewart_climate_target_offset"
SELECT_ENTITY_ID = "select.stewart_heating_mode"
TEXT_ENTITY_ID = "text.stewart_notify_greeting"


def reconfigure_request(golden: str, **config_overrides: object) -> dict[str, Any]:
    request = Golden.load(golden).request
    reconfigure = {**request, "config": {**request["config"], **config_overrides}}
    del reconfigure["state"]
    return reconfigure


async def expose(client: MockHAClientWebSocket, golden: str) -> None:
    assert (await send_request(client, Golden.load(golden).request))["success"]


async def test_reconfigure_renames_and_changes_icon(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    await expose(session_client, "entity-upsert.number")

    response = await send_request(
        session_client, reconfigure_request("entity-upsert.number", name="Offset", icon="mdi:tune")
    )

    assert response["result"]["entity_id"] == NUMBER_ENTITY_ID
    state = hass.states.get(NUMBER_ENTITY_ID)
    assert state is not None
    assert state.attributes["friendly_name"] == "Stewart · climate Offset"
    assert state.attributes["icon"] == "mdi:tune"


async def test_reconfigure_widens_number_range_keeping_state(
    hass: HomeAssistant, session_client: MockHAClientWebSocket
) -> None:
    await expose(session_client, "entity-upsert.number")

    response = await send_request(session_client, reconfigure_request("entity-upsert.number", max=10, step=1))

    assert response["result"]["state"] == 0.5
    state = hass.states.get(NUMBER_ENTITY_ID)
    assert state is not None
    assert state.attributes["max"] == 10
    assert state.attributes["step"] == 1


async def test_reconfigure_narrowing_number_range_drops_state(
    hass: HomeAssistant, session_client: MockHAClientWebSocket
) -> None:
    await expose(session_client, "entity-upsert.number")

    response = await send_request(session_client, reconfigure_request("entity-upsert.number", min=1))

    assert response["result"]["state"] is None
    state = hass.states.get(NUMBER_ENTITY_ID)
    assert state is not None
    assert state.state == "unknown"


async def test_reconfigure_keeps_select_option_still_offered(
    hass: HomeAssistant, session_client: MockHAClientWebSocket
) -> None:
    await expose(session_client, "entity-upsert.select")

    response = await send_request(session_client, reconfigure_request("entity-upsert.select", options=["eco", "boost"]))

    assert response["result"]["state"] == "eco"
    state = hass.states.get(SELECT_ENTITY_ID)
    assert state is not None
    assert state.attributes["options"] == ["eco", "boost"]


async def test_reconfigure_dropping_select_option_drops_state(
    hass: HomeAssistant, session_client: MockHAClientWebSocket
) -> None:
    await expose(session_client, "entity-upsert.select")

    response = await send_request(session_client, reconfigure_request("entity-upsert.select", options=["comfort"]))

    assert response["result"]["state"] is None


async def test_reconfigure_text_pattern_drops_unfit_state(
    hass: HomeAssistant, session_client: MockHAClientWebSocket
) -> None:
    await expose(session_client, "entity-upsert.text")

    response = await send_request(session_client, reconfigure_request("entity-upsert.text", pattern="^[a-z]+$"))

    assert response["result"]["state"] is None
    state = hass.states.get(TEXT_ENTITY_ID)
    assert state is not None
    assert state.attributes["pattern"] == "^[a-z]+$"


async def test_refused_reconfigure_keeps_config(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    await expose(session_client, "entity-upsert.number")

    response = await send_request(session_client, reconfigure_request("entity-upsert.number", name="Offset", min=5))

    assert response["error"]["code"] == "invalid_config"
    state = hass.states.get(NUMBER_ENTITY_ID)
    assert state is not None
    assert state.state == "0.5"
    assert state.attributes["min"] == -3
    assert state.attributes["friendly_name"] == "Stewart · climate Target offset"

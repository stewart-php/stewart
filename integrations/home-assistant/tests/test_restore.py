from typing import Any

import pytest
from homeassistant.core import HomeAssistant, State
from pytest_homeassistant_custom_component.common import (
    MockConfigEntry,
    async_mock_restore_state_shutdown_restart,
    mock_restore_cache_with_extra_data,
)
from pytest_homeassistant_custom_component.typing import MockHAClientWebSocket, WebSocketGenerator

from custom_components.stewart.change import JsonValue
from tests.exchange import send_request, subscribe
from tests.golden import Golden

SENSOR_ENTITY_ID = "sensor.stewart_climate_average_temperature"
BINARY_SENSOR_ENTITY_ID = "binary_sensor.stewart_presence_anyone_home"
STORED_ATTRIBUTES = {"sources": ["sensor.attic_temperature"]}


def sensor_upsert_without(*fields: str, **overrides: object) -> dict[str, Any]:
    request = {**Golden.load("entity-upsert.sensor").request, **overrides}
    for field in fields:
        del request[field]
    return request


def store_extra_data(hass: HomeAssistant, entity_id: str, extra_data: dict[str, Any]) -> None:
    mock_restore_cache_with_extra_data(hass, ((State(entity_id, "unavailable"), extra_data),))


@pytest.fixture
async def restored_client(
    hass: HomeAssistant, hass_ws_client: WebSocketGenerator, config_entry: MockConfigEntry
) -> MockHAClientWebSocket:
    store_extra_data(hass, SENSOR_ENTITY_ID, {"state": 20.5, "attributes": STORED_ATTRIBUTES})
    config_entry.add_to_hass(hass)
    assert await hass.config_entries.async_setup(config_entry.entry_id)
    client = await hass_ws_client(hass)
    await subscribe(client)
    return client


async def test_upsert_without_state_restores_stored_one(
    hass: HomeAssistant, restored_client: MockHAClientWebSocket
) -> None:
    response = await send_request(restored_client, sensor_upsert_without("state", "attributes"))

    assert response["result"]["state"] == 20.5
    assert response["result"]["attributes"] == STORED_ATTRIBUTES
    state = hass.states.get(SENSOR_ENTITY_ID)
    assert state is not None
    assert state.state == "20.5"
    assert state.attributes["sources"] == STORED_ATTRIBUTES["sources"]


async def test_upsert_state_wins_over_stored_one(hass: HomeAssistant, restored_client: MockHAClientWebSocket) -> None:
    response = await send_request(restored_client, sensor_upsert_without("attributes"))

    assert response["result"]["state"] == 21.4
    assert response["result"]["attributes"] == STORED_ATTRIBUTES


async def test_upsert_null_state_wins_over_stored_one(
    hass: HomeAssistant, restored_client: MockHAClientWebSocket
) -> None:
    response = await send_request(restored_client, sensor_upsert_without(state=None))

    assert response["result"]["state"] is None


async def test_unfit_stored_state_is_dropped(hass: HomeAssistant, restored_client: MockHAClientWebSocket) -> None:
    config = {"device_class": "enum", "options": ["low", "high"]}

    response = await send_request(restored_client, sensor_upsert_without("state", "attributes", config=config))

    assert response["result"]["state"] is None
    assert response["result"]["attributes"] == STORED_ATTRIBUTES


@pytest.mark.parametrize("extra_data", [{"attributes": {}}, {"state": 20.5, "attributes": []}])
async def test_malformed_stored_data_is_ignored(
    hass: HomeAssistant,
    hass_ws_client: WebSocketGenerator,
    loaded_entry: MockConfigEntry,
    extra_data: dict[str, Any],
) -> None:
    store_extra_data(hass, SENSOR_ENTITY_ID, extra_data)
    client = await hass_ws_client(hass)
    await subscribe(client)

    response = await send_request(client, sensor_upsert_without("state", "attributes"))

    assert response["result"]["state"] is None
    assert response["result"]["attributes"] == {}


@pytest.mark.parametrize("stored_state", [True, False])
async def test_binary_sensor_restores_stored_state(
    hass: HomeAssistant,
    hass_ws_client: WebSocketGenerator,
    loaded_entry: MockConfigEntry,
    stored_state: JsonValue,
) -> None:
    store_extra_data(hass, BINARY_SENSOR_ENTITY_ID, {"state": stored_state, "attributes": {}})
    client = await hass_ws_client(hass)
    await subscribe(client)
    request = dict(Golden.load("entity-upsert.binary-sensor").request)
    del request["state"]

    response = await send_request(client, request)

    assert response["result"]["state"] is stored_state


async def test_unavailable_entity_stores_state_and_attributes(
    hass: HomeAssistant, hass_ws_client: WebSocketGenerator, loaded_entry: MockConfigEntry
) -> None:
    client = await hass_ws_client(hass)
    await subscribe(client)
    golden = Golden.load("entity-upsert.sensor")
    await send_request(client, golden.request)
    await client.close()
    await hass.async_block_till_done()

    stored = await async_mock_restore_state_shutdown_restart(hass)

    extra_data = stored.last_states[SENSOR_ENTITY_ID].extra_data
    assert extra_data is not None
    assert extra_data.as_dict() == {"state": 21.4, "attributes": golden.request["attributes"]}

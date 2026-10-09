from dataclasses import dataclass
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
SWITCH_ENTITY_ID = "switch.stewart_lights_night_mode"
BUTTON_ENTITY_ID = "button.stewart_lights_all_off"
NUMBER_ENTITY_ID = "number.stewart_climate_target_offset"
SELECT_ENTITY_ID = "select.stewart_heating_mode"
TEXT_ENTITY_ID = "text.stewart_notify_greeting"
TIME_ENTITY_ID = "time.stewart_wakeup_alarm"
DATE_ENTITY_ID = "date.stewart_garden_next_mowing"
DATETIME_ENTITY_ID = "datetime.stewart_garden_last_watered"
LAST_PRESS = "2026-10-09T12:00:00+00:00"
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


async def test_switch_restores_stored_state(
    hass: HomeAssistant, hass_ws_client: WebSocketGenerator, loaded_entry: MockConfigEntry
) -> None:
    store_extra_data(hass, SWITCH_ENTITY_ID, {"state": False, "attributes": {}})
    client = await hass_ws_client(hass)
    await subscribe(client)
    request = dict(Golden.load("entity-upsert.switch").request)
    del request["state"]

    response = await send_request(client, request)

    assert response["result"]["state"] is False


@dataclass(frozen=True, kw_only=True)
class StoredStateCase:
    golden: str
    entity_id: str
    stored_state: JsonValue
    restored_state: JsonValue


@pytest.mark.parametrize(
    "case",
    [
        StoredStateCase(
            golden="entity-upsert.number", entity_id=NUMBER_ENTITY_ID, stored_state=-1.5, restored_state=-1.5
        ),
        StoredStateCase(golden="entity-upsert.number", entity_id=NUMBER_ENTITY_ID, stored_state=7, restored_state=None),
        StoredStateCase(
            golden="entity-upsert.select", entity_id=SELECT_ENTITY_ID, stored_state="away", restored_state="away"
        ),
        StoredStateCase(
            golden="entity-upsert.select", entity_id=SELECT_ENTITY_ID, stored_state="boost", restored_state=None
        ),
        StoredStateCase(golden="entity-upsert.text", entity_id=TEXT_ENTITY_ID, stored_state="Hi", restored_state="Hi"),
        StoredStateCase(
            golden="entity-upsert.text", entity_id=TEXT_ENTITY_ID, stored_state="Hi 2", restored_state=None
        ),
        StoredStateCase(
            golden="entity-upsert.time", entity_id=TIME_ENTITY_ID, stored_state="05:30:00", restored_state="05:30:00"
        ),
        StoredStateCase(
            golden="entity-upsert.date",
            entity_id=DATE_ENTITY_ID,
            stored_state="2026-11-01",
            restored_state="2026-11-01",
        ),
        StoredStateCase(
            golden="entity-upsert.datetime",
            entity_id=DATETIME_ENTITY_ID,
            stored_state="2026-10-08T20:00:00+00:00",
            restored_state="2026-10-08T20:00:00+00:00",
        ),
        StoredStateCase(
            golden="entity-upsert.datetime",
            entity_id=DATETIME_ENTITY_ID,
            stored_state="2026-10-08",
            restored_state=None,
        ),
    ],
)
async def test_restores_stored_state_that_fits_config(
    hass: HomeAssistant, hass_ws_client: WebSocketGenerator, loaded_entry: MockConfigEntry, case: StoredStateCase
) -> None:
    store_extra_data(hass, case.entity_id, {"state": case.stored_state, "attributes": {}})
    client = await hass_ws_client(hass)
    await subscribe(client)
    request = dict(Golden.load(case.golden).request)
    del request["state"]

    response = await send_request(client, request)

    assert response["result"]["state"] == case.restored_state


async def test_button_restores_last_press(
    hass: HomeAssistant, hass_ws_client: WebSocketGenerator, loaded_entry: MockConfigEntry
) -> None:
    mock_restore_cache_with_extra_data(
        hass, ((State(BUTTON_ENTITY_ID, LAST_PRESS), {"state": None, "attributes": {}}),)
    )
    client = await hass_ws_client(hass)
    await subscribe(client)

    response = await send_request(client, Golden.load("entity-upsert.button").request)

    assert response["result"]["state"] is None
    state = hass.states.get(BUTTON_ENTITY_ID)
    assert state is not None
    assert state.state == LAST_PRESS


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

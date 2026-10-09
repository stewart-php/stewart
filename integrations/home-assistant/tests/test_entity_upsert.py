from typing import Any

import pytest
from homeassistant.core import HomeAssistant
from homeassistant.helpers import entity_registry as er
from pytest_homeassistant_custom_component.common import MockConfigEntry
from pytest_homeassistant_custom_component.typing import MockHAClientWebSocket, WebSocketGenerator

from custom_components.stewart.change import JsonValue
from tests.exchange import send_request, subscribe
from tests.golden import Golden
from tests.registry import find_device

SENSOR_ENTITY_ID = "sensor.stewart_climate_average_temperature"


def sensor_upsert(**overrides: object) -> dict[str, Any]:
    return {**Golden.load("entity-upsert.sensor").request, **overrides}


def sensor_config(**overrides: object) -> dict[str, Any]:
    return {**sensor_upsert()["config"], **overrides}


async def test_sensor_upsert_matches_golden(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    golden = Golden.load("entity-upsert.sensor")

    response = await send_request(session_client, golden.request)

    assert response["result"] == golden.result
    state = hass.states.get(SENSOR_ENTITY_ID)
    assert state is not None
    assert state.state == "21.4"
    assert state.attributes["unit_of_measurement"] == "°C"
    assert state.attributes["sources"] == golden.request["attributes"]["sources"]


async def test_sensor_upsert_registers_unique_id(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    await send_request(session_client, sensor_upsert())

    entry = er.async_get(hass).async_get(SENSOR_ENTITY_ID)

    assert entry is not None
    assert entry.unique_id == "stewart-default-climate-average_temperature"


async def test_sensor_upsert_creates_app_device_under_hub(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, loaded_entry: MockConfigEntry
) -> None:
    await send_request(session_client, sensor_upsert())

    hub = find_device(hass, loaded_entry, "default")
    app = find_device(hass, loaded_entry, "default/app/climate")
    entry = er.async_get(hass).async_get(SENSOR_ENTITY_ID)

    assert hub is not None
    assert hub.name == "Stewart"
    assert app is not None
    assert app.name == "Stewart · climate"
    assert app.via_device_id == hub.id
    assert entry is not None
    assert entry.device_id == app.id


async def test_upsert_names_hub_after_other_instance(
    hass: HomeAssistant, hass_ws_client: WebSocketGenerator, loaded_entry: MockConfigEntry
) -> None:
    client = await hass_ws_client(hass)
    await send_request(client, {**Golden.load("session-subscribe").request, "instance": "attic"})

    await send_request(client, sensor_upsert(instance="attic"))

    hub = find_device(hass, loaded_entry, "attic")
    assert hub is not None
    assert hub.name == "Stewart attic"


async def test_upsert_without_name_uses_key(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    config = sensor_config()
    del config["name"]

    response = await send_request(session_client, sensor_upsert(config=config))

    assert response["result"]["entity_id"] == "sensor.stewart_climate_average_temperature"


async def test_upsert_updates_existing_entity(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    await send_request(session_client, sensor_upsert())

    response = await send_request(session_client, sensor_upsert(state=22.5, attributes={}))

    assert response["result"] == {"entity_id": SENSOR_ENTITY_ID, "state": 22.5, "attributes": {}, "available": True}
    state = hass.states.get(SENSOR_ENTITY_ID)
    assert state is not None
    assert state.state == "22.5"
    assert "sources" not in state.attributes


async def test_upsert_without_state_keeps_state(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    await send_request(session_client, sensor_upsert())
    request = sensor_upsert(config=sensor_config(name="Mean temperature"))
    del request["state"], request["attributes"]

    response = await send_request(session_client, request)

    assert response["result"]["state"] == 21.4
    assert response["result"]["attributes"] == sensor_upsert()["attributes"]


async def test_new_entity_without_state_is_unknown(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    request = sensor_upsert()
    del request["state"]

    response = await send_request(session_client, request)

    assert response["result"]["state"] is None
    state = hass.states.get(SENSOR_ENTITY_ID)
    assert state is not None
    assert state.state == "unknown"


async def test_config_change_drops_unfit_state(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    await send_request(
        session_client, sensor_upsert(config={"device_class": "enum", "options": ["low", "high"]}, state="low")
    )
    request = sensor_upsert(config={"device_class": "enum", "options": ["off", "on"]})
    del request["state"]

    response = await send_request(session_client, request)

    assert response["result"]["state"] is None


@pytest.mark.parametrize(
    ("config", "state"),
    [
        ({"device_class": "enum", "options": ["low", "high"]}, "high"),
        ({"device_class": "timestamp"}, "2026-10-09T10:00:00+02:00"),
        ({"device_class": "date"}, "2026-10-09"),
        ({"name": "Mode"}, "eco"),
        ({"name": "Count"}, 3),
        ({"device_class": "aqi"}, 42),
    ],
)
async def test_upsert_accepts_sensor_state(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, config: dict[str, Any], state: JsonValue
) -> None:
    response = await send_request(session_client, sensor_upsert(config=config, state=state))

    assert response["success"], response
    assert response["result"]["state"] == state


async def test_upsert_refuses_invalid_config(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    golden = Golden.load("entity-upsert.invalid-config")

    response = await send_request(session_client, golden.request)

    assert response["error"]["code"] == golden.error_code


@pytest.mark.parametrize(
    "config",
    [
        {"device_class": "temperature", "unit_of_measurement": "%"},
        {"device_class": "temperature"},
        {"device_class": "humidity", "unit_of_measurement": "%", "state_class": "total_increasing"},
        {"device_class": "enum"},
        {"options": ["low"]},
        {"device_class": "enum", "options": ["low"], "state_class": "measurement"},
        {"device_class": "timestamp", "unit_of_measurement": "s"},
        {"device_class": "loudness"},
        {"suggested_display_precision": -1},
        {"icon": "weather-night"},
        {"entity_category": "system"},
        {"enabled_by_default": "yes"},
        {"name": 3},
        {"colour": "red"},
    ],
)
async def test_upsert_refuses_sensor_config(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, config: dict[str, Any]
) -> None:
    response = await send_request(session_client, sensor_upsert(config=config, state=None))

    assert response["error"]["code"] == "invalid_config", config


async def test_upsert_refuses_unknown_platform(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    response = await send_request(session_client, sensor_upsert(platform="vacuum", config={}))

    assert response["error"]["code"] == "invalid_config"


@pytest.mark.parametrize(
    ("config", "state"),
    [
        ({"device_class": "enum", "options": ["low", "high"]}, "medium"),
        ({"device_class": "temperature", "unit_of_measurement": "°C"}, "warm"),
        ({"state_class": "measurement"}, "12"),
        ({"device_class": "timestamp"}, "2026-10-09T10:00:00"),
        ({"device_class": "date"}, "tomorrow"),
        ({"name": "Mode"}, True),
        ({"name": "Mode"}, "x" * 256),
        ({"name": "Mode"}, {"mode": "eco"}),
    ],
)
async def test_upsert_refuses_sensor_state(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, config: dict[str, Any], state: JsonValue
) -> None:
    response = await send_request(session_client, sensor_upsert(config=config, state=state))

    assert response["error"]["code"] == "invalid_state", (config, state)


async def test_refused_upsert_keeps_entity(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    await send_request(session_client, sensor_upsert())

    await send_request(
        session_client, sensor_upsert(config={"name": "Renamed", "state_class": "measurement"}, state="warm")
    )

    state = hass.states.get(SENSOR_ENTITY_ID)
    assert state is not None
    assert state.state == "21.4"
    assert state.attributes["friendly_name"] == "Stewart · climate Average temperature"


async def test_refused_upsert_creates_no_hub(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, loaded_entry: MockConfigEntry
) -> None:
    await send_request(session_client, Golden.load("entity-upsert.invalid-config").request)

    assert find_device(hass, loaded_entry, "default") is None


async def test_upsert_needs_session(
    hass: HomeAssistant, hass_ws_client: WebSocketGenerator, loaded_entry: MockConfigEntry
) -> None:
    golden = Golden.load("entity-upsert.no-session")

    response = await send_request(await hass_ws_client(hass), golden.request)

    assert response["error"]["code"] == golden.error_code


async def test_upsert_needs_session_on_same_connection(
    hass: HomeAssistant, hass_ws_client: WebSocketGenerator, loaded_entry: MockConfigEntry
) -> None:
    await subscribe(await hass_ws_client(hass))

    response = await send_request(await hass_ws_client(hass), sensor_upsert())

    assert response["error"]["code"] == "no_session"


async def test_upsert_refuses_non_admin(
    hass: HomeAssistant,
    hass_ws_client: WebSocketGenerator,
    hass_read_only_access_token: str,
    loaded_entry: MockConfigEntry,
) -> None:
    client = await hass_ws_client(hass, hass_read_only_access_token)

    response = await send_request(client, sensor_upsert())

    assert response["error"]["code"] == "unauthorized"


@pytest.mark.parametrize("override", [{"key": "Average"}, {"app": "Climate"}, {"instance": "a-b"}, {"config": []}])
async def test_upsert_refuses_invalid_fields(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, override: dict[str, Any]
) -> None:
    response = await send_request(session_client, sensor_upsert(**override))

    assert response["error"]["code"] == "invalid_format"

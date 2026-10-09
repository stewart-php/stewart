from typing import Any

from homeassistant.core import HomeAssistant
from homeassistant.helpers import entity_registry as er
from pytest_homeassistant_custom_component.common import MockConfigEntry
from pytest_homeassistant_custom_component.typing import MockHAClientWebSocket, WebSocketGenerator

from tests.exchange import send_request
from tests.golden import Golden
from tests.registry import find_device

PRESENCE_ENTITY_ID = "binary_sensor.stewart_presence_anyone_home"
CLIMATE_ENTITY_ID = "sensor.stewart_climate_average_temperature"


def sensor_upsert(**overrides: object) -> dict[str, Any]:
    return {**Golden.load("entity-upsert.sensor").request, **overrides}


def reconcile(**overrides: object) -> dict[str, Any]:
    return {**Golden.load("entity-reconcile").request, **overrides}


async def test_reconcile_matches_golden(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    golden = Golden.load("entity-reconcile")
    await send_request(session_client, sensor_upsert())
    await send_request(session_client, sensor_upsert(app="lights", key="night_mode", config={}, state="on"))
    await send_request(session_client, sensor_upsert(app="heating", key="boiler_load", config={}, state=40))
    await send_request(session_client, Golden.load("entity-upsert.binary-sensor").request)

    response = await send_request(session_client, golden.request)
    await hass.async_block_till_done()

    assert response["result"] == golden.result
    assert hass.states.get(PRESENCE_ENTITY_ID) is None
    assert er.async_get(hass).async_get(PRESENCE_ENTITY_ID) is None
    assert hass.states.get(CLIMATE_ENTITY_ID) is not None
    assert hass.states.get("sensor.stewart_heating_boiler_load") is not None


async def test_reconcile_removes_key_app_no_longer_exposes(
    hass: HomeAssistant, session_client: MockHAClientWebSocket
) -> None:
    await send_request(session_client, sensor_upsert())
    stale = await send_request(session_client, sensor_upsert(key="outdoor_temperature"))

    response = await send_request(session_client, reconcile(keep_apps=[]))

    assert response["result"] == {"removed": [stale["result"]["entity_id"]]}
    assert hass.states.get(CLIMATE_ENTITY_ID) is not None


async def test_reconcile_removes_entry_from_before_restart(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, loaded_entry: MockConfigEntry
) -> None:
    entry = er.async_get(hass).async_get_or_create(
        "sensor", "stewart", "stewart-default-sun-tracker-elevation", config_entry=loaded_entry
    )

    response = await send_request(session_client, reconcile())

    assert response["result"] == {"removed": [entry.entity_id]}
    assert er.async_get(hass).async_get(entry.entity_id) is None


async def test_reconcile_leaves_other_instance_alone(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, loaded_entry: MockConfigEntry
) -> None:
    er.async_get(hass).async_get_or_create(
        "sensor", "stewart", "stewart-attic-climate-humidity", config_entry=loaded_entry
    )

    response = await send_request(session_client, reconcile())

    assert response["result"] == {"removed": []}


async def test_reconcile_removes_emptied_app_device_and_hub(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, loaded_entry: MockConfigEntry
) -> None:
    await send_request(session_client, Golden.load("entity-upsert.binary-sensor").request)

    await send_request(session_client, reconcile())

    assert find_device(hass, loaded_entry, "default/app/presence") is None
    assert find_device(hass, loaded_entry, "default") is None


async def test_upsert_after_reconcile_recreates_entity(
    hass: HomeAssistant, session_client: MockHAClientWebSocket
) -> None:
    upsert = Golden.load("entity-upsert.binary-sensor").request
    await send_request(session_client, upsert)
    await send_request(session_client, reconcile())

    response = await send_request(session_client, upsert)

    assert response["result"]["entity_id"] == PRESENCE_ENTITY_ID
    assert hass.states.get(PRESENCE_ENTITY_ID) is not None


async def test_reconcile_needs_session(
    hass: HomeAssistant, hass_ws_client: WebSocketGenerator, loaded_entry: MockConfigEntry
) -> None:
    response = await send_request(await hass_ws_client(hass), reconcile())

    assert response["error"]["code"] == "no_session"

from typing import Any

import pytest
from homeassistant.core import HomeAssistant
from homeassistant.helpers import area_registry as ar
from homeassistant.helpers import entity_registry as er
from pytest_homeassistant_custom_component.common import MockConfigEntry
from pytest_homeassistant_custom_component.typing import MockHAClientWebSocket

from tests.exchange import send_request
from tests.golden import Golden
from tests.registry import find_device

GREENHOUSE_ENTITY_ID = "sensor.greenhouse_soil_moisture"


def greenhouse_upsert(**overrides: object) -> dict[str, Any]:
    return {**Golden.load("entity-upsert.device-override").request, **overrides}


def climate_upsert(**overrides: object) -> dict[str, Any]:
    return {**Golden.load("entity-upsert.sensor").request, **overrides}


def find_device_id(hass: HomeAssistant, entity_id: str) -> str | None:
    entry = er.async_get(hass).async_get(entity_id)
    assert entry is not None
    return entry.device_id


async def test_device_override_matches_golden(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, loaded_entry: MockConfigEntry
) -> None:
    golden = Golden.load("entity-upsert.device-override")

    response = await send_request(session_client, golden.request)

    assert response["result"] == golden.result
    hub = find_device(hass, loaded_entry, "default")
    greenhouse = find_device(hass, loaded_entry, "default/device/greenhouse")
    assert hub is not None
    assert greenhouse is not None
    assert greenhouse.name == "Greenhouse"
    assert greenhouse.manufacturer == "Stewart"
    assert greenhouse.via_device_id == hub.id
    assert find_device_id(hass, GREENHOUSE_ENTITY_ID) == greenhouse.id
    assert find_device(hass, loaded_entry, "default/app/garden") is None


async def test_new_named_device_takes_suggested_area(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, loaded_entry: MockConfigEntry
) -> None:
    device = {"identifier": "greenhouse", "name": "Greenhouse", "suggested_area": "Garden"}

    await send_request(session_client, greenhouse_upsert(device=device))

    greenhouse = find_device(hass, loaded_entry, "default/device/greenhouse")
    area = ar.async_get(hass).async_get_area_by_name("Garden")
    assert greenhouse is not None
    assert area is not None
    assert greenhouse.area_id == area.id


async def test_apps_naming_same_device_share_it(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, loaded_entry: MockConfigEntry
) -> None:
    await send_request(session_client, greenhouse_upsert())

    response = await send_request(session_client, greenhouse_upsert(app="irrigation", key="valve_open"))

    assert find_device_id(hass, response["result"]["entity_id"]) == find_device_id(hass, GREENHOUSE_ENTITY_ID)


async def test_upsert_updates_named_device(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, loaded_entry: MockConfigEntry
) -> None:
    await send_request(session_client, greenhouse_upsert())

    await send_request(session_client, greenhouse_upsert(device={"identifier": "greenhouse", "name": "Glasshouse"}))

    greenhouse = find_device(hass, loaded_entry, "default/device/greenhouse")
    assert greenhouse is not None
    assert greenhouse.name == "Glasshouse"


async def test_upsert_moves_entity_and_removes_empty_device(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, loaded_entry: MockConfigEntry
) -> None:
    entity_id = (await send_request(session_client, climate_upsert()))["result"]["entity_id"]

    await send_request(session_client, climate_upsert(device={"identifier": "hall", "name": "Hall"}))

    hall = find_device(hass, loaded_entry, "default/device/hall")
    assert hall is not None
    assert find_device_id(hass, entity_id) == hall.id
    assert find_device(hass, loaded_entry, "default/app/climate") is None
    assert find_device(hass, loaded_entry, "default") is not None


async def test_remove_of_last_entity_removes_device_and_hub(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, loaded_entry: MockConfigEntry
) -> None:
    await send_request(session_client, climate_upsert())

    await send_request(
        session_client, {**Golden.load("entity-remove").request, "app": "climate", "key": "average_temperature"}
    )

    assert find_device(hass, loaded_entry, "default/app/climate") is None
    assert find_device(hass, loaded_entry, "default") is None


async def test_remove_keeps_device_with_other_entities(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, loaded_entry: MockConfigEntry
) -> None:
    await send_request(session_client, climate_upsert())
    await send_request(session_client, climate_upsert(key="max_temperature"))

    await send_request(
        session_client, {**Golden.load("entity-remove").request, "app": "climate", "key": "max_temperature"}
    )

    assert find_device(hass, loaded_entry, "default/app/climate") is not None


async def test_remove_keeps_hub_with_other_devices(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, loaded_entry: MockConfigEntry
) -> None:
    await send_request(session_client, climate_upsert())
    await send_request(session_client, greenhouse_upsert())

    await send_request(
        session_client, {**Golden.load("entity-remove").request, "app": "climate", "key": "average_temperature"}
    )

    assert find_device(hass, loaded_entry, "default") is not None


async def test_platform_change_keeps_device(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, loaded_entry: MockConfigEntry
) -> None:
    await send_request(session_client, climate_upsert())
    device = find_device(hass, loaded_entry, "default/app/climate")

    response = await send_request(
        session_client, climate_upsert(platform="binary_sensor", config={"name": "Warm"}, state=True, attributes={})
    )

    assert device is not None
    assert find_device_id(hass, response["result"]["entity_id"]) == device.id


@pytest.mark.parametrize(
    "device",
    [{"identifier": "Greenhouse", "name": "Greenhouse"}, {"identifier": "greenhouse"}, {"name": "Greenhouse"}],
)
async def test_upsert_refuses_invalid_device(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, device: dict[str, Any]
) -> None:
    response = await send_request(session_client, greenhouse_upsert(device=device))

    assert response["error"]["code"] == "invalid_format"

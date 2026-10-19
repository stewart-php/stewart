from typing import Any

import pytest
from homeassistant.core import HomeAssistant
from homeassistant.helpers import entity_registry as er
from homeassistant.helpers.device_registry import DeviceEntry
from homeassistant.setup import async_setup_component
from pytest_homeassistant_custom_component.common import MockConfigEntry
from pytest_homeassistant_custom_component.typing import MockHAClientWebSocket, WebSocketGenerator

from tests.exchange import send_request
from tests.golden import Golden
from tests.registry import find_device, remove_device

CLIMATE_ENTITY_ID = "sensor.stewart_climate_average_temperature"
GREENHOUSE_ENTITY_ID = "sensor.greenhouse_soil_moisture"


def climate_upsert(**overrides: object) -> dict[str, Any]:
    return {**Golden.load("entity-upsert.sensor").request, **overrides}


def greenhouse_upsert() -> dict[str, Any]:
    return Golden.load("entity-upsert.device-override").request


def require_device(hass: HomeAssistant, entry: MockConfigEntry, identifier: str) -> DeviceEntry:
    device = find_device(hass, entry, identifier)
    assert device is not None
    return device


def is_registered(hass: HomeAssistant, entity_id: str) -> bool:
    return er.async_get(hass).async_get(entity_id) is not None


async def expose_and_disconnect(
    hass: HomeAssistant, hass_ws_client: WebSocketGenerator, *upserts: dict[str, Any], instance: str = "default"
) -> None:
    client = await hass_ws_client(hass)
    await send_request(client, {**Golden.load("session-subscribe").request, "instance": instance})
    for upsert in upserts:
        await send_request(client, upsert)
    await client.close()
    await hass.async_block_till_done()


# Autouse runs it before any client starts the HTTP server, which then refuses new views.
@pytest.fixture(autouse=True)
async def device_registry_commands(hass: HomeAssistant) -> None:
    assert await async_setup_component(hass, "config", {})


@pytest.fixture
async def admin_client(
    hass: HomeAssistant, hass_ws_client: WebSocketGenerator, loaded_entry: MockConfigEntry
) -> MockHAClientWebSocket:
    return await hass_ws_client(hass)


@pytest.mark.parametrize("identifier", ["default", "default/app/climate"])
async def test_live_instance_keeps_its_devices(
    hass: HomeAssistant,
    session_client: MockHAClientWebSocket,
    admin_client: MockHAClientWebSocket,
    loaded_entry: MockConfigEntry,
    identifier: str,
) -> None:
    await send_request(session_client, climate_upsert())

    response = await remove_device(admin_client, loaded_entry, require_device(hass, loaded_entry, identifier).id)
    await hass.async_block_till_done()

    assert not response["success"]
    assert find_device(hass, loaded_entry, identifier) is not None
    assert hass.states.get(CLIMATE_ENTITY_ID) is not None


async def test_app_device_of_disconnected_instance_can_be_deleted(
    hass: HomeAssistant,
    hass_ws_client: WebSocketGenerator,
    admin_client: MockHAClientWebSocket,
    loaded_entry: MockConfigEntry,
) -> None:
    await expose_and_disconnect(hass, hass_ws_client, climate_upsert())

    response = await remove_device(
        admin_client, loaded_entry, require_device(hass, loaded_entry, "default/app/climate").id
    )
    await hass.async_block_till_done()

    assert response["success"]
    assert find_device(hass, loaded_entry, "default/app/climate") is None
    assert find_device(hass, loaded_entry, "default") is None
    assert not is_registered(hass, CLIMATE_ENTITY_ID)
    assert hass.states.get(CLIMATE_ENTITY_ID) is None


async def test_hub_stays_while_it_has_other_devices(
    hass: HomeAssistant,
    hass_ws_client: WebSocketGenerator,
    admin_client: MockHAClientWebSocket,
    loaded_entry: MockConfigEntry,
) -> None:
    await expose_and_disconnect(hass, hass_ws_client, climate_upsert(), greenhouse_upsert())

    await remove_device(admin_client, loaded_entry, require_device(hass, loaded_entry, "default/device/greenhouse").id)
    await hass.async_block_till_done()

    assert find_device(hass, loaded_entry, "default/device/greenhouse") is None
    assert not is_registered(hass, GREENHOUSE_ENTITY_ID)
    assert find_device(hass, loaded_entry, "default") is not None
    assert find_device(hass, loaded_entry, "default/app/climate") is not None
    assert is_registered(hass, CLIMATE_ENTITY_ID)


async def test_hub_deletion_takes_its_devices_and_entities(
    hass: HomeAssistant,
    hass_ws_client: WebSocketGenerator,
    admin_client: MockHAClientWebSocket,
    loaded_entry: MockConfigEntry,
) -> None:
    await expose_and_disconnect(hass, hass_ws_client, climate_upsert(), greenhouse_upsert())

    response = await remove_device(admin_client, loaded_entry, require_device(hass, loaded_entry, "default").id)
    await hass.async_block_till_done()

    assert response["success"]
    assert find_device(hass, loaded_entry, "default") is None
    assert find_device(hass, loaded_entry, "default/app/climate") is None
    assert find_device(hass, loaded_entry, "default/device/greenhouse") is None
    assert not is_registered(hass, CLIMATE_ENTITY_ID)
    assert not is_registered(hass, GREENHOUSE_ENTITY_ID)


async def test_session_of_another_instance_does_not_block(
    hass: HomeAssistant,
    hass_ws_client: WebSocketGenerator,
    session_client: MockHAClientWebSocket,
    admin_client: MockHAClientWebSocket,
    loaded_entry: MockConfigEntry,
) -> None:
    await expose_and_disconnect(hass, hass_ws_client, climate_upsert(instance="attic"), instance="attic")

    response = await remove_device(admin_client, loaded_entry, require_device(hass, loaded_entry, "attic").id)
    await hass.async_block_till_done()

    assert response["success"]
    assert find_device(hass, loaded_entry, "attic/app/climate") is None


async def test_upsert_after_deletion_recreates_device_and_entity(
    hass: HomeAssistant,
    hass_ws_client: WebSocketGenerator,
    admin_client: MockHAClientWebSocket,
    loaded_entry: MockConfigEntry,
) -> None:
    await expose_and_disconnect(hass, hass_ws_client, climate_upsert())
    await remove_device(admin_client, loaded_entry, require_device(hass, loaded_entry, "default").id)
    await hass.async_block_till_done()
    client = await hass_ws_client(hass)
    await send_request(client, Golden.load("session-subscribe").request)

    response = await send_request(client, climate_upsert())

    assert response["result"]["entity_id"] == CLIMATE_ENTITY_ID
    assert find_device(hass, loaded_entry, "default/app/climate") is not None
    assert hass.states.get(CLIMATE_ENTITY_ID) is not None


async def test_unloaded_entry_refuses_removal(
    hass: HomeAssistant,
    hass_ws_client: WebSocketGenerator,
    admin_client: MockHAClientWebSocket,
    loaded_entry: MockConfigEntry,
) -> None:
    await expose_and_disconnect(hass, hass_ws_client, climate_upsert())
    assert await hass.config_entries.async_unload(loaded_entry.entry_id)

    response = await remove_device(admin_client, loaded_entry, require_device(hass, loaded_entry, "default").id)

    assert not response["success"]
    assert find_device(hass, loaded_entry, "default") is not None

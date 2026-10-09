from typing import Any

from homeassistant.core import HomeAssistant
from pytest_homeassistant_custom_component.common import MockConfigEntry
from pytest_homeassistant_custom_component.typing import MockHAClientWebSocket, WebSocketGenerator

from tests.exchange import send_request, subscribe
from tests.golden import Golden

SENSOR_ENTITY_ID = "sensor.stewart_climate_average_temperature"


def sensor_upsert(**overrides: object) -> dict[str, Any]:
    return {**Golden.load("entity-upsert.sensor").request, **overrides}


def read_state(hass: HomeAssistant) -> str:
    state = hass.states.get(SENSOR_ENTITY_ID)
    assert state is not None
    return state.state


async def open_sensor_session(hass: HomeAssistant, hass_ws_client: WebSocketGenerator) -> MockHAClientWebSocket:
    client = await hass_ws_client(hass)
    await subscribe(client)
    await send_request(client, sensor_upsert())
    return client


async def test_unsubscribe_makes_entities_unavailable(
    hass: HomeAssistant, hass_ws_client: WebSocketGenerator, loaded_entry: MockConfigEntry
) -> None:
    client = await hass_ws_client(hass)
    subscription = await subscribe(client)
    await send_request(client, sensor_upsert())

    await send_request(client, {"type": "unsubscribe_events", "subscription": subscription["id"]})
    await hass.async_block_till_done()

    assert read_state(hass) == "unavailable"


async def test_closed_socket_makes_entities_unavailable(
    hass: HomeAssistant, hass_ws_client: WebSocketGenerator, loaded_entry: MockConfigEntry
) -> None:
    client = await open_sensor_session(hass, hass_ws_client)

    await client.close()
    await hass.async_block_till_done()

    assert read_state(hass) == "unavailable"


async def test_takeover_keeps_entities_available(
    hass: HomeAssistant, hass_ws_client: WebSocketGenerator, loaded_entry: MockConfigEntry
) -> None:
    await open_sensor_session(hass, hass_ws_client)

    await subscribe(await hass_ws_client(hass))
    await hass.async_block_till_done()

    assert read_state(hass) == "21.4"


async def test_new_session_makes_entities_available_again(
    hass: HomeAssistant, hass_ws_client: WebSocketGenerator, loaded_entry: MockConfigEntry
) -> None:
    client = await open_sensor_session(hass, hass_ws_client)
    await client.close()
    await hass.async_block_till_done()

    await subscribe(await hass_ws_client(hass))
    await hass.async_block_till_done()

    assert read_state(hass) == "21.4"


async def test_other_instance_session_leaves_entities_alone(
    hass: HomeAssistant, hass_ws_client: WebSocketGenerator, loaded_entry: MockConfigEntry
) -> None:
    client = await open_sensor_session(hass, hass_ws_client)
    other = await hass_ws_client(hass)
    await send_request(other, {**Golden.load("session-subscribe").request, "instance": "attic"})

    await client.close()
    await hass.async_block_till_done()

    assert read_state(hass) == "unavailable"


async def test_upsert_can_create_entity_unavailable(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    response = await send_request(session_client, sensor_upsert(available=False))

    assert response["result"]["available"] is False
    assert read_state(hass) == "unavailable"


async def test_own_unavailability_survives_new_session(
    hass: HomeAssistant, hass_ws_client: WebSocketGenerator, loaded_entry: MockConfigEntry
) -> None:
    client = await hass_ws_client(hass)
    await subscribe(client)
    await send_request(client, sensor_upsert(available=False))
    await client.close()
    await hass.async_block_till_done()

    new_client = await hass_ws_client(hass)
    await subscribe(new_client)
    await hass.async_block_till_done()
    response = await send_request(new_client, sensor_upsert())

    assert response["result"]["available"] is False
    assert read_state(hass) == "unavailable"


async def test_upsert_can_make_entity_available_again(
    hass: HomeAssistant, session_client: MockHAClientWebSocket
) -> None:
    await send_request(session_client, sensor_upsert(available=False))

    response = await send_request(session_client, sensor_upsert(available=True))

    assert response["result"]["available"] is True
    assert read_state(hass) == "21.4"

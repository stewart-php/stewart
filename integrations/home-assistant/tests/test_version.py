from homeassistant.core import HomeAssistant
from pytest_homeassistant_custom_component.common import MockConfigEntry
from pytest_homeassistant_custom_component.typing import WebSocketGenerator

from tests.golden import Golden


async def test_version_reports_manifest_and_protocol(
    hass: HomeAssistant, hass_ws_client: WebSocketGenerator, loaded_entry: MockConfigEntry, manifest_version: str
) -> None:
    golden = Golden.load("version")
    client = await hass_ws_client(hass)

    await client.send_json_auto_id(golden.request)
    response = await client.receive_json()

    assert response["success"]
    assert response["result"] == {**golden.result, "component_version": manifest_version}


async def test_version_refuses_non_admin(
    hass: HomeAssistant,
    hass_ws_client: WebSocketGenerator,
    hass_read_only_access_token: str,
    loaded_entry: MockConfigEntry,
) -> None:
    client = await hass_ws_client(hass, hass_read_only_access_token)

    await client.send_json_auto_id(Golden.load("version").request)
    response = await client.receive_json()

    assert response["error"]["code"] == "unauthorized"


async def test_version_unknown_after_unload(
    hass: HomeAssistant, hass_ws_client: WebSocketGenerator, loaded_entry: MockConfigEntry
) -> None:
    await hass.config_entries.async_unload(loaded_entry.entry_id)
    client = await hass_ws_client(hass)

    await client.send_json_auto_id(Golden.load("version").request)
    response = await client.receive_json()

    assert response["error"]["code"] == "unknown_command"

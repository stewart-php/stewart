from typing import Any

import pytest
from homeassistant.core import HomeAssistant
from homeassistant.helpers.dispatcher import async_dispatcher_connect
from pytest_homeassistant_custom_component.common import MockConfigEntry
from pytest_homeassistant_custom_component.typing import MockHAClientWebSocket, WebSocketGenerator

from custom_components.stewart.const import SIGNAL_SESSION_CHANGED
from tests.exchange import subscribe
from tests.golden import Golden


async def assert_next_frame_is_pong(client: MockHAClientWebSocket) -> None:
    await client.send_json_auto_id({"type": "ping"})
    assert (await client.receive_json())["type"] == "pong"


async def assert_subscription_ended(client: MockHAClientWebSocket, subscription_id: int) -> None:
    await client.send_json_auto_id({"type": "unsubscribe_events", "subscription": subscription_id})
    assert (await client.receive_json())["error"]["code"] == "not_found"


async def test_subscribe_opens_session(
    hass: HomeAssistant, hass_ws_client: WebSocketGenerator, loaded_entry: MockConfigEntry
) -> None:
    client = await hass_ws_client(hass)

    response = await subscribe(client)

    assert response["success"]
    assert response["result"] == Golden.load("session-subscribe").result


async def test_subscribe_refuses_other_protocol(
    hass: HomeAssistant, hass_ws_client: WebSocketGenerator, loaded_entry: MockConfigEntry
) -> None:
    golden = Golden.load("session-subscribe.protocol-mismatch")
    client = await hass_ws_client(hass)

    await client.send_json_auto_id(golden.request)
    response = await client.receive_json()

    assert response["error"]["code"] == golden.error_code


@pytest.mark.parametrize(
    "override",
    [
        {"instance": "Default"},
        {"instance": "default\n"},
        {"instance": "a" * 65},
        {"command_timeout": 0},
        {"command_timeout": 300.5},
    ],
)
async def test_subscribe_refuses_invalid_fields(
    hass: HomeAssistant, hass_ws_client: WebSocketGenerator, loaded_entry: MockConfigEntry, override: dict[str, Any]
) -> None:
    client = await hass_ws_client(hass)

    await client.send_json_auto_id({**Golden.load("session-subscribe").request, **override})
    response = await client.receive_json()

    assert response["error"]["code"] == "invalid_format"


async def test_subscribe_refuses_non_admin(
    hass: HomeAssistant,
    hass_ws_client: WebSocketGenerator,
    hass_read_only_access_token: str,
    loaded_entry: MockConfigEntry,
) -> None:
    client = await hass_ws_client(hass, hass_read_only_access_token)

    response = await subscribe(client)

    assert response["error"]["code"] == "unauthorized"


async def test_new_session_replaces_old_one(
    hass: HomeAssistant, hass_ws_client: WebSocketGenerator, loaded_entry: MockConfigEntry
) -> None:
    old_client = await hass_ws_client(hass)
    new_client = await hass_ws_client(hass)
    old_subscription = await subscribe(old_client)

    assert (await subscribe(new_client))["success"]

    frame = await old_client.receive_json()
    assert frame["id"] == old_subscription["id"]
    assert frame["type"] == "event"
    assert frame["event"] == Golden.load("event-session-replaced").event


async def test_replaced_session_is_unsubscribed(
    hass: HomeAssistant, hass_ws_client: WebSocketGenerator, loaded_entry: MockConfigEntry
) -> None:
    old_client = await hass_ws_client(hass)
    old_subscription = await subscribe(old_client)

    await subscribe(await hass_ws_client(hass))

    await old_client.receive_json()
    await assert_subscription_ended(old_client, old_subscription["id"])


async def test_unsubscribe_frees_instance(
    hass: HomeAssistant, hass_ws_client: WebSocketGenerator, loaded_entry: MockConfigEntry
) -> None:
    old_client = await hass_ws_client(hass)
    old_subscription = await subscribe(old_client)
    await old_client.send_json_auto_id({"type": "unsubscribe_events", "subscription": old_subscription["id"]})
    assert (await old_client.receive_json())["success"]

    await subscribe(await hass_ws_client(hass))

    await assert_next_frame_is_pong(old_client)


async def test_unload_ends_sessions(
    hass: HomeAssistant, hass_ws_client: WebSocketGenerator, loaded_entry: MockConfigEntry
) -> None:
    old_client = await hass_ws_client(hass)
    old_subscription = await subscribe(old_client)

    await hass.config_entries.async_unload(loaded_entry.entry_id)

    await assert_subscription_ended(old_client, old_subscription["id"])


async def test_session_open_and_close_signal_instance(
    hass: HomeAssistant, hass_ws_client: WebSocketGenerator, loaded_entry: MockConfigEntry
) -> None:
    signals: list[None] = []
    async_dispatcher_connect(hass, SIGNAL_SESSION_CHANGED.format("default"), lambda: signals.append(None))
    client = await hass_ws_client(hass)

    subscription = await subscribe(client)
    await hass.async_block_till_done()
    assert len(signals) == 1

    await client.send_json_auto_id({"type": "unsubscribe_events", "subscription": subscription["id"]})
    await client.receive_json()
    await hass.async_block_till_done()
    assert len(signals) == 2


async def test_takeover_keeps_instance_signal_quiet(
    hass: HomeAssistant, hass_ws_client: WebSocketGenerator, loaded_entry: MockConfigEntry
) -> None:
    signals: list[None] = []
    await subscribe(await hass_ws_client(hass))
    async_dispatcher_connect(hass, SIGNAL_SESSION_CHANGED.format("default"), lambda: signals.append(None))

    await subscribe(await hass_ws_client(hass))
    await hass.async_block_till_done()

    assert signals == []

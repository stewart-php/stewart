from typing import Protocol

from homeassistant.core import callback
from homeassistant.helpers.device_registry import DeviceInfo
from homeassistant.helpers.dispatcher import async_dispatcher_connect
from homeassistant.helpers.restore_state import RestoreEntity

from .change import ABSENT, EntityChange, JsonValue
from .config import EntityConfig
from .const import SIGNAL_SESSION_CHANGED
from .errors import InvalidStateError
from .identity import EntityAddress
from .restore import StoredExposure
from .session import SessionRegistry
from .snapshot import EntitySnapshot


class PlatformConfig(Protocol):
    @property
    def entity(self) -> EntityConfig: ...


class StewartEntity[ConfigT: PlatformConfig, NativeT](RestoreEntity):
    _attr_has_entity_name = True
    _attr_should_poll = False

    def __init__(
        self, *, address: EntityAddress, config: ConfigT, sessions: SessionRegistry, device_info: DeviceInfo
    ) -> None:
        self.address = address
        self.config = config
        self._sessions = sessions
        self._attr_unique_id = address.unique_id
        self._attr_device_info = device_info
        self._wire_state: JsonValue = None
        self._attributes: dict[str, JsonValue] = {}
        self._available_flag = True
        self._live = False
        self._restores_state = False
        self._restores_attributes = False
        self._show_config(config)

    @property
    def available(self) -> bool:
        return self._available_flag and self._sessions.has_session(self.address.instance)

    @property
    def extra_state_attributes(self) -> dict[str, JsonValue]:
        return self._attributes

    # Validates the whole upsert before changing anything, so a refused one leaves the entity as it was.
    @callback
    def apply_upsert(self, config: ConfigT, change: EntityChange) -> None:
        self._check_change(change)
        state = self._wire_state if change.state is ABSENT else change.state
        try:
            native = self._convert_state(config, state)
        except InvalidStateError:
            if change.state is not ABSENT:
                raise
            state, native = None, self._convert_state(config, None)
        self.config = config
        self._show_config(config)
        self._wire_state = state
        self._show_state(native)
        self._apply_extras(change)

    # Fields the creating upsert left out come from what Home Assistant stored before a restart.
    @callback
    def seed_from_upsert(self, change: EntityChange) -> None:
        self.apply_change(change)
        self._restores_state = change.state is ABSENT
        self._restores_attributes = change.attributes is ABSENT

    @callback
    def apply_change(self, change: EntityChange) -> None:
        self._check_change(change)
        if change.state is not ABSENT:
            self._show_state(self._convert_state(self.config, change.state))
            self._wire_state = change.state
            self._restores_state = False
        self._apply_extras(change)

    @callback
    def publish(self) -> None:
        if self._live:
            self.async_write_ha_state()

    def take_snapshot(self) -> EntitySnapshot:
        return EntitySnapshot(
            entity_id=self.entity_id,
            state=self._wire_state,
            attributes=self._attributes,
            available=self.available,
        )

    @property
    def extra_restore_state_data(self) -> StoredExposure:
        return StoredExposure(state=self._wire_state, attributes=self._attributes)

    async def async_added_to_hass(self) -> None:
        await super().async_added_to_hass()
        await self._restore_stored_exposure()
        self.async_on_remove(
            async_dispatcher_connect(
                self.hass, SIGNAL_SESSION_CHANGED.format(self.address.instance), self.async_write_ha_state
            )
        )
        self._live = True

    async def async_will_remove_from_hass(self) -> None:
        self._live = False
        await super().async_will_remove_from_hass()

    async def _restore_stored_exposure(self) -> None:
        if not (self._restores_state or self._restores_attributes):
            return
        extra_data = await self.async_get_last_extra_data()
        if extra_data is None or (stored := StoredExposure.from_dict(extra_data.as_dict())) is None:
            return
        if self._restores_attributes:
            self._attributes = dict(stored.attributes)
        if self._restores_state:
            try:
                self._show_state(self._convert_state(self.config, stored.state))
            except InvalidStateError:
                return
            self._wire_state = stored.state

    def _show_config(self, config: ConfigT) -> None:
        self._attr_name = config.entity.name if config.entity.name is not None else self.address.key
        self._attr_icon = config.entity.icon
        self._attr_entity_category = config.entity.entity_category
        self._attr_entity_registry_enabled_default = config.entity.enabled_by_default

    def _check_change(self, change: EntityChange) -> None:  # noqa: ARG002
        return

    def _convert_state(self, config: ConfigT, state: JsonValue) -> NativeT:
        raise NotImplementedError

    def _show_state(self, native: NativeT) -> None:
        raise NotImplementedError

    def _apply_extras(self, change: EntityChange) -> None:
        if change.attributes is not ABSENT:
            self._attributes = dict(change.attributes)
            self._restores_attributes = False
        if change.available is not ABSENT:
            self._available_flag = change.available

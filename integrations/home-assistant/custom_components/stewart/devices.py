from collections.abc import Iterable, Mapping
from dataclasses import dataclass
from typing import Any, Self

from homeassistant.core import HomeAssistant, callback
from homeassistant.helpers import device_registry as dr
from homeassistant.helpers import entity_registry as er
from homeassistant.helpers.device_registry import DeviceEntry, DeviceEntryType, DeviceInfo

from .const import DEFAULT_INSTANCE, DOMAIN, MANUFACTURER
from .identity import EntityAddress


@dataclass(frozen=True, slots=True, kw_only=True)
class DeviceTarget:
    instance: str
    identifier: str
    name: str
    manufacturer: str | None = None
    model: str | None = None
    suggested_area: str | None = None
    entry_type: DeviceEntryType | None = None

    @classmethod
    def for_app(cls, address: EntityAddress) -> Self:
        return cls(
            instance=address.instance,
            identifier=f"{address.instance}/app/{address.app}",
            name=f"{MANUFACTURER} · {address.app}",
            manufacturer=MANUFACTURER,
            entry_type=DeviceEntryType.SERVICE,
        )

    @classmethod
    def for_named(cls, instance: str, device: Mapping[str, Any]) -> Self:
        return cls(
            instance=instance,
            identifier=f"{instance}/device/{device['identifier']}",
            name=device["name"],
            manufacturer=device.get("manufacturer"),
            model=device.get("model"),
            suggested_area=device.get("suggested_area"),
        )

    @property
    def device_info(self) -> DeviceInfo:
        return DeviceInfo(identifiers={(DOMAIN, self.identifier)})


class DeviceDirectory:
    def __init__(self, hass: HomeAssistant, config_entry_id: str) -> None:
        self._registry = dr.async_get(hass)
        self._entity_registry = er.async_get(hass)
        self._config_entry_id = config_entry_id

    # The hub link is set on the registry directly: DeviceInfo spells it differently across supported releases.
    @callback
    def ensure_device(self, target: DeviceTarget) -> DeviceEntry:
        hub = self._ensure_hub(target.instance)
        device = self._registry.async_get_or_create(
            config_entry_id=self._config_entry_id,
            identifiers={(DOMAIN, target.identifier)},
            name=target.name,
            manufacturer=target.manufacturer,
            model=target.model,
            suggested_area=target.suggested_area,
            entry_type=target.entry_type,
        )
        if device.via_device_id != hub.id:
            device = self._registry.async_update_device(device.id, via_device_id=hub.id) or device
        return device

    @callback
    def remove_if_empty(self, device_ids: Iterable[str | None]) -> None:
        for device_id in {device_id for device_id in device_ids if device_id is not None}:
            device = self._find_own_device(device_id)
            if device is None or self._has_entities(device_id):
                continue
            self._registry.async_remove_device(device_id)
            if device.via_device_id is not None and not self._has_children(device.via_device_id):
                self._registry.async_remove_device(device.via_device_id)

    def _ensure_hub(self, instance: str) -> DeviceEntry:
        return self._registry.async_get_or_create(
            config_entry_id=self._config_entry_id,
            identifiers={(DOMAIN, instance)},
            name=MANUFACTURER if instance == DEFAULT_INSTANCE else f"{MANUFACTURER} {instance}",
            manufacturer=MANUFACTURER,
            entry_type=DeviceEntryType.SERVICE,
        )

    def _has_entities(self, device_id: str) -> bool:
        return bool(er.async_entries_for_device(self._entity_registry, device_id, include_disabled_entities=True))

    def _has_children(self, hub_id: str) -> bool:
        return any(device.via_device_id == hub_id for device in self._list_own_devices())

    def _find_own_device(self, device_id: str) -> DeviceEntry | None:
        return next((device for device in self._list_own_devices() if device.id == device_id), None)

    def _list_own_devices(self) -> list[DeviceEntry]:
        return dr.async_entries_for_config_entry(self._registry, self._config_entry_id)

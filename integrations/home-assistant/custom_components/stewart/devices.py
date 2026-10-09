from dataclasses import dataclass
from typing import Self

from homeassistant.core import HomeAssistant, callback
from homeassistant.helpers import device_registry as dr
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

    @property
    def device_info(self) -> DeviceInfo:
        return DeviceInfo(identifiers={(DOMAIN, self.identifier)})


class DeviceDirectory:
    def __init__(self, hass: HomeAssistant, config_entry_id: str) -> None:
        self._registry = dr.async_get(hass)
        self._config_entry_id = config_entry_id

    # The hub link is set on the registry directly: DeviceInfo spells it differently across supported releases.
    @callback
    def ensure_device(self, target: DeviceTarget) -> None:
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
            self._registry.async_update_device(device.id, via_device_id=hub.id)

    def _ensure_hub(self, instance: str) -> DeviceEntry:
        return self._registry.async_get_or_create(
            config_entry_id=self._config_entry_id,
            identifiers={(DOMAIN, instance)},
            name=MANUFACTURER if instance == DEFAULT_INSTANCE else f"{MANUFACTURER} {instance}",
            manufacturer=MANUFACTURER,
            entry_type=DeviceEntryType.SERVICE,
        )

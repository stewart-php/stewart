from collections.abc import Callable, Coroutine
from typing import Any

from homeassistant.core import HomeAssistant
from homeassistant.helpers.entity_platform import AddConfigEntryEntitiesCallback, async_get_current_platform

from .entity import StewartEntity
from .runtime import StewartConfigEntry

type PlatformSetup = Callable[
    [HomeAssistant, StewartConfigEntry, AddConfigEntryEntitiesCallback], Coroutine[Any, Any, None]
]


def attach(entity_class: type[StewartEntity[Any, Any]]) -> PlatformSetup:
    async def async_setup_entry(
        hass: HomeAssistant,  # noqa: ARG001
        entry: StewartConfigEntry,
        async_add_entities: AddConfigEntryEntitiesCallback,  # noqa: ARG001
    ) -> None:
        entry.runtime_data.entities.attach_platform(entity_class, async_get_current_platform())

    return async_setup_entry

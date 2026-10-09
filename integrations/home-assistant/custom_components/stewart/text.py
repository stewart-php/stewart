import re
from collections.abc import Mapping
from dataclasses import dataclass
from typing import Final, Self

from homeassistant.components.text import TextEntity, TextMode
from homeassistant.const import MAX_LENGTH_STATE_STATE, Platform
from homeassistant.core import HomeAssistant
from homeassistant.helpers.device_registry import DeviceInfo
from homeassistant.helpers.entity_platform import AddConfigEntryEntitiesCallback, async_get_current_platform

from .change import EntityChange, JsonValue
from .command import CommandAction
from .config import ConfigReader, EntityConfig
from .entity import StewartEntity
from .errors import InvalidStateError
from .identity import EntityAddress
from .runtime import StewartConfigEntry
from .session import SessionRegistry


@dataclass(frozen=True, slots=True, kw_only=True)
class TextConfig:
    entity: EntityConfig
    min: int
    max: int
    pattern: re.Pattern[str] | None
    mode: TextMode

    @classmethod
    def read_from(cls, raw: Mapping[str, JsonValue]) -> Self:
        reader = ConfigReader(Platform.TEXT, raw)
        entity = EntityConfig.read_from(reader)
        minimum = reader.read_non_negative_int("min")
        maximum = reader.read_non_negative_int("max")
        pattern = reader.read_str("pattern")
        mode = reader.read_choice("mode", TextMode)
        reader.finish()
        config = cls(
            entity=entity,
            min=0 if minimum is None else minimum,
            max=MAX_LENGTH_STATE_STATE if maximum is None else maximum,
            pattern=_compile_pattern(reader, pattern),
            mode=mode or TextMode.TEXT,
        )
        config._check_lengths(reader)
        return config

    def _check_lengths(self, reader: ConfigReader) -> None:
        if self.max > MAX_LENGTH_STATE_STATE:
            raise reader.fail(f"max must not be greater than {MAX_LENGTH_STATE_STATE}")
        if self.min > self.max:
            raise reader.fail("min must not be greater than max")


def _compile_pattern(reader: ConfigReader, pattern: str | None) -> re.Pattern[str] | None:
    if pattern is None:
        return None
    try:
        return re.compile(pattern)
    except re.error as error:
        raise reader.fail(f"pattern is not a valid regular expression: {error}") from error


class StewartText(StewartEntity[TextConfig, str | None], TextEntity):
    async def async_set_value(self, value: str) -> None:
        await self.run_command(CommandAction.SET_VALUE, {"value": value})
        self.apply_change(EntityChange(state=value))
        self.publish()

    def _show_config(self, config: TextConfig) -> None:
        super()._show_config(config)
        self._attr_native_min = config.min
        self._attr_native_max = config.max
        self._attr_pattern = config.pattern.pattern if config.pattern is not None else None
        self._attr_mode = config.mode

    def _convert_state(self, config: TextConfig, state: JsonValue) -> str | None:
        if state is None:
            return None
        if not isinstance(state, str) or not config.min <= len(state) <= config.max:
            raise InvalidStateError(
                f"A text state must be a string of {config.min} to {config.max} characters or null."
            )
        if config.pattern is not None and not config.pattern.match(state):
            raise InvalidStateError(f"A text state must match {config.pattern.pattern}.")
        return state

    def _show_state(self, native: str | None) -> None:
        self._attr_native_value = native


class TextExposure:
    domain: Final = Platform.TEXT

    def read_config(self, raw: Mapping[str, JsonValue]) -> TextConfig:
        return TextConfig.read_from(raw)

    def create_entity(
        self, *, address: EntityAddress, config: TextConfig, sessions: SessionRegistry, device_info: DeviceInfo
    ) -> StewartText:
        return StewartText(address=address, config=config, sessions=sessions, device_info=device_info)


async def async_setup_entry(
    hass: HomeAssistant,  # noqa: ARG001
    entry: StewartConfigEntry,
    async_add_entities: AddConfigEntryEntitiesCallback,  # noqa: ARG001
) -> None:
    entry.runtime_data.entities.attach_platform(TextExposure(), async_get_current_platform())

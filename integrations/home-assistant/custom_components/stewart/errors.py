from typing import ClassVar, Self

from .const import ERR_INVALID_CONFIG, ERR_INVALID_STATE, ERR_NO_SESSION, ERR_NOT_FOUND
from .identity import EntityAddress


class ExposureError(Exception):
    code: ClassVar[str]

    def __init__(self, message: str) -> None:
        super().__init__(message)
        self.message = message


class NoSessionError(ExposureError):
    code = ERR_NO_SESSION

    @classmethod
    def for_instance(cls, instance: str) -> Self:
        return cls(f"This connection holds no session for instance {instance}.")

    @classmethod
    def for_connection(cls) -> Self:
        return cls("This connection holds no session.")


class EntityNotFoundError(ExposureError):
    code = ERR_NOT_FOUND

    @classmethod
    def for_address(cls, address: EntityAddress) -> Self:
        return cls(f"Instance {address.instance} has no entity {address}.")


class CommandNotFoundError(ExposureError):
    code = ERR_NOT_FOUND

    @classmethod
    def for_command(cls, command_id: str) -> Self:
        return cls(f"Command {command_id} is unknown, already answered or timed out.")


class InvalidConfigError(ExposureError):
    code = ERR_INVALID_CONFIG


class InvalidStateError(ExposureError):
    code = ERR_INVALID_STATE

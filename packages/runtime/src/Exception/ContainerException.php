<?php

declare(strict_types=1);

namespace Stewart\Runtime\Exception;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Runtime\Model\WorkerId;
use Throwable;

/** @extends StewartException<ContainerError> */
final class ContainerException extends StewartException
{
    public static function serviceTypeMismatch(string $serviceId, string $expectedType, string $actualType): self
    {
        return self::createForReason(
            ContainerError::ServiceTypeMismatch,
            ['serviceId' => $serviceId, 'expectedType' => $expectedType, 'actualType' => $actualType],
        );
    }

    public static function buildFailed(WorkerId $workerId, Throwable $previous): self
    {
        return self::createForReason(ContainerError::BuildFailed, ['workerId' => $workerId->value], $previous);
    }

    public static function appInServicesFile(string $serviceId, string $class): self
    {
        return self::createForReason(ContainerError::AppInServicesFile, ['serviceId' => $serviceId, 'class' => $class]);
    }

    public static function peerStoreWritable(string $class, string $parameter): self
    {
        return self::createForReason(ContainerError::PeerStoreWritable, ['class' => $class, 'parameter' => $parameter]);
    }

    public static function peerStoreAppIdInvalid(string $class, string $parameter, Throwable $previous): self
    {
        return self::createForReason(ContainerError::PeerStoreAppIdInvalid, ['class' => $class, 'parameter' => $parameter], $previous);
    }

    public static function messageHandlerDuplicated(string $messageClass, string $firstHandler, string $secondHandler): self
    {
        return self::createForReason(
            ContainerError::MessageHandlerDuplicated,
            ['messageClass' => $messageClass, 'firstHandler' => $firstHandler, 'secondHandler' => $secondHandler],
        );
    }

    public static function appOptionInvalid(string $appId, string $option, string $value, string $expectedType): self
    {
        return self::createForReason(
            ContainerError::AppOptionInvalid,
            ['appId' => $appId, 'option' => $option, 'value' => $value, 'expectedType' => $expectedType],
        );
    }

    public static function appOptionUnknown(AppId $appId, string $option, string $class, ?string $suggestion): self
    {
        return self::createForReasonWithAppendedText(
            ContainerError::AppOptionUnknown,
            ['appId' => $appId->value, 'option' => $option, 'class' => $class, 'suggestion' => $suggestion],
            $suggestion === null ? null : \sprintf('Did you mean "%s"?', $suggestion),
        );
    }

    public static function appOptionMissing(AppId $appId, string $option, string $class): self
    {
        return self::createForReason(ContainerError::AppOptionMissing, ['appId' => $appId->value, 'option' => $option, 'class' => $class]);
    }

    public static function appBuildFailed(AppId $appId, Throwable $previous): self
    {
        return self::createForReason(ContainerError::AppBuildFailed, ['appId' => $appId->value], $previous);
    }

    public static function appClassMissing(AppId $appId, string $class): self
    {
        return self::createForReason(ContainerError::AppClassMissing, ['appId' => $appId->value, 'class' => $class]);
    }

    public static function servicesFileMissing(string $path): self
    {
        return self::createForReason(ContainerError::ServicesFileMissing, ['path' => $path]);
    }
}

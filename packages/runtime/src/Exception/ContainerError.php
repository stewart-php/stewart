<?php

declare(strict_types=1);

namespace Stewart\Runtime\Exception;

use Stewart\Contracts\Exception\ExceptionReason;

enum ContainerError: string implements ExceptionReason
{
    case ServiceTypeMismatch = 'service_type_mismatch';
    case BuildFailed = 'build_failed';
    case AppInServicesFile = 'app_in_services_file';
    case PeerStoreWritable = 'peer_store_writable';
    case PeerStoreAppIdInvalid = 'peer_store_app_id_invalid';
    case MessageHandlerDuplicated = 'message_handler_duplicated';
    case AppOptionInvalid = 'app_option_invalid';
    case AppOptionUnknown = 'app_option_unknown';
    case AppOptionMissing = 'app_option_missing';
    case AppBuildFailed = 'app_build_failed';
    case AppClassMissing = 'app_class_missing';
    case ServicesFileMissing = 'services_file_missing';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::ServiceTypeMismatch => 'Service "{serviceId}" is {actualType}; expected {expectedType}.',
            self::BuildFailed => 'Worker {workerId} failed to build its container: {cause}',
            self::AppInServicesFile => 'services.php defines "{serviceId}" as automation {class}. Configure it in stewart.yaml instead.',
            self::PeerStoreWritable => '{class}::__construct() takes ${parameter} with #[PeerStore] as Store; type it ReadableStore.',
            self::PeerStoreAppIdInvalid => '{class}::__construct() takes ${parameter} with #[PeerStore]: {cause}',
            self::MessageHandlerDuplicated => '{firstHandler} and {secondHandler} both handle {messageClass}.',
            self::AppOptionInvalid => 'Option "{option}" of app "{appId}" is "{value}", which is not a valid {expectedType}.',
            self::AppOptionUnknown => 'App "{appId}" sets option "{option}", which matches no parameter of {class}::__construct().',
            self::AppOptionMissing => 'App "{appId}" needs option "{option}"; {class}::__construct() takes ${option} with no default.',
            self::AppBuildFailed => 'App "{appId}" cannot be built: {cause}',
            self::AppClassMissing => 'App "{appId}" names class {class}, which cannot be loaded.',
            self::ServicesFileMissing => 'Services file {path} no longer exists.',
        };
    }
}

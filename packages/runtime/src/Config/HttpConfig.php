<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use Stewart\Runtime\Exception\ConfigurationError;
use Stewart\Runtime\Exception\ConfigurationException;

final readonly class HttpConfig
{
    public function __construct(
        public ?HttpListenAddress $listen,
        public HttpAdminConfig $admin,
        public HttpMetricsConfig $metrics = new HttpMetricsConfig(null, null),
    ) {}

    /** @throws ConfigurationException */
    public static function fromSection(ConfigSection $http): self
    {
        return new self(
            listen: $http->findSwitchableValue('listen', HttpListenAddress::parse(...)),
            admin: HttpAdminConfig::fromSection($http->readSection('admin')),
            metrics: HttpMetricsConfig::fromSection($http->readSection('metrics')),
        );
    }

    // Checked apart from loading, so commands that never open a listener still load such a config.
    /** @throws ConfigurationException */
    public function requireValidListeners(): void
    {
        if ($this->admin->listen !== null) {
            $this->admin->requireToken();
        }

        $checked = [];

        foreach (['http.listen' => $this->listen, 'http.admin.listen' => $this->admin->listen, 'http.metrics.listen' => $this->metrics->listen] as $setting => $address) {
            if ($address === null) {
                continue;
            }

            foreach ($checked as $otherSetting => $other) {
                if ($address->sharesPortWith($other)) {
                    throw ConfigurationException::createForReason(
                        ConfigurationError::HttpListenAddressShared,
                        setting: $otherSetting,
                        address: (string) $other,
                        otherSetting: $setting,
                        otherAddress: (string) $address,
                    );
                }
            }

            $checked[$setting] = $address;
        }
    }
}

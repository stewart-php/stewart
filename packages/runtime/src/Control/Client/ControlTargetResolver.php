<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Client;

use Closure;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Runtime\Config\ControlAddress;
use Stewart\Runtime\Config\ControlConfig;
use Stewart\Runtime\Exception\ControlException;

final readonly class ControlTargetResolver
{
    /**
     * @param Closure(): ControlConfig $settings
     * @throws ControlException
     */
    public function resolveTarget(?string $address, ?string $token, Closure $settings): ControlTarget
    {
        if ($address !== null && $token !== null) {
            return new ControlTarget(ControlAddress::parse($address)->toConnectableAddress(), $token);
        }

        $control = $this->loadControlConfig($settings);

        $resolvedAddress = $address === null
            ? ($control->listen ?? throw ControlException::controlDisabled())
            : ControlAddress::parse($address);

        $resolvedToken = $token
            ?? $control->token
            ?? throw ControlException::tokenMissing();

        return new ControlTarget($resolvedAddress->toConnectableAddress(), $resolvedToken);
    }

    /**
     * @param Closure(): ControlConfig $settings
     * @throws ControlException
     */
    private function loadControlConfig(Closure $settings): ControlConfig
    {
        try {
            return $settings();
        } catch (StewartException $e) {
            throw ControlException::configUnavailable($e);
        }
    }
}

<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

final readonly class CgroupCpuQuota
{
    private const string UNLIMITED = 'max';

    private function __construct(public int $cpus) {}

    public static function parseCpuMax(string $cpuMax): ?self
    {
        $fields = preg_split('/\s+/', trim($cpuMax));

        if ($fields === false || \count($fields) !== 2) {
            return null;
        }

        [$quota, $period] = $fields;

        if ($quota === self::UNLIMITED || !ctype_digit($quota) || !ctype_digit($period) || (int) $period === 0) {
            return null;
        }

        return new self(max(1, (int) ceil((int) $quota / (int) $period)));
    }
}

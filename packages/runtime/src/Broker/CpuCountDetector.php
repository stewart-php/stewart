<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;

final readonly class CpuCountDetector
{
    private const string CPU_INFO = '/proc/cpuinfo';

    private const string CGROUP_CPU_MAX = '/sys/fs/cgroup/cpu.max';

    public function __construct(private string $cgroupCpuMaxFile = self::CGROUP_CPU_MAX) {}

    public function detectCpuCount(): int
    {
        $hostCount = $this->countFromNproc() ?? $this->countFromProcCpuInfo() ?? 1;
        $quota = $this->readCgroupQuota();

        return $quota === null ? $hostCount : min($hostCount, $quota->cpus);
    }

    private function readCgroupQuota(): ?CgroupCpuQuota
    {
        $cpuMax = is_readable($this->cgroupCpuMaxFile) ? file_get_contents($this->cgroupCpuMaxFile) : false;

        return $cpuMax === false ? null : CgroupCpuQuota::parseCpuMax($cpuMax);
    }

    private function countFromNproc(): ?int
    {
        $process = new Process(['nproc']);

        try {
            $process->run();
        } catch (ProcessException) {
            return null;
        }

        $output = trim($process->getOutput());

        return $process->isSuccessful() && ctype_digit($output) ? max(1, (int) $output) : null;
    }

    private function countFromProcCpuInfo(): ?int
    {
        $cpuInfo = is_readable(self::CPU_INFO) ? file_get_contents(self::CPU_INFO) : false;

        if ($cpuInfo === false) {
            return null;
        }

        $processors = preg_match_all('/^processor\s*:/m', $cpuInfo);

        return $processors > 0 ? $processors : null;
    }
}

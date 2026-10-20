<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console\Doctor;

final readonly class ContainerChecks
{
    private const array REQUIRED_EXTENSIONS = [
        'pcntl' => 'signal handling; without it graceful shutdown is impossible',
        'json' => 'Home Assistant wire format',
        'ctype' => 'config and cpu-count parsing',
    ];

    private const string PROBE_SOCKET_PREFIX = '/stewart-doctor-';

    /** @return list<DoctorFinding> */
    public function runContainerChecks(): array
    {
        $findings = [];

        foreach (self::REQUIRED_EXTENSIONS as $extension => $purpose) {
            $findings[] = \extension_loaded($extension) ? new DoctorFinding($extension, DoctorOutcome::Pass) : new DoctorFinding($extension, DoctorOutcome::Fail, $purpose);
        }

        $findings[] = new DoctorFinding('zlib', DoctorOutcome::Pass, \extension_loaded('zlib') ? 'websocket compression on' : 'absent; websocket compression off');
        $findings[] = new DoctorFinding('xdebug', DoctorOutcome::Pass, \extension_loaded('xdebug') ? 'loaded, mode=' . (\ini_get('xdebug.mode') ?: 'off') : 'absent (fine for production)');

        $temporaryDirectory = sys_get_temp_dir();
        $findings[] = is_dir($temporaryDirectory) && is_writable($temporaryDirectory)
            ? new DoctorFinding('tmpdir', DoctorOutcome::Pass, $temporaryDirectory)
            : new DoctorFinding('tmpdir', DoctorOutcome::Fail, $temporaryDirectory . ' is not writable');
        $findings[] = $this->canBindUnixSocket($temporaryDirectory)
            ? new DoctorFinding('unix sock', DoctorOutcome::Pass, 'can bind')
            : new DoctorFinding('unix sock', DoctorOutcome::Fail, 'cannot bind; workers will not spawn');
        $findings[] = new DoctorFinding('memory', DoctorOutcome::Pass, (string) \ini_get('memory_limit'));

        return $findings;
    }

    private function canBindUnixSocket(string $directory): bool
    {
        $path = $directory . self::PROBE_SOCKET_PREFIX . getmypid() . '.sock';
        $server = @stream_socket_server('unix://' . $path);

        if ($server === false) {
            return false;
        }

        fclose($server);
        @unlink($path);

        return true;
    }
}

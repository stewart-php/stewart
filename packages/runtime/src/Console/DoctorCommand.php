<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: self::NAME, description: 'Check that this PHP can run Stewart')]
final class DoctorCommand extends Command
{
    public const string NAME = 'doctor';

    private const array REQUIRED_EXTENSIONS = [
        'pcntl' => 'signal handling; without it graceful shutdown is impossible',
        'json' => 'Home Assistant wire format',
        'ctype' => 'config and cpu-count parsing',
    ];

    private const string PROBE_SOCKET_PREFIX = '/stewart-doctor-';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Composer's platform check already refused an older PHP before this command could load.
        $output->writeln(\sprintf('PHP %s (%s)', \PHP_VERSION, \PHP_BINARY));
        $healthy = true;

        foreach (self::REQUIRED_EXTENSIONS as $extension => $purpose) {
            $healthy = $this->reportCheck($output, $extension, \extension_loaded($extension), '', $purpose) && $healthy;
        }

        $this->reportInfo($output, 'zlib', \extension_loaded('zlib') ? 'websocket compression on' : 'absent; websocket compression off');
        $this->reportInfo($output, 'xdebug', \extension_loaded('xdebug') ? 'loaded, mode=' . (\ini_get('xdebug.mode') ?: 'off') : 'absent (fine for production)');

        $temporaryDirectory = sys_get_temp_dir();
        $healthy = $this->reportCheck($output, 'tmpdir', is_dir($temporaryDirectory) && is_writable($temporaryDirectory), $temporaryDirectory, $temporaryDirectory . ' is not writable') && $healthy;
        $healthy = $this->reportCheck($output, 'unix sock', $this->canBindUnixSocket($temporaryDirectory), 'can bind', 'cannot bind; workers will not spawn') && $healthy;

        $this->reportInfo($output, 'memory', (string) \ini_get('memory_limit'));

        $output->writeln($healthy ? 'All good.' : 'Problems found.');

        return $healthy ? Command::SUCCESS : Command::FAILURE;
    }

    private function reportCheck(OutputInterface $output, string $label, bool $passed, string $passDetail, string $failDetail): bool
    {
        $this->writeLine($output, $label, $passed ? '✓' : '✗ FAIL', $passed ? $passDetail : $failDetail);

        return $passed;
    }

    private function reportInfo(OutputInterface $output, string $label, string $detail): void
    {
        $this->writeLine($output, $label, '✓', $detail);
    }

    private function writeLine(OutputInterface $output, string $label, string $mark, string $detail): void
    {
        $output->writeln(rtrim(\sprintf('  %-10s %s  %s', $label, $mark, $detail)));
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

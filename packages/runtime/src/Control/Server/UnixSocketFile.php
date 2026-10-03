<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Server;

use Psr\Log\LoggerInterface;
use Stewart\Runtime\Exception\ControlException;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

final readonly class UnixSocketFile
{
    private const int SOCKET_DIRECTORY_MODE = 0o700;

    private const float LISTENER_PROBE_TIMEOUT_SECONDS = 1.0;

    private const array LINUX_ENOENT_AND_ECONNREFUSED = [2, 111];

    public function __construct(
        private Filesystem $filesystem,
        private LoggerInterface $logger,
    ) {}

    /** @throws ControlException */
    public function prepareForListening(string $path): void
    {
        $this->createDirectoryFor($path);

        if (!file_exists($path) && !is_link($path)) {
            return;
        }

        if (filetype($path) !== 'socket') {
            throw ControlException::socketPathNotASocket($path);
        }

        if ($this->hasListener($path)) {
            throw ControlException::socketInUse('unix://' . $path);
        }

        $this->removeStaleSocket($path);
    }

    public function identifyBoundSocket(string $path): ?BoundSocketFile
    {
        clearstatcache(true, $path);
        $stat = @stat($path);

        return $stat === false ? null : new BoundSocketFile($path, $stat['dev'], $stat['ino']);
    }

    public function removeIfStillBound(BoundSocketFile $socket): void
    {
        $current = $this->identifyBoundSocket($socket->path);

        if ($current === null) {
            return;
        }

        if (!$current->isSameFileAs($socket)) {
            $this->logger->warning('Left the control socket in place because another process replaced it', ['path' => $socket->path]);

            return;
        }

        try {
            $this->filesystem->remove($socket->path);
        } catch (IOException $e) {
            $this->logger->warning('Could not remove the control socket', ['path' => $socket->path, 'exception' => $e]);
        }
    }

    /** @throws ControlException */
    private function createDirectoryFor(string $path): void
    {
        $directory = \dirname($path);

        if (is_dir($directory)) {
            return;
        }

        try {
            $this->filesystem->mkdir($directory, self::SOCKET_DIRECTORY_MODE);
        } catch (IOException $e) {
            throw ControlException::socketDirectoryNotCreatable($directory, $e);
        }
    }

    // A timeout or any unknown error counts as a listener: deleting a live daemon's socket is worse than refusing to start.
    private function hasListener(string $path): bool
    {
        $probe = @stream_socket_client('unix://' . $path, $errno, $message, self::LISTENER_PROBE_TIMEOUT_SECONDS);

        if ($probe === false) {
            return !\in_array($errno, self::LINUX_ENOENT_AND_ECONNREFUSED, true);
        }

        fclose($probe);

        return true;
    }

    /** @throws ControlException */
    private function removeStaleSocket(string $path): void
    {
        try {
            $this->filesystem->remove($path);
        } catch (IOException $e) {
            throw ControlException::socketUnremovable($path, $e);
        }
    }
}

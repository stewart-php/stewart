<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Protocol\Codec;

use JsonException;
use Stewart\Contracts\Exception\JsonShapeException;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Runtime\Control\Protocol\Frame\Bye;
use Stewart\Runtime\Control\Protocol\Frame\ClientFrame;
use Stewart\Runtime\Control\Protocol\Frame\CommandResult;
use Stewart\Runtime\Control\Protocol\Frame\Hello;
use Stewart\Runtime\Control\Protocol\Frame\PauseAppRequest;
use Stewart\Runtime\Control\Protocol\Frame\Rejected;
use Stewart\Runtime\Control\Protocol\Frame\RequestFailed;
use Stewart\Runtime\Control\Protocol\Frame\ResumeAppRequest;
use Stewart\Runtime\Control\Protocol\Frame\ServerFrame;
use Stewart\Runtime\Control\Protocol\Frame\SnapshotFrame;
use Stewart\Runtime\Control\Protocol\Frame\SnapshotRequest;
use Stewart\Runtime\Control\Protocol\Frame\Welcome;
use Stewart\Runtime\Exception\ControlException;
use Stewart\Runtime\Json\ClassShapeReader;
use Stewart\Runtime\Json\Collection\ValueConverterCollection;
use Stewart\Runtime\Json\DurationConverter;
use Stewart\Runtime\Json\IsoInstantConverter;
use Stewart\Runtime\Json\WireMapper;
use Stewart\Support\Json\JsonDecoder;
use Stewart\Support\Json\JsonEncoder;
use Stewart\Support\Json\JsonShape;
use Throwable;

final readonly class FrameCodec
{
    private const int DEPTH = 32;

    private const array CLIENT_FRAMES = [
        'hello' => Hello::class,
        'snapshot_request' => SnapshotRequest::class,
        'pause_app' => PauseAppRequest::class,
        'resume_app' => ResumeAppRequest::class,
    ];

    private const array SERVER_FRAMES = [
        'welcome' => Welcome::class,
        'snapshot' => SnapshotFrame::class,
        'rejected' => Rejected::class,
        'command_result' => CommandResult::class,
        'request_failed' => RequestFailed::class,
        'bye' => Bye::class,
    ];

    public function __construct(private WireMapper $controlWireMapper) {}

    public static function createControlWireMapper(): WireMapper
    {
        return new WireMapper(new ClassShapeReader(ValueConverterCollection::keyedByHandledClass([new IsoInstantConverter(), new DurationConverter()])));
    }

    /** @throws ControlException */
    public function encodeFrame(ClientFrame|ServerFrame $frame): string
    {
        $type = array_search($frame::class, self::CLIENT_FRAMES + self::SERVER_FRAMES, true);

        if ($type === false) {
            throw ControlException::frameClassUnknown($frame::class);
        }

        try {
            return JsonEncoder::encodeToJson(['type' => $type, ...(array) $this->controlWireMapper->normalizeObject($frame)], self::DEPTH) . "\n";
        } catch (JsonException|StewartException $e) {
            throw ControlException::frameUnencodable($frame::class, $e);
        }
    }

    /** @throws ControlException|StewartException */
    public function decodeClientFrame(string $line): ClientFrame
    {
        $frame = $this->decodeFrameOfType($line, self::CLIENT_FRAMES, 'a client frame type');
        \assert($frame instanceof ClientFrame);

        return $frame;
    }

    /** @throws ControlException|StewartException */
    public function decodeServerFrame(string $line): ServerFrame
    {
        $frame = $this->decodeFrameOfType($line, self::SERVER_FRAMES, 'a server frame type');
        \assert($frame instanceof ServerFrame);

        return $frame;
    }

    /**
     * @param array<string, class-string> $frames
     * @throws ControlException|StewartException
     */
    private function decodeFrameOfType(string $line, array $frames, string $expected): object
    {
        try {
            $data = JsonDecoder::decodeJson($line, self::DEPTH);
        } catch (JsonException $e) {
            throw ControlException::frameNotJson($e);
        }

        if (!\is_array($data)) {
            throw ControlException::frameNotAnObject(get_debug_type($data));
        }

        $type = JsonShape::requireString($data, 'type');
        $class = $frames[$type] ?? throw JsonShapeException::unexpectedValue('type', $expected, $type);

        try {
            return $this->controlWireMapper->decodeObject($class, $data);
        } catch (StewartException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ControlException::frameUndecodable($type, $e);
        }
    }
}

<?php

declare(strict_types=1);

namespace Stewart\Runtime\Http;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Psr\Log\LoggerInterface;
use Stewart\Runtime\Health\ProbeKind;
use Stewart\Runtime\Health\ProbeReportCodec;
use Stewart\Runtime\Health\ProbeReporter;
use Throwable;

final readonly class ProbeRequestHandler implements RequestHandler
{
    private const array PROBE_PATHS = ['/healthz' => ProbeKind::Liveness, '/readyz' => ProbeKind::Readiness];

    private const array REPORT_HEADERS = ['content-type' => 'application/json', 'cache-control' => 'no-store'];

    public function __construct(
        private ProbeReporter $reporter,
        private ProbeReportCodec $codec,
        private LoggerInterface $logger,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $kind = self::PROBE_PATHS[$request->getUri()->getPath()] ?? null;

        if ($kind === null) {
            return new Response(HttpStatus::NOT_FOUND);
        }

        try {
            $report = $this->reporter->reportProbe($kind);
            $body = $this->codec->encodeReport($report);
        } catch (Throwable $e) {
            $this->logger->error('Could not answer the probe', ['probe' => $kind->value, 'exception' => $e]);

            return new Response(HttpStatus::INTERNAL_SERVER_ERROR);
        }

        return new Response($report->status->isHealthy() ? HttpStatus::OK : HttpStatus::SERVICE_UNAVAILABLE, self::REPORT_HEADERS, $body);
    }
}

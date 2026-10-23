<?php

declare(strict_types=1);

namespace Stewart\Runtime\Http;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Psr\Log\LoggerInterface;
use Stewart\Runtime\Metrics\PrometheusTextEncoder;
use Stewart\Runtime\Metrics\RuntimeMetricsExporter;
use Throwable;

final readonly class MetricsRequestHandler implements RequestHandler
{
    public const array RESPONSE_HEADERS = ['content-type' => PrometheusTextEncoder::CONTENT_TYPE, 'cache-control' => 'no-store'];

    private const string METRICS_PATH = '/metrics';

    public function __construct(
        private RuntimeMetricsExporter $metrics,
        private BearerTokenCheck $tokens,
        private UnauthorizedRequestWarnings $refusals,
        private LoggerInterface $logger,
    ) {}

    public function handleRequest(Request $request): Response
    {
        if (!$this->tokens->admitsRequest($request)) {
            $this->refusals->recordRefusedRequest($request);

            return new Response(HttpStatus::UNAUTHORIZED, ['www-authenticate' => 'Bearer']);
        }

        if ($request->getUri()->getPath() !== self::METRICS_PATH) {
            return new Response(HttpStatus::NOT_FOUND);
        }

        try {
            return new Response(HttpStatus::OK, self::RESPONSE_HEADERS, $this->metrics->exportMetrics());
        } catch (Throwable $e) {
            $this->logger->error('Could not export the metrics', ['exception' => $e]);

            return new Response(HttpStatus::INTERNAL_SERVER_ERROR);
        }
    }
}

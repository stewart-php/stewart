<?php

declare(strict_types=1);

namespace Stewart\Runtime\Http\Admin;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\ExceptionReason;
use Stewart\Contracts\Exception\IdentifierError;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Runtime\Exception\AppError;
use Stewart\Runtime\Http\Admin\Response\AdminAppList;
use Stewart\Runtime\Http\Admin\Response\AdminAppView;
use Stewart\Runtime\Http\Admin\Response\AdminCommandResult;
use Stewart\Runtime\Http\Admin\Response\AdminFailure;
use Stewart\Runtime\Metrics\PrometheusTextEncoder;
use Stewart\Runtime\Metrics\RuntimeMetricsExporter;
use Throwable;

final readonly class AdminRequestHandler implements RequestHandler
{
    private const string BEARER_TOKEN = '~\ABearer +(\S+)\z~i';

    private const array JSON_HEADERS = ['content-type' => 'application/json', 'cache-control' => 'no-store'];

    private const array METRICS_HEADERS = ['content-type' => PrometheusTextEncoder::CONTENT_TYPE, 'cache-control' => 'no-store'];

    public function __construct(
        private AppsAdminApi $apps,
        private AdminApiCodec $codec,
        private RuntimeMetricsExporter $metrics,
        private LoggerInterface $logger,
        #[SensitiveParameter]
        private string $adminApiToken,
    ) {}

    public function handleRequest(Request $request): Response
    {
        if (!$this->isAuthorized($request)) {
            return $this->respondWithFailure(HttpStatus::UNAUTHORIZED, new AdminFailure('unauthorized', 'A valid bearer token is required.'), ['www-authenticate' => 'Bearer']);
        }

        $path = $request->getUri()->getPath();
        $route = AdminRoute::fromPath($path);

        if ($route === null) {
            return $this->respondWithFailure(HttpStatus::NOT_FOUND, new AdminFailure('not_found', \sprintf('No admin endpoint at %s.', $path)));
        }

        if (!$route->allowsMethod($request->getMethod())) {
            return $this->respondWithFailure(
                HttpStatus::METHOD_NOT_ALLOWED,
                new AdminFailure('method_not_allowed', \sprintf('%s does not accept %s.', $path, $request->getMethod())),
                ['allow' => implode(', ', $route->action->listAllowedMethods())],
            );
        }

        try {
            return $this->answerRoute($route);
        } catch (StewartException $e) {
            return $this->respondWithFailure($this->selectFailureStatus($e->reason), new AdminFailure((string) $e->reason->value, $e->getMessage()));
        } catch (Throwable $e) {
            $this->logger->error('Could not answer the admin request', ['path' => $path, 'exception' => $e]);

            return $this->respondWithFailure(HttpStatus::INTERNAL_SERVER_ERROR, new AdminFailure('internal_error', 'The admin request failed; the daemon log has the cause.'));
        }
    }

    /** @throws Throwable */
    private function answerRoute(AdminRoute $route): Response
    {
        return match ($route->action) {
            AdminAction::ListApps => $this->respond(HttpStatus::OK, $this->apps->listApps()),
            AdminAction::ShowApp => $this->respond(HttpStatus::OK, $this->apps->showApp(new AppId((string) $route->appId))),
            AdminAction::PauseApp => $this->respond(HttpStatus::OK, $this->apps->pauseApp(new AppId((string) $route->appId))),
            AdminAction::ResumeApp => $this->respond(HttpStatus::OK, $this->apps->resumeApp(new AppId((string) $route->appId))),
            AdminAction::ResetApp => $this->respond(HttpStatus::OK, $this->apps->resetApp(new AppId((string) $route->appId))),
            AdminAction::ShowMetrics => new Response(HttpStatus::OK, self::METRICS_HEADERS, $this->metrics->exportMetrics()),
        };
    }

    private function isAuthorized(Request $request): bool
    {
        $authorization = $request->getHeader('authorization');

        return $authorization !== null
            && preg_match(self::BEARER_TOKEN, $authorization, $matches) === 1
            && hash_equals($this->adminApiToken, $matches[1]);
    }

    private function selectFailureStatus(ExceptionReason $reason): int
    {
        return match ($reason) {
            AppError::Unknown, IdentifierError::AppIdInvalid, IdentifierError::AppIdTooLong => HttpStatus::NOT_FOUND,
            AppError::Disabled => HttpStatus::CONFLICT,
            default => HttpStatus::INTERNAL_SERVER_ERROR,
        };
    }

    /** @param array<non-empty-string, string> $headers */
    private function respondWithFailure(int $status, AdminFailure $failure, array $headers = []): Response
    {
        try {
            return $this->respond($status, $failure, $headers);
        } catch (Throwable $e) {
            $this->logger->error('Could not encode the admin failure', ['exception' => $e]);

            return new Response($status, $headers);
        }
    }

    /**
     * @param array<non-empty-string, string> $headers
     * @throws Throwable
     */
    private function respond(int $status, AdminAppList|AdminAppView|AdminCommandResult|AdminFailure $body, array $headers = []): Response
    {
        return new Response($status, [...self::JSON_HEADERS, ...$headers], $this->codec->encodeResponse($body));
    }
}

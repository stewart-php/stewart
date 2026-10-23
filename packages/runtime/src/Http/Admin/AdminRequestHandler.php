<?php

declare(strict_types=1);

namespace Stewart\Runtime\Http\Admin;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use LogicException;
use Psr\Log\LoggerInterface;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\ExceptionReason;
use Stewart\Contracts\Exception\IdentifierError;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Runtime\Admin\RuntimeAdmin;
use Stewart\Runtime\Broker\Reload\ReloadTrigger;
use Stewart\Runtime\Control\Protocol\Frame\CommandResult;
use Stewart\Runtime\Control\Protocol\Frame\RequestFailed;
use Stewart\Runtime\Exception\AppError;
use Stewart\Runtime\Exception\WorkerError;
use Stewart\Runtime\Http\Admin\Response\AdminAppList;
use Stewart\Runtime\Http\Admin\Response\AdminAppView;
use Stewart\Runtime\Http\BearerTokenCheck;
use Stewart\Runtime\Http\MetricsRequestHandler;
use Stewart\Runtime\Http\UnauthorizedRequestWarnings;
use Stewart\Runtime\Lifecycle\AppPauseSource;
use Stewart\Runtime\Metrics\RuntimeMetricsExporter;
use Throwable;

final readonly class AdminRequestHandler implements RequestHandler
{
    private const array JSON_HEADERS = ['content-type' => 'application/json', 'cache-control' => 'no-store'];

    public function __construct(
        private RuntimeAdmin $admin,
        private AdminApiCodec $codec,
        private RuntimeMetricsExporter $metrics,
        private LoggerInterface $logger,
        private BearerTokenCheck $tokens,
        private UnauthorizedRequestWarnings $refusals,
    ) {}

    public function handleRequest(Request $request): Response
    {
        if (!$this->tokens->admitsRequest($request)) {
            $this->refusals->recordRefusedRequest($request);

            return $this->respondWithFailure(HttpStatus::UNAUTHORIZED, new RequestFailed('unauthorized', 'A valid bearer token is required.'), ['www-authenticate' => 'Bearer']);
        }

        $path = $request->getUri()->getPath();
        $route = AdminRoute::fromPath($path);

        if ($route === null) {
            return $this->respondWithFailure(HttpStatus::NOT_FOUND, new RequestFailed('not_found', \sprintf('No admin endpoint at %s.', $path)));
        }

        if (!$route->allowsMethod($request->getMethod())) {
            return $this->respondWithFailure(
                HttpStatus::METHOD_NOT_ALLOWED,
                new RequestFailed('method_not_allowed', \sprintf('%s does not accept %s.', $path, $request->getMethod())),
                ['allow' => implode(', ', $route->action->listAllowedMethods())],
            );
        }

        try {
            return $route->action === AdminAction::ShowMetrics
                ? new Response(HttpStatus::OK, MetricsRequestHandler::RESPONSE_HEADERS, $this->metrics->exportMetrics())
                : $this->respond(HttpStatus::OK, $this->answerRoute($route));
        } catch (StewartException $e) {
            return $this->respondWithFailure($this->selectFailureStatus($e->reason), new RequestFailed((string) $e->reason->value, $e->getMessage()));
        } catch (Throwable $e) {
            $this->logger->error('Could not answer the admin request', ['path' => $path, 'exception' => $e]);

            return $this->respondWithFailure(HttpStatus::INTERNAL_SERVER_ERROR, new RequestFailed('internal_error', 'The admin request failed; the daemon log has the cause.'));
        }
    }

    /** @throws StewartException */
    private function answerRoute(AdminRoute $route): AdminAppList|AdminAppView|CommandResult
    {
        return match ($route->action) {
            AdminAction::ListApps => new AdminAppList(array_map(AdminAppView::fromAppStatus(...), $this->admin->listHostedAppStatuses())),
            AdminAction::ShowApp => AdminAppView::fromAppStatus($this->admin->requireAppStatus(new AppId((string) $route->resourceId))),
            AdminAction::PauseApp => $this->admin->pauseApp(new AppId((string) $route->resourceId), AppPauseSource::Http),
            AdminAction::ResumeApp => $this->admin->resumeApp(new AppId((string) $route->resourceId), AppPauseSource::Http),
            AdminAction::ResetApp => $this->admin->resetApp(new AppId((string) $route->resourceId), AppPauseSource::Http),
            AdminAction::RestartWorker => $this->admin->restartWorker((string) $route->resourceId),
            AdminAction::Reload => $this->admin->reload(ReloadTrigger::Http),
            AdminAction::ShowMetrics => throw new LogicException('Metrics are answered before the JSON routes.'),
        };
    }

    private function selectFailureStatus(ExceptionReason $reason): int
    {
        return match ($reason) {
            AppError::Unknown, IdentifierError::AppIdInvalid, IdentifierError::AppIdTooLong, WorkerError::Unknown => HttpStatus::NOT_FOUND,
            AppError::Disabled, WorkerError::ShuttingDown => HttpStatus::CONFLICT,
            default => HttpStatus::INTERNAL_SERVER_ERROR,
        };
    }

    /** @param array<non-empty-string, string> $headers */
    private function respondWithFailure(int $status, RequestFailed $failure, array $headers = []): Response
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
    private function respond(int $status, AdminAppList|AdminAppView|CommandResult|RequestFailed $body, array $headers = []): Response
    {
        return new Response($status, [...self::JSON_HEADERS, ...$headers], $this->codec->encodeResponse($body));
    }
}

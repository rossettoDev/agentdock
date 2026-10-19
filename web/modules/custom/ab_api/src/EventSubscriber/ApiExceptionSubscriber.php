<?php

declare(strict_types=1);

namespace Drupal\ab_api\EventSubscriber;

use Drupal\ab_agent\AgentAccessDeniedException;
use Drupal\ab_api\ApiException;
use Drupal\ab_api\ApiPath;
use Drupal\ab_api\ProblemDetails;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Turns an API exception into problem+json without a stack trace.
 */
final class ApiExceptionSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::EXCEPTION => ['onException', 0]];
  }

  /**
   * Replaces the HTML error page for this API.
   */
  public function onException(ExceptionEvent $event): void {
    $request = $event->getRequest();
    if (!ApiPath::matches($request)) {
      return;
    }
    $throwable = $event->getThrowable();
    [$status, $code, $detail] = $this->classify($throwable);
    $this->logger->notice('API error @status @class.', [
      '@status' => (string) $status,
      '@class' => $throwable::class,
    ]);
    $problem = new ProblemDetails(
      $status,
      $code,
      $detail,
      ProblemDetails::requestId($request),
    );
    $event->setResponse($problem->response());
    $event->stopPropagation();
  }

  /**
   * Maps an exception to a status, a code and a safe detail.
   *
   * Domain messages stay off the wire. A secret in the message cannot
   * leak, and neither can a file path or a stack frame.
   *
   * @return array{0: int, 1: string, 2: string}
   *   Status, machine code and detail.
   */
  private function classify(\Throwable $throwable): array {
    if ($throwable instanceof ApiException) {
      return [$throwable->status, $throwable->errorCode, $throwable->getMessage()];
    }
    if ($throwable instanceof ScopedResourceNotFoundException) {
      return [404, 'not_found', ProblemDetails::fixedDetail(404)];
    }
    if ($throwable instanceof ScopedOperationDeniedException) {
      return [403, 'forbidden', ProblemDetails::fixedDetail(403)];
    }
    if ($throwable instanceof AgentAccessDeniedException) {
      return [403, 'forbidden', 'The agent cannot perform that operation.'];
    }
    if ($throwable instanceof HttpExceptionInterface) {
      $status = $throwable->getStatusCode();
      return [$status, $this->httpCode($status), ProblemDetails::fixedDetail($status)];
    }
    return [500, 'internal', ProblemDetails::fixedDetail(500)];
  }

  /**
   * Returns the machine code for an HTTP status.
   */
  private function httpCode(int $status): string {
    return match ($status) {
      401 => 'invalid_credential',
      403 => 'forbidden',
      404 => 'not_found',
      409 => 'conflict',
      410 => 'gone',
      412 => 'stale_version',
      413 => 'body_too_large',
      422 => 'invalid',
      428 => 'precondition_required',
      429 => 'rate_limited',
      default => 'http_error',
    };
  }

}

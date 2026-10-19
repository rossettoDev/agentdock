<?php

declare(strict_types=1);

namespace Drupal\ab_api\EventSubscriber;

use Drupal\ab_api\ApiLimits;
use Drupal\ab_api\ApiPath;
use Drupal\ab_api\ProblemDetails;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Assigns a request id and refuses an oversized or rejected credential.
 */
final class ApiRequestSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly UuidInterface $uuid,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::REQUEST => ['onRequest', 250]];
  }

  /**
   * Prepares an API request before the controller runs.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $request = $event->getRequest();
    if (!ApiPath::matches($request)) {
      return;
    }
    $request->attributes->set('ab_api_request_id', $this->requestId($request));
    if ($this->bodyTooLarge($request)) {
      $event->setResponse($this->problem($request, 413, 'body_too_large'));
      return;
    }
    if ($this->credentialRejected($request)) {
      $event->setResponse($this->problem($request, 401, 'invalid_credential'));
    }
  }

  /**
   * Uses a client UUID when it is safe, otherwise generates one.
   */
  private function requestId(Request $request): string {
    $header = $request->headers->get('X-Request-Id');
    if (is_string($header) && ApiPath::isUuid($header)) {
      return $header;
    }
    return $this->uuid->generate();
  }

  /**
   * Rejects a body above 256 KiB before the controller reads it.
   */
  private function bodyTooLarge(Request $request): bool {
    $length = $request->headers->get('Content-Length');
    $headerTooLarge = is_string($length)
      && ctype_digit($length)
      && (int) $length > ApiLimits::BODY;
    if ($headerTooLarge) {
      return TRUE;
    }
    return strlen($request->getContent()) > ApiLimits::BODY;
  }

  /**
   * A bearer that did not authenticate an agent is a 401.
   *
   * A malformed bearer with a session still falls through to the cookie.
   * A well-formed bearer that fails does not.
   */
  private function credentialRejected(Request $request): bool {
    $header = $request->headers->get('Authorization');
    if (!is_string($header) || !str_starts_with($header, 'Bearer ')) {
      return FALSE;
    }
    $pattern = '/^Bearer ab_[0-9a-f]{32}\.[0-9a-f]{64}$/';
    $actor = $request->attributes->get('ab_agent_actor');
    if (preg_match($pattern, $header) === 1) {
      return !is_array($actor);
    }
    return $this->currentUser->isAnonymous();
  }

  /**
   * Builds a problem response for a request rejected up front.
   */
  private function problem(Request $request, int $status, string $code): JsonResponse {
    return (new ProblemDetails(
      $status,
      $code,
      ProblemDetails::fixedDetail($status),
      ProblemDetails::requestId($request),
    ))->response();
  }

}

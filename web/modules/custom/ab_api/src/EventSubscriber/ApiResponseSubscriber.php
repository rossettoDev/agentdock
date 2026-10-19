<?php

declare(strict_types=1);

namespace Drupal\ab_api\EventSubscriber;

use Drupal\ab_api\ApiPath;
use Drupal\ab_api\ProblemDetails;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Marks every API response private and echoes the request id.
 */
final class ApiResponseSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After FinishResponseSubscriber, which drops an ETag it did not set.
    return [KernelEvents::RESPONSE => ['onResponse', -10]];
  }

  /**
   * Applies the cache policy to a finished API response.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $request = $event->getRequest();
    if (!ApiPath::matches($request)) {
      return;
    }
    $response = $event->getResponse();
    $response->headers->set('Cache-Control', 'private, no-store');
    $response->headers->set('X-Request-Id', ProblemDetails::requestId($request));
    $this->etag($response);
  }

  /**
   * Quotes the task version after Drupal's cache subscriber.
   */
  private function etag(Response $response): void {
    $content = $response->getContent();
    if (!is_string($content)) {
      return;
    }
    $decoded = json_decode($content, TRUE);
    if (!is_array($decoded) || !is_array($decoded['data'] ?? NULL)) {
      return;
    }
    $version = $decoded['data']['version'] ?? NULL;
    if (!is_int($version)) {
      return;
    }
    $response->headers->set('ETag', '"' . $version . '"');
  }

}

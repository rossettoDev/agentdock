<?php

declare(strict_types=1);

namespace Drupal\ab_api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds an RFC 9457 problem response without a stack trace.
 */
final class ProblemDetails {

  public function __construct(
    public readonly int $status,
    public readonly string $code,
    public readonly string $detail,
    public readonly string $requestId,
  ) {}

  /**
   * Returns the problem document.
   *
   * @return array<string, int|string>
   *   The problem fields.
   */
  public function toArray(): array {
    return [
      'type' => '/problems/' . $this->code,
      'title' => self::title($this->status),
      'status' => $this->status,
      'detail' => $this->detail,
      'code' => $this->code,
      'request_id' => $this->requestId,
    ];
  }

  /**
   * Creates the JSON response and the private cache header.
   */
  public function response(): JsonResponse {
    $response = new JsonResponse($this->toArray(), $this->status);
    $response->headers->set('Content-Type', 'application/problem+json');
    $response->headers->set('Cache-Control', 'private, no-store');
    $response->headers->set('X-Request-Id', $this->requestId);
    return $response;
  }

  /**
   * Reads the request id stored for this API call.
   */
  public static function requestId(Request $request): string {
    $stored = $request->attributes->get('ab_api_request_id');
    return is_string($stored) && $stored !== '' ? $stored : 'unknown';
  }

  /**
   * Returns a stable title for a status code.
   */
  public static function title(int $status): string {
    return match ($status) {
      401 => 'Unauthorized',
      403 => 'Forbidden',
      404 => 'Not Found',
      409 => 'Conflict',
      410 => 'Gone',
      412 => 'Precondition Failed',
      413 => 'Payload Too Large',
      422 => 'Unprocessable Content',
      428 => 'Precondition Required',
      429 => 'Too Many Requests',
      500 => 'Internal Server Error',
      default => 'Error',
    };
  }

  /**
   * Returns the detail used when the exception text must stay hidden.
   */
  public static function fixedDetail(int $status): string {
    return match ($status) {
      401 => 'The credential is invalid.',
      403 => 'The operation is not allowed.',
      404 => 'The resource was not found.',
      409 => 'The request conflicts with the current state.',
      410 => 'The cursor expired. A new snapshot is required.',
      412 => 'The version is stale.',
      413 => 'The request body exceeds 256 KiB.',
      422 => 'The request is invalid.',
      428 => 'A required precondition is missing.',
      429 => 'The rate limit was exceeded.',
      default => 'The request could not be completed.',
    };
  }

}

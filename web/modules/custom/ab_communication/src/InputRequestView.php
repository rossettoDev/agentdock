<?php

declare(strict_types=1);

namespace Drupal\ab_communication;

/**
 * One request as the task screen needs to render it.
 */
final class InputRequestView {

  public function __construct(
    public readonly string $uuid,
    public readonly string $status,
    public readonly string $question,
    public readonly ?string $answer,
    public readonly ?string $authorType,
    public readonly ?string $authorId,
  ) {}

}

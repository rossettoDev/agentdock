<?php

declare(strict_types=1);

namespace Drupal\ab_communication;

/**
 * One page of messages and the cursor of the next older page.
 */
final class MessagePage {

  public function __construct(
    public readonly array $messages,
    public readonly ?string $cursor,
  ) {}

}

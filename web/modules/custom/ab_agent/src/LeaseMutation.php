<?php

declare(strict_types=1);

namespace Drupal\ab_agent;

/**
 * What a lease mutation wrote inside the open transaction.
 */
final class LeaseMutation {

  public const RENEWED = 'renewed';

  public const EXPIRED = 'expired';

  public const FAILED = 'failed';

  public const RELEASED = 'released';

  public const READY = 'ready';

  public const PROGRESSED = 'progressed';

  public const SUBMITTED = 'submitted';

  public function __construct(
    public readonly string $kind,
    public readonly ?RenewedLease $renewed = NULL,
    public readonly int $workspaceId = 0,
    public readonly int $taskId = 0,
    public readonly int $executionId = 0,
  ) {}

}

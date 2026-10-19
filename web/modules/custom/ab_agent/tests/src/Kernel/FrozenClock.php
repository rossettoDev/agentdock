<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_agent\Kernel;

use Drupal\Component\Datetime\TimeInterface;

/**
 * A clock tests can move without sleeping.
 */
final class FrozenClock implements TimeInterface {

  public function __construct(
    private int $now,
  ) {}

  /**
   * Moves the clock forward.
   */
  public function advance(int $seconds): void {
    $this->now += $seconds;
  }

  /**
   * {@inheritdoc}
   */
  public function getRequestTime() {
    return $this->now;
  }

  /**
   * {@inheritdoc}
   */
  public function getRequestMicroTime() {
    return (float) $this->now;
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentTime() {
    return $this->now;
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentMicroTime() {
    return (float) $this->now;
  }

}

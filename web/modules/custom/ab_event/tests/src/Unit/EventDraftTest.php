<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_event\Unit;

use Drupal\ab_event\EventDraft;
use PHPUnit\Framework\TestCase;

/**
 * Validates the event draft contract.
 *
 * @group ab_event
 */
class EventDraftTest extends TestCase {

  /**
   * A valid draft can be constructed.
   */
  public function testValidDraft(): void {
    $draft = new EventDraft(
      '22222222-2222-4222-8222-222222222222',
      'task.created',
      '33333333-3333-4333-8333-333333333333',
      'human',
      '42',
      ['title' => 'Ship'],
    );
    $this->assertSame(['sync'], $draft->destinations);
  }

  /**
   * Secret keys are rejected anywhere in the payload.
   */
  public function testSensitivePayloadKeyIsRejected(): void {
    $this->expectException(\InvalidArgumentException::class);
    new EventDraft(
      '22222222-2222-4222-8222-222222222222',
      'task.created',
      '33333333-3333-4333-8333-333333333333',
      'agent',
      '44444444-4444-4444-8444-444444444444',
      ['meta' => ['lease_token' => 'hidden']],
    );
  }

  /**
   * Uppercase UUIDs are rejected.
   */
  public function testUppercaseUuidIsRejected(): void {
    $this->expectException(\InvalidArgumentException::class);
    new EventDraft(
      '22222222-2222-4222-8222-222222222222',
      'task.created',
      '33333333-3333-4333-8333-33333333333A',
      'human',
      '1',
    );
  }

}

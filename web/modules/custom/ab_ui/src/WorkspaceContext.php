<?php

declare(strict_types=1);

namespace Drupal\ab_ui;

use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\WorkspaceRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Stores the workspace chosen by the current user.
 *
 * The value lives in the session. A workspace id in the URL does not read or
 * write this selection.
 */
final class WorkspaceContext {

  /**
   * Session key for the selected workspace UUID.
   */
  private const SESSION_KEY = 'ab_workspace';

  public function __construct(
    private readonly RequestStack $requestStack,
    private readonly WorkspaceRepository $workspaces,
  ) {}

  /**
   * Returns the selected workspace when the current user can still read it.
   */
  public function workspace(): ?Workspace {
    $session = $this->session();
    $uuid = $session?->get(self::SESSION_KEY);
    if (!is_string($uuid) || $uuid === '') {
      return NULL;
    }
    try {
      $workspace = $this->workspaces->loadAccessibleByUuid($uuid);
    }
    catch (\InvalidArgumentException) {
      $session->remove(self::SESSION_KEY);
      return NULL;
    }
    if (!$workspace instanceof Workspace) {
      $session->remove(self::SESSION_KEY);
      return NULL;
    }
    return $workspace;
  }

  /**
   * Selects a workspace the current user can read.
   *
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   *   The UUID is missing, malformed, or outside the caller's scope.
   * @throws \RuntimeException
   *   The request has no session.
   */
  public function select(string $uuid): Workspace {
    $session = $this->session();
    if (!$session instanceof SessionInterface) {
      throw new \RuntimeException('A session is required to select a workspace.');
    }
    try {
      $workspace = $this->workspaces->loadAccessibleByUuid($uuid);
    }
    catch (\InvalidArgumentException) {
      $workspace = NULL;
    }
    if (!$workspace instanceof Workspace) {
      throw new ScopedResourceNotFoundException('Workspace was not found.');
    }
    $session->set(self::SESSION_KEY, $workspace->uuid());
    return $workspace;
  }

  /**
   * Returns the request session when one is available.
   */
  private function session(): ?SessionInterface {
    $request = $this->requestStack->getCurrentRequest();
    if ($request === NULL || !$request->hasSession()) {
      return NULL;
    }
    return $request->getSession();
  }

}

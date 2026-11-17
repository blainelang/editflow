<?php

namespace Drupal\editflow_test;

use Drupal\Core\State\StateInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Counts lock releases during isolated editorial save tests.
 */
final class ReleaseCounter implements EventSubscriberInterface {

  public function __construct(private StateInterface $state) {}

  /**
   * Handles get subscribed events.
   */
  public static function getSubscribedEvents(): array {
    // String name keeps the fixture loadable when Content Lock is absent.
    return ['Drupal\content_lock\Event\ContentLockReleaseEvent' => 'released'];
  }

  /**
   * Handles released.
   */
  public function released(): void {
    $this->state->set('editflow_test.releases', $this->state->get('editflow_test.releases', 0) + 1);
  }

}

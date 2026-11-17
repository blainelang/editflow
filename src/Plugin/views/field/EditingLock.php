<?php

namespace Drupal\editflow\Plugin\views\field;

use Drupal\node\NodeInterface;
use Drupal\views\ResultRow;
use Drupal\views\Attribute\ViewsField;

/**
 * Shows live Content Lock status without acquiring or releasing a lock.
 *
 * @ViewsField("editflow_editing_lock")
 */
#[ViewsField('editflow_editing_lock')]
class EditingLock extends EditorialFieldBase {

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    // Read lock status for the revision entity already loaded by Views.
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    // Lock expiry and release need not invalidate the node's cache tags.
    return 0;
  }

  /**
   * Builds access-aware field output and cache metadata.
   *
   * @return array
   *   A render array, supported by the Views field rendering pipeline.
   */
  public function render(ResultRow $values): array {
    $node = $this->getEntity($values);
    $label = $this->t('Not locked');
    if (!$node instanceof NodeInterface || $this->lockService === NULL) {
      $label = $this->t('Unavailable');
    }
    elseif (!$node->access('update')) {
      $label = '—';
    }
    elseif ($lock = $this->lockService->fetchLock($node)) {
      // fetchLock() respects Content Lock's configured timeout.
      $label = $this->t('Locked by @name', [
        '@name' => $lock->name ?: $this->t('Unknown user'),
      ]);
    }
    return ['#plain_text' => $label, '#cache' => ['max-age' => 0]];
  }

}

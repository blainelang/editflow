<?php

namespace Drupal\editflow\ContentLock;

use Drupal\content_lock\ContentLock\ContentLock;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\editflow\EditorialSettings;
use Drupal\node\NodeInterface;

/**
 * Keeps lock notices concise while preserving Content Lock behavior.
 */
class EditorialContentLock extends ContentLock {

  /**
   * {@inheritdoc}
   */
  public function locking(EntityInterface $entity, string $form_op, int $uid, bool $quiet = FALSE, ?string $destination = NULL, ?array &$messages = NULL): bool {
    $generated = [];
    $locked = parent::locking($entity, $form_op, $uid, $quiet, $destination, $generated);
    if ($locked && $entity instanceof NodeInterface && EditorialSettings::applies($entity) && !empty($generated[MessengerInterface::TYPE_STATUS])) {
      $generated[MessengerInterface::TYPE_STATUS] = [
        $this->isTranslationLockEnabled($entity->getEntityTypeId())
          ? $this->t('Translation locked for your editing. Save and finish editing, or unlock, to release it.')
          : $this->t('Locked for your editing. Save and finish editing, or unlock, to release it.'),
      ];
    }
    // Preserve caller messages, warnings, quiet mode and lock acquisition results.
    foreach ($generated as $type => $items) {
      foreach ($items as $message) {
        $messages[$type][] = $message;
      }
    }
    return $locked;
  }

  /**
   * {@inheritdoc}
   */
  public function displayLockOwner(object $lock, bool $translation_lock): string|TranslatableMarkup {
    $message = parent::displayLockOwner($lock, $translation_lock);
    $timeout = (int) $this->config()->get('timeout');
    $policy = $timeout > 0
      ? $this->t('Expires @timeout after the last lock refresh, or sooner if the editor finishes or unlocks.', [
        '@timeout' => $this->dateFormatter->formatInterval($timeout),
      ])
      : $this->t('No automatic expiry. The editor or an authorized administrator must release the lock.');
    return $this->t('@message @policy', ['@message' => $message, '@policy' => $policy]);
  }

}

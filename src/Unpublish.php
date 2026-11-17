<?php

namespace Drupal\editflow;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\editflow\EditorialSettings;

/**
 * Takes the live revision offline while retaining any pending draft.
 */
final class Unpublish {

  public static function access(NodeInterface $node, AccountInterface $account) {
    $allowed = FALSE;
    if (\Drupal::config('editflow.settings')->get('unpublish') && $node->isPublished() && EditorialSettings::applies($node) && $node->access('update', $account)) {
      foreach (\Drupal::service('content_moderation.state_transition_validation')->getValidTransitions($node, $account) as $transition) {
        $target = $transition->to();
        if ($target->id() === EditorialSettings::state($node, 'unpublish_state') && !$target->isPublishedState() && $target->isDefaultRevisionState()) {
          $allowed = TRUE;
        }
      }
    }
    return AccessResult::allowedIf($allowed)->addCacheableDependency($node)->addCacheContexts(['user.permissions'])->setCacheMaxAge(0);
  }

  public static function snapshot(NodeInterface $node): array {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache([$node->id()]);
    $live = $storage->load($node->id());
    return [(string) $live->getRevisionId(), (string) $storage->getLatestRevisionId($node->id())];
  }

  public static function execute(NodeInterface $node, AccountInterface $account, array $expected): void {
    $lock = \Drupal::lock();
    $key = 'editflow_unpublish:' . $node->id();
    if (!$lock->acquire($key)) {
      throw new \DomainException('This content is being updated. Please reload and try again.');
    }
    $transaction = \Drupal::database()->startTransaction();
    try {
      if (self::snapshot($node) !== $expected) {
        throw new \DomainException('A newer revision exists. Review it before unpublishing.');
      }
      $storage = \Drupal::entityTypeManager()->getStorage('node');
      $live = $storage->load($node->id());
      if (!self::access($live, $account)->isAllowed()) {
        throw new \DomainException('You no longer have permission to unpublish this content, or it is already unpublished.');
      }
      if (\Drupal::hasService('content_lock')) {
        $editing_lock = \Drupal::service('content_lock')->fetchLock($live);
        if ($editing_lock && (string) $editing_lock->uid !== (string) $account->id()) {
          throw new \DomainException('Another editor holds the editing lock. Ask them to finish before unpublishing.');
        }
      }
      $draft = $expected[0] !== $expected[1] ? $storage->loadRevision($expected[1]) : NULL;
      // Record the formerly live version being taken offline first.
      $live->setNewRevision(TRUE);
      foreach ($live->getTranslationLanguages() as $langcode => $language) {
        $live->getTranslation($langcode)->set('moderation_state', EditorialSettings::state($live, 'unpublish_state'))->setUnpublished();
      }
      $live->setRevisionUserId($account->id());
      $live->setRevisionCreationTime(\Drupal::time()->getRequestTime());
      $live->setRevisionLogMessage('Content unpublished.');
      $live->save();
      if ($draft) {
        // Keep pending work as the latest revision. Core makes a draft the
        // default when no published default remains; it stays unpublished.
        // The original draft and its author/date remain in history.
        $draft->setNewRevision(TRUE);
        $draft->isDefaultRevision(FALSE);
        foreach ($draft->getTranslationLanguages() as $langcode => $language) {
          $draft->getTranslation($langcode)->set('moderation_state', EditorialSettings::state($draft, 'draft_state'))->setUnpublished();
        }
        $draft->setRevisionUserId($account->id());
        $draft->setRevisionCreationTime(\Drupal::time()->getRequestTime());
        $draft->setRevisionLogMessage('Pending draft preserved after unpublishing.');
        $draft->save();
      }
      if (\Drupal::entityTypeManager()->hasDefinition('preview_link')) {
        $links = \Drupal::entityTypeManager()->getStorage('preview_link');
        $ids = $links->getQuery()->accessCheck(FALSE)->condition('entities.target_type', 'node')->condition('entities.target_id', $node->id())->execute();
        foreach ($links->loadMultiple($ids) as $link) {
          $remaining = array_filter($link->getEntities(), static fn ($entity) => $entity->getEntityTypeId() !== 'node' || (string) $entity->id() !== (string) $node->id());
          if ($remaining) {
            $link->setEntities(array_values($remaining))->save();
          }
          else {
            $link->delete();
          }
        }
      }
    }
    catch (\Throwable $error) {
      $transaction->rollBack();
      throw $error;
    }
    finally {
      unset($transaction);
      $lock->release($key);
    }
  }

}

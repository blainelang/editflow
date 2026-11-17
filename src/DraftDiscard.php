<?php

namespace Drupal\editflow;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TempStore\SharedTempStoreFactory;
use Drupal\node\NodeInterface;

/**
 * Plans and executes explicit deletion of unpublished revisions of one node.
 */
final class DraftDiscard {

  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
    private Connection $database,
    private SharedTempStoreFactory $tempStore,
    private $contentLock = NULL,
    private $sectionManager = NULL,
    private $layoutTempstore = NULL,
  ) {}

  /**
   * Builds a fresh snapshot rather than trusting the routed revision.
   */
  public function plan(NodeInterface $node, AccountInterface $account): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $storage->resetCache([$node->id()]);
    $live = $storage->load($node->id());
    if (!$live instanceof NodeInterface || !$live->isPublished() || !EditorialSettings::applies($live)
      || !$live->access('update', $account) || !$live->access('view', $account)) {
      throw new \DomainException('A published page in an enabled editorial workflow and edit access are required.');
    }
    if ($this->contentLock && ($this->contentLock->isTranslationLockEnabled('node') || $this->contentLock->isFormOperationLockEnabled('node'))) {
      throw new \DomainException('Discard is not available with translation-specific or operation-specific locks.');
    }
    $lock = $this->contentLock ? $this->contentLock->fetchLock($live, NULL, TRUE) : FALSE;
    if ($lock && (string) $lock->uid !== (string) $account->id() && !$account->hasPermission('break content lock')) {
      throw new \DomainException('Permission to release the other editor’s lock is required.');
    }
    $ids = $storage->getQuery()->allRevisions()->accessCheck(FALSE)->condition('nid', $live->id())->sort('vid')->execute();
    $drafts = [];
    $hashes = [];
    foreach (array_keys($ids) as $vid) {
      $revision = $storage->loadRevision($vid);
      if (!$revision instanceof NodeInterface || (count($revision->getTranslationLanguages()) !== 1 || $revision->language()->getId() !== $live->language()->getId())) {
        throw new \DomainException('Discard is only available for single-language revision histories.');
      }
      $hashes[$vid] = hash('sha256', serialize($revision->toArray()));
      if ((string) $vid !== (string) $live->getRevisionId() && !$revision->isPublished()) {
        if (!$revision->access('delete revision', $account) || !$revision->access('view revision', $account)) {
          throw new \DomainException('Permission to view and delete every unpublished revision is required.');
        }
        $drafts[$vid] = $revision;
      }
    }
    if (!$drafts) {
      throw new \DomainException('There are no saved unpublished revisions to discard.');
    }
    $sections = $this->sections($live);
    $temporary = [];
    foreach ($sections as $section) {
      $temporary[$section->getTempstoreKey()] = $this->tempStore->get('layout_builder.section_storage.overrides')->get($section->getTempstoreKey());
    }
    return [
      'node' => $live,
      'drafts' => $drafts,
      'lock' => $lock,
      'sections' => $sections,
      'fingerprint' => hash('sha256', serialize([$live->getRevisionId(), $hashes, $lock, $temporary])),
    ];
  }

  /**
   * Checks route access without caching volatile revision or lock decisions.
   */
  public function access(NodeInterface $node, AccountInterface $account) {
    try {
      $this->plan($node, $account);
      return AccessResult::allowed()->setCacheMaxAge(0);
    }
    catch (\DomainException $exception) {
      return AccessResult::forbidden($exception->getMessage())->setCacheMaxAge(0);
    }
  }

  /**
   * Gets full-page override keys, including the fallback display mode.
   */
  private function sections(NodeInterface $node): array {
    if (!$this->sectionManager || !$node->hasField('layout_builder__layout')) {
      return [];
    }
    $sections = [];
    foreach (['full', 'default'] as $mode) {
      $section = $this->sectionManager->load('overrides', [
        'entity' => EntityContext::fromEntity($node),
        'view_mode' => new Context(new ContextDefinition('string'), $mode),
      ]);
      if (!$section) {
        throw new \DomainException('The pending layout could not be checked.');
      }
      $sections[] = $section;
    }
    return $sections;
  }

  /**
   * Deletes selected draft IDs from the confirmed snapshot using core hooks.
   *
   * Only selection of every draft clears pending layouts and releases the lock.
   * Empty or invalid selections fail without changing any data.
   */
  public function discard(NodeInterface $node, AccountInterface $account, string $fingerprint, array $selected): int {
    $transaction = $this->database->startTransaction();
    try {
      // Serialize node/revision writes during the final recheck and deletion.
      foreach (['node' => 'nid', 'node_revision' => 'vid'] as $table => $key) {
        $query = $this->database->select($table, 'n')->fields('n', [$key])->condition('nid', $node->id());
        $query->forUpdate();
        $query->execute()->fetchCol();
      }
      if ($this->contentLock) {
        $query = $this->database->select('content_lock', 'l')->fields('l')->condition('entity_type', 'node')->condition('entity_id', $node->id());
        $query->forUpdate();
        $query->execute()->fetchAll();
      }
      // Match SharedTempStoreFactory's standard SQL collection. These row locks
      // protect layouts during the final snapshot check and deletion.
      $temporary_sections = $this->database->schema()->tableExists('key_value_expire') ? $this->sections($node) : [];
      foreach ($temporary_sections as $section) {
        $query = $this->database->select('key_value_expire', 'k')->fields('k', ['name'])
          ->condition('collection', 'tempstore.shared.layout_builder.section_storage.overrides')
          ->condition('name', $section->getTempstoreKey());
        $query->forUpdate();
        $query->execute()->fetchCol();
      }
      $plan = $this->plan($node, $account);
      if (!hash_equals($fingerprint, $plan['fingerprint'])) {
        throw new \DomainException('The revisions, lock, or pending layout changed. Reopen the confirmation page and review the new list.');
      }
      $selected = array_unique(array_map('strval', $selected));
      if (!$selected || array_diff($selected, array_keys($plan['drafts']))) {
        throw new \DomainException('Select one or more unpublished revisions from the reviewed list.');
      }
      $all = count($selected) === count($plan['drafts']);
      $this->preserveModerationHistory($node, $selected);
      $storage = $this->entityTypeManager->getStorage('node');
      // Preserve newest-first deletion regardless of checkbox submission order.
      foreach (array_reverse(array_keys($plan['drafts'])) as $vid) {
        if (!in_array((string) $vid, $selected, TRUE)) {
          continue;
        }
        $storage->deleteRevision($vid);
      }
      foreach ($all ? $plan['sections'] : [] as $section) {
        $this->layoutTempstore->delete($section);
      }
      if ($all && $this->contentLock) {
        $this->contentLock->release($plan['node']);
      }
      $storage->resetCache([$node->id()]);
      return count($selected);
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      $this->entityTypeManager->getStorage('node')->resetCache([$node->id()]);
      throw $exception;
    }
  }

  /**
   * Keeps core revision deletion from removing surviving moderation history.
   */
  private function preserveModerationHistory(NodeInterface $node, array $selected): void {
    // When moderation was enabled after publication, the first draft may own
    // the default moderation-state revision. Core deletes the entire state
    // entity (including other revisions) when that default revision is deleted.
    // Repoint only its default to a surviving state before invoking core hooks.
    $storage = $this->entityTypeManager->getStorage('content_moderation_state');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('content_entity_type_id', 'node')
      ->condition('content_entity_id', $node->id())
      ->allRevisions()->sort('revision_id', 'DESC')->execute();
    $survivors = [];
    foreach (array_keys($ids) as $id) {
      /** @var \Drupal\Core\Entity\ContentEntityInterface $state */
      $state = $storage->loadRevision($id);
      if (!in_array((string) $state->get('content_entity_revision_id')->value, $selected, TRUE)) {
        $survivors[$state->id()] ??= $state;
      }
    }
    foreach ($survivors as $id => $survivor) {
      $current = $storage->load($id);
      if (in_array((string) $current->get('content_entity_revision_id')->value, $selected, TRUE)) {
        $survivor->setNewRevision(FALSE);
        $survivor->isDefaultRevision(TRUE);
        // Intentionally use storage: ContentModerationState::save() resaves the
        // node. This adapter changes only the metadata default pointer, not the
        // state values, content, published revision, or revision identifiers.
        $storage->save($survivor);
        $storage->resetCache([$id]);
      }
    }
  }

}

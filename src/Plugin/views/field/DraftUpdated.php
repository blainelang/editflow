<?php

namespace Drupal\editflow\Plugin\views\field;

use Drupal\views\Plugin\views\query\Sql;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\node\NodeInterface;
use Drupal\Core\Url;
use Drupal\views\ResultRow;
use Drupal\views\Attribute\ViewsField;

/**
 * Shows and sorts the latest pending revision's save time.
 *
 * @ViewsField("editflow_draft_updated")
 */
#[ViewsField('editflow_draft_updated')]
class DraftUpdated extends EditorialFieldBase {

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    if (!$this->query instanceof Sql) {
      return;
    }
    $this->ensureMyTable();
    $table = $this->tableAlias;
    // A scalar expression avoids duplicate rows and permits sorting before
    // pagination. Match core's latest translation-affecting revision selection.
    $expression = "(SELECT r.revision_timestamp FROM {node_revision} r
      WHERE r.nid = $table.nid AND r.vid <> $table.vid
      AND r.revision_default = 0
      AND r.vid = (SELECT MAX(fr.vid) FROM {node_field_revision} fr
        WHERE fr.nid = $table.nid AND fr.langcode = $table.langcode
        AND fr.revision_translation_affected = 1))";
    $this->field_alias = $this->query->addField(NULL, $expression, 'editflow_draft_updated');
  }

  /**
   * Builds access-aware field output and cache metadata.
   *
   * @return array
   *   A render array, supported by the Views field rendering pipeline.
   */
  public function render(ResultRow $values): array {
    $build = ['#plain_text' => '—'];
    $node = $this->getEntity($values);
    if (!$node instanceof NodeInterface) {
      return $build;
    }
    $cache = CacheableMetadata::createFromObject($node);
    $cache->addCacheTags(['node_list'])->addCacheContexts(['timezone']);
    // Draft metadata is only exposed to users allowed to preview it.
    $access = Url::fromRoute('entity.node.latest_version', ['node' => $node->id()])->access(NULL, TRUE);
    $cache->addCacheableDependency($access);
    if (!$access->isAllowed()) {
      $cache->applyTo($build);
      return $build;
    }
    $info = $this->moderationInformation;
    if ($info->isModeratedEntity($node)) {
      $cache->addCacheableDependency($info->getWorkflowForEntity($node));
      if ($this->getValue($values) && $info->hasPendingRevision($node)) {
        $storage = $this->entityTypeManager->getStorage('node');
        $vid = $storage->getLatestTranslationAffectedRevisionId($node->id(), $node->language()->getId());
        $revision = $storage->loadRevision($vid);
        if ($revision instanceof NodeInterface) {
          $cache->addCacheableDependency($revision);
          $author = $revision->getRevisionUser();
          if ($author) {
            $cache->addCacheableDependency($author);
          }
          $timestamp = $revision->getRevisionCreationTime();
          $build = [
            'date' => [
              '#type' => 'html_tag',
              '#tag' => 'time',
              '#attributes' => ['datetime' => gmdate('c', $timestamp)],
              '#value' => $this->dateFormatter->format($timestamp, 'custom', 'm/d/Y - H:i'),
            ],
            'author' => [
              '#type' => 'html_tag',
              '#tag' => 'div',
              '#value' => $this->t('by @name', ['@name' => $author ? $author->getDisplayName() : $this->t('Unknown user')]),
            ],
          ];
        }
      }
    }
    $cache->applyTo($build);
    return $build;
  }

}

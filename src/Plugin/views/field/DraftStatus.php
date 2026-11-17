<?php

namespace Drupal\editflow\Plugin\views\field;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\views\ResultRow;
use Drupal\views\Attribute\ViewsField;

/**
 * Indicates pending saved work without changing the live content listing.
 *
 * @ViewsField("editflow_draft_status")
 */
#[ViewsField('editflow_draft_status')]
class DraftStatus extends EditorialFieldBase {

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    // The view already loads the node entities; no revision join is needed.
  }

  /**
   * Builds access-aware field output and cache metadata.
   *
   * @return array
   *   A render array, supported by the Views field rendering pipeline.
   */
  public function render(ResultRow $values): array {
    $node = $this->getEntity($values);
    $build = ['#plain_text' => '—'];
    if (!$node instanceof NodeInterface) {
      return $build;
    }
    $cache = CacheableMetadata::createFromObject($node);
    $cache->addCacheTags(['node_list'])->addCacheContexts(['user.permissions', 'user']);
    // Draft metadata is only exposed to users allowed to preview it.
    $access = Url::fromRoute('entity.node.latest_version', ['node' => $node->id()])->access(NULL, TRUE);
    $cache->addCacheableDependency($access);
    if ($this->moderationInformation->hasPendingRevision($node) && !$access->isAllowed()) {
      $cache->applyTo($build);
      return $build;
    }
    $info = $this->moderationInformation;
    if ($info->isModeratedEntity($node)) {
      $cache->addCacheableDependency($info->getWorkflowForEntity($node));
      $pending = $info->hasPendingRevision($node);
      if ($pending || !$node->isPublished()) {
        $label = $pending ? $this->t('Unpublished changes') : $this->t('Unpublished');
        $build = ['#plain_text' => $label];
        if ($pending) {
          $url = Url::fromRoute('entity.node.latest_version', ['node' => $node->id()]);
          $access = $url->access(NULL, TRUE);
          $cache->addCacheableDependency($access);
          if ($access->isAllowed()) {
            $build = [
              '#type' => 'link',
              '#title' => $label,
              '#url' => $url,
              '#attributes' => ['title' => $this->t('Preview the latest saved version')],
            ];
          }
        }
      }
      else {
        $build = ['#plain_text' => $this->t('No pending draft')];
      }
    }
    $cache->applyTo($build);
    return $build;
  }

}

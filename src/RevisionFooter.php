<?php

namespace Drupal\editflow;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\node\NodeInterface;

/**
 * Identifies the exact revision being viewed without changing publication state.
 */
final class RevisionFooter {

  public static function build(NodeInterface $revision, NodeInterface $current): array {
    $access = $revision->access('view revision', NULL, TRUE);
    $cache = (new CacheableMetadata())
      ->addCacheContexts(['route', 'timezone', 'languages:language_content'])
      ->addCacheableDependency($revision)
      ->addCacheableDependency($current)
      ->addCacheableDependency($access);
    $build = ['#access' => $access->isAllowed()];
    if (!$access->isAllowed()) {
      $cache->applyTo($build);
      return $build;
    }

    $langcode = $revision->language()->getId();
    $has_translation = $current->hasTranslation($langcode);
    if ($has_translation) {
      $current = $current->getTranslation($langcode);
    }
    $is_live = $has_translation && $current->isPublished()
      && (string) $revision->getRevisionId() === (string) $current->getRevisionId();
    $label = $is_live ? t('Live revision') : ($revision->isPublished() ? t('Historical revision') : t('Unpublished draft'));
    $notice = $is_live ? t('This is the currently published version.') : t('This is not the live page.');
    $author = $revision->getRevisionUser();
    if ($author) {
      $cache->addCacheableDependency($author);
    }
    $date = \Drupal::service('date.formatter')->format($revision->getRevisionCreationTime(), 'custom', 'M j, Y · g:i a T');
    $build += [
      '#type' => 'container',
      '#attributes' => ['class' => ['editflow-revision-footer-space']],
      '#attached' => ['library' => ['editflow/revision_footer']],
      'notice' => [
        '#type' => 'html_tag',
        '#tag' => 'aside',
        '#attributes' => ['class' => ['editflow-revision-footer'], 'aria-label' => t('Revision information')],
        'info' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['editflow-revision-footer__info']],
          'status' => ['#type' => 'html_tag', '#tag' => 'strong', '#value' => $label],
          'notice' => ['#type' => 'html_tag', '#tag' => 'span', '#value' => $notice],
          'saved' => [
            '#type' => 'html_tag', '#tag' => 'small',
            '#value' => t('Saved by @author · @date', [
              '@author' => $author ? $author->getDisplayName() : t('Unknown author'),
              '@date' => $date,
            ]),
          ],
        ],
      ],
    ];
    $live_access = $current->access('view', NULL, TRUE);
    $cache->addCacheableDependency($live_access);
    if ($has_translation && $current->isPublished() && $live_access->isAllowed()) {
      $build['notice']['live'] = [
        '#type' => 'link',
        '#title' => t('View live page'),
        '#url' => $current->toUrl('canonical', ['language' => $current->language()]),
        '#attributes' => ['class' => ['editflow-revision-footer__live']],
      ];
    }
    elseif (!$current->isPublished()) {
      $build['notice']['info']['notice']['#value'] = t('This page has no published version.');
    }
    $cache->applyTo($build);
    return $build;
  }

}

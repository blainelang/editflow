<?php

namespace Drupal\editflow\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\editflow\Form\LatestPreviewForm;
use Drupal\node\NodeInterface;

/**
 * Provides publication review when core's pending-revision route is unavailable.
 */
final class PublishPreviewController extends ControllerBase {

  private function latest(NodeInterface $node): NodeInterface {
    $storage = $this->entityTypeManager()->getStorage('node');
    $latest = $storage->loadRevision($storage->getLatestRevisionId($node->id()));
    $langcode = $node->language()->getId();
    return $latest->hasTranslation($langcode) ? $latest->getTranslation($langcode) : $latest;
  }

  public function access(NodeInterface $node, AccountInterface $account) {
    return AccessResult::allowedIf(!$node->isPublished() && LatestPreviewForm::canPublish($this->latest($node), $account))->setCacheMaxAge(0);
  }

  public function build(NodeInterface $node): array {
    $latest = $this->latest($node);
    return [
      '#cache' => ['max-age' => 0],
      'notice' => ['#markup' => '<p>' . $this->t('Review the latest saved version before publishing. Unsaved changes on the editing form are not included.') . '</p>'],
      'content' => $this->entityTypeManager()->getViewBuilder('node')->view($latest, 'full'),
      'publish' => $this->formBuilder()->getForm(LatestPreviewForm::class, $latest),
    ];
  }

}

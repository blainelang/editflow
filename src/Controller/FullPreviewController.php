<?php

namespace Drupal\editflow\Controller;

use Drupal\editflow\EditorialSettings;
use Drupal\Core\Entity\EntityInterface;
use Drupal\node\Controller\NodePreviewController;

/**
 * Uses full display for opted-in previews, including old teaser preview URLs.
 *
 * Other workflows and disabled full_preview retain the requested view mode.
 */
class FullPreviewController extends NodePreviewController {

  /**
   * {@inheritdoc}
   */
  public function view(EntityInterface $node_preview, $view_mode_id = 'full', $langcode = NULL) {
    return parent::view($node_preview, EditorialSettings::applies($node_preview, 'full_preview') ? 'full' : $view_mode_id, $langcode);
  }

}

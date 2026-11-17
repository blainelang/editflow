<?php

namespace Drupal\editflow\Controller;

use Drupal\editflow\EditorialSettings;
use Drupal\diff\Controller\NodeRevisionController;
use Drupal\node\NodeInterface;

/**
 * Refines comparison controls without changing Diff's revision access checks.
 */
class ComparisonController extends NodeRevisionController {

  /**
   * {@inheritdoc}
   */
  public function compareNodeRevisions(NodeInterface $node, $left_revision, $right_revision, $filter): array {
    if (!EditorialSettings::applies($node, 'comparison')) {
      return parent::compareNodeRevisions($node, $left_revision, $right_revision, $filter);
    }
    $request = $this->requestStack->getCurrentRequest();
    $had_view_mode = $request->query->has('view_mode');
    $view_mode = $request->query->get('view_mode');
    try {
      if ($filter === 'visual_inline') {
        $request->query->set('view_mode', 'full');
      }
      $build = parent::compareNodeRevisions($node, $left_revision, $right_revision, $filter);
    }
    finally {
      if ($had_view_mode) {
        $request->query->set('view_mode', $view_mode);
      }
      else {
        $request->query->remove('view_mode');
      }
    }
    unset($build['controls']['view_mode']);
    if (isset($build['controls']['diff_layout'])) {
      $build['controls']['diff_layout']['#title'] = $this->t('Comparison style');
    }
    if (isset($build['header'], $build['controls'])) {
      $build['comparison_options'] = [
        '#type' => 'details',
        '#title' => $this->t('Comparison details and options'),
        '#open' => FALSE,
        '#weight' => 0,
        '#attributes' => ['class' => ['editflow-comparison-options']],
        'header' => $build['header'],
        'controls' => $build['controls'],
      ];
      unset($build['header'], $build['controls']);
    }
    return $build;
  }

}

<?php

namespace Drupal\editflow\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\editflow\Controller\FullPreviewController;
use Symfony\Component\Routing\RouteCollection;

/**
 * Installs request-time adapters without changing route access requirements.
 *
 * Routes are shared by all nodes and cannot be selected per workflow at rebuild
 * time. Both controllers call EditorialSettings::applies() for the current node
 * and delegate unchanged to core/Diff when that workflow or feature is
 * disabled.
 * The Diff adapter is installed only when Diff provides its route.
 */
class PreviewRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    if ($route = $collection->get('entity.node.preview')) {
      $route->setDefault('_controller', FullPreviewController::class . '::view');
    }
    if ($route = $collection->get('diff.revisions_diff')) {
      $route->setDefault('_controller', '\\Drupal\\editflow\\Controller\\ComparisonController::compareNodeRevisions');
    }
  }

}

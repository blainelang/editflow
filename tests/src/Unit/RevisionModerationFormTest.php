<?php

namespace Drupal\Tests\editflow\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @group editflow
 */
class RevisionModerationFormTest extends UnitTestCase {

  public function testOnlyViewedRevisionFormIsHidden(): void {
    require_once __DIR__ . '/../../../editflow.module';
    foreach ([
      ['entity.node.revision', '69', '100', FALSE],
      ['entity.node.latest_version', '69', '100', TRUE],
      ['entity.node.canonical', '69', '100', TRUE],
      ['entity.node.revision', '70', '100', TRUE],
      ['entity.node.revision', '69', '101', TRUE],
    ] as [$route_name, $entity_id, $revision_id, $visible]) {
      $revision = $this->createMock(NodeInterface::class);
      $revision->method('id')->willReturn('69');
      $revision->method('getRevisionId')->willReturn('100');
      $entity = $this->createMock(NodeInterface::class);
      $entity->method('id')->willReturn($entity_id);
      $entity->method('getRevisionId')->willReturn($revision_id);
      $route = $this->createMock(RouteMatchInterface::class);
      $route->method('getRouteName')->willReturn($route_name);
      $route->method('getParameter')->with('node_revision')->willReturn($revision);
      $container = new ContainerBuilder();
      $container->set('current_route_match', $route);
      $container->set('config.factory', $this->getConfigFactoryStub(['editflow.settings' => ['revision_footer' => TRUE]]));
      $contexts = $this->createMock(\Drupal\Core\Cache\Context\CacheContextsManager::class);
      $contexts->method('assertValidTokens')->willReturn(TRUE);
      $container->set('cache_contexts_manager', $contexts);
      \Drupal::setContainer($container);
      $state = new FormState();
      $state->set('entity', $entity);
      $form = ['#access' => TRUE];
      editflow_revision_moderation_form_alter($form, $state);
      $this->assertSame($visible, $form['#access']);
      $this->assertContains('route', $form['#cache']['contexts']);
    }
  }

}

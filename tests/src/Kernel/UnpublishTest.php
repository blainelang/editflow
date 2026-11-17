<?php

namespace Drupal\Tests\editflow\Kernel;

use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\editflow\Unpublish;
use Drupal\preview_link\Entity\PreviewLink;
use Drupal\workflows\Entity\Workflow;

#[\PHPUnit\Framework\Attributes\Group('editflow')]
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(FALSE)]
class UnpublishTest extends EntityKernelTestBase {

  protected static $modules = ['node', 'text', 'filter', 'workflows', 'content_moderation', 'editflow', 'dynamic_entity_reference', 'preview_link'];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('content_moderation_state');
    $this->installEntitySchema('preview_link');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['filter', 'node', 'content_moderation', 'editflow', 'preview_link']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    Workflow::create([
      'id' => 'editorial_pilot', 'label' => 'Editorial', 'type' => 'content_moderation',
      'type_settings' => [
        'states' => [
          'draft' => ['label' => 'Draft', 'published' => FALSE, 'default_revision' => FALSE, 'weight' => 0],
          'published' => ['label' => 'Published', 'published' => TRUE, 'default_revision' => TRUE, 'weight' => 1],
          'unpublished' => ['label' => 'Unpublished', 'published' => FALSE, 'default_revision' => TRUE, 'weight' => 2],
        ],
        'transitions' => [
          'unpublish' => ['label' => 'Unpublish', 'from' => ['published'], 'to' => 'unpublished', 'weight' => 0],
          'publish' => ['label' => 'Publish', 'from' => ['draft', 'unpublished'], 'to' => 'published', 'weight' => 1],
        ],
        'entity_types' => ['node' => ['page']], 'default_moderation_state' => 'draft',
      ],
    ])->save();
    $this->config('editflow.settings')->set('unpublish', TRUE)->set('workflows', [['id' => 'editorial_pilot', 'draft_state' => 'draft', 'publish_state' => 'published', 'unpublish_state' => 'unpublished']])->save();
  }

  public function testUnpublishPreservesDraft(): void {
    $account = $this->createUser(['administer nodes', 'use editorial_pilot transition unpublish', 'access content']);
    $this->container->get('current_user')->setAccount($account);
    $node = Node::create(['type' => 'page', 'title' => 'Live title', 'moderation_state' => 'published']);
    $node->save();
    $live_id = $node->getRevisionId();
    $other = Node::create(['type' => 'page', 'title' => 'Other page', 'moderation_state' => 'published']);
    $other->save();
    $shared = PreviewLink::create()->addEntity($node)->addEntity($other);
    $shared->save();
    $single = PreviewLink::create()->addEntity($node);
    $single->save();
    $node->setNewRevision(TRUE);
    $node->setTitle('Pending draft title');
    $node->set('moderation_state', 'draft');
    $node->save();
    $draft_id = $node->getRevisionId();
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $live = $storage->load($node->id());
    $this->assertTrue(Unpublish::access($live, $account)->isAllowed());
    $expected = Unpublish::snapshot($live);
    Unpublish::execute($live, $account, $expected);
    $storage->resetCache();
    $offline = $storage->load($node->id());
    $this->assertFalse($offline->isPublished());
    $this->assertSame('Pending draft title', $offline->label());
    $this->assertSame('draft', $offline->moderation_state->value);
    $latest = $storage->loadRevision($storage->getLatestRevisionId($node->id()));
    $this->assertSame('Pending draft title', $latest->label());
    $this->assertSame('draft', $latest->moderation_state->value);
    $this->assertFalse($latest->isPublished());
    $this->assertSame('Live title', $storage->loadRevision($live_id)->label());
    $this->assertSame('Pending draft title', $storage->loadRevision($draft_id)->label());
    $this->assertFalse(Unpublish::access($offline, $account)->isAllowed());
    $this->assertNull(PreviewLink::load($single->id()));
    $remaining = PreviewLink::load($shared->id())->getEntities();
    $this->assertCount(1, $remaining);
    $this->assertSame($other->id(), reset($remaining)->id());
  }

  public function testUnpublishWithoutDraftAndDeniedEditor(): void {
    $publisher = $this->createUser(['administer nodes', 'use editorial_pilot transition unpublish', 'use editorial_pilot transition publish', 'access content']);
    $editor = $this->createUser(['edit any page content', 'access content']);
    $this->container->get('current_user')->setAccount($publisher);
    $node = Node::create(['type' => 'page', 'title' => 'Live', 'moderation_state' => 'published']);
    $node->save();
    $this->assertFalse(Unpublish::access($node, $editor)->isAllowed());
    Unpublish::execute($node, $publisher, Unpublish::snapshot($node));
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    $offline = $storage->load($node->id());
    $this->assertFalse($offline->isPublished());
    $this->assertSame('unpublished', $offline->moderation_state->value);
    $offline->setNewRevision(TRUE);
    $offline->set('moderation_state', 'published')->save();
    $storage->resetCache();
    $this->assertTrue($storage->load($node->id())->isPublished());
  }

  public function testRejectsNewRevisionAfterConfirmation(): void {
    $publisher = $this->createUser(['administer nodes', 'use editorial_pilot transition unpublish', 'access content']);
    $this->container->get('current_user')->setAccount($publisher);
    $node = Node::create(['type' => 'page', 'title' => 'Live', 'moderation_state' => 'published']);
    $node->save();
    $expected = Unpublish::snapshot($node);
    $node->setNewRevision(TRUE);
    $node->set('moderation_state', 'draft')->save();
    $this->expectException(\DomainException::class);
    $this->expectExceptionMessage('A newer revision exists');
    Unpublish::execute($node, $publisher, $expected);
  }

}

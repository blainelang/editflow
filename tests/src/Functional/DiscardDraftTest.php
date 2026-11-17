<?php

namespace Drupal\Tests\editflow\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\workflows\Entity\Workflow;
use Drupal\views\Views;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContext;

/**
 * Tests confirmation against isolated revisions, tempstore and locks.
 *
 * @group editflow
 */
#[\PHPUnit\Framework\Attributes\Group('editflow')]
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(FALSE)]
class DiscardDraftTest extends BrowserTestBase {
  use ContentModerationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';
  /**
   * {@inheritdoc}
   */
  protected static $modules = ['editflow', 'content_lock', 'layout_builder', 'language'];

  /**
   * Exercises the discard operation.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalCreateContentType(['type' => 'article']);
    $this->addEntityTypeAndBundleToWorkflow($this->createEditorialWorkflow(), 'node', 'article');
    LayoutBuilderEntityViewDisplay::load('node.article.default')->enableLayoutBuilder()->setOverridable()->save();
    $this->config('editflow.settings')->set('workflows', [
      ['id' => 'editorial', 'draft_state' => 'draft', 'publish_state' => 'published'],
    ])->save();
    $this->config('content_lock.settings')->set('types.node', ['article'])->save();
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Exercises the discard operation.
   */
  private function revisions(): array {
    $node = $this->drupalCreateNode(['type' => 'article', 'title' => 'Original live', 'moderation_state' => 'published']);
    $published = [$node->getRevisionId()];
    $drafts = [];
    foreach (['draft', 'published', 'draft', 'draft'] as $state) {
      $node->setNewRevision(TRUE);
      $node->moderation_state = $state;
      $node->setTitle($state === 'published' ? 'Current live' : 'Unpublished work');
      $node->save();
      if ($state === 'published') {
        $published[] = $node->getRevisionId();
      }
      else {
        $drafts[] = $node->getRevisionId();
      }
    }
    return [$node, $published, $drafts];
  }

  /**
   * Exercises the discard operation.
   */
  public function testConfirmationAndCleanup(): void {
    [$node, $published, $drafts] = $this->revisions();
    $this->drupalLogin($this->rootUser);
    $url = 'node/' . $node->id() . '/discard-unpublished-changes';
    $this->drupalGet('node/' . $node->id() . '/edit');
    $this->assertSession()->linkByHrefExists('/' . $url);
    $this->assertSession()->elementExists('css', '.editflow-layout-actions a.editflow-discard-drafts');
    $this->drupalGet('node/' . $node->id() . '/layout');
    $this->assertSession()->linkByHrefExists('/' . $url);
    $section = $this->container->get('plugin.manager.layout_builder.section_storage')->load('overrides', [
      'entity' => EntityContext::fromEntity($node),
      'view_mode' => new Context(new ContextDefinition('string'), 'default'),
    ]);
    $this->container->get('layout_builder.tempstore_repository')->set($section);
    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Published revision ' . end($published) . ' will remain live.');
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    foreach ($drafts as $vid) {
      $this->assertNotNull($storage->loadRevision($vid));
    }
    $this->clickLink('Cancel');
    $this->assertNotFalse($this->container->get('content_lock')->fetchLock($node));
    $this->drupalGet($url);
    $this->getSession()->getPage()->find('css', 'input[name="snapshot_signature"]')->setValue('tampered');
    $this->submitForm(['acknowledge' => TRUE] + array_fill_keys(array_map(fn($vid) => 'revisions[' . $vid . ']', $drafts), TRUE), 'Delete selected revisions');
    $this->assertSession()->pageTextContains('confirmation expired or is invalid');
    $this->drupalGet($url);
    // Required explicit acknowledgement: no deletion on an incomplete POST.
    $this->submitForm([], 'Delete selected revisions');
    $this->assertSession()->pageTextContains('field is required');
    $this->drupalGet($url);
    // Stale confirmation cannot delete a newly saved revision.
    $node->setNewRevision(TRUE);
    $node->save();
    $new_draft = $node->getRevisionId();
    $this->submitForm(['acknowledge' => TRUE] + array_fill_keys(array_map(fn($vid) => 'revisions[' . $vid . ']', $drafts), TRUE), 'Delete selected revisions');
    $this->assertSession()->pageTextContains('changed. Reopen the confirmation');
    $drafts[] = $new_draft;
    foreach ($drafts as $vid) {
      $this->assertNotNull($storage->loadRevision($vid));
    }
    // The redirect presents a fresh confirmation for deliberate review.
    $this->submitForm(['acknowledge' => TRUE] + array_fill_keys(array_map(fn($vid) => 'revisions[' . $vid . ']', $drafts), TRUE), 'Delete selected revisions');
    $this->assertSession()->addressEquals('node/' . $node->id());
    $storage->resetCache();
    $this->assertSame('Current live', $storage->load($node->id())->label());
    foreach ($published as $vid) {
      $this->assertNotNull($storage->loadRevision($vid));
    }
    foreach ($drafts as $vid) {
      $this->assertNull($storage->loadRevision($vid));
    }
    $this->assertFalse($this->container->get('content_lock')->fetchLock($node, NULL, TRUE));
    $this->assertNull($this->container->get('tempstore.shared')->get('layout_builder.section_storage.overrides')->get($section->getTempstoreKey()));
  }

  /**
   * Exercises the discard operation.
   */
  public function testAccessAndNeverPublished(): void {
    [$node] = $this->revisions();
    $editor = $this->drupalCreateUser([
      'access content', 'edit any article content', 'view any unpublished content', 'view latest version',
    ]);
    $this->drupalLogin($editor);
    $this->drupalGet('node/' . $node->id() . '/discard-unpublished-changes');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogin($this->rootUser);
    $draft = $this->drupalCreateNode(['type' => 'article', 'moderation_state' => 'draft']);
    $this->drupalGet('node/' . $draft->id() . '/discard-unpublished-changes');
    $this->assertSession()->statusCodeEquals(403);
    // A changed lock is part of the confirmation snapshot.
    $service = $this->container->get('editflow.draft_discard');
    $plan = $service->plan($node, $this->rootUser);
    $this->container->get('content_lock')->locking($node, 'edit', $editor->id());
    try {
      $service->discard($node, $this->rootUser, $plan['fingerprint'], array_keys($plan['drafts']));
      $this->fail('A changed lock must invalidate confirmation.');
    }
    catch (\DomainException $exception) {
      $this->assertStringContainsString('changed', $exception->getMessage());
    }
    $reviewer = $this->drupalCreateUser(['access content', 'administer nodes', 'bypass node access']);
    $this->assertFalse($service->access($node, $reviewer)->isAllowed());
    $this->container->get('content_lock')->release($node);
    $this->assertTrue($service->access($node, $reviewer)->isAllowed());
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->container->get('language_manager')->reset();
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    $node = $storage->loadRevision($node->getRevisionId());
    $node->addTranslation('fr', ['title' => 'French work', 'moderation_state' => 'draft']);
    $node->setNewRevision(TRUE);
    $node->save();
    $this->assertFalse($service->access($node, $this->rootUser)->isAllowed());

  }

  /**
   * Partial selection preserves other drafts, pending layouts, and the lock.
   */
  public function testSelectedRevisions(): void {
    [$node, $published, $drafts] = $this->revisions();
    $this->drupalLogin($this->rootUser);
    $lock = $this->container->get('content_lock');
    $lock->locking($node, 'edit', $this->rootUser->id());
    $section = $this->container->get('plugin.manager.layout_builder.section_storage')->load('overrides', [
      'entity' => EntityContext::fromEntity($node),
      'view_mode' => new Context(new ContextDefinition('string'), 'default'),
    ]);
    $this->container->get('layout_builder.tempstore_repository')->set($section);
    $temporary = $this->container->get('tempstore.shared')->get('layout_builder.section_storage.overrides');
    $before = serialize($temporary->get($section->getTempstoreKey()));
    $service = $this->container->get('editflow.draft_discard');
    $plan = $service->plan($node, $this->rootUser);
    // Reject empty, published, unrelated, or mixed valid/invalid selections.
    foreach ([[], [$published[0]], [999999], [$drafts[0], $published[0]]] as $invalid) {
      try {
        $service->discard($node, $this->rootUser, $plan['fingerprint'], $invalid);
        $this->fail('Invalid selections must not delete revisions.');
      }
      catch (\DomainException $exception) {
        $this->assertStringContainsString('Select one or more', $exception->getMessage());
      }
    }
    $this->drupalGet('node/' . $node->id() . '/discard-unpublished-changes');
    foreach ($drafts as $vid) {
      $this->assertSession()->checkboxNotChecked('revisions[' . $vid . ']');
    }
    $this->submitForm(['acknowledge' => TRUE], 'Delete selected revisions');
    $this->assertSession()->pageTextContains('required');
    // Delete the latest draft; an earlier unchecked draft must remain usable.
    $selected = array_pop($drafts);
    $this->submitForm(['acknowledge' => TRUE, 'revisions[' . $selected . ']' => TRUE], 'Delete selected revisions');
    $this->assertSession()->pageTextContains('Unchecked drafts, pending layout changes, and the editing lock were preserved.');
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    $this->assertNull($storage->loadRevision($selected));
    foreach (array_merge($published, $drafts) as $vid) {
      $this->assertNotNull($storage->loadRevision($vid));
    }
    $this->assertEquals(end($drafts), $storage->getLatestRevisionId($node->id()));
    $this->assertSame('Current live', $storage->load($node->id())->label());
    $this->assertEquals($plan['lock'], $lock->fetchLock($node, NULL, TRUE));
    $this->assertSame($before, serialize($temporary->get($section->getTempstoreKey())));
  }

  /**
   * Older drafts must not take newer moderation metadata with them.
   */
  public function testModerationEnabledAfterPublication(): void {
    $this->container->get('module_installer')->install(['views']);
    $workflow = Workflow::load('editorial');
    $workflow->getTypePlugin()->removeEntityTypeAndBundle('node', 'article');
    $workflow->save();
    $node = $this->drupalCreateNode(['type' => 'article', 'title' => 'Existing page', 'status' => 1]);
    $published = $node->getRevisionId();
    $workflow->getTypePlugin()->addEntityTypeAndBundle('node', 'article');
    $workflow->save();
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    $node = $storage->load($node->id());
    $drafts = [];
    foreach (['First draft', 'Second draft'] as $title) {
      $node->setNewRevision(TRUE);
      $node->setTitle($title);
      $node->moderation_state = 'draft';
      $node->save();
      $drafts[] = $node->getRevisionId();
    }
    $service = $this->container->get('editflow.draft_discard');
    $plan = $service->plan($node, $this->rootUser);
    $service->discard($node, $this->rootUser, $plan['fingerprint'], [$drafts[0]]);
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    $this->assertNull($storage->loadRevision($drafts[0]));
    $this->assertSame('draft', $storage->loadRevision($drafts[1])->moderation_state->value);
    $this->assertEquals($published, $storage->load($node->id())->getRevisionId());
    $view = Views::getView('moderated_content');
    $view->setDisplay('moderated_content');
    $view->execute();
    $this->assertContains((string) $drafts[1], array_map(fn($row) => (string) $row->_entity->getRevisionId(), $view->result));
  }

  /**
   * A changed pending layout cannot be discarded with an older confirmation.
   */
  public function testChangedPendingLayout(): void {
    [$node, , $drafts] = $this->revisions();
    $service = $this->container->get('editflow.draft_discard');
    $section = $this->container->get('plugin.manager.layout_builder.section_storage')->load('overrides', [
      'entity' => EntityContext::fromEntity($node),
      'view_mode' => new Context(new ContextDefinition('string'), 'default'),
    ]);
    $repository = $this->container->get('layout_builder.tempstore_repository');
    $repository->set($section);
    $plan = $service->plan($node, $this->rootUser);
    $section->appendSection(new Section('layout_onecol'));
    $repository->set($section);
    try {
      $service->discard($node, $this->rootUser, $plan['fingerprint'], array_keys($plan['drafts']));
      $this->fail('A changed layout must invalidate confirmation.');
    }
    catch (\DomainException $exception) {
      $this->assertStringContainsString('changed', $exception->getMessage());
    }
    foreach ($drafts as $vid) {
      $this->assertNotNull($this->container->get('entity_type.manager')->getStorage('node')->loadRevision($vid));
    }
    $this->assertNotNull($this->container->get('tempstore.shared')->get('layout_builder.section_storage.overrides')->get($section->getTempstoreKey()));
  }

}

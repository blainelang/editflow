<?php

namespace Drupal\Tests\editflow\Functional;

use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;

/**
 * Exercises real Layout Builder saving, validation and lock release.
 *
 * @group editflow
 */
#[\PHPUnit\Framework\Attributes\Group('editflow')]
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(FALSE)]
abstract class LayoutSaveTestBase extends BrowserTestBase {
  use ContentModerationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';
  /**
   * {@inheritdoc}
   */
  protected static $modules = ['editflow', 'layout_builder', 'editflow_test'];

  /**
   * Verifies save actions.
   */
  public function testSaveActions(): void {
    $this->drupalCreateContentType(['type' => 'article']);
    $this->addEntityTypeAndBundleToWorkflow($this->createEditorialWorkflow(), 'node', 'article');
    LayoutBuilderEntityViewDisplay::load('node.article.default')->enableLayoutBuilder()->setOverridable()->save();
    $this->config('editflow.settings')->set('workflows', [
    [
      'id' => 'editorial',
      'draft_state' => 'draft',
      'publish_state' => 'published',
    ],
    ])->save();
    $has_lock = $this->container->has('content_lock');
    if ($has_lock) {
      $this->config('content_lock.settings')->set('types.node', ['article'])->set('timeout', 1800)->save();
      $this->container->get('router.builder')->rebuild();
    }
    $editor = $this->drupalCreateUser([
      'access content', 'edit any article content', 'configure any layout',
      'view any unpublished content', 'view latest version',
      'use editorial transition create_new_draft',
    ]);
    $node = $this->drupalCreateNode(['type' => 'article', 'uid' => $editor->id(), 'moderation_state' => 'published']);
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $revision = $storage->getLatestRevisionId($node->id());
    $this->drupalLogin($editor);
    $path = 'node/' . $node->id() . '/layout';
    $this->drupalGet($path);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->buttonExists('Save draft and continue editing');
    if ($has_lock) {
      $this->assertSame((string) $editor->id(), (string) $this->container->get('content_lock')->fetchLock($node)->uid);
    }
    // A failure for either action must neither save nor release the lock.
    foreach (['Save draft and continue editing', 'Save draft and finish editing'] as $button) {
      $this->submitForm(['test_required' => ''], $button);
      $this->assertSession()->pageTextContains('Required test value field is required.');
      $storage->resetCache();
      $this->assertEquals($revision, $storage->getLatestRevisionId($node->id()));
      $this->assertSame(0, $this->releaseCount());
      if ($has_lock) {
        $this->assertNotFalse($this->container->get('content_lock')->fetchLock($node));
      }
    }
    $this->submitForm(['test_required' => 'valid'], 'Save draft and continue editing');
    $this->assertSession()->addressEquals($path);
    $storage->resetCache();
    $continued_revision = $storage->getLatestRevisionId($node->id());
    $this->assertGreaterThan($revision, $continued_revision);
    $this->assertSame('draft', $storage->loadRevision($continued_revision)->moderation_state->value);
    // Counting releases catches a release/reacquire hidden by the redirect.
    $this->assertSame(0, $this->releaseCount());
    if ($has_lock) {
      $this->assertNotFalse($this->container->get('content_lock')->fetchLock($node));
    }
    $this->submitForm(['test_required' => 'valid'], 'Save draft and finish editing');
    $this->assertSession()->addressEquals('node/' . $node->id() . '/latest');
    $storage->resetCache();
    $this->assertGreaterThan($continued_revision, $storage->getLatestRevisionId($node->id()));
    if ($has_lock) {
      $this->assertFalse($this->container->get('content_lock')->fetchLock($node));
      $this->assertSame(1, $this->releaseCount());
    }
  }

  /**
   * Page Details shares save behavior without saving on discard or validation.
   */
  public function testDetailsSaveActions(): void {
    $this->drupalCreateContentType(['type' => 'article']);
    $this->addEntityTypeAndBundleToWorkflow($this->createEditorialWorkflow(), 'node', 'article');
    $this->config('editflow.settings')->set('workflows', [
      ['id' => 'editorial', 'draft_state' => 'draft', 'publish_state' => 'published'],
    ])->save();
    $has_lock = $this->container->has('content_lock');
    if ($has_lock) {
      $this->config('content_lock.settings')->set('types.node', ['article'])->set('timeout', 1800)->save();
      $this->container->get('router.builder')->rebuild();
    }
    $editor = $this->drupalCreateUser([
      'access content', 'edit any article content', 'view any unpublished content',
      'view latest version', 'use editorial transition create_new_draft',
      'use editorial transition publish',
    ]);
    $node = $this->drupalCreateNode(['type' => 'article', 'moderation_state' => 'published']);
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $revision = $storage->getLatestRevisionId($node->id());
    $this->drupalLogin($editor);
    $path = 'node/' . $node->id() . '/edit';
    $this->drupalGet($path);
    $this->assertSession()->elementExists('css', '.editflow-layout-actions');
    $this->assertSession()->elementNotExists('css', 'select option[value="published"]');
    foreach (['Save draft and continue editing', 'Save draft and finish editing'] as $button) {
      $this->submitForm(['title[0][value]' => ''], $button);
      $this->assertSession()->pageTextContains('Title field is required.');
      $storage->resetCache();
      $this->assertEquals($revision, $storage->getLatestRevisionId($node->id()));
      $this->assertSame(0, $this->releaseCount());
    }
    $this->submitForm(['title[0][value]' => 'Saved draft'], 'Save draft and continue editing');
    $this->assertSession()->addressEquals($path);
    $storage->resetCache();
    $continued = $storage->getLatestRevisionId($node->id());
    $this->assertGreaterThan($revision, $continued);
    $this->assertSame('draft', $storage->loadRevision($continued)->moderation_state->value);
    $this->assertSame(0, $this->releaseCount());
    $this->submitForm(['title[0][value]' => ''], 'Discard unsaved changes');
    $this->assertSession()->addressEquals('node/' . $node->id());
    $storage->resetCache();
    $this->assertEquals($continued, $storage->getLatestRevisionId($node->id()));
    if ($has_lock) {
      $this->assertFalse($this->container->get('content_lock')->fetchLock($node));
    }
    $this->drupalGet($path);
    $this->submitForm(['title[0][value]' => 'Finished draft'], 'Save draft and finish editing');
    $this->assertSession()->addressEquals('node/' . $node->id() . '/latest');
    $this->assertSession()->buttonExists('Publish this version');
    $storage->resetCache();
    $this->assertGreaterThan($continued, $storage->getLatestRevisionId($node->id()));
    if ($has_lock) {
      $this->assertFalse($this->container->get('content_lock')->fetchLock($node));
    }
  }

  /**
   * Handles release count.
   */
  private function releaseCount(): int {
    $this->container->get('state')->resetCache();
    return $this->container->get('state')->get('editflow_test.releases', 0);
  }

}

<?php

namespace Drupal\Tests\editflow\Functional;

use Drupal\user\Entity\Role;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;

/**
 * Exercises publication safeguards through real HTTP form submissions.
 *
 * @group editflow
 */
#[\PHPUnit\Framework\Attributes\Group('editflow')]
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(FALSE)]
class PublicationTest extends BrowserTestBase {
  use ContentModerationTestTrait;
  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';
  /**
   * {@inheritdoc}
   */
  protected static $modules = ['editflow'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->assertTrue($this->container->get('module_handler')->moduleExists('content_lock'), 'Installing EditFlow enables its required locking dependency.');
    $this->drupalCreateContentType(['type' => 'article']);
    $this->addEntityTypeAndBundleToWorkflow($this->createEditorialWorkflow(), 'node', 'article');
    $this->config('editflow.settings')->set('workflows', [
    [
      'id' => 'editorial',
      'draft_state' => 'draft',
      'publish_state' => 'published',
    ],
    ])->save();
    $this->config('content_lock.settings')->set('types.node', ['article'])->set('timeout', 1800)->save();
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Creates published content with a newer saved draft.
   */
  private function pendingNode() {
    $node = $this->drupalCreateNode(['type' => 'article', 'title' => 'Live title', 'moderation_state' => 'published']);
    $node->setNewRevision(TRUE);
    $node->setTitle('Saved draft title');
    $node->moderation_state = 'draft';
    $node->save();
    return $node;
  }

  /**
   * The unpublish shortcut belongs to published edits, never draft edits.
   */
  public function testUnpublishShortcutHiddenOnDraft(): void {
    $this->config('editflow.settings')->set('unpublish', TRUE)
      ->set('workflows.0.unpublish_state', 'archived')->save();
    $this->drupalLogin(\Drupal\user\Entity\User::load(1));
    $node = $this->drupalCreateNode(['type' => 'article', 'title' => 'Live page', 'moderation_state' => 'published']);
    $this->drupalGet('node/' . $node->id() . '/edit');
    $this->assertSession()->linkExists('Unpublish content…');
    $node->setNewRevision(TRUE);
    $node->setTitle('Pending draft');
    $node->set('moderation_state', 'draft');
    $node->save();
    $this->drupalGet('node/' . $node->id() . '/edit');
    $this->assertSession()->fieldValueEquals('title[0][value]', 'Pending draft');
    $this->assertSession()->linkNotExists('Unpublish content…');
  }

  /**
   * Asserts that the saved draft has not replaced published content.
   */
  private function assertStillDraft($node): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    $this->assertSame('Live title', $storage->load($node->id())->label());
    $this->assertSame('draft', $storage->loadRevision($storage->getLatestRevisionId($node->id()))->moderation_state->value);
  }

  /**
   * Verifies administrator saves draft.
   */
  public function testAdministratorSavesDraft(): void {
    $node = $this->drupalCreateNode(['type' => 'article', 'title' => 'Live title', 'moderation_state' => 'published']);
    $this->drupalLogin($this->rootUser);
    $this->drupalGet('node/' . $node->id() . '/edit');
    $this->assertSession()->fieldValueEquals('moderation_state[0][state]', 'draft');
    $this->submitForm(['title[0][value]' => 'Admin draft title'], 'Save');
    $this->assertStillDraft($node);
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $this->assertSame('Admin draft title', $storage->loadRevision($storage->getLatestRevisionId($node->id()))->label());
  }

  public function testOfflinePageCanBePublishedWithoutContentChanges(): void {
    $node = $this->drupalCreateNode(['type' => 'article', 'title' => 'Offline page', 'moderation_state' => 'draft']);
    $this->drupalLogin($this->rootUser);
    $this->drupalGet('node/' . $node->id() . '/edit');
    $this->assertSession()->elementExists('css', '#edit-meta a[href$="/publish-preview"]');
    $this->clickLink('Publish content…');
    $this->assertSession()->pageTextContains('Unsaved changes on the editing form are not included.');
    $this->submitForm(['confirm_publish' => TRUE], 'Publish this version');
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    $saved = $storage->load($node->id());
    $this->assertTrue($saved->isPublished());
    $this->assertSame('Offline page', $saved->label());
  }

  /**
   * Verifies stale preview and successful publication.
   */
  public function testStalePreviewAndSuccessfulPublication(): void {
    $node = $this->pendingNode();
    $this->drupalLogin($this->rootUser);
    $this->drupalGet('node/' . $node->id() . '/latest');
    $this->assertSession()->buttonExists('Publish this version');
    // Another save occurs after this browser has loaded its preview form.
    $node->setNewRevision(TRUE);
    $node->setTitle('Newer draft');
    $node->save();
    $this->submitForm(['confirm_publish' => TRUE], 'Publish this version');
    $this->assertSession()->pageTextContains('A newer revision exists.');
    $this->assertStillDraft($node);
    $this->drupalGet('node/' . $node->id() . '/latest');
    $this->submitForm(['confirm_publish' => TRUE], 'Publish this version');
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    $this->assertTrue($storage->load($node->id())->isPublished());
    $this->assertSame('Newer draft', $storage->load($node->id())->label());
  }

  /**
   * Verifies lock acquired after preview blocks publication.
   */
  public function testLockAcquiredAfterPreviewBlocksPublication(): void {
    $node = $this->pendingNode();
    $this->drupalLogin($this->rootUser);
    $this->drupalGet('node/' . $node->id() . '/latest');
    $owner = $this->drupalCreateUser(['edit any article content']);
    $this->container->get('content_lock')->locking($node, 'edit', (int) $owner->id(), TRUE);
    $this->submitForm(['confirm_publish' => TRUE], 'Publish this version');
    $this->assertStillDraft($node);
    $this->drupalGet('node/' . $node->id() . '/latest');
    $this->assertSession()->buttonNotExists('Publish this version');
    $this->assertSession()->pageTextContains('Publishing is unavailable');
  }

  /**
   * Verifies publishing permission revoked after preview.
   */
  public function testPublishingPermissionRevokedAfterPreview(): void {
    $node = $this->pendingNode();
    $permissions = [
      'access content',
      'edit any article content',
      'view any unpublished content',
      'view latest version',
      'use editorial transition create_new_draft',
      'use editorial transition publish',
    ];
    $publisher = $this->drupalCreateUser($permissions);
    $this->drupalLogin($publisher);
    $this->drupalGet('node/' . $node->id() . '/latest');
    $this->assertSession()->buttonExists('Publish this version');
    foreach ($publisher->getRoles(TRUE) as $rid) {
      Role::load($rid)->revokePermission('use editorial transition publish')->save();
    }
    $this->submitForm(['confirm_publish' => TRUE], 'Publish this version');
    $this->assertStillDraft($node);
    $this->drupalGet('node/' . $node->id() . '/latest');
    $this->assertSession()->buttonNotExists('Publish this version');
    $this->assertSession()->pageTextContains('A publisher must make this draft live.');
  }

}

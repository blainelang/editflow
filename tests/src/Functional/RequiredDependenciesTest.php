<?php

namespace Drupal\Tests\editflow\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;

/**
 * Tests a site with only EditFlow and its required dependencies.
 *
 * @group editflow
 */
#[\PHPUnit\Framework\Attributes\Group('editflow')]
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(FALSE)]
class RequiredDependenciesTest extends BrowserTestBase {
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
   * Verifies minimal site.
   */
  public function testMinimalSite(): void {
    $this->assertTrue($this->container->get('module_handler')->moduleExists('content_lock'));
    $this->assertInstanceOf(\Drupal\editflow\ContentLock\EditorialContentLock::class, $this->container->get('content_lock'));
    foreach (['layout_builder', 'diff', 'preview_link', 'views'] as $module) {
      $this->assertFalse($this->container->get('module_handler')->moduleExists($module));
    }
    $this->assertTrue($this->container->get('module_handler')->moduleExists('content_moderation'));
    $this->drupalCreateContentType(['type' => 'article']);
    $this->addEntityTypeAndBundleToWorkflow($this->createEditorialWorkflow(), 'node', 'article');
    $this->config('editflow.settings')->set('workflows', [
    [
      'id' => 'editorial',
      'draft_state' => 'draft',
      'publish_state' => 'published',
    ],
    ])->set('comparison',
    TRUE)->set('editing_bar',
    TRUE)->save();
    $this->drupalLogin($this->rootUser);
    $this->drupalGet('admin/config/content/editflow/interface');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkNotExists('Add editorial columns to content views');
    $this->drupalGet('admin/config/content/editflow/interface/content-views');
    $this->assertSession()->statusCodeEquals(404);
    $node = $this->drupalCreateNode(['type' => 'article', 'moderation_state' => 'published']);
    $node->setNewRevision(TRUE);
    $node->moderation_state = 'draft';
    $node->save();
    $this->drupalGet('node/' . $node->id() . '/latest');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Preview — latest saved version');
    $this->assertSession()->linkNotExists('Edit Layout');
    $this->assertSession()->linkNotExists('Release editing lock');
    $this->drupalGet('node/' . $node->id() . '/edit');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Unpublished changes');
    $this->drupalGet('admin/content/editflow-help');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('For administrators');
    $this->assertSession()->pageTextNotContains('Review and apply');
    $this->drupalGet('admin/config/content/editflow');
    $this->assertSession()->linkExists('Configuration help');
    $this->assertSession()->linkNotExists('Editorial help');
    $this->drupalGet('admin/config/content/editflow/interface');
    $this->assertSession()->linkExists('Configuration help');
    foreach (['workflow' => 'Workflow setup', 'interface' => 'Interface settings'] as $section => $title) {
      $this->drupalGet('admin/config/content/editflow/help/' . $section);
      $this->assertSession()->statusCodeEquals(200);
      $open = $this->assertSession()->elementExists('css', 'details[open]');
      $this->assertStringContainsString($title, $open->getText());
      $this->assertSession()->pageTextContains('Review and apply');
      $this->assertSession()->pageTextContains('Editing locks');
      $this->drupalGet('admin/config/content/editflow/help/' . $section, ['query' => ['_wrapper_format' => 'drupal_modal']]);
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->responseContains('openDialog');
      $this->assertSession()->responseContains('EditFlow configuration help');
    }
    $draft_vid = $node->getRevisionId();
    $this->drupalGet('node/' . $node->id() . '/discard-unpublished-changes');
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm(['acknowledge' => TRUE, 'revisions[' . $draft_vid . ']' => TRUE], 'Delete selected revisions');
    $this->assertSession()->addressEquals('node/' . $node->id());
    $this->assertNull($this->container->get('entity_type.manager')->getStorage('node')->loadRevision($draft_vid));
    // Required dependencies cannot be removed while EditFlow remains.
    $reasons = $this->container->get('module_installer')->validateUninstall(['content_moderation']);
    $this->assertNotEmpty($reasons);
    $editor = $this->drupalCreateUser(['access content overview']);
    $this->drupalLogin($editor);
    $this->drupalGet('admin/content/editflow-help');
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalGet('admin/config/content/editflow/help/workflow');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Concise notices retain lock results, quiet mode and existing messages.
   */
  public function testLockNotices(): void {
    $this->drupalCreateContentType(['type' => 'article']);
    $this->addEntityTypeAndBundleToWorkflow($this->createEditorialWorkflow(), 'node', 'article');
    $this->config('editflow.settings')->set('workflows', [
      ['id' => 'editorial', 'draft_state' => 'draft', 'publish_state' => 'published'],
    ])->save();
    $this->config('content_lock.settings')->set('verbose', TRUE)->save();
    $node = $this->drupalCreateNode(['type' => 'article', 'moderation_state' => 'draft']);
    $service = $this->container->get('content_lock');
    $owner = $this->drupalCreateUser();
    $other = $this->drupalCreateUser();
    $messages = ['status' => ['Existing notice']];
    $this->assertTrue($service->locking($node, 'edit', (int) $owner->id(), FALSE, NULL, $messages));
    $this->assertSame('Existing notice', $messages['status'][0]);
    $this->assertSame('Locked for your editing. Save and finish editing, or unlock, to release it.', (string) $messages['status'][1]);
    $messages = [];
    $this->assertTrue($service->locking($node, 'edit', (int) $owner->id(), FALSE, NULL, $messages));
    $this->assertSame('Locked for your editing. Save and finish editing, or unlock, to release it.', (string) $messages['status'][0]);
    $messages = [];
    $this->assertTrue($service->locking($node, 'edit', (int) $owner->id(), TRUE, NULL, $messages));
    $this->assertSame([], $messages);
    $this->assertFalse($service->locking($node, 'edit', (int) $other->id(), FALSE, NULL, $messages));
    $this->assertNotEmpty($messages['warning']);
    $this->assertArrayNotHasKey('status', $messages);
  }

}

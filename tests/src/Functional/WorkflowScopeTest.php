<?php

namespace Drupal\Tests\editflow\Functional;

use Drupal\node\Entity\Node;
use Drupal\editflow\Controller\FullPreviewController;
use Drupal\editflow\EditorialSettings;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use Drupal\workflows\Entity\Workflow;

/**
 * Verifies opt-in boundaries with selected, unselected and unmoderated bundles.
 *
 * @group editflow
 */
#[\PHPUnit\Framework\Attributes\Group('editflow')]
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(FALSE)]
class WorkflowScopeTest extends BrowserTestBase {
  use ContentModerationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';
  /**
   * {@inheritdoc}
   */
  protected static $modules = ['editflow', 'diff'];

  /**
   * Verifies workflow and feature boundaries.
   */
  public function testWorkflowAndFeatureBoundaries(): void {
    foreach (['selected', 'other', 'plain'] as $type) {
      $this->drupalCreateContentType(['type' => $type]);
    }
    $workflow = $this->createEditorialWorkflow();
    $this->addEntityTypeAndBundleToWorkflow($workflow, 'node', 'selected');
    $other = Workflow::create([
      'id' => 'other',
      'label' => 'Other workflow',
      'type' => 'content_moderation',
      'type_settings' => $workflow->getTypePlugin()->getConfiguration(),
    ]);
    $other->getTypePlugin()->removeEntityTypeAndBundle('node', 'selected');
    $other->save();
    $this->addEntityTypeAndBundleToWorkflow($other, 'node', 'other');
    $config = $this->config('editflow.settings');
    $config->set('workflows', [['id' => 'editorial', 'draft_state' => 'draft', 'publish_state' => 'published']])->set('editing_bar', TRUE)->set('comparison', TRUE)->save();
    $this->drupalLogin($this->rootUser);
    $nodes = [];
    foreach (['selected', 'other', 'plain'] as $type) {
      $values = ['type' => $type, 'title' => $type . ' title', 'status' => 1];
      if ($type !== 'plain') {
        $values['moderation_state'] = 'published';
      }
      $node = $nodes[$type] = $this->drupalCreateNode($values);
      $enabled = $type === 'selected';
      $this->assertSame($enabled, EditorialSettings::applies($node));
      $this->assertSame($enabled, EditorialSettings::applies($node, 'full_preview'));
      $this->assertSame($enabled ? 'draft' : '', EditorialSettings::state($node, 'draft_state'));
      $preview = clone $node;
      FullPreviewController::create($this->container)->view($preview, 'teaser');
      $this->assertSame($enabled ? 'full' : 'teaser', $preview->preview_view_mode);
      $this->drupalGet($node->toUrl());
      $this->assertSession()->statusCodeEquals(200);
      $html = $this->getSession()->getPage()->getContent();
      $this->assertSame($enabled, str_contains($html, '/editflow/js/editing-bar.js'));
      $this->drupalGet($node->toUrl('edit-form'));
      $this->assertSame($enabled, $this->getSession()->getPage()->has('css', 'form[data-editflow-unsaved-guard]'));
      $left = $node->getRevisionId();
      if ($enabled) {
        $left_selected = $left;
      }
      $node->setNewRevision(TRUE);
      $node->setTitle($type . ' revised');
      $node->save();
      $this->drupalGet('node/' . $node->id() . '/revisions/view/' . $left . '/' . $node->getRevisionId() . '/split_fields');
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSame($enabled, $this->getSession()->getPage()->has('css', '.editflow-comparison-summary'));
      $this->assertSame($enabled, $this->getSession()->getPage()->has('css', '.editflow-comparison-options'));
    }
    // Adding a bundle to the selected workflow needs no module code changes.
    $this->drupalCreateContentType(['type' => 'added_later']);
    $this->addEntityTypeAndBundleToWorkflow($workflow, 'node', 'added_later');
    $new = Node::create(['type' => 'added_later']);
    $this->assertTrue(EditorialSettings::applies($new));
    $this->assertFalse(EditorialSettings::applies(NULL));
    foreach (['full_preview', 'comparison', 'editing_bar', 'draft_notice', 'latest_preview', 'layout_controls'] as $feature) {
      $config->set($feature, FALSE)->save();
      $this->assertFalse(EditorialSettings::applies($nodes['selected'], $feature));
    }
    $preview = clone $nodes['selected'];
    FullPreviewController::create($this->container)->view($preview, 'teaser');
    $this->assertSame('teaser', $preview->preview_view_mode);
    $this->drupalGet($nodes['selected']->toUrl());
    $this->assertSession()->responseNotContains('/editflow/js/editing-bar.js');
    $selected = $nodes['selected'];
    $this->drupalGet('node/' . $selected->id() . '/revisions/view/' . $left_selected . '/' . $selected->getRevisionId() . '/split_fields');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementNotExists('css', '.editflow-comparison-options');
    $this->assertSession()->elementNotExists('css', '.editflow-comparison-summary');
    $config->set('workflows', [])->save();
    $this->assertFalse(EditorialSettings::applies($nodes['selected']));
  }

}

<?php

namespace Drupal\Tests\editflow\Functional;

use Drupal\editflow\EditorialSettings;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use Drupal\views\Entity\View;

/**
 * Verifies metadata access and clean configuration lifecycle on a fresh site.
 *
 * @group editflow
 */
#[\PHPUnit\Framework\Attributes\Group('editflow')]
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(FALSE)]
class AuditSafetyTest extends BrowserTestBase {

  use ContentModerationTestTrait;
  use \Drupal\Tests\ConfigTestTrait;
  use \Drupal\Tests\SchemaCheckTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Cached configuration importer for isolated sync round trips.
   *
   * @var \Drupal\Core\Config\ConfigImporter|null
   */
  protected $configImporter;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['editflow', 'views'];

  /**
   * Checks field output under different accounts using actual Views plugins.
   */
  public function testDraftMetadataAccess(): void {
    $this->drupalCreateContentType(['type' => 'article']);
    $this->addEntityTypeAndBundleToWorkflow($this->createEditorialWorkflow(), 'node', 'article');
    $node = $this->drupalCreateNode(['type' => 'article', 'moderation_state' => 'published']);
    $node->setNewRevision(TRUE);
    $node->setTitle('Private draft');
    $node->moderation_state = 'draft';
    $node->save();
    $view = View::create([
      'id' => 'audit_drafts',
      'label' => 'Audit drafts',
      'base_table' => 'node_field_data',
      'display' => [
        'default' => [
          'id' => 'default',
          'display_plugin' => 'default',
          'display_options' => [
            'fields' => [
              'editflow_draft_status' => [
                'id' => 'editflow_draft_status',
                'table' => 'node_field_data',
                'field' => 'editflow_draft_status',
                'plugin_id' => 'editflow_draft_status',
              ],
              'editflow_draft_updated' => [
                'id' => 'editflow_draft_updated',
                'table' => 'node_field_data',
                'field' => 'editflow_draft_updated',
                'plugin_id' => 'editflow_draft_updated',
              ],
            ],
          ],
        ],
      ],
    ]);
    $view->save();
    $reader = $this->drupalCreateUser(['access content']);
    foreach ([$reader, $this->rootUser, $reader] as $account) {
      $this->container->get('current_user')->setAccount($account);
      $executable = $view->getExecutable();
      $executable->execute();
      $this->assertNotEmpty($executable->result);
      foreach ($executable->field as $field) {
        $build = $field->render($executable->result[0]);
        if ($account->id() === $reader->id()) {
          $this->assertSame('—', $build['#plain_text']);
        }
        else {
          $this->assertNotSame('—', $build['#plain_text'] ?? NULL);
        }
        $this->assertContains('user.permissions', CacheableMetadata::createFromRenderArray($build)->getCacheContexts());
      }
      $executable->destroy();
    }
    $this->drupalLogin($reader);
    foreach (['admin/config/content/editflow/interface', 'admin/config/content/editflow/interface/content-views'] as $path) {
      $this->drupalGet($path);
      $this->assertSession()->statusCodeEquals(403);
    }
  }

  /**
   * Verifies defaults, schema, and uninstall without modifying site workflows.
   */
  public function testConfigurationLifecycle(): void {
    $config = $this->config('editflow.settings');
    $this->assertSame([], $config->get('workflows'));
    $this->assertConfigSchema($this->container->get('config.typed'), 'editflow.settings', $config->get());
    $this->drupalCreateContentType(['type' => 'article']);
    $workflow = $this->createEditorialWorkflow();
    $this->addEntityTypeAndBundleToWorkflow($workflow, 'node', 'article');
    $before = $workflow->toArray();
    $sync = $this->container->get('config.storage.sync');
    $this->copyConfig($this->container->get('config.storage'), $sync);
    $exported = $sync->read('editflow.settings');
    $exported['editing_bar'] = TRUE;
    $exported['workflows'] = [
      ['id' => 'editorial', 'draft_state' => 'removed_state', 'publish_state' => 'published'],
    ];
    $sync->write('editflow.settings', $exported);
    $this->configImporter()->import();
    $this->assertTrue($this->config('editflow.settings')->get('editing_bar'));
    $node = $this->drupalCreateNode(['type' => 'article', 'moderation_state' => 'published']);
    $this->assertSame('', EditorialSettings::state($node, 'draft_state'));
    $tags = EditorialSettings::cacheability($node)->getCacheTags();
    foreach ([
      'config:editflow.settings',
      'config:workflow_list',
      'config:workflows.workflow.editorial',
      'node:' . $node->id(),
    ] as $tag) {
      $this->assertContains($tag, $tags);
    }
    $this->assertSame('published', EditorialSettings::state($node, 'publish_state'));

    $this->container->get('module_installer')->uninstall(['editflow']);
    $this->assertTrue($this->container->get('config.factory')->get('editflow.settings')->isNew());
    $this->assertSame($before, $this->container->get('entity_type.manager')->getStorage('workflow')->loadUnchanged($workflow->id())->toArray());
    $this->container->get('module_installer')->install(['editflow']);
    $this->assertSame([], $this->container->get('config.factory')->get('editflow.settings')->get('workflows'));
  }

}

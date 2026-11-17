<?php

namespace Drupal\Tests\editflow\Functional;

use Drupal\editflow\EditflowSetup;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use Drupal\workflows\Entity\Workflow;

#[\PHPUnit\Framework\Attributes\Group('editflow')]
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(FALSE)]
class ConfigureEditflowTest extends BrowserTestBase {

  protected static $modules = ['editflow'];
  protected $defaultTheme = 'stark';

  protected function createBasicWorkflow(): void {
    if (!Workflow::load('editflow')) {
      $data = \Symfony\Component\Yaml\Yaml::parseFile(__DIR__ . '/../../fixtures/basic-workflow.yml');
      Workflow::create($data)->save();
    }
  }

  public function testStandaloneModuleAndReviewedSetup(): void {
    $this->drupalCreateContentType(['type' => 'article']);
    Role::create(['id' => 'writers', 'label' => 'Writers'])->save();
    Role::create(['id' => 'approvers', 'label' => 'Approvers'])->save();
    $this->createBasicWorkflow();
    $workflow = Workflow::load('editflow');
    $this->assertSame(['draft', 'published'], array_keys($workflow->getTypePlugin()->getStates()));
    $this->assertSame([], $workflow->getTypePlugin()->getBundlesForEntityType('node'));
    $this->assertFalse($this->container->get('module_handler')->moduleExists('sa'));
    $this->assertSame([], $this->config('editflow.settings')->get('workflows'));
    // Creating the test fixture twice leaves the workflow unchanged.
    $this->createBasicWorkflow();
    $admin = $this->drupalCreateUser(['administer workflows', 'administer permissions', 'administer site configuration']);
    $this->drupalLogin($admin);
    $this->drupalGet('/admin/config/content/editflow');
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([
      'workflow' => 'editflow', 'draft_state' => 'draft', 'publish_state' => 'published',
      'enhancements' => TRUE, 'bundles[article]' => TRUE, 'editor_role' => 'writers', 'publisher_role' => 'approvers',
    ], 'Review changes');
    $this->assertSession()->pageTextContains('Permissions to grant to Writers');
    $this->assertSame([], Workflow::load('editflow')->getTypePlugin()->getBundlesForEntityType('node'));
    $this->submitForm([], 'Apply configuration');
    $this->assertSession()->addressEquals('/admin/config/content/editflow?workflow=editflow');
    $this->assertSession()->fieldValueEquals('workflow', 'editflow');
    $this->assertSession()->pageTextContains('Workflow setup saved');
    $this->container->get('entity_type.manager')->getStorage('workflow')->resetCache();
    $this->assertSame(['article'], Workflow::load('editflow')->getTypePlugin()->getBundlesForEntityType('node'));
    $this->container->get('entity_type.manager')->getStorage('user_role')->resetCache();
    $this->assertTrue(Role::load('writers')->hasPermission('use editflow transition save_draft'));
    $this->assertFalse(Role::load('writers')->hasPermission('use editflow transition publish'));
    $this->assertTrue(Role::load('approvers')->hasPermission('use editflow transition publish'));
    // Repeated setup adds nothing and does not revoke existing permissions.
    $choices = ['workflow' => 'editflow', 'draft_state' => 'draft', 'publish_state' => 'published', 'bundles' => ['article'], 'editor_role' => 'writers', 'publisher_role' => 'approvers'];
    $plan = EditflowSetup::plan($choices);
    $this->assertSame([], $plan['add']);
    $this->assertSame([], $plan['grants']);
  }

  public function testSeparatedFormsPreserveConfiguration(): void {
    $this->drupalCreateContentType(['type' => 'article']);
    $this->createBasicWorkflow();
    $workflow = Workflow::load('editflow');
    $other = $workflow->createDuplicate();
    $other->set('id', 'other')->set('label', 'Other workflow')->save();
    $mappings = [
      ['id' => 'editflow', 'draft_state' => 'draft', 'publish_state' => 'published'],
      ['id' => 'other', 'draft_state' => 'draft', 'publish_state' => 'published'],
    ];
    $this->config('editflow.settings')->set('workflows', $mappings)->save();
    $admin = $this->drupalCreateUser([], NULL, TRUE);
    $this->drupalLogin($admin);
    $this->drupalGet('/admin/config/content/editflow/interface');
    $this->assertSession()->fieldNotExists('draft_state');
    $this->assertSession()->fieldNotExists('editor_role');
    $this->submitForm(['revision_footer' => TRUE], 'Save configuration');
    $this->assertSame($mappings, $this->config('editflow.settings')->get('workflows'));
    $this->drupalGet('/admin/config/content/editflow');
    $this->assertSession()->pageTextContains('Workflows on this site');
    $this->assertSession()->pageTextContains('No eligible roles are available.');
    $this->assertSession()->fieldNotExists('editor_role');
    $this->assertSession()->fieldNotExists('publisher_role');
    $this->assertSession()->linkExists('Create a role');
    $this->assertSession()->fieldNotExists('revision_footer');
    $this->submitForm([
      'workflow' => 'editflow', 'enhancements' => FALSE,
      'draft_state' => 'draft', 'publish_state' => 'published',
      'bundles[article]' => TRUE,
    ], 'Review changes');
    $this->assertSession()->pageTextContains('Disabled; moderation assignments and permissions are retained.');
    $this->submitForm([], 'Apply configuration');
    $this->assertSession()->addressEquals('/admin/config/content/editflow?workflow=editflow');
    $this->assertSession()->fieldValueEquals('workflow', 'editflow');
    $this->assertSame([$mappings[1]], $this->config('editflow.settings')->get('workflows'));
    $this->assertTrue($this->config('editflow.settings')->get('revision_footer'));
    $this->container->get('entity_type.manager')->getStorage('workflow')->resetCache();
    $this->assertSame(['article'], Workflow::load('editflow')->getTypePlugin()->getBundlesForEntityType('node'));
    $choices = ['workflow' => 'editflow', 'draft_state' => 'draft', 'publish_state' => 'published', 'unpublish_state' => 'published'];
    $this->expectException(\InvalidArgumentException::class);
    EditflowSetup::plan($choices);
  }

  public function testAjaxWorkflowSwitchLoadsSavedValues(): void {
    $this->drupalCreateContentType(['type' => 'article']);
    $this->createBasicWorkflow();
    $pilot = Workflow::load('editflow');
    $other = $pilot->createDuplicate();
    $other->set('id', 'editorial')->set('label', 'Editorial')->save();
    $settings = $pilot->get('type_settings');
    $settings['states']['offline'] = ['label' => 'Offline', 'weight' => 2, 'published' => FALSE, 'default_revision' => TRUE];
    $pilot->set('type_settings', $settings);
    $pilot->getTypePlugin()->addEntityTypeAndBundle('node', 'article');
    $pilot->save();
    $this->config('editflow.settings')->set('workflows', [
      ['id' => 'editflow', 'draft_state' => 'draft', 'publish_state' => 'published', 'unpublish_state' => 'offline'],
    ])->save();
    $admin = $this->drupalCreateUser(['administer workflows', 'administer permissions', 'administer site configuration']);
    $this->drupalLogin($admin);
    $this->drupalGet('/admin/config/content/editflow');
    $page = $this->getSession()->getPage();
    $post = [
      'form_id' => 'editflow_configure',
      'form_build_id' => $page->find('css', 'input[name="form_build_id"]')->getValue(),
      'form_token' => $page->find('css', 'input[name="form_token"]')->getValue(),
      '_triggering_element_name' => 'workflow', '_drupal_ajax' => '1',
      'workflow' => 'editorial', 'enhancements' => '1',
      'draft_state' => 'draft', 'publish_state' => 'published',
      'unpublish_state' => 'offline', 'bundles' => ['article' => 'article'],
    ];
    foreach (['editorial', 'editflow', 'editorial'] as $target) {
      $post['workflow'] = $target;
      $this->getSession()->getDriver()->getClient()->request('POST', $this->buildUrl('/admin/config/content/editflow', ['query' => ['_wrapper_format' => 'drupal_ajax', 'ajax_form' => '1']]), $post);
      $this->assertSession()->statusCodeEquals(200);
      $commands = json_decode($this->getSession()->getPage()->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
      $html = '';
      foreach ($commands as $command) {
        if ($command['command'] === 'update_build_id') { $post['form_build_id'] = $command['new']; }
        if ($command['command'] === 'insert' && str_contains($command['data'] ?? '', 'editflow-workflow-fields')) { $html = $command['data']; }
      }
      $this->assertNotEmpty($html);
      $dom = new \DOMXPath(\Drupal\Component\Utility\Html::load($html));
      $checked = $dom->query('//input[@name="bundles[article]" and @checked]')->length;
      $enabled = $dom->query('//input[@name="enhancements" and @checked]')->length;
      $this->assertSame($target === 'editflow' ? 1 : 0, $checked, 'Assignment reflects the selected workflow.');
      $this->assertSame($target === 'editflow' ? 1 : 0, $enabled, 'Enhancement status reflects the selected workflow.');
      $this->assertSame($target === 'editflow' ? 1 : 0, $dom->query('//select[@name="unpublish_state"]/option[@value="offline"]')->length);
      $post['unpublish_state'] = $target === 'editflow' ? 'offline' : '';
      $post['bundles'] = $target === 'editflow' ? ['article' => 'article'] : [];
      $post['enhancements'] = $target === 'editflow' ? '1' : '0';
    }
    $this->assertSame(['article'], Workflow::load('editflow')->getTypePlugin()->getBundlesForEntityType('node'));
    $this->assertSame([], Workflow::load('editorial')->getTypePlugin()->getBundlesForEntityType('node'));
  }

  public function testConflictsAndAccess(): void {
    $this->drupalCreateContentType(['type' => 'article']);
    $this->createBasicWorkflow();
    $workflow = Workflow::load('editflow');
    $other = $workflow->createDuplicate();
    $other->set('id', 'existing');
    $other->set('label', 'Existing workflow');
    $other->getTypePlugin()->addEntityTypeAndBundle('node', 'article');
    $other->save();
    $this->drupalGet('/admin/config/content/editflow');
    $this->assertSession()->statusCodeEquals(403);
    $limited = $this->drupalCreateUser(['administer site configuration']);
    $this->drupalLogin($limited);
    $this->drupalGet('/admin/config/content/editflow');
    $this->assertSession()->statusCodeEquals(403);
    $choices = ['workflow' => 'editflow', 'draft_state' => 'draft', 'publish_state' => 'published', 'bundles' => ['article']];
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('already belongs to another workflow');
    EditflowSetup::plan($choices);
  }

  public function testStaleReviewRejected(): void {
    $this->createBasicWorkflow();
    $choices = ['workflow' => 'editflow', 'draft_state' => 'draft', 'publish_state' => 'published', 'bundles' => []];
    $plan = EditflowSetup::plan($choices);
    Role::create(['id' => 'new_role', 'label' => 'New role'])->save();
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Configuration changed since review');
    EditflowSetup::apply($choices, $plan['fingerprint']);
  }

}

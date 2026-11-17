<?php

namespace Drupal\Tests\editflow\Functional;

use Drupal\editflow\ContentViewSetup;
use Drupal\Tests\BrowserTestBase;
use Drupal\views\Entity\View;
use Drupal\views\Views;

/**
 * Ensures removing editorial columns never removes site-owned listings.
 *
 * @group editflow
 */
#[\PHPUnit\Framework\Attributes\Group('editflow')]
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(FALSE)]
class ViewUninstallTest extends BrowserTestBase {

  protected static $modules = ['editflow', 'views'];
  protected $defaultTheme = 'stark';

  public function testUninstallPreservesViews(): void {
    $before = [];
    foreach (['content', 'moderated_content'] as $id) {
      $view = Views::getView($id);
      $this->assertNotNull($view);
      $before[$id] = $view->storage->get('display');
      foreach ($before[$id] as $display_id => $display) {
        if ($display['display_plugin'] === 'page') {
          $this->assertNotEmpty(ContentViewSetup::apply($view, $display_id));
        }
      }
      $view->storage->save();
      $this->assertContains('editflow', $view->storage->getDependencies()['module']);
    }
    $this->container->get('module_installer')->uninstall(['editflow']);
    foreach ($before as $id => $displays) {
      $view = View::load($id);
      $this->assertNotNull($view, 'The view survives uninstall.');
      $this->assertTrue($view->status());
      $this->assertNotContains('editflow', $view->getDependencies()['module']);
      $actual = $view->get('display');
      foreach ($displays as $display_id => $display) {
        // Page overrides may remain, but existing fields and filters must stay.
        $this->assertSame($display['display_options']['filters'] ?? NULL, $actual[$display_id]['display_options']['filters'] ?? NULL);
        foreach ($actual[$display_id]['display_options']['fields'] ?? [] as $field) {
          $this->assertFalse(str_starts_with($field['plugin_id'], 'editflow_'));
        }
      }
    }
  }
}

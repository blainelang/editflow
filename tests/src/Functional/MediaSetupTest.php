<?php

namespace Drupal\Tests\editflow\Functional;

use Drupal\editflow\MediaLibrarySetup;
use Drupal\Tests\BrowserTestBase;
use Drupal\views\Entity\View;

#[\PHPUnit\Framework\Attributes\Group('editflow')]
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(FALSE)]
class MediaSetupTest extends BrowserTestBase {

  protected static $modules = ['editflow', 'media_library'];
  protected $defaultTheme = 'stark';

  public function testTargetedSetupPreservesAccessAndFilters(): void {
    $view = View::load('media_library');
    $before = $view->get('display');
    MediaLibrarySetup::apply();
    $after = View::load('media_library')->get('display');
    $this->assertSame($before['page'], $after['page']);
    foreach (['widget', 'widget_table'] as $id) {
      $this->assertSame($before[$id]['display_options']['filters'], $after[$id]['display_options']['filters']);
      $this->assertSame($before[$id]['display_options']['access'], $after[$id]['display_options']['access']);
      $this->assertSame('DESC', $after[$id]['display_options']['sorts']['changed']['order']);
      $this->assertFalse($after[$id]['display_options']['sorts']['changed']['exposed']);
    }
    $table = $after['widget_table']['display_options'];
    $this->assertSame('changed', $table['style']['options']['default']);
    foreach (['name', 'uid', 'changed'] as $field) {
      $this->assertTrue($table['style']['options']['info'][$field]['sortable']);
    }
    $this->assertSame('users_field_data', $table['fields']['uid']['table']);
    $this->assertSame('value', $table['fields']['uid']['click_sort_column']);
    MediaLibrarySetup::apply();
    $this->assertSame($after, View::load('media_library')->get('display'));
    $executable = View::load('media_library')->getExecutable();
    $executable->setDisplay('widget_table');
    $executable->setArguments(['image']);
    $executable->execute();
    $this->assertIsArray($executable->result);
    $this->assertFalse($this->container->get('module_handler')->moduleExists('sa'));
  }

  public function testPreferencesAndStylingAreIndependent(): void {
    $this->container->get('theme_installer')->install(['claro']);
    $this->config('system.theme')->set('admin', 'claro')->save();
    $this->drupalLogin($this->rootUser);
    $this->config('editflow.settings')->set('comparison', TRUE)->save();
    $this->drupalGet('/admin/config/content/editflow/interface');
    $this->assertSession()->elementExists('css', 'input[name="comparison"][disabled]');
    $this->assertSession()->elementExists('css', 'input[name="preview_sharing"][disabled]');
    $this->assertSession()->elementExists('css', 'input[name="layout_controls"][disabled]');
    $this->assertSession()->elementExists('css', 'input[name="media_library"]:not([disabled])');
    $this->submitForm(['media_library' => TRUE, 'media_library_styling' => FALSE], 'Save configuration');
    $this->assertTrue($this->config('editflow.settings')->get('comparison'));
    $this->assertTrue($this->config('editflow.settings')->get('media_library'));
    $this->assertFalse($this->config('editflow.settings')->get('media_library_styling'));
    $account = $this->container->get('current_user');
    $account->setAccount($this->rootUser);
    $data = $this->container->get('user.data');
    $state = \Drupal\media_library\MediaLibraryState::create('media_library.opener.field_widget', ['image'], 'image', 1);
    foreach ([FALSE, TRUE] as $styling) {
      foreach ([FALSE, TRUE] as $preferences) {
        $this->config('editflow.settings')->set('media_library', $preferences)->set('media_library_styling', $styling)->save();
        $discovery = $this->container->get('library.discovery');
        $discovery->clearCachedDefinitions();
        $library = $discovery->getLibraryByName('media_library', 'ui');
        $this->assertSame($styling, in_array('editflow/media_library', $library['dependencies'], TRUE));
        $claro = $discovery->getLibraryByName('claro', 'media_library.theme');
        $this->assertSame($styling, in_array('editflow/media_library_claro', $claro['dependencies'], TRUE));
        $editor = ['editor' => ['formats' => ['test' => ['editor' => 'ckeditor5', 'editorSettings' => ['config' => ['drupalMedia' => ['libraryURL' => '/media-library']]]]]]];
        editflow_editor_js_settings_alter($editor);
        $this->assertSame($styling, isset($editor['editor']['formats']['test']['editorSettings']['config']['drupalMedia']['dialogSettings']['width']));
        $data->delete('editflow', $account->id(), 'media_library_display');
        $subscriber = $this->container->get('editflow.media_library_preference');
        foreach (['media_library.ui', 'view.media_library.widget', 'media_library.ui'] as $step => $route) {
          $request = new \Symfony\Component\HttpFoundation\Request($state->all() + ['hash' => $state->getHash()]);
          $request->attributes->set('_route', $route);
          $event = new \Symfony\Component\HttpKernel\Event\ControllerEvent($this->container->get('http_kernel'), static function () {}, $request, \Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST);
          $subscriber->onController($event);
          $this->assertSame($preferences ? ($step === 0 ? 'widget_table' : 'widget') : NULL, $request->query->get('views_display_id'));
        }
      }
    }
  }

}

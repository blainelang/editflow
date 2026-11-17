<?php

namespace Drupal\editflow\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Shared editorial interface features and optional media presentation setup.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'editflow_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['editflow.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('editflow.settings');
    $form['#attributes']['data-editflow-interface-settings'] = 'true';
    $form['#attached']['library'][] = 'editflow/settings_warning';
    $form['help'] = \Drupal\editflow\Controller\ConfigurationHelpController::link('interface');
    $form['intro'] = ['#markup' => '<p>' . $this->t('Choose the shared editing features for this site. Manage workflows, state mappings, content types and roles on the Workflow setup tab.') . '</p>'];
    $form['features'] = ['#type' => 'details', '#title' => $this->t('Interface features'), '#open' => TRUE];
    $form['editing_locks'] = ['#type' => 'details', '#title' => $this->t('Editing locks'), '#open' => FALSE];
    $form['editing_locks']['description'] = ['#markup' => '<p>' . $this->t('Helps prevent editors from overwriting each other’s work. Workflow setup can add protection for selected content types. Manage the timeout and advanced settings here; EditFlow preserves your choices.') . '</p>'];
    $form['editing_locks']['advanced_help'] = ['#markup' => '<p>' . $this->t('Separate translation or form-operation locks allow independent editing. Deleting unpublished revisions requires shared locking and is unavailable with those options.') . '</p>'];
    $lock_url = \Drupal\Core\Url::fromRoute('content_lock.settings');
    $form['editing_locks']['configure'] = ['#type' => 'link', '#title' => $this->t('Configure editing locks'), '#url' => $lock_url, '#access' => $lock_url->access()];
    $dependencies = [
      'media_library' => ['media_library', 'Media Library', TRUE],
      'media_library_styling' => ['media_library', 'Media Library', TRUE],
      'preview_sharing' => ['preview_link', 'Preview Link', FALSE],
      'layout_controls' => ['layout_builder', 'Layout Builder', TRUE],
      'comparison' => ['diff', 'Diff', FALSE],
    ];
    // Refresh discovery so newly downloaded module code is reflected here too.
    $available_modules = \Drupal::service('extension.list.module')->reset()->getList();
    $form['features']['help'] = [
      '#markup' => '<p>' . $this->t('Options that require an unavailable module are disabled, with guidance on how to enable them. Saved preferences are retained.') . '</p>',
    ];
    foreach (self::features() as $key => $label) {
      $form['features'][$key] = ['#type' => 'checkbox', '#title' => $label, '#default_value' => $config->get($key)];
      if (isset($dependencies[$key])) {
        [$module, $name, $core] = $dependencies[$key];
        $form['features'][$key]['#disabled'] = !\Drupal::moduleHandler()->moduleExists($module);
        if (\Drupal::moduleHandler()->moduleExists($module)) {
          $description = match ($key) {
            'comparison' => $this->t('Simplifies revision comparisons with a clear summary, collapsible options, easier navigation and improved text legend for full-page visual comparisons.'),
            'layout_controls' => $this->t('Adds a fixed editing footer with draft-saving options, a saved-preview link and clearer discard controls. Shows the page’s workflow status and explains how draft changes affect the live page.'),
            'preview_sharing' => $this->t('Adds a Revoke shared preview access action to the Share preview page, letting authorized editors stop access through an existing link without changing content or drafts.'),
            'media_library' => $this->t('Remembers each signed-in user’s Grid/Table choice and defaults to Table when no preference is saved. Display preferences work independently of styling.'),
            'media_library_styling' => $this->t('Applies EditFlow buttons, spacing, dialog width and opening focus/scroll behaviour. Keep disabled to retain your theme’s native Media Library presentation.'),
            default => '',
          };
        }
        elseif (isset($available_modules[$module])) {
          $description = $this->t('@module: Available but not enabled. Enable it under Extend before using this feature.', ['@module' => $name]);
        }
        elseif ($core) {
          $description = $this->t('@module: Missing. This module is included with Drupal core. Restore the missing core module code, then enable it under Extend.', ['@module' => $name]);
        }
        else {
          $description = $this->t('@module: Missing. Add the drupal/@machine package with Composer, then enable the module under Extend before using this feature.', ['@module' => $name, '@machine' => $module]);
        }
        $form['features'][$key]['#description'] = $description;
      }
    }
    $form['features']['revision_footer']['#description'] = $this->t('Shown when you open Revisions and select a revision’s date/time link. Displays the revision status, who saved it and when, whether it is the live version, and a View live page link when available. This does not change the editing footer. For pending-draft author and date information under Save details while editing, use Show pending-draft author and save time.');
    $form['features']['unpublish']['#description'] = $this->t('Lets authorized editors take published content offline after confirming. Saved drafts and revision history are kept. First choose an Unpublish destination on the Workflow setup tab; the action appears only when the workflow and the editor’s permissions allow it.');
    $form['features']['editing_bar']['#description'] = $this->t('Groups page links such as Edit, Preview and Revisions into a collapsible toolbar when viewing pages. Keep disabled to use the theme’s default local tabs. Custom themes may need styling adjustments.');
    $form['media_setup'] = ['#type' => 'details', '#title' => $this->t('Media Library table setup')];
    $form['media_setup']['media_table'] = ['#type' => 'checkbox', '#title' => $this->t('Apply the standard media table configuration on save'), '#disabled' => !\Drupal::moduleHandler()->moduleExists('media_library'), '#description' => $this->t('Replaces picker sorting with Updated descending, removes the exposed Sort by selector, enables sortable Name/Author/Updated headings, and enables display preferences. Does not enable EditFlow styling. Filters and access rules are retained. Leave unchecked to keep the current View configuration.')];
    $columns_url = \Drupal\Core\Url::fromRoute('editflow.content_view_setup');
    $form['content_columns'] = ['#type' => 'details', '#title' => $this->t('Content list columns (optional)'), '#access' => $columns_url->access()];
    $form['content_columns']['description'] = ['#markup' => '<p>' . $this->t('Add Draft status and Draft updated columns to eligible Content listings, and Editing lock to eligible Moderated content listings. New columns appear before Operations. These optional columns help editors spot pending changes and locks. The setup screen shows missing columns and makes changes only when you select a display and submit.') . '</p>'];
    $form['content_columns']['save_reminder'] = [
      '#type' => 'html_tag', '#tag' => 'div',
      '#attributes' => ['class' => ['editflow-settings-warning'], 'hidden' => TRUE, 'role' => 'status', 'aria-live' => 'polite'],
      '#value' => $this->t('You have unsaved changes. Save configuration before opening the column review. Leaving this page will discard your changes.'),
    ];
    $form['content_columns']['setup'] = ['#type' => 'link', '#title' => $this->t('Review optional content list columns'), '#url' => $columns_url];
    return parent::buildForm($form, $form_state);
  }

  /**
   * Returns the configurable editorial interface features.
   */
  public static function features(): array {
    return [
      'revision_footer' => t('Show revision information footer on revision pages'),
      'media_library' => t('Remember Media Library display preference'),
      'media_library_styling' => t('Apply EditFlow Media Library styling'),
      'preview_sharing' => t('Show shared preview revocation controls'),
      'unpublish' => t('Show an Unpublish action with confirmation'),
      'draft_notice' => t('Show pending-draft author and save time'),
      'layout_controls' => t('Enhance Layout Builder controls'),
      'latest_preview' => t('Show latest-preview controls and permitted publication action'),
      'full_preview' => t('Use full-page previews with simplified controls'),
      'editing_bar' => t('Show page editing tabs in a compact toolbar'),
      'comparison' => t('Enhance revision comparisons'),
    ];
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    if ($form_state->getValue('media_table')) {
      try { \Drupal\editflow\MediaLibrarySetup::view(); }
      catch (\InvalidArgumentException $error) { $form_state->setErrorByName('media_table', $error->getMessage()); }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('editflow.settings');
    foreach (self::features() as $key => $label) {
      // Keep the saved preference when its dependency is unavailable.
      if (!empty($form['features'][$key]['#disabled'])) {
        continue;
      }
      $config->set($key, (bool) $form_state->getValue($key));
    }
    if ($form_state->getValue('media_table')) {
      \Drupal\editflow\MediaLibrarySetup::apply();
      $config->set('media_library', TRUE);
    }
    $config->save();
    \Drupal::service('library.discovery')->clearCachedDefinitions();
    parent::submitForm($form, $form_state);
  }

}

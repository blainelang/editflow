<?php

namespace Drupal\editflow\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\workflows\Entity\Workflow;
use Drupal\editflow\EditflowSetup;

/**
 * Reviewable, repeatable content-type and role onboarding.
 */
final class ConfigureEditflowForm extends FormBase {

  public static function access(\Drupal\Core\Session\AccountInterface $account) {
    return \Drupal\Core\Access\AccessResult::allowedIfHasPermissions($account, ['administer workflows', 'administer permissions', 'administer site configuration'], 'AND');
  }

  public function getFormId(): string { return 'editflow_configure'; }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#cache']['max-age'] = 0;
    $form['help'] = \Drupal\editflow\Controller\ConfigurationHelpController::link('workflow');
    if ($plan = $form_state->get('review')) {
      $form['summary'] = ['#markup' => '<p>' . $this->t('Review these changes. Existing content and revisions are not rewritten. Existing permissions are not removed. Permission grants below apply site-wide where Drupal defines them globally.') . '</p>'];
      $form['workflow'] = ['#type' => 'item', '#title' => $this->t('Workflow'), '#plain_text' => $plan['workflow']];
      $form['types'] = ['#theme' => 'item_list', '#title' => $this->t('New Drupal workflow assignments to apply'), '#items' => $plan['add'] ?: [$this->t('None — existing assignments are retained.')]];
      $lock_names = array_map(static fn($id) => NodeType::load($id)?->label() ?? $id, $plan['lock_add']);
      $form['locks'] = ['#theme' => 'item_list', '#title' => $this->t('Content types to protect with editing locks'), '#items' => $lock_names ?: [$this->t('No changes to editing locks.')]];
      $form['lock_policy'] = ['#markup' => '<p>' . $this->t('Existing lock settings, timeout and advanced options are preserved.') . '</p>'];
      $form['grants'] = ['#type' => 'container'];
      foreach ($plan['grants'] as $role => $permissions) {
        $form['grants'][$role] = ['#theme' => 'item_list', '#title' => $this->t('Permissions to grant to @role', ['@role' => (Role::load($role)?->label() ?? $role)]), '#items' => $permissions];
      }
      $choices = $form_state->get('choices');
      $form['enhancements'] = ['#type' => 'item', '#title' => $this->t('EditFlow enhancements'), '#markup' => !empty($choices['enhancements']) ? $this->t('Enabled') : $this->t('Disabled; moderation assignments and permissions are retained.')];
      $form['unpublish'] = ['#type' => 'item', '#title' => $this->t('Unpublish destination'), '#plain_text' => $choices['unpublish_state'] ?: $this->t('Not configured')];
      $form['mapping'] = ['#type' => 'item', '#title' => $this->t('Editorial state mapping'), '#plain_text' => $choices['draft_state'] . ' → ' . $choices['publish_state']];
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Apply configuration')];
      $form['actions']['back'] = ['#type' => 'submit', '#value' => $this->t('Back'), '#submit' => ['::back'], '#limit_validation_errors' => []];
      return $form;
    }
    $workflows = $owners = [];
    foreach (Workflow::loadMultiple() as $id => $workflow) {
      if ($workflow->getTypePlugin()->getPluginId() !== 'content_moderation') { continue; }
      $workflows[$id] = $workflow->label();
      foreach ($workflow->getTypePlugin()->getBundlesForEntityType('node') as $bundle) { $owners[$bundle] = $workflow->label(); }
    }
    $choices = $form_state->get('choices') ?? [];
    $mapped = \Drupal::config('editflow.settings')->get('workflows') ?? [];
    $form['overview'] = ['#type' => 'details', '#title' => $this->t('Workflows on this site'), '#open' => TRUE];
    $rows = [];
    $types = NodeType::loadMultiple();
    $enabled = array_column($mapped, NULL, 'id');
    foreach ($workflows as $id => $label) {
      $bundles = Workflow::load($id)->getTypePlugin()->getBundlesForEntityType('node');
      $names = array_map(static fn($bundle) => isset($types[$bundle]) ? $types[$bundle]->label() : $bundle, $bundles);
      $rows[] = [$label, $this->workflowContext(Workflow::load($id)), isset($enabled[$id]) ? $this->t('Enabled') : $this->t('Not configured'), $names ? implode(', ', $names) : $this->t('No content types')];
    }
    $form['overview']['list'] = ['#type' => 'table', '#header' => [$this->t('Workflow'), $this->t('Description'), $this->t('EditFlow enhancements'), $this->t('Assigned content types')], '#rows' => $rows, '#empty' => $this->t('No content moderation workflows exist yet.')];
    $form['overview']['context_help'] = ['#markup' => '<p>' . $this->t('Drupal’s Standard installation profile supplies an optional Editorial workflow when Content Moderation is enabled. Other workflows are defined for the site, manually or through configuration. Default matching compares states and transitions, not content-type assignments.') . '</p>'];
    $form['overview']['manage'] = ['#type' => 'link', '#title' => $this->t('Manage workflows and transitions'), '#url' => Url::fromRoute('entity.workflow.collection')];
    $requested = $this->getRequest()->query->all()['workflow'] ?? NULL;
    $requested = is_string($requested) && isset($workflows[$requested]) ? $requested : NULL;
    $default = $form_state->getValue('workflow') ?: ($choices['workflow'] ?? ($requested ?? ($mapped[0]['id'] ?? (isset($workflows['editflow']) ? 'editflow' : array_key_first($workflows)))));
    // A workflow switch must not reuse another workflow's submitted mappings.
    if ($form_state->get('displayed_workflow') && $form_state->get('displayed_workflow') !== $default) {
      $input = $form_state->getUserInput();
      foreach (['draft_state', 'publish_state', 'unpublish_state', 'enhancements', 'bundles', 'protect_content'] as $key) {
        unset($input[$key], $choices[$key]);
        $form_state->unsetValue($key);
      }
      $form_state->setUserInput($input);
      $choices['workflow'] = $default;
      $form_state->set('choices', $choices);
    }
    $form_state->set('displayed_workflow', $default);
    $form['intro'] = ['#markup' => '<p>' . $this->t('This form reads existing Drupal workflows and content-type assignments. Those remain when EditFlow is uninstalled. Choose your EditFlow settings below, then Review changes to see what will be applied. Nothing changes until you choose Apply configuration.') . '</p>'];
    $form['workflow'] = ['#type' => 'select', '#title' => $this->t('Workflow to configure'), '#description' => $this->t('Loads this workflow’s saved settings below. Switching does not save changes.'), '#options' => $workflows, '#default_value' => $default, '#required' => TRUE, '#limit_validation_errors' => [['workflow']], '#ajax' => ['callback' => '::workflowFields', 'wrapper' => 'editflow-workflow-fields']];
    $form['mapping'] = ['#type' => 'fieldset', '#attributes' => ['id' => 'editflow-workflow-fields']];
    $workflow = $default ? Workflow::load($default) : NULL;
    $form['mapping']['#title'] = $this->t('Settings for @workflow', ['@workflow' => $workflow ? $workflow->label() : $this->t('selected workflow')]);
    if ($workflow) {
      $form['mapping']['context'] = ['#type' => 'item', '#title' => $this->t('Description'), '#markup' => $this->workflowContext($workflow)];
    }
    $drafts = $published = [];
    $unpublished = ['' => $this->t('- Not configured -')];
    if ($workflow) {
      foreach ($workflow->getTypePlugin()->getStates() as $id => $state) {
        if (!$state->isPublishedState() && !$state->isDefaultRevisionState()) { $drafts[$id] = $state->label(); }
        if (!$state->isPublishedState() && $state->isDefaultRevisionState()) { $unpublished[$id] = $state->label(); }
        if ($state->isPublishedState() && $state->isDefaultRevisionState()) { $published[$id] = $state->label(); }
      }
    }
    $saved_mapping = array_column($mapped, NULL, 'id')[$default] ?? [];
    $form['mapping']['saved_status'] = [
      '#type' => 'item', '#title' => $this->t('Saved EditFlow setup'),
      '#markup' => isset($enabled[$default])
        ? $this->t('Enhancements are enabled for this workflow. Changes below require Review and Apply.')
        : $this->t('Enhancements are not enabled for this workflow. Draft and Published below are suggested from Drupal’s existing states; they are not saved EditFlow settings.'),
    ];
    $form['mapping']['enhancements'] = ['#type' => 'checkbox', '#title' => $this->t('Enable EditFlow enhancements for this workflow'), '#default_value' => $choices['enhancements'] ?? isset($enabled[$default]), '#description' => $this->t('Unchecking disables EditFlow enhancements for this workflow. It does not remove moderation, content-type assignments or permissions.')];
    $form['mapping']['unpublish_state'] = ['#type' => 'select', '#title' => $this->t('Unpublish destination (optional)'), '#options' => $unpublished, '#default_value' => $choices['unpublish_state'] ?? $saved_mapping['unpublish_state'] ?? '', '#description' => $this->t('Select an unpublished default-revision state to support taking live content offline. Enable the unpublish control on Interface settings separately.')];
    $form['mapping']['draft_state'] = ['#type' => 'select', '#title' => $this->t('Draft state'), '#options' => $drafts, '#default_value' => $choices['draft_state'] ?? $saved_mapping['draft_state'] ?? array_key_first($drafts), '#required' => TRUE];
    $form['mapping']['publish_state'] = ['#type' => 'select', '#title' => $this->t('Published state'), '#options' => $published, '#default_value' => $choices['publish_state'] ?? $saved_mapping['publish_state'] ?? array_key_first($published), '#required' => TRUE];
    $options = [];
    foreach (NodeType::loadMultiple() as $id => $type) {
      $options[$id] = $type->label() . ' — ' . (isset($owners[$id]) ? $this->t('Already assigned in Drupal: @workflow', ['@workflow' => $owners[$id]]) : $this->t('Not assigned — select to add'));
    }
    $assigned = $default && Workflow::load($default) ? Workflow::load($default)->getTypePlugin()->getBundlesForEntityType('node') : [];
    $form['mapping']['bundles'] = ['#type' => 'checkboxes', '#title' => $this->t('Drupal content-type assignments'), '#description' => $this->t('Checked types may already belong to this workflow in Drupal. Select unassigned types to propose additions. Unchecking does not remove existing assignments; use Manage workflows for removals. Types assigned to another workflow cannot be moved here.'), '#options' => $options, '#default_value' => $choices['bundles'] ?? $assigned];
    $protected = \Drupal::config('content_lock.settings')->get('types.node') ?? [];
    $protected_names = array_map(static fn($id) => isset($types[$id]) ? $types[$id]->label() : $id, $protected);
    $form['mapping']['existing_locks'] = [
      '#type' => 'item', '#title' => $this->t('Currently protected by Content Lock on this site'),
      '#plain_text' => in_array('*', $protected, TRUE) ? $this->t('All content types') : ($protected_names ? implode(', ', $protected_names) : $this->t('No content types')),
    ];
    $form['mapping']['protect_content'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Add editing-lock protection for selected content types when applying'),
      // Offer defaults on first setup, not on subsequent edits that could
      // otherwise re-enable protection deliberately removed in Content Lock.
      '#default_value' => $choices['protect_content'] ?? !isset($enabled[$default]),
      '#access' => $this->currentUser()->hasPermission('administer content lock'),
      '#states' => ['visible' => [':input[name="enhancements"]' => ['checked' => TRUE]]],
      '#description' => $this->t('Adds editing locks to selected content types that are not already protected. Click Review changes below to see the additions before applying. Existing lock settings remain unchanged.'),
    ];
    $lock_url = Url::fromRoute('content_lock.settings');
    $form['mapping']['lock_settings'] = ['#type' => 'link', '#title' => $this->t('Configure editing locks'), '#url' => $lock_url, '#access' => $lock_url->access(), '#prefix' => '<p>', '#suffix' => '</p>'];
    $roles = ['' => $this->t('Do not change role permissions')];
    foreach (Role::loadMultiple() as $id => $role) {
      if (!in_array($id, ['anonymous', 'authenticated'], TRUE) && !$role->isAdmin()) { $roles[$id] = $role->label(); }
    }
    $form['roles'] = ['#type' => 'details', '#title' => $this->t('Role permissions (optional)'), '#open' => TRUE];
    $form['roles']['purpose'] = ['#markup' => '<p>' . $this->t('Use this optional step to grant editing or publishing permissions to existing dedicated roles. It does not assign roles to users or define who currently has access. Leave it unchanged when permissions are already configured. All proposed grants are shown before you apply them.') . '</p>'];
    if (count($roles) === 1) {
      $form['roles']['empty'] = ['#markup' => '<p><strong>' . $this->t('No eligible roles are available.') . '</strong> ' . $this->t('Administrator roles already have full access. Anonymous and Authenticated are excluded because they apply to all visitors or all signed-in users. Create a dedicated role, such as Editor or Publisher, to use this step. You can continue configuring the workflow without creating roles.') . '</p>'];
      foreach (['editor_role', 'publisher_role'] as $key) {
        $form['roles'][$key] = ['#type' => 'value', '#value' => ''];
      }
    }
    else {
      foreach (['editor_role' => $this->t('Grant editing permissions to'), 'publisher_role' => $this->t('Grant editing and publishing permissions to')] as $key => $label) {
        $form['roles'][$key] = ['#type' => 'select', '#title' => $label, '#options' => $roles, '#default_value' => $choices[$key] ?? ''];
      }
      $form['roles']['permissions_help'] = ['#markup' => '<p>' . $this->t('Editing grants cover edit/revision access for participating content types and draft transitions. Publishing grants also include publication transitions. Both include global access to unpublished content, shown for review. Existing permissions are retained; choosing an existing publisher as an editor does not remove publishing rights.') . '</p>'];
    }
    $role_url = Url::fromRoute('user.role_add');
    $form['roles']['add'] = ['#type' => 'link', '#title' => $this->t('Create a role'), '#url' => $role_url, '#access' => $role_url->access(), '#prefix' => '<p>', '#suffix' => '</p>'];
    $form['roles']['integration_help'] = ['#markup' => '<p>' . $this->t('Role grants are optional and reviewed before applying. Layout Builder, Content Lock and Preview Link permissions must be configured separately. Existing permissions are never removed by this setup.') . '</p>'];
    foreach (['entity.user_role.collection' => $this->t('Manage roles'), 'user.admin_permissions' => $this->t('Manage permissions')] as $route => $label) {
      $url = Url::fromRoute($route);
      $form['roles'][$route] = ['#type' => 'link', '#title' => $label, '#url' => $url, '#access' => $url->access(), '#prefix' => '<p>', '#suffix' => '</p>'];
    }
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Review changes'), '#disabled' => !$workflows];
    return $form;
  }

  /**
   * Describes configuration without claiming an unrecorded creation history.
   */
  private function workflowContext(Workflow $workflow) {
    if ($workflow->id() !== 'editorial') {
      return $this->t('Site-defined workflow');
    }
    $path = DRUPAL_ROOT . '/core/profiles/standard/config/optional/workflows.workflow.editorial.yml';
    if (is_file($path)) {
      $default = \Drupal\Component\Serialization\Yaml::decode(file_get_contents($path));
      $settings = $workflow->get('type_settings');
      if (($settings['states'] ?? []) == ($default['type_settings']['states'] ?? [])
        && ($settings['transitions'] ?? []) == ($default['type_settings']['transitions'] ?? [])) {
        return $this->t('Matches Drupal Standard profile default');
      }
      return $this->t('Editorial — differs from Drupal Standard profile default');
    }
    return $this->t('Editorial — default comparison unavailable');
  }

  public function workflowFields(array &$form, FormStateInterface $form_state): array {
    return $form['mapping'];
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($form_state->get('review') || ($form_state->getTriggeringElement()['#name'] ?? '') === 'workflow') { return; }
    $choices = array_intersect_key($form_state->getValues(), array_flip(['workflow', 'draft_state', 'publish_state', 'bundles', 'editor_role', 'publisher_role', 'enhancements', 'unpublish_state', 'protect_content']));
    if (!$this->currentUser()->hasPermission('administer content lock')) {
      $choices['protect_content'] = FALSE;
    }
    try {
      $form_state->set('pending_plan', EditflowSetup::plan($choices));
      $form_state->set('choices', $choices);
    }
    catch (\InvalidArgumentException $error) { $form_state->setErrorByName('workflow', $error->getMessage()); }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (!$form_state->get('review')) {
      $form_state->set('review', $form_state->get('pending_plan'))->setRebuild();
      return;
    }
    try {
      if (!empty($form_state->get('review')['lock_add']) && !$this->currentUser()->hasPermission('administer content lock')) {
        throw new \RuntimeException('Permission to configure editing locks is required. Review the setup again.');
      }
      EditflowSetup::apply($form_state->get('choices'), $form_state->get('review')['fingerprint']);
      $this->messenger()->addStatus($this->t('Workflow setup saved.'));
      $form_state->setRedirect('editflow.configure', [], ['query' => ['workflow' => $form_state->get('choices')['workflow']]]);
    }
    catch (\RuntimeException | \InvalidArgumentException $error) {
      $this->messenger()->addError($error->getMessage());
      $this->back($form, $form_state);
    }
  }

  public function back(array &$form, FormStateInterface $form_state): void {
    $form_state->set('review', NULL)->setRebuild();
    $form_state->setUserInput([]);
  }

}

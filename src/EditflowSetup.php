<?php

namespace Drupal\editflow;

use Drupal\content_moderation\ContentModerationState;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\workflows\Entity\Workflow;

/**
 * Additive, reviewed setup. Never moves bundles out of another workflow.
 */
final class EditflowSetup {

  public static function fingerprint(): string {
    $factory = \Drupal::configFactory();
    $data = [];
    foreach (['workflows.workflow.', 'node.type.', 'user.role.'] as $prefix) {
      foreach ($factory->listAll($prefix) as $name) {
        $data[$name] = $factory->get($name)->getRawData();
      }
    }
    $data['views.view.media_library'] = $factory->get('views.view.media_library')->getRawData();
    $data['editflow.settings'] = $factory->get('editflow.settings')->getRawData();
    ksort($data);
    return hash('sha256', serialize($data));
  }

  public static function plan(array $choices): array {
    if (!empty($choices['media_table'])) { MediaLibrarySetup::view(); }
    $workflow = Workflow::load($choices['workflow'] ?? '');
    if (!$workflow || $workflow->getTypePlugin()->getPluginId() !== 'content_moderation') {
      throw new \InvalidArgumentException('Choose an existing Content Moderation workflow. Create a workflow under Configuration → Workflow → Workflows if needed.');
    }
    $plugin = $workflow->getTypePlugin();
    foreach (['draft_state', 'publish_state'] as $key) {
      $id = $choices[$key] ?? '';
      $state = $id && $plugin->hasState($id) ? $plugin->getState($id) : NULL;
      if (!$state instanceof ContentModerationState
        || ($key === 'draft_state' && ($state->isPublishedState() || $state->isDefaultRevisionState()))
        || ($key === 'publish_state' && (!$state->isPublishedState() || !$state->isDefaultRevisionState()))) {
        throw new \InvalidArgumentException('Choose a non-default unpublished Draft state and a published default-revision Published state.');
      }
    }
    if (!empty($choices['unpublish_state'])) {
      $id = $choices['unpublish_state'];
      $state = $plugin->hasState($id) ? $plugin->getState($id) : NULL;
      if (!$state instanceof ContentModerationState || $state->isPublishedState() || !$state->isDefaultRevisionState()) {
        throw new \InvalidArgumentException('The unpublish destination must be an unpublished default-revision state.');
      }
    }
    $bundles = array_values(array_filter($choices['bundles'] ?? []));
    $all_types = NodeType::loadMultiple();
    $owners = [];
    foreach (Workflow::loadMultiple() as $other) {
      if ($other->getTypePlugin()->getPluginId() === 'content_moderation') {
        foreach ($other->getTypePlugin()->getBundlesForEntityType('node') as $bundle) {
          $owners[$bundle] = $other->id();
        }
      }
    }
    $add = [];
    foreach ($bundles as $bundle) {
      if (!isset($all_types[$bundle])) {
        throw new \InvalidArgumentException('A selected content type no longer exists.');
      }
      if (isset($owners[$bundle]) && $owners[$bundle] !== $workflow->id()) {
        throw new \InvalidArgumentException(sprintf('%s already belongs to another workflow. No content types have been reassigned.', $all_types[$bundle]->label()));
      }
      if (!isset($owners[$bundle])) {
        $add[] = $bundle;
      }
    }
    // Include previously assigned bundles in the permission review, even if
    // omitted from submitted checkboxes. Unchecking never removes moderation.
    $bundles = array_values(array_unique(array_merge($bundles, $plugin->getBundlesForEntityType('node'))));
    $grants = [];
    foreach (['editor_role', 'publisher_role'] as $role_key) {
      $role_id = $choices[$role_key] ?? '';
      if (!$role_id) { continue; }
      $role = Role::load($role_id);
      if (!$role || in_array($role_id, ['anonymous', 'authenticated'], TRUE) || $role->isAdmin()) {
        throw new \InvalidArgumentException('Choose a dedicated non-administrator role, not Anonymous or Authenticated.');
      }
      $permissions = ['access content', 'access content overview', 'view latest version', 'view any unpublished content'];
      foreach ($bundles as $bundle) {
        $permissions[] = "edit any $bundle content";
        $permissions[] = "view $bundle revisions";
      }
      foreach ($plugin->getTransitions() as $transition) {
        $to = $transition->to()->id();
        if ($to === $choices['draft_state'] || ($role_key === 'publisher_role' && $to === $choices['publish_state'])) {
          $permissions[] = 'use ' . $workflow->id() . ' transition ' . $transition->id();
        }
      }
      foreach ($permissions as $permission) {
        if (!$role->hasPermission($permission)) {
          $grants[$role_id][] = $permission;
        }
      }
    }
    foreach ($grants as &$permissions) { $permissions = array_values(array_unique($permissions)); }
    return ['workflow' => $workflow->id(), 'add' => $add, 'bundles' => $bundles, 'grants' => $grants, 'fingerprint' => self::fingerprint()];
  }

  public static function apply(array $choices, string $expected): array {
    if (!hash_equals(self::fingerprint(), $expected)) {
      throw new \RuntimeException('Configuration changed since review. Review the setup again before applying.');
    }
    $plan = self::plan($choices);
    $workflow = Workflow::load($plan['workflow']);
    foreach ($plan['add'] as $bundle) {
      $workflow->getTypePlugin()->addEntityTypeAndBundle('node', $bundle);
    }
    if ($plan['add']) { $workflow->save(); }
    foreach ($plan['grants'] as $id => $permissions) {
      $role = Role::load($id);
      foreach ($permissions as $permission) { $role->grantPermission($permission); }
      $role->save();
    }
    if (!empty($choices['media_table'])) { MediaLibrarySetup::apply(); }
    $config = \Drupal::configFactory()->getEditable('editflow.settings');
    if (!empty($choices['media_table'])) { $config->set('media_library', TRUE); }
    $mappings = array_column($config->get('workflows') ?? [], NULL, 'id');
    $mappings[$workflow->id()] = array_merge($mappings[$workflow->id()] ?? [], [
      'id' => $workflow->id(), 'draft_state' => $choices['draft_state'], 'publish_state' => $choices['publish_state'],
    ]);
    if (array_key_exists('unpublish_state', $choices)) {
      $mappings[$workflow->id()]['unpublish_state'] = $choices['unpublish_state'];
    }
    if (array_key_exists('enhancements', $choices) && !$choices['enhancements']) {
      unset($mappings[$workflow->id()]);
    }
    $config->set('workflows', array_values($mappings))->save();
    \Drupal::service('library.discovery')->clearCachedDefinitions();
    return $plan;
  }

}

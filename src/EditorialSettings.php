<?php

namespace Drupal\editflow;

use Drupal\content_moderation\ContentModerationState;
use Drupal\node\NodeInterface;
use Drupal\Core\Cache\CacheableMetadata;

/**
 * Resolves opt-in workflows without assuming role, theme or state names.
 */
final class EditorialSettings {

  /**
   * Describes dependencies of opt-in decisions, including disabled features.
   */
  public static function cacheability($entity): CacheableMetadata {
    $cache = CacheableMetadata::createFromObject(\Drupal::config('editflow.settings'));
    $cache->addCacheTags(['config:workflow_list']);
    if ($entity instanceof NodeInterface) {
      $cache->addCacheableDependency($entity);
      $info = \Drupal::service('content_moderation.moderation_information');
      if ($info->isModeratedEntity($entity)) {
        $cache->addCacheableDependency($info->getWorkflowForEntity($entity));
      }
    }
    return $cache;
  }

  /**
   * Central opt-in boundary for node editorial enhancements.
   *
   * Match the node's actual moderation workflow against site configuration;
   * neither bundle names, roles, nor themes confer eligibility. A feature must
   * also be enabled when supplied. New nodes are supported through their
   * bundle.
   * This is a presentation policy, not an access check: callers retain Drupal's
   * entity, revision, transition, and optional-module access checks.
   */
  public static function applies($entity, ?string $feature = NULL): bool {
    if (!$entity instanceof NodeInterface) {
      return FALSE;
    }
    $config = \Drupal::config('editflow.settings');
    if ($feature && !$config->get($feature)) {
      return FALSE;
    }
    $info = \Drupal::service('content_moderation.moderation_information');
    if (!$info->isModeratedEntity($entity)) {
      return FALSE;
    }
    $id = $info->getWorkflowForEntity($entity)->id();
    foreach ($config->get('workflows') ?? [] as $settings) {
      if ($settings['id'] === $id) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns a configured state only when its workflow semantics remain valid.
   */
  public static function state(NodeInterface $entity, string $key): string {
    if (!self::applies($entity)) {
      return '';
    }
    $workflow = \Drupal::service('content_moderation.moderation_information')->getWorkflowForEntity($entity);
    foreach (\Drupal::config('editflow.settings')->get('workflows') ?? [] as $settings) {
      if ($settings['id'] !== $workflow->id()) {
        continue;
      }
      $id = $settings[$key] ?? '';
      $plugin = $workflow->getTypePlugin();
      if (!$id || !$plugin->hasState($id)) {
        return '';
      }
      $state = $plugin->getState($id);
      if (!$state instanceof ContentModerationState) {
        return '';
      }
      if ($key === 'draft_state') {
        return !$state->isPublishedState() && !$state->isDefaultRevisionState() ? $id : '';
      }
      if ($key === 'unpublish_state') {
        return !$state->isPublishedState() && $state->isDefaultRevisionState() ? $id : '';
      }
      if ($key === 'publish_state') {
        return $state->isPublishedState() && $state->isDefaultRevisionState() ? $id : '';
      }
    }
    return '';
  }

}

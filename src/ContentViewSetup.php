<?php

namespace Drupal\editflow;

use Drupal\views\ViewExecutable;

/**
 * Adds optional columns to individual site-owned content listing displays.
 */
final class ContentViewSetup {

  /**
   * Removes our fields before module dependency cleanup can delete site views.
   */
  public static function removeColumns(): void {
    foreach (\Drupal\views\Entity\View::loadMultiple() as $view) {
      $displays = $view->get('display');
      $changed = FALSE;
      foreach ($displays as &$display) {
        $options = &$display['display_options'];
        $removed = [];
        foreach ($options['fields'] ?? [] as $id => $field) {
          if (str_starts_with($field['plugin_id'] ?? '', 'editflow_')) {
            unset($options['fields'][$id]);
            $removed[$id] = TRUE;
            $changed = TRUE;
          }
        }
        if (!$removed) {
          continue;
        }
        foreach (['columns', 'info'] as $key) {
          if (isset($options['style']['options'][$key])) {
            $options['style']['options'][$key] = array_diff_key($options['style']['options'][$key], $removed);
          }
        }
        foreach ($options['style']['options']['columns'] ?? [] as $id => $column) {
          if (isset($removed[$column])) {
            $options['style']['options']['columns'][$id] = $id;
          }
        }
        if (isset($removed[$options['style']['options']['default'] ?? ''])) {
          $options['style']['options']['default'] = '-1';
        }
      }
      unset($display);
      if ($changed) {
        $view->set('display', $displays)->save();
      }
    }
  }

  /**
   * Returns missing fields, or NULL when this display is not supported.
   */
  public static function missing(ViewExecutable $view, string $display_id): ?array {
    $display = $view->storage->get('display')[$display_id] ?? [];
    if (!in_array($view->storage->id(), ['content', 'moderated_content'], TRUE)
      || ($display['display_plugin'] ?? '') !== 'page'
      || !$view->storage->status()) {
      return NULL;
    }
    $view->setDisplay($display_id);
    $handler = $view->display_handler;
    if (!$handler->isEnabled() || $handler->getOption('group_by') || ($handler->getOption('style')['type'] ?? '') !== 'table') {
      return NULL;
    }
    $table = $view->storage->get('base_table');
    if ($table === 'node_field_data') {
      $wanted = [
        'editflow_draft_status' => (string) t('Draft status'),
        'editflow_draft_updated' => (string) t('Draft updated'),
      ];
    }
    elseif ($table === 'node_field_revision' && \Drupal::moduleHandler()->moduleExists('content_lock')) {
      $wanted = ['editflow_editing_lock' => (string) t('Editing lock')];
    }
    else {
      return NULL;
    }
    foreach ($handler->getOption('fields') ?? [] as $field) {
      unset($wanted[$field['plugin_id'] ?? $field['field'] ?? '']);
    }
    return $wanted;
  }

  /**
   * Updates the executable's config entity in memory; caller explicitly saves.
   */
  public static function apply(ViewExecutable $view, string $display_id): array {
    $missing = self::missing($view, $display_id);
    if (!$missing) {
      return [];
    }
    $handler = $view->display_handler;
    $fields = $handler->getOption('fields') ?? [];
    $style = $handler->getOption('style');
    $added = [];
    foreach ($missing as $plugin => $label) {
      $id = $plugin;
      for ($suffix = 2; isset($fields[$id]); $suffix++) {
        $id = $plugin . '_' . $suffix;
      }
      $fields[$id] = [
        'id' => $id,
        'table' => $view->storage->get('base_table'),
        'field' => $plugin,
        'plugin_id' => $plugin,
        'relationship' => 'none',
        'group_type' => 'group',
        'admin_label' => '',
        'label' => $label,
        'exclude' => FALSE,
      ];
      $added[$id] = $fields[$id];
      $style['options']['columns'][$id] = $id;
      $style['options']['info'][$id] = [
        'sortable' => $plugin === 'editflow_draft_updated',
        'default_sort_order' => 'asc',
        'align' => '',
        'separator' => '',
        'empty_column' => FALSE,
        'responsive' => '',
      ];
    }
    // Keep the site's existing field order, inserting new columns immediately
    // before the first Operations field (whose ID may have been customized).
    $ordered = [];
    foreach (array_diff_key($fields, $added) as $id => $field) {
      if (($field['plugin_id'] ?? '') === 'entity_operations' || ($field['field'] ?? '') === 'operations') {
        $ordered += $added;
      }
      $ordered[$id] = $field;
    }
    $fields = $ordered + $added;
    foreach (['columns', 'info'] as $option) {
      $values = $style['options'][$option] ?? [];
      $style['options'][$option] = array_replace(array_intersect_key($fields, $values), $values);
    }
    // Override only this display, never the master or other inheriting
    // displays.
    $handler->overrideOption('fields', $fields);
    $handler->overrideOption('style', $style);
    return array_values($missing);
  }

}

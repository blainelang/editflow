<?php

namespace Drupal\editflow;

/**
 * Optional, targeted configuration of core's media picker displays.
 */
final class MediaLibrarySetup {

  public static function view() {
    if (!\Drupal::moduleHandler()->moduleExists('media_library')) {
      throw new \InvalidArgumentException('Install Media Library before configuring its table display.');
    }
    $view = \Drupal::entityTypeManager()->getStorage('view')->load('media_library');
    $displays = $view ? $view->get('display') : [];
    if (!isset($displays['widget'], $displays['widget_table']) || ($displays['widget_table']['display_options']['style']['type'] ?? '') !== 'table') {
      throw new \InvalidArgumentException('The standard Media Library widget and widget_table displays are required. Custom displays must be configured manually.');
    }
    foreach (['name', 'uid', 'changed'] as $field) {
      if (!isset($displays['widget_table']['display_options']['fields'][$field])) {
        throw new \InvalidArgumentException('The media table must include Name, Author and Updated fields.');
      }
    }
    return $view;
  }

  public static function apply(): void {
    $view = self::view();
    $displays = $view->get('display');
    foreach (['widget', 'widget_table'] as $id) {
      $options = &$displays[$id]['display_options'];
      $options['defaults']['sorts'] = FALSE;
      $options['sorts'] = ['changed' => [
        'id' => 'changed', 'table' => 'media_field_data', 'field' => 'changed',
        'relationship' => 'none', 'entity_type' => 'media', 'entity_field' => 'changed',
        'plugin_id' => 'date', 'order' => 'DESC', 'exposed' => FALSE, 'granularity' => 'second',
      ]];
      unset($options);
    }
    $options = &$displays['widget_table']['display_options'];
    // Retain inherited relationships before adding the author-name relationship.
    if ($options['defaults']['relationships'] ?? TRUE) {
      $options['relationships'] = $displays['default']['display_options']['relationships'] ?? [];
    }
    $options['defaults']['relationships'] = FALSE;
    $options['relationships']['editflow_media_author'] = [
      'id' => 'uid', 'table' => 'media_field_data', 'field' => 'uid',
      'relationship' => 'none', 'admin_label' => 'Media author', 'plugin_id' => 'standard', 'required' => FALSE,
    ];
    $options['fields']['uid'] = array_replace($options['fields']['uid'], [
      'id' => 'name', 'table' => 'users_field_data', 'field' => 'name',
      'relationship' => 'editflow_media_author', 'entity_type' => 'user', 'entity_field' => 'name',
      'plugin_id' => 'field', 'click_sort_column' => 'value', 'type' => 'string', 'settings' => ['link_to_entity' => FALSE],
    ]);
    foreach (['name', 'uid', 'changed'] as $field) {
      $options['style']['options']['info'][$field]['sortable'] = TRUE;
      $options['style']['options']['info'][$field]['default_sort_order'] = $field === 'changed' ? 'desc' : 'asc';
    }
    $options['style']['options']['default'] = 'changed';
    $view->set('display', $displays)->save();
  }

}

<?php

namespace Drupal\editflow\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\editflow\ContentViewSetup;
use Drupal\views\Views;

/**
 * Explicit, repeatable setup for the standard content administration views.
 */
class ContentViewSetupForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'editflow_content_view_setup';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['intro'] = ['#markup' => '<p>' . $this->t('Add missing editorial columns before Operations in selected page displays of the Content and Moderated content views. If there is no Operations column, they are added at the end. Existing columns, filters, sorting, access and other displays are preserved. Nothing changes until you select a display and submit. Disabled views/displays, non-table displays and aggregated displays are skipped.') . '</p>'];
    $options = [];
    $rows = [];
    foreach (['content', 'moderated_content'] as $id) {
      $view = Views::getView($id);
      if (!$view) {
        $rows[] = [$id, $this->t('View not installed')];
        continue;
      }
      foreach ($view->storage->get('display') as $display_id => $display) {
        if ($display['display_plugin'] !== 'page') {
          continue;
        }
        $missing = ContentViewSetup::missing($view, $display_id);
        $label = $view->storage->label() . ': ' . $display['display_title'] . ' (' . $display_id . ')';
        $rows[] = [
          $label,
          $missing === NULL ? $this->t('Not eligible') : ($missing ? implode(', ', $missing) : $this->t('Already up to date')),
        ];
        if ($missing) {
          $options[$id . ':' . $display_id] = $this->t('@display — add @fields', [
            '@display' => $label,
            '@fields' => implode(', ', $missing),
          ]);
        }
      }
    }
    $form['summary'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Display'),
        $this->t('Missing columns / status'),
      ],
      '#rows' => $rows,
    ];
    $form['displays'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Displays to update'),
      '#options' => $options,
      '#access' => (bool) $options,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add editorial columns'),
      '#disabled' => !$options,
    ];
    $form['#cache']['max-age'] = 0;
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $changed = 0;
    foreach (array_filter($form_state->getValue('displays', [])) as $selection) {
      [$id, $display_id] = explode(':', $selection, 2);
      // Re-read current config and recheck eligibility at submission time.
      $view = Views::getView($id);
      if ($view && ContentViewSetup::apply($view, $display_id)) {
        $view->storage->save();
        $changed++;
      }
    }
    $this->messenger()->addStatus($changed
      ? $this->formatPlural($changed, 'Updated one display. Export the site configuration to deploy this change.', 'Updated @count displays. Export the site configuration to deploy these changes.')
      : $this->t('No changes were needed.'));
    $form_state->setRebuild();
  }

}

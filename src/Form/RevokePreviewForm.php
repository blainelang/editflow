<?php

namespace Drupal\editflow\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\preview_link\Entity\PreviewLinkInterface;

/**
 * Revokes a shared token without deleting its content or creating a new token.
 */
final class RevokePreviewForm extends ConfirmFormBase {

  protected PreviewLinkInterface $previewLink;

  public static function access() {
    return \Drupal\Core\Access\AccessResult::allowedIf((bool) \Drupal::config('editflow.settings')->get('preview_sharing'))->addCacheableDependency(\Drupal::config('editflow.settings'));
  }

  public function getFormId(): string {
    return 'editflow_revoke_preview_form';
  }

  public function getQuestion() {
    return $this->t('Revoke this shared preview link?');
  }

  public function getDescription() {
    return $this->t('The current link will stop working for everyone, including visitors who previously opened it. This revokes access granted by this link to every page listed below. Pages and saved drafts remain unchanged. Other preview links and normal account permissions are unaffected. No replacement link will be created.');
  }

  public function getConfirmText() {
    return $this->t('Revoke shared preview access');
  }

  public function getCancelUrl(): Url {
    // Returning to Share preview would automatically create another token.
    return Url::fromRoute('system.admin_content');
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?PreviewLinkInterface $preview_link = NULL): array {
    $this->previewLink = $preview_link;
    $form = parent::buildForm($form, $form_state);
    $form['affected_pages'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Pages covered by this link'),
      '#items' => array_map(static fn ($entity) => ['#plain_text' => $entity->label()], $preview_link->getEntities()),
      '#weight' => -1,
    ];
    $form['#cache']['max-age'] = 0;
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->previewLink->delete();
    $this->messenger()->addStatus($this->t('Shared preview access revoked. The old link no longer works. Pages and drafts have not been changed.'));
    $form_state->setIgnoreDestination(TRUE);
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}

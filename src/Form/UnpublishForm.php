<?php

namespace Drupal\editflow\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\editflow\Unpublish;

final class UnpublishForm extends ConfirmFormBase {

  protected NodeInterface $node;

  public function getFormId(): string {
    return 'editflow_unpublish_form';
  }

  public function getQuestion() {
    return $this->t('Unpublish “@title”?', ['@title' => $this->node->label()]);
  }

  public function getDescription() {
    return $this->t('This removes the content from public view, in all languages, and revokes shared preview access to it. Content, saved drafts and revision history are kept. Unsaved changes in the editing form will not be saved. Other pages covered by a shared preview link remain accessible.');
  }

  public function getConfirmText() {
    return $this->t('Unpublish content');
  }

  public function getCancelUrl(): Url {
    return $this->node->toUrl('edit-form');
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    $this->node = $node;
    $form = parent::buildForm($form, $form_state);
    $form['expected_revisions'] = [
      '#type' => 'hidden',
      '#default_value' => implode(':', Unpublish::snapshot($node)),
    ];
    $form['#cache']['max-age'] = 0;
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      Unpublish::execute($this->node, $this->currentUser(), explode(':', (string) $form_state->getValue('expected_revisions')));
      $this->messenger()->addStatus($this->t('Content unpublished. Saved drafts and revision history have been retained.'));
      $form_state->setIgnoreDestination(TRUE);
      $form_state->setRedirect('entity.node.edit_form', ['node' => $this->node->id()]);
    }
    catch (\DomainException $error) {
      $this->messenger()->addError($error->getMessage());
      $form_state->setIgnoreDestination(TRUE);
      $form_state->setRedirect('entity.node.edit_form', ['node' => $this->node->id()]);
    }
  }

}

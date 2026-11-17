<?php

namespace Drupal\editflow\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Shared, CSRF-protected confirmation for page-details and layout editing.
 */
final class DiscardDraftForm extends ConfirmFormBase {

  /**
   * The discard dependency.
   *
   * @var \Drupal\editflow\DraftDiscard
   */
  protected $discard;
  /**
   * The node dependency.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected $node;
  /**
   * The dateFormatter dependency.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Session-bound signature generator for the revision snapshot.
   *
   * @var \Drupal\Core\Access\CsrfTokenGenerator
   */
  protected $snapshotSigner;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->discard = $container->get('editflow.draft_discard');
    $instance->dateFormatter = $container->get('date.formatter');
    $instance->snapshotSigner = $container->get('csrf_token');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'editflow_discard_draft';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Delete unpublished revisions of @title?', ['@title' => $this->node->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Select the unpublished revisions to permanently delete, or use the header checkbox to select all. Nothing is selected initially. Published revisions are preserved. If you select every listed revision, pending Layout Builder changes are also cleared and the editing lock is released. If you select only some revisions, unchecked drafts, pending layout changes, and the editing lock are preserved. Unsaved edits in your browser are not saved. Close any other edit tabs; this action cannot erase their browser contents.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Delete selected revisions');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return $this->node->toUrl();
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL) {
    $this->node = $node;
    try {
      $plan = $this->discard->plan($node, $this->currentUser());
    }
    catch (\DomainException $exception) {
      throw new AccessDeniedHttpException($exception->getMessage());
    }
    $form = parent::buildForm($form, $form_state);
    $form['#cache']['max-age'] = 0;
    // Preserve the reviewed snapshot across Form API's uncached POST rebuild.
    // The signature binds it to this node and session; input cannot replace it.
    $form['snapshot'] = ['#type' => 'hidden', '#default_value' => $plan['fingerprint']];
    $form['snapshot_signature'] = [
      '#type' => 'hidden',
      '#default_value' => $this->snapshotSigner->get('discard:' . $node->id() . ':' . $plan['fingerprint']),
    ];
    $form['published'] = ['#markup' => '<p>' . $this->t('Published revision @id will remain live.', ['@id' => $plan['node']->getRevisionId()]) . '</p>'];
    $rows = [];
    foreach ($plan['drafts'] as $revision) {
      $rows[$revision->getRevisionId()] = [
        $revision->getRevisionId(),
        $revision->label(),
        $revision->getRevisionUser()?->getDisplayName() ?? $this->t('Unknown user'),
        $this->dateFormatter->format($revision->getRevisionCreationTime(), 'short'),
      ];
    }
    $form['revisions'] = [
      '#type' => 'tableselect',
      '#header' => [$this->t('Revision'), $this->t('Title'), $this->t('Saved by'), $this->t('Saved')],
      '#options' => $rows,
      '#default_value' => [],
      '#required' => TRUE,
      '#title' => $this->t('Unpublished revisions to delete'),
    ];
    $form['lock'] = ['#markup' => '<p>' . ($plan['lock'] ? $this->t('The editing lock owned by @name will be released only if you select every listed revision.', ['@name' => $plan['lock']->name ?? $this->t('Unknown user')]) : $this->t('There is no editing lock.')) . '</p>'];
    $form['acknowledge'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I understand that selected revisions will be permanently deleted. Selecting all revisions also discards pending layout changes and releases the editing lock.'),
      '#required' => TRUE,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $snapshot = (string) $form_state->getValue('snapshot');
    $signature = (string) $form_state->getValue('snapshot_signature');
    if (!isset($form_state->getUserInput()['snapshot'], $form_state->getUserInput()['snapshot_signature']) || !$this->snapshotSigner->validate($signature, 'discard:' . $this->node->id() . ':' . $snapshot)) {
      $form_state->setErrorByName('acknowledge', $this->t('The confirmation expired or is invalid. Reopen it and review the revisions again.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    try {
      $selected = array_values(array_filter($form_state->getValue('revisions', [])));
      $all = count($selected) === count($form['revisions']['#options']);
      $count = $this->discard->discard($this->node, $this->currentUser(), $form_state->getValue('snapshot'), $selected);
      $this->getLogger('editflow')->notice('User @uid discarded @count unpublished revisions of node @nid.', [
        '@uid' => $this->currentUser()->id(),
        '@count' => $count,
        '@nid' => $this->node->id(),
      ]);
      $this->messenger()->addStatus($this->formatPlural($count, 'Deleted 1 unpublished revision. The published page is unchanged.', 'Deleted @count unpublished revisions. The published page is unchanged.'));
      $this->messenger()->addStatus($all ? $this->t('All unpublished revisions were deleted. Pending layout changes were cleared and the editing lock was released.') : $this->t('Unchecked drafts, pending layout changes, and the editing lock were preserved.'));
      $form_state->setIgnoreDestination(TRUE);
      $form_state->setRedirectUrl($this->node->toUrl());
    }
    catch (\DomainException $exception) {
      $this->messenger()->addError($exception->getMessage());
      $form_state->setIgnoreDestination(TRUE);
      $form_state->setRedirectUrl(Url::fromRoute('editflow.discard_draft', ['node' => $this->node->id()]));
    }
  }

}

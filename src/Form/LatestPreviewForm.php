<?php

namespace Drupal\editflow\Form;

use Drupal\content_moderation\ContentModerationState;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\editflow\EditorialSettings;
use Drupal\content_moderation\Form\EntityModerationForm;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Saved-preview controls, using core moderation's revision-saving handler.
 */
class LatestPreviewForm extends EntityModerationForm {

  /**
   * Injected integration dependency.
   *
   * @var \Drupal\content_lock\ContentLock\ContentLock|null
   */
  protected $lockService;

  /**
   * Entity storage manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Injected integration dependency.
   *
   * @var \Drupal\Core\Routing\RouteProviderInterface
   */
  protected $routeProvider;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->lockService = $container->has('content_lock') ? $container->get('content_lock') : NULL;
    $instance->routeProvider = $container->get('router.route_provider');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'editflow_latest_preview_form';
  }

  /**
   * Checks entity access and an allowed transition to the configured state.
   */
  public static function canPublish(NodeInterface $node, AccountInterface $account): bool {
    if (!$node->access('view', $account) || !$node->access('update', $account) || $node->isPublished()) {
      return FALSE;
    }
    if (!EditorialSettings::applies($node, 'latest_preview')) {
      return FALSE;
    }
    foreach (\Drupal::service('content_moderation.state_transition_validation')->getValidTransitions($node, $account) as $transition) {
      if ($transition->to() instanceof ContentModerationState && $transition->to()->id() === EditorialSettings::state($node, 'publish_state') && $transition->to()->isPublishedState()) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ContentEntityInterface $entity = NULL) {
    if (!$entity instanceof NodeInterface) {
      return $form;
    }
    $form['#attributes']['class'][] = 'editflow-latest-footer';
    $form['#attributes']['aria-label'] = $this->t('Saved preview controls');
    $form['#attached']['library'][] = 'editflow/latest_preview';
    $form['#cache']['max-age'] = 0;
    $form_state->set('entity', $entity);
    $form['status'] = [
      '#weight' => -100,
      '#type' => 'container',
      '#attributes' => ['class' => ['editflow-latest-status']],
      'title' => ['#markup' => '<strong>' . $this->t('Preview — latest saved version') . '</strong>'],
      'help' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $this->t('Unsaved Layout Builder changes are not included.'),
      ],
    ];
    $lock = $this->lockService !== NULL ? $this->lockService->fetchLock($entity) : FALSE;
    if ($lock) {
      $days = max(0, (int) floor(($this->time->getCurrentTime() - (int) $lock->timestamp) / 86400));
      $age = $days === 0 ? $this->t('less than 1 day') : $this->formatPlural($days, '1 day', '@count days');
      $form['status']['lock'] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#attributes' => ['class' => ['editflow-lock-holder']],
        '#value' => $this->t('Editing lock: @name · Lock age: @age', [
          '@name' => $lock->name ?: $this->t('Unknown user'),
          '@age' => $age,
        ]),
      ];
    }
    foreach ([
      'entity.node.edit_form' => $this->t('Edit Page Details'),
      'layout_builder.overrides.node.view' => $this->t('Edit Layout'),
    ] as $route => $label) {
      if (!$this->routeProvider->getRoutesByNames([$route])) {
        continue;
      }
      $url = Url::fromRoute($route, ['node' => $entity->id()]);
      if ($url->access()) {
        $form[$route] = [
          '#type' => 'link',
          '#title' => $label,
          '#url' => $url,
          '#attributes' => [
            'class' => [
              'editflow-latest-edit',
            ],
          ],
        ];
      }
    }
    if ($lock) {
      $release = $this->lockService->unlockButton($entity, $lock->form_op ?? NULL, '/node/' . $entity->id() . '/latest');
      $release['#access'] = $release['#url']->access();
      $release['#title'] = $this->t('Release editing lock');
      $release['#attributes']['class'] = ['editflow-latest-edit'];
      $release['#attributes']['title'] = $this->t('Opens confirmation. Unsaved layout changes will not be saved.');
      $release['#weight'] = 0;
      $form['release_lock'] = $release;
    }
    if (static::canPublish($entity, $this->currentUser())) {
      if ($lock && (int) $lock->uid !== (int) $this->currentUser()->id()) {
        $form['locked'] = ['#markup' => $this->t('Publishing is unavailable while @name holds the editing lock.', ['@name' => $lock->name ?: $this->t('another editor')])];
        return $form;
      }
      $form['expected_revision'] = ['#type' => 'hidden', '#default_value' => $entity->getRevisionId()];
      $form['confirm_publish'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('I confirm this saved version should become public.'),
        '#required' => TRUE,
        '#wrapper_attributes' => ['class' => ['editflow-publish-confirmation']],
      ];
      $form['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Publish this version'),
        '#attributes' => [
          'class' => [
            'editflow-publish',
          ],
          'data-publish-confirm' => $this->t('Publish “@title”? This will make the saved version you are previewing live. Unsaved layout changes will not be included.', [
            '@title' => $entity->label(),
          ]),
        ],
      ];
    }
    else {
      $form['notice'] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $entity->isPublished() ? $this->t('This version is already published.') : $this->t('A publisher must make this draft live.'),
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $original = $form_state->get('entity');
    $storage = $this->entityTypeManager->getStorage('node');
    $storage->resetCache([$original->id()]);
    $latest_id = $storage->getLatestRevisionId($original->id());
    $expected_id = $form_state->getValue('expected_revision');
    if (!$expected_id || (string) $latest_id !== (string) $expected_id) {
      $form_state->setErrorByName('expected_revision', $this->t('A newer revision exists. Reload Preview - Latest Version and review it before publishing.'));
      return;
    }
    $latest = $storage->loadRevision($latest_id);
    // Revision storage returns the default language; retain the reviewed one.
    $langcode = $original->language()->getId();
    $latest = $latest instanceof NodeInterface && $latest->hasTranslation($langcode)
      ? $latest->getTranslation($langcode) : NULL;
    if (!$latest instanceof NodeInterface || !static::canPublish($latest, $this->currentUser())) {
      $form_state->setErrorByName('submit', $this->t('You no longer have permission to publish this draft, or it is already published.'));
      return;
    }
    $lock = $this->lockService !== NULL ? $this->lockService->fetchLock($latest) : FALSE;
    if ($lock && (int) $lock->uid !== (int) $this->currentUser()->id()) {
      $form_state->setErrorByName('submit', $this->t('Another editor holds the editing lock. Ask them to finish before publishing.'));
    }
    if (!$form_state->getValue('confirm_publish')) {
      $form_state->setErrorByName('confirm_publish', $this->t('Please confirm publication.'));
    }
    $form_state->set('entity', $latest);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Recheck immediately before core creates and saves the published revision.
    // Do not set Form API validation errors after validation has completed.
    $storage = $this->entityTypeManager->getStorage('node');
    $entity = $form_state->get('entity');
    $storage->resetCache([$entity->id()]);
    $latest_id = $storage->getLatestRevisionId($entity->id());
    $latest = $latest_id ? $storage->loadRevision($latest_id) : NULL;
    $langcode = $entity->language()->getId();
    $latest = $latest instanceof NodeInterface && $latest->hasTranslation($langcode)
      ? $latest->getTranslation($langcode) : NULL;
    $lock = $latest && $this->lockService !== NULL ? $this->lockService->fetchLock($latest) : FALSE;
    if (!$latest instanceof NodeInterface || (string) $latest_id !== (string) $form_state->getValue('expected_revision') || !static::canPublish($latest, $this->currentUser()) || !$form_state->getValue('confirm_publish') || ($lock && (int) $lock->uid !== (int) $this->currentUser()->id())) {
      $this->messenger()->addError($this->t('The revision, permissions or editing lock changed. Reload the latest preview before publishing.'));
      $form_state->setRebuild();
      return;
    }
    $form_state->set('entity', $latest);
    $form_state->setValue('new_state', EditorialSettings::state($latest, 'publish_state'));
    $form_state->setValue('revision_log', $this->t('Published after reviewing the latest saved preview.'));
    parent::submitForm($form, $form_state);
  }

}

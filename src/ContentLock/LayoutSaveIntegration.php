<?php

namespace Drupal\editflow\ContentLock;

use Drupal\Core\Form\FormStateInterface;

/**
 * Adapts Layout Builder's continue action to Content Lock 3.0.0.
 *
 * Content Lock appends FormAlter::formSubmit() to the normal save action to
 * release the lock. There is no public per-button keep-lock option. Remove
 * only that callback for Continue; keep Drupal's validation and saving intact.
 *
 * Call after all form alters so callbacks added by later modules are retained.
 * When Content Lock changes its release handler, update this adapter and run
 * the functional tests (including the release-event assertion, which detects
 * release/reacquire cycles hidden by the redirect back to Layout).
 */
final class LayoutSaveIntegration {

  /**
   * Completes Continue's submit pipeline from the final normal-save pipeline.
   */
  public static function configureContinue(array &$form, ?FormStateInterface $form_state = NULL): void {
    if (!isset($form['actions']['submit']['#submit'], $form['actions']['editorial_continue'])) {
      return;
    }
    $handlers = array_filter($form['actions']['submit']['#submit'], static function ($handler): bool {
      if ($handler === 'editflow_finish_editing_submit') {
        return FALSE;
      }
      return !(is_array($handler)
        && isset($handler[0], $handler[1])
        && is_object($handler[0])
        && is_a($handler[0], 'Drupal\content_lock\Hook\FormAlter')
        && $handler[1] === 'formSubmit');
    });
    $form['actions']['editorial_continue']['#submit'] = array_values($handlers);
    $form['actions']['editorial_continue']['#submit'][] = 'editflow_continue_editing_submit';
    // Form API snapshots the clicked button before the root #after_build.
    // Keep that snapshot in sync before it chooses validation/submit handlers.
    if (($form_state?->getTriggeringElement()['#name'] ?? NULL) === 'editorial_continue') {
      $form_state->setTriggeringElement($form['actions']['editorial_continue']);
    }
  }

}

/**
 * @file
 * Provides accessible close controls and initial focus for workflow help.
 *
 * Loaded through editflow/workflow_help by WorkflowHelpController for
 * help content/links, and by editflow_preprocess_html() when an eligible
 * frontend editing bar includes an accessible workflow-help link.
 *
 * Drupal behavior attachment labels close buttons in .editflow-help-dialog.
 * A page-lifetime jQuery dialog:aftercreate listener positions matching dialogs
 * at the top and focuses their first help heading without scrolling. Other
 * dialogs are ignored by the focus/scroll handling. Standalone help content
 * does not trigger that dialog handling. once prevents repeated button labeling.
 * The listener is registered once when this library loads, not on every attach.
 */
(function (Drupal, once, $) {
  'use strict';
  const labelClose = () => {
      // The frontend theme strips Drupal's default close-button label/icon.
      once('editflow-help-close', '.editflow-help-dialog .ui-dialog-titlebar-close').forEach((button) => {
        button.setAttribute('aria-label', Drupal.t('Close workflow help'));
        button.textContent = '×';
      });
  };
  $(window).on('dialog:aftercreate.editflowHelp', (event, dialog, $element) => {
    labelClose();
    const content = $element ? $element[0] : event.target;
    if (!(content instanceof Element) || !content.closest('.editflow-help-dialog')) {
      return;
    }
    // Dialog autofocus otherwise selects the first setup link at the bottom,
    // scrolling past the guidance. Start keyboard reading at the first heading.
    const heading = content.querySelector('.editflow-workflow-help h2');
    if (heading) {
      heading.setAttribute('tabindex', '-1');
      heading.setAttribute('autofocus', '');
      heading.focus({ preventScroll: true });
    }
    content.scrollTop = 0;
  });
  Drupal.behaviors.editflowWorkflowHelp = { attach: labelClose };
})(Drupal, once, jQuery);

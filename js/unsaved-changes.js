/**
 * @file
 * Warns before leaving an ordinary node form with unsaved changes.
 *
 * Active on node add/edit forms whose workflow is enabled for EditFlow.
 * editflow_form_node_form_alter() attaches editflow/unsaved_changes
 * and supplies form[data-editflow-unsaved-guard], including an initial dirty flag
 * after preview or submitted input. This does not target Layout Builder forms.
 *
 * Initial/AJAX attachment tracks input, change, and Drupal formUpdated events
 * (including rich-text edits). Dirty state survives replacement of a form with
 * the same ID. Deliberate Save/Preview submissions suppress the leaving warning;
 * canceled submissions restore it. Browsers control the warning wording.
 *
 * Window listeners last for the page lifetime. beforeunload considers only
 * connected forms; pageshow clears submission state after browser Back/Forward.
 * Unlike the fixed controls, this script does not use the shared UI helper or
 * detach cleanup: its state map deliberately survives AJAX form replacement.
 */
(function (Drupal, once, $) {
  'use strict';

  // Keep dirty state across AJAX replacements of the same edit form.
  const states = new Map();
  const ignore = new Set(['form_id', 'form_build_id', 'form_token']);

  Drupal.behaviors.editflowUnsavedChanges = {
    attach(context) {
      const forms = once('editflow-unsaved-guard', 'form[data-editflow-unsaved-guard]', context);
      // AJAX can pass the replacement form itself as the behavior context.
      if (context.matches?.('form[data-editflow-unsaved-guard]')) {
        forms.push(...once('editflow-unsaved-guard', context));
      }
      forms.forEach((form) => {
        const key = form.id || form;
        const state = states.get(key) || { dirty: false };
        state.form = form;
        state.submitting = false;
        state.dirty ||= form.dataset.editflowUnsavedInitial === 'true';
        states.set(key, state);

        const changed = (event) => {
          const field = event.target;
          if (field.name && !ignore.has(field.name) && !field.disabled) {
            state.dirty = true;
            state.submitting = false;
          }
        };
        form.addEventListener('input', changed);
        form.addEventListener('change', changed);
        // Drupal emits this for rich-text editor changes, too.
        $(form).on('formUpdated.editflowUnsaved', changed);
        form.addEventListener('submit', (event) => {
          // Save and form Preview deliberately submit the entered values.
          state.submitting = true;
          // AJAX or another handler may cancel native submission.
          queueMicrotask(() => {
            if (event.defaultPrevented) state.submitting = false;
          });
        });
      });
    },
  };

  window.addEventListener('beforeunload', (event) => {
    if (Array.from(states.values()).some((state) => state.form.isConnected && state.dirty && !state.submitting)) {
      // Browsers supply the wording and require prior user interaction.
      event.preventDefault();
      event.returnValue = '';
    }
  });
  window.addEventListener('pageshow', () => {
    // Browser Back may restore the edit form from the back/forward cache.
    states.forEach((state) => { state.submitting = false; });
  });
})(Drupal, once, jQuery);

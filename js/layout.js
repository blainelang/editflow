/**
 * @file
 * Organizes shared node editing controls and measures their fixed footer.
 *
 * Active on node Layout Builder edit forms (normally /node/{node}/layout) when
 * the node's workflow enables layout_controls. The module's
 * editflow_form_node_layout_builder_form_alter() attaches
 * editflow/layout and marks the form .editflow-layout-form. Both
 * The shared discard-drafts confirmation link moves into Advanced editing
 * options beside the template reset; it never submits or saves this form.
 * .editflow-summary and .editflow-layout-actions must be present.
 *
 * Runs on initial and AJAX behavior attachment. Moves existing server-built
 * controls into groups, adjusts save-button labels to the selected state, and
 * delegates footer measurement to the shared UI helper. Saving, validation,
 * permissions, and lock handling remain in the server-side form handlers.
 * Unload reverses tracked DOM moves, removes generated wrappers and the change
 * listener, and clears once markers so the form can be attached again.
 */
(function (Drupal, once) {
  'use strict';
  Drupal.behaviors.editflowLayout = {
    attach(context) {
      const ui = Drupal.editflowUI;
      once('editflow-layout', ui.find(context, '.editflow-layout-form')).forEach((form) => {
        const summary = form.querySelector('.editflow-summary');
        const actions = form.querySelector('.editflow-layout-actions');
        if (!actions || !summary) { once.remove('editflow-layout', form); return; }
        // Mark original positions so nested moves can be undone in reverse order.
        const moves = [];
        const move = (element, parent, prepend = false) => {
          const marker = document.createComment('editorial-original-position');
          element.before(marker);
          moves.push(() => marker.replaceWith(element));
          if (prepend) parent.prepend(element); else parent.append(element);
        };
        const created = [];
        let update;
        const toggle = actions.querySelector('#layout-builder-content-preview');
        if (toggle?.closest('.js-show')) move(toggle.closest('.js-show'), summary);
        // Moderation stays outside the footer (hidden for draft-only saves).
        const select = form.querySelector('select[name="moderation_state[0][state]"]');
        const save = actions.querySelector('[data-editorial-save]');
        const keepEditing = actions.querySelector('[data-editorial-continue]');
        if (select && save) {
          update = () => {
            save.value = select.value === form.dataset.draftState ? Drupal.t('Save draft and finish editing') : select.value === form.dataset.publishState ? Drupal.t('Publish and finish editing') : Drupal.t('Save and finish editing');
            if (keepEditing) keepEditing.value = select.value === form.dataset.draftState ? Drupal.t('Save draft and continue editing') : select.value === form.dataset.publishState ? Drupal.t('Publish and continue editing') : Drupal.t('Save and continue editing');
          };
          select.addEventListener('change', update);
          update();
        }
        // Keep the administrative template reset out of everyday footer actions.
        const reset = actions.querySelector('[data-drupal-selector="edit-revert"]');
        const discardDrafts = actions.querySelector('.editflow-discard-drafts');
        const extraActions = Array.from(actions.querySelectorAll('[data-drupal-selector="edit-preview"], [data-drupal-selector="edit-delete"]'));
        const workflow = form.querySelector('.editflow-layout-workflow');
        if (reset || discardDrafts || extraActions.length || workflow) {
          const advanced = document.createElement('details');
          advanced.className = 'editflow-lock';
          const heading = document.createElement('summary');
          heading.textContent = Drupal.t('Advanced editing options');
          advanced.append(heading);
          if (workflow) move(workflow, advanced);
          const advancedActions = document.createElement('div');
          advancedActions.className = 'editflow-advanced-actions';
          advanced.append(advancedActions);
          if (reset) move(reset, advancedActions);
          extraActions.forEach((action) => move(action, advancedActions));
          if (discardDrafts) move(discardDrafts, advancedActions);
          created.push(advanced);
          summary.append(advanced);
        }
        const status = actions.querySelector('.editflow-layout-status');
        const controls = document.createElement('div');
        controls.className = 'editflow-layout-controls';
        created.push(controls);
        Array.from(actions.children).forEach((child) => {
          if (child !== status && child.nodeType === 1) move(child, controls);
        });
        actions.append(controls);
        const preview = controls.querySelector('.editflow-saved-preview');
        const firstSave = keepEditing || save;
        if (preview && firstSave && firstSave.parentNode === controls) {
          controls.insertBefore(preview, firstSave);
        }
        const choices = document.createElement('div');
        choices.className = 'editflow-layout-choices';

        if (preview) choices.append(preview);
        const buttons = document.createElement('div');
        buttons.className = 'editflow-layout-buttons';
        Array.from(controls.children).forEach((child) => buttons.append(child));
        controls.append(choices, buttons);
        const previewNote = status?.querySelector('.editflow-preview-note');
        if (previewNote && preview) move(previewNote, choices);
        const details = actions.querySelector('.editflow-footer-details');
        const detailsHeading = details?.querySelector('summary');
        let closeDetails;
        let escapeDetails;
        let focusDetails;
        if (details && detailsHeading) {
          const close = document.createElement('button');
          close.type = 'button';
          close.className = 'editflow-details-close';
          close.textContent = Drupal.t('Close');
          close.setAttribute('aria-label', Drupal.t('Close save and lock details'));
          closeDetails = () => {
            details.open = false;
            detailsHeading.focus();
          };
          escapeDetails = (event) => {
            if (event.key === 'Escape' && !event.defaultPrevented && details.open) {
              event.preventDefault();
              closeDetails();
            }
          };
          focusDetails = () => {
            if (details.open) close.focus();
          };
          details.classList.add('editflow-dismissible');
          details.addEventListener('toggle', focusDetails);
          close.addEventListener('click', closeDetails);
          detailsHeading.after(close);
          created.push(close);
          document.addEventListener('keydown', escapeDetails);
        }
        ui.mount(form, 'layout', {
          elements: [actions, summary],
          bodyClass: 'editflow-layout', properties: ['--editflow-footer-height'],
          measure: () => ui.footer(actions, 'editflow-layout', '--editflow-footer-height'),
          cleanup() {
            if (update) select.removeEventListener('change', update);
            if (escapeDetails) document.removeEventListener('keydown', escapeDetails);
            if (focusDetails) {
              details.removeEventListener('toggle', focusDetails);
              details.classList.remove('editflow-dismissible');
            }
            actions.classList.remove('editflow-fixed-footer');
            moves.reverse().forEach((restore) => restore());
            created.forEach((element) => element.remove());
            once.remove('editflow-layout', form);
          },
        });
      });
    },
    detach(context, settings, trigger) {
      if (trigger === 'unload') Drupal.editflowUI.detach(context, 'layout');
    }
  };
})(Drupal, once);

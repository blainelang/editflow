/**
 * @file
 * Enhances the latest saved revision's footer and publication confirmation.
 *
 * Active on /node/{node}/latest (entity.node.latest_version) when the node's
 * workflow enables latest_preview. editflow_preprocess_page() builds
 * LatestPreviewForm, which attaches editflow/latest_preview and supplies
 * .editflow-latest-footer. This is not the unsaved node Preview screen.
 *
 * Runs on initial and AJAX behavior attachment. A measured footer becomes
 * fixed with matching body space. If publication controls exist, a browser
 * confirmation replaces the visible required checkbox; PHP still validates
 * confirmation, permissions, the latest revision, and editing locks.
 * Without JS the footer and checkbox remain usable in normal document flow.
 * Unload restores the checkbox, removes the submit handler, and clears fixed
 * positioning; the shared helper disposes observers and spacing state.
 */
(function (Drupal, once) {
  'use strict';
  Drupal.behaviors.editflowLatestPreview = {
    attach(context) {
      const ui = Drupal.editflowUI;
      once('editflow-latest-footer', ui.find(context, '.editflow-latest-footer')).forEach((form) => {
        const button = form.querySelector('[data-publish-confirm]');
        const checkbox = form.querySelector('[name="confirm_publish"]');
        const wrapper = checkbox?.closest('.editflow-publish-confirmation');
        // Preserve the no-JS form state for detach and later reattachment.
        const required = checkbox?.required;
        const hidden = wrapper?.hidden;
        const confirm = (event) => {
          if (event.submitter && event.submitter !== button) return;
          checkbox.checked = window.confirm(button.dataset.publishConfirm);
          if (!checkbox.checked) event.preventDefault();
        };
        if (button && checkbox && wrapper) {
          // Keep the server-validated checkbox usable if JS is unavailable.
          form.addEventListener('submit', confirm);
          checkbox.required = false;
          wrapper.hidden = true;
        }
        ui.mount(form, 'latest', {
          bodyClass: 'editflow-latest-preview', properties: ['--editflow-latest-footer-height'],
          measure: () => ui.footer(form, 'editflow-latest-preview', '--editflow-latest-footer-height'),
          cleanup() {
            form.removeEventListener('submit', confirm);
            if (checkbox && wrapper) {
              checkbox.required = required;
              checkbox.checked = false;
              wrapper.hidden = hidden;
            }
            form.classList.remove('editflow-fixed-footer');
            once.remove('editflow-latest-footer', form);
          },
        });
      });
    },
    detach(context, settings, trigger) {
      if (trigger === 'unload') Drupal.editflowUI.detach(context, 'latest');
    },
  };
})(Drupal, once);

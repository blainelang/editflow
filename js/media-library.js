(function (Drupal, $) {
  'use strict';

  function labelControls() {
    document.querySelectorAll('.media-library-widget-modal').forEach((modal) => {
      const close = modal.querySelector('.ui-dialog-titlebar-close');
      if (close) {
        close.setAttribute('aria-label', Drupal.t('Close media library'));
        close.setAttribute('title', Drupal.t('Close media library'));
      }
      modal.querySelectorAll('.views-display-link').forEach((link) => {
        if (link.classList.contains('is-active')) {
          link.setAttribute('aria-current', 'true');
        }
        else {
          link.removeAttribute('aria-current');
        }
      });
    });
  }

  // The dialog wrapper can be created after Drupal attaches the AJAX content.
  $(window).on('dialog:aftercreate.editflowMediaLibrary', function (event, dialog, $element) {
    labelControls();
    // Drupal 10 supplies $element; newer core dispatches on the content node.
    const content = $element ? $element[0] : event.target;
    const modal = content.closest?.('.media-library-widget-modal');
    if (!modal) {
      return;
    }
    // Core focuses the first input while opening the dialog, which can scroll
    // its label out of view. Reset after that focus/layout work has completed,
    // only on opening (not on every filter or view-switch AJAX response).
    window.setTimeout(() => window.requestAnimationFrame(() => {
      // CKEditor can focus an existing media selection farther down the list.
      // Keep initial keyboard focus at the top without scrolling the page.
      modal.querySelector('.ui-dialog-titlebar-close')?.focus({ preventScroll: true });
      modal.querySelectorAll('.ui-dialog-content, #media-library-content, .media-library-content, .media-library-wrapper').forEach((scroller) => {
        scroller.scrollTop = 0;
      });
    }), 0);
  });
  Drupal.behaviors.editflowMediaLibrary = { attach: labelControls };
})(Drupal, jQuery);

/**
 * @file
 * Enhances revision comparison navigation and video links.
 *
 * Active on diff.revisions_diff when the node's workflow enables comparison.
 * editflow_preprocess_html() attaches editflow/comparison; the page
 * preprocess hook supplies .editflow-comparison-summary. Requires the
 * optional Diff integration and its rendered navigation markup.
 *
 * On initial/AJAX behavior attachment, restores new-tab attributes on video
 * links sanitized by Diff and moves existing previous/next links into the
 * summary panel. Original destinations and query parameters are preserved.
 * once markers prevent repeat processing. This behavior registers no observers
 * or window listeners and has no detach restoration for its DOM changes.
 */
(function (Drupal, once) {
  'use strict';
  Drupal.behaviors.editflowComparison = {
    attach(context) {
      // Diff sanitizes target/rel attributes from the compared HTML.
      once('editflow-video-link', '.editflow-video-card a', context).forEach((link) => {
        link.setAttribute('target', '_blank');
        link.setAttribute('rel', 'noopener noreferrer');
        link.setAttribute('aria-label', Drupal.t('@title (opens in a new tab)', {'@title': link.textContent}));
      });
      const panel = document.querySelector('.editflow-comparison-summary');
      if (!panel) return;
      once('editflow-comparison-navigation', '.diff-navigation', context).forEach((navigation) => {
        const links = navigation.querySelectorAll('a');
        if (!links.length) return;
        const group = document.createElement('nav');
        group.className = 'editflow-change-navigation';
        group.setAttribute('aria-label', Drupal.t('Revision comparison navigation'));
        // Keep Diff's original destinations and query parameters intact.
        const previous = navigation.querySelector('.prev-link a');
        const next = navigation.querySelector('.next-link a');
        if (previous) group.append(previous);
        if (next) group.append(next);
        panel.querySelector('.editflow-comparison-links').append(group);
        navigation.hidden = true;
      });
    }
  };
})(Drupal, once);

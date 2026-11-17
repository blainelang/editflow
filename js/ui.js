/**
 * @file
 * Shared lifecycle and footer measurement helpers for EditFlow.
 *
 * Loaded by editflow/ui, a dependency of editing_bar, latest_preview,
 * layout, preview, and comparison (see editflow.libraries.yml). Loading
 * this file only defines Drupal.editflowUI; observers/listeners start when
 * a toolbar, latest-preview form, or layout form calls mount(). Preview and
 * comparison also depend on this library for its shared CSS.
 *
 * Components register an owner element, a kind, measurement callbacks, and
 * cleanup callbacks. Drupal unload or removal of a tracked element disposes
 * that registration. Measurements are batched into animation frames; footer
 * positioning stays in normal flow without ResizeObserver support.
 */
(function (Drupal) {
  'use strict';
  // One registration per owner; kind groups shared body classes/CSS variables.
  const active = new Map();
  let removalObserver;
  let removalFrame;

  /**
   * Disposes one registration, including component-specific DOM restoration.
   * Delete first: cleanup may itself invoke Drupal.detachBehaviors().
   */
  const stop = (owner) => {
    const state = active.get(owner);
    if (!state) return;
    active.delete(owner);
    state.dispose();
    if (![...active.values()].some((entry) => entry.kind === state.kind)) {
      if (state.bodyClass) document.body.classList.remove(state.bodyClass);
      state.properties.forEach((name) => document.body.style.removeProperty(name));
    }
    if (!active.size && removalObserver) {
      removalObserver.disconnect();
      removalObserver = null;
      cancelAnimationFrame(removalFrame);
    }
  };

  Drupal.editflowUI = {
    /**
     * Finds matching descendants and includes context itself when it matches.
     * Drupal's AJAX context can be the new component rather than its parent.
     */
    find(context, selector) {
      return [...(context.matches?.(selector) ? [context] : []), ...context.querySelectorAll(selector)];
    },
    /**
     * Registers a component and schedules its first measurement.
     *
     * @param {Element} owner
     *   Stable component root; a new registration replaces its previous one.
     * @param {string} kind
     *   Component group: toolbar, latest, or layout.
     * @param {Object} options
     *   elements: required connected elements, observed for size (default owner).
     *   resize: additional elements to observe for size changes.
     *   mutations: elements to observe for class/style changes in their subtree.
     *   events: window events that request measurement (default ['resize']).
     *   measure: callback to update dimensions/position before the next paint.
     *   cleanup: callback to undo component-specific handlers and DOM changes.
     *   bodyClass/properties: body class and CSS variables removed on final
     *   disposal of this kind. Components of a kind must use the same names.
     */
    mount(owner, kind, options) {
      stop(owner);
      let frame;
      const elements = options.elements || [owner];
      // Coalesce resize, mutation, and scroll notifications into one frame.
      const schedule = () => {
        if (frame) return;
        frame = requestAnimationFrame(() => {
          frame = null;
          if (!owner.isConnected || elements.some((element) => !element.isConnected)) {
            stop(owner);
            return;
          }
          options.measure?.();
        });
      };
      const resize = typeof ResizeObserver === 'function' ? new ResizeObserver(schedule) : null;
      elements.concat(options.resize || []).forEach((element) => resize?.observe(element));
      const mutations = typeof MutationObserver === 'function' ? new MutationObserver(schedule) : null;
      (options.mutations || []).forEach((element) => mutations?.observe(element, { attributes: true, subtree: true, attributeFilter: ['class', 'style'] }));
      const events = options.events || ['resize'];
      events.forEach((name) => window.addEventListener(name, schedule, { passive: true }));
      active.set(owner, {
        kind, elements, bodyClass: options.bodyClass, properties: options.properties || [],
        dispose() {
          cancelAnimationFrame(frame);
          resize?.disconnect();
          mutations?.disconnect();
          events.forEach((name) => window.removeEventListener(name, schedule));
          options.cleanup?.();
        },
      });
      // Also clean up if a third-party replacement omits Drupal.detachBehaviors.
      if (!removalObserver && typeof MutationObserver === 'function') {
        removalObserver = new MutationObserver(() => {
          if (removalFrame) return;
          removalFrame = requestAnimationFrame(() => {
            removalFrame = null;
            active.forEach((state, element) => {
              if (!element.isConnected || state.elements.some((child) => !child.isConnected)) stop(element);
            });
          });
        });
        removalObserver.observe(document.body, { childList: true, subtree: true });
      }
      schedule();
    },
    /**
     * Stops matching registrations owned by, or represented inside, context.
     * Call only for Drupal's unload trigger, not form serialization.
     */
    detach(context, kind) {
      active.forEach((state, owner) => {
        if (state.kind === kind && (context === owner || context.contains(owner) || state.elements.some((el) => context === el || context.contains(el)))) stop(owner);
      });
    },
    /**
     * Measures a footer at its fixed width and enables matching body spacing.
     *
     * Apply fixed positioning before measuring because it can change wrapping.
     * Set the height variable/body class synchronously before the next paint;
     * roll back if measurement fails. Feature CSS consumes the height variable.
     *
     * @param {Element} element
     *   Footer to measure.
     * @param {string} bodyClass
     *   Class that enables the feature's body padding.
     * @param {string} property
     *   CSS custom property receiving the measured pixel height.
     */
    footer(element, bodyClass, property) {
      // Without measurement support leave the usable controls in normal flow.
      if (typeof ResizeObserver !== 'function') return;
      element.classList.add('editflow-fixed-footer');
      const height = element.getBoundingClientRect().height;
      if (!Number.isFinite(height) || height <= 0) {
        element.classList.remove('editflow-fixed-footer');
        document.body.classList.remove(bodyClass);
        document.body.style.removeProperty(property);
        return;
      }
      document.body.style.setProperty(property, `${height}px`);
      document.body.classList.add(bodyClass);
    },
  };
})(Drupal);

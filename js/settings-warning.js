/**
 * Shows a local warning when interface options differ from their initial values.
 * Does not save settings or intercept navigation. Form tokens are ignored.
 */
(function (Drupal, once) {
  'use strict';
  const selector = 'form[data-editflow-interface-settings]';
  const handlers = new WeakMap();
  Drupal.behaviors.editflowSettingsWarning = {
    attach(context) {
      const forms = [...context.querySelectorAll(selector)];
      if (context.matches && context.matches(selector)) forms.unshift(context);
      once('editflow-settings-warning', forms).forEach((form) => {
        const warning = form.querySelector('.editflow-settings-warning');
        if (!warning) return;
        const snapshot = () => JSON.stringify([...form.querySelectorAll('input[type="checkbox"][name]:not(:disabled)')].map((input) => [input.name, input.checked]));
        const initial = snapshot();
        const update = () => { warning.hidden = snapshot() === initial; };
        const reset = () => { queueMicrotask(update); };
        form.addEventListener('change', update);
        form.addEventListener('reset', reset);
        handlers.set(form, {update, reset});
        update();
      });
    },
    detach(context, settings, trigger) {
      if (trigger !== 'unload') return;
      const forms = [...context.querySelectorAll(selector)];
      if (context.matches && context.matches(selector)) forms.unshift(context);
      once.remove('editflow-settings-warning', forms).forEach((form) => {
        const handler = handlers.get(form);
        if (!handler) return;
        form.removeEventListener('change', handler.update);
        form.removeEventListener('reset', handler.reset);
        handlers.delete(form);
      });
    }
  };
})(Drupal, once);

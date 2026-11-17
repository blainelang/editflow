(function (Drupal, once) {
  'use strict';
  const observers = new WeakMap();
  Drupal.behaviors.editflowRevisionFooter = {
    attach(context) {
      once('editflow-revision-footer', '.editflow-revision-footer-space', context).forEach((space) => {
        if (typeof ResizeObserver !== 'function') return;
        const footer = space.querySelector('.editflow-revision-footer');
        space.classList.add('is-fixed');
        const measure = () => { space.style.height = `${footer.getBoundingClientRect().height}px`; };
        const observer = new ResizeObserver(measure);
        observer.observe(footer);
        observers.set(space, observer);
        measure();
      });
    },
    detach(context, settings, trigger) {
      if (trigger !== 'unload') return;
      const spaces = [...context.querySelectorAll('.editflow-revision-footer-space')];
      if (context.matches?.('.editflow-revision-footer-space')) spaces.push(context);
      spaces.forEach((space) => {
        observers.get(space)?.disconnect();
        observers.delete(space);
        space.classList.remove('is-fixed');
        space.style.removeProperty('height');
        once.remove('editflow-revision-footer', space);
      });
    },
  };
})(Drupal, once);

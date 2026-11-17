/**
 * @file
 * Turns existing frontend node tabs into the Page editing navigation bar.
 *
 * Active where editflow_preprocess_html() attaches editflow/editing_bar:
 * non-admin routes with a node whose configured workflow enables editing_bar.
 * This includes eligible frontend node views and latest-version views; it is
 * not the admin-theme Edit Page Details toolbar. The behavior requires a
 * .block-local-tasks-block or main .tabs-primary containing primary tab links.
 *
 * Runs on initial Drupal behavior attachment and AJAX attachment. It moves
 * existing access-filtered links, optionally adds the configured workflow-help
 * link, and follows the admin tray's orientation and expanded state. A keyboard-
 * accessible Page editing button also toggles the links independently until the
 * admin tray next opens or closes. Vertical navigation sits beside the admin
 * tray (on the right for RTL); narrow screens use an overlay. On unload
 * it restores the original tab order, location, and visibility. The shared UI
 * helper removes listeners, observers, and page-spacing state.
 */
(function (Drupal, once) {
  'use strict';
  Drupal.behaviors.editflowEditingBar = {
    attach(context, settings) {
      const ui = Drupal.editflowUI;
      once('editflow-editing-bar', ui.find(context, '.block-local-tasks-block, main .tabs-primary')).forEach((tabs) => {
        const list = tabs.querySelector('ul.primary, ul.tabs--primary, ul.tabs__primary, ul.tabs');
        if (!list || !list.querySelector('a')) { once.remove('editflow-editing-bar', tabs); return; }
        const originalListClass = list.getAttribute('class');
        list.classList.add('editflow-tabs');
        const originalParent = list.parentNode;
        const originalNext = list.nextSibling;
        const originalItems = [...list.children];
        const toggles = [...list.querySelectorAll('.tab-toggle, [data-drupal-nav-tabs-trigger]')].map((element) => [element, element.hidden]);
        // Keep the two editing destinations adjacent, including keyboard order.
        const pageEdit = list.querySelector('a[data-drupal-link-system-path$="/edit"]')?.closest('li');
        const layoutEdit = list.querySelector('a[data-drupal-link-system-path$="/layout"]')?.closest('li');
        if (pageEdit && layoutEdit) pageEdit.after(layoutEdit);
        const horizontalItems = [...list.children];
        const originalClasses = originalItems.map((item) => [item, item.getAttribute('class')]);
        const group = (item) => {
          const path = item.querySelector('a')?.getAttribute('data-drupal-link-system-path') || '';
          if (/\/delete$/.test(path)) return 4;
          if (/\/revisions(?:\/|$)/.test(path)) return 3;
          if (/\/(latest|preview|preview-link)(?:\/|$)/.test(path)) return 2;
          return 1;
        };
        const verticalItems = [...horizontalItems].sort((a, b) => group(a) - group(b));
        let previousVertical;
        const bar = document.createElement('nav');
        bar.className = 'editflow-editing-bar';
        bar.setAttribute('aria-label', Drupal.t('Page editing'));
        const label = document.createElement('button');
        label.type = 'button';
        label.setAttribute('aria-label', Drupal.t('Toggle page editing navigation'));
        label.className = 'editflow-editing-label';
        label.textContent = Drupal.t('Page editing');
        const links = document.createElement('div');
        links.className = 'editflow-editing-links';
        // once() is per tab block, so each disclosure needs a unique target.
        let id = 'editflow-editing-links';
        while (document.getElementById(id)) id += '-next';
        links.id = id;
        label.setAttribute('aria-controls', id);
        links.append(list);
        bar.append(label, links);
        // Move, never clone, the original permission-filtered links.
        toggles.forEach(([toggle]) => { toggle.hidden = true; });
        const oldBlock = tabs.closest('.block-local-tasks-block');
        const hiddenBlock = oldBlock && !oldBlock.querySelector('.tabs-secondary') ? oldBlock : tabs;
        const wasHidden = hiddenBlock.hidden;
        hiddenBlock.hidden = true;
        document.querySelector('#toolbar-administration')?.after(bar);
        if (!bar.isConnected) document.body.prepend(bar);
        // Reserve flow space without competing with Drupal's inline body padding.
        const spacer = document.createElement('div');
        spacer.className = 'editflow-editing-spacer';
        spacer.setAttribute('aria-hidden', 'true');
        bar.after(spacer);
        if (settings.editflow?.workflowHelpUrl) {
          const help = document.createElement('a');
          help.href = settings.editflow.workflowHelpUrl;
          help.className = 'use-ajax editflow-help-link editflow-toolbar-help';
          help.textContent = Drupal.t('Workflow help');
          help.dataset.dialogType = 'modal';
          help.dataset.dialogOptions = JSON.stringify({ width: 760, classes: { 'ui-dialog': 'editflow-help-dialog' } });
          links.append(help);
          Drupal.attachBehaviors(bar, settings);
        }
        let expanded = true;
        let previousAdminOpen;
        const update = () => {
          const toolbar = document.querySelector('#toolbar-bar');
          const activeTray = document.querySelector('#toolbar-administration .toolbar-tray.is-active');
          const adminOpen = Boolean(activeTray);
          if (toolbar && adminOpen !== previousAdminOpen) expanded = adminOpen;
          previousAdminOpen = adminOpen;
          const vertical = Boolean(document.querySelector('#toolbar-administration .toolbar-tray-vertical'));
          if (vertical !== previousVertical) {
            let lastGroup;
            (vertical ? verticalItems : horizontalItems).forEach((item) => {
              list.append(item);
              item.classList.toggle('editflow-group-start', vertical && lastGroup !== undefined && group(item) !== lastGroup);
              item.classList.toggle('editflow-delete-item', vertical && group(item) === 4);
              lastGroup = group(item);
            });
            previousVertical = vertical;
          }
          bar.classList.toggle('editflow-editing-bar--vertical', vertical);
          bar.classList.toggle('editflow-editing-bar--collapsed', !expanded);
          // Return focus before hiding a link activated by keyboard or script.
          if (!expanded && links.contains(document.activeElement)) label.focus();
          links.hidden = !expanded;
          label.setAttribute('aria-expanded', String(expanded));
          const tray = activeTray?.classList.contains('toolbar-tray-horizontal') ? activeTray : null;
          const side = vertical && adminOpen ? activeTray.getBoundingClientRect().width : 0;
          document.body.style.setProperty('--editflow-admin-toolbar-side', `${side}px`);
          const bottom = Math.max(0, toolbar?.getBoundingClientRect().bottom || 0,
            tray?.getBoundingClientRect().bottom || 0);
          document.body.style.setProperty('--editflow-admin-toolbar-bottom', `${bottom}px`);
          document.body.style.setProperty('--editflow-editing-bar-height', `${vertical ? 0 : bar.getBoundingClientRect().height}px`);
          document.body.style.setProperty('--editflow-editing-bar-width', `${vertical ? bar.getBoundingClientRect().width : 0}px`);
          document.body.classList.add('editflow-has-editing-bar');
        };
        const toggle = () => { expanded = !expanded; update(); };
        label.addEventListener('click', toggle);
        ui.mount(tabs, 'toolbar', {
          elements: [bar, list],
          bodyClass: 'editflow-has-editing-bar',
          properties: ['--editflow-admin-toolbar-bottom', '--editflow-admin-toolbar-side', '--editflow-editing-bar-height', '--editflow-editing-bar-width'],
          resize: [...document.querySelectorAll('#toolbar-bar, .toolbar-tray')],
          mutations: [...document.querySelectorAll('#toolbar-administration')],
          events: ['resize', 'scroll'], measure: update,
          cleanup() {
            label.removeEventListener('click', toggle);
            Drupal.detachBehaviors(bar, settings, 'unload');
            originalItems.forEach((item) => list.append(item));
            originalClasses.forEach(([item, value]) => {
              if (value === null) item.removeAttribute('class');
              else item.setAttribute('class', value);
            });
            originalParent.insertBefore(list, originalNext?.parentNode === originalParent ? originalNext : null);
            toggles.forEach(([toggle, hidden]) => { toggle.hidden = hidden; });
            hiddenBlock.hidden = wasHidden;
            if (originalListClass === null) list.removeAttribute('class');
            else list.setAttribute('class', originalListClass);
            spacer.remove();
            bar.remove();
            once.remove('editflow-editing-bar', tabs);
          },
        });
      });
    },
    detach(context, settings, trigger) {
      if (trigger === 'unload') Drupal.editflowUI.detach(context, 'toolbar');
    },
  };
})(Drupal, once);

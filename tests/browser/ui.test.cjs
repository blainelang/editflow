/**
 * Isolated Chromium regression checks using the real Drupal and module assets.
 * No Drupal server, accounts, content, or editing locks are used or modified.
 * Run: NODE_PATH=<directory containing playwright> CHROME_BIN=<chrome> node <this file>
 */
const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
const { chromium } = require('playwright');
const moduleRoot = path.resolve(__dirname, '../..');
let webRoot = process.env.DRUPAL_ROOT ? path.resolve(process.env.DRUPAL_ROOT) : moduleRoot;
while (!fs.existsSync(path.join(webRoot, 'core/misc/drupal.js'))) {
  const parent = path.dirname(webRoot);
  if (parent === webRoot) throw new Error('Set DRUPAL_ROOT to the Drupal webroot');
  webRoot = parent;
}
const latest = `<main style="height:1200px">Preview content</main>
<form class="editflow-latest-footer"><div class="editflow-latest-status"><strong>Preview — latest saved version</strong><span>Unsaved Layout Builder changes are not included.</span></div><a class="editflow-latest-edit" href="#edit">Edit Page Details</a><a class="editflow-latest-edit" href="#layout">Edit Layout</a><label class="editflow-publish-confirmation"><input name="confirm_publish" type="checkbox" required>Confirm publication</label><input type="submit" class="editflow-publish" data-publish-confirm="Publish this version?" value="Publish this version"></form>`;
const layout = `<form class="editflow-layout-form" data-draft-state="draft" data-publish-state="published"><div class="editflow-summary">Editing summary</div><div class="field--name-moderation-state"><select name="moderation_state[0][state]"><option value="draft">Draft</option><option value="published">Published</option></select></div><main style="height:1200px">Layout content</main><div class="editflow-layout-actions"><div class="editflow-layout-status"><strong>Layout changes</strong><span class="editflow-preview-note">Saved changes only</span><details class="editflow-footer-details"><summary>Locked by editor · Details</summary><p>Saved by editor. Lock age: 5 minutes.</p><a href="#unlock">Unlock</a></details></div><label class="js-show"><input id="layout-builder-content-preview" type="checkbox">Show content preview</label><input data-drupal-selector="edit-unlock" type="submit" value="Unlock"><input data-drupal-selector="edit-revert" type="submit" value="Reset"><a class="editflow-discard-drafts" href="#discard">Discard unpublished changes</a><a class="editflow-saved-preview" href="#preview">Preview saved version</a><input data-editorial-continue type="submit" value="Save draft and continue editing"><input data-editorial-save type="submit" value="Save draft and finish editing"></div></form>`;
const toolbar = `<div id="toolbar-administration" style="position:fixed;top:0;left:0;right:0"><div id="toolbar-bar" style="height:40px">Administration</div><div class="toolbar-tray toolbar-tray-horizontal" style="height:20px"></div></div><div class="block-local-tasks-block"><ul class="primary"><li><a data-drupal-link-system-path="node/1/edit" href="#edit">Edit Page Details</a></li><li><a href="#preview">Preview</a></li><li><a data-drupal-link-system-path="node/1/layout" href="#layout">Edit Layout</a></li><li class="tab-toggle">More</li></ul></div><main style="height:1200px">Page content</main>`;
const errors = [];
let passed = 0;

(async () => {
  const browser = await chromium.launch({headless: true, ...(process.env.CHROME_BIN ? {executablePath: process.env.CHROME_BIN} : {})});
  try {
    async function fixture(html, scripts, noResize = false) {
      const page = await browser.newPage({viewport: {width: 1280, height: 800}});
      page.on('pageerror', error => errors.push(error.message));
      await page.setContent(html);
      await page.addStyleTag({content: 'body { margin:0 } * { box-sizing:border-box }'});
      for (const name of ['ui', ...scripts]) {
        const css = path.join(moduleRoot, `css/${name}.css`);
        if (fs.existsSync(css)) await page.addStyleTag({path: css});
      }
      await page.evaluate(() => { window.drupalSettings = {}; });
      await page.addScriptTag({path: path.join(webRoot, 'core/misc/drupal.js')});
      await page.addScriptTag({path: path.join(webRoot, 'core/assets/vendor/once/once.min.js')});
      if (scripts.some(name => ['workflow-help', 'unsaved-changes'].includes(name))) {
        await page.addScriptTag({path: path.join(webRoot, 'core/assets/vendor/jquery/jquery.min.js')});
      }
      // Track actual native resources, rather than replacing their behavior.
      await page.evaluate(noResize => {
        window.resources = {resize: new Set(), mutation: new Set(), listeners: {resize: new Set(), scroll: new Set()}};
        for (const [name, key] of [['ResizeObserver', 'resize'], ['MutationObserver', 'mutation']]) {
          const Native = window[name];
          window[name] = class extends Native {
            observe(...args) { resources[key].add(this); return super.observe(...args); }
            disconnect() { resources[key].delete(this); return super.disconnect(); }
          };
        }
        if (noResize) window.ResizeObserver = undefined;
        const add = window.addEventListener;
        const remove = window.removeEventListener;
        window.addEventListener = function (name, callback, options) {
          if (name === 'resize' || name === 'scroll') resources.listeners[name].add(callback);
          return add.call(this, name, callback, options);
        };
        window.removeEventListener = function (name, callback, options) {
          if (name === 'resize' || name === 'scroll') resources.listeners[name].delete(callback);
          return remove.call(this, name, callback, options);
        };
      }, noResize);
      for (const name of ['ui', ...scripts]) await page.addScriptTag({path: path.join(moduleRoot, `js/${name}.js`)});
      return page;
    }
    async function settle(page) {
      await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(() => requestAnimationFrame(resolve)))));
    }
    async function attach(page, selector) {
      await page.evaluate(selector => Drupal.attachBehaviors(selector ? document.querySelector(selector) : document, drupalSettings), selector);
      await settle(page);
    }
    async function detach(page, selector) {
      await page.evaluate(selector => Drupal.detachBehaviors(selector ? document.querySelector(selector) : document, drupalSettings, 'unload'), selector);
      await settle(page);
    }
    async function clean(page) {
      const result = await page.evaluate(() => ({
        observers: resources.resize.size + resources.mutation.size,
        listeners: resources.listeners.resize.size + resources.listeners.scroll.size,
        classes: [...document.body.classList].filter(c => c.startsWith('editflow-')),
        properties: [...document.body.style].filter(c => c.startsWith('--editflow-')),
      }));
      assert.deepEqual(result, {observers: 0, listeners: 0, classes: [], properties: []});
    }
    async function fixed(page, selector, property) {
      await page.waitForFunction(({selector, property}) => {
        const e = document.querySelector(selector);
        return getComputedStyle(e).position === 'fixed' && Math.abs(e.getBoundingClientRect().height - parseFloat(document.body.style.getPropertyValue(property))) < 1;
      }, {selector, property});
      assert.ok(await page.evaluate(selector => {
        const r = document.querySelector(selector).getBoundingClientRect();
        return r.left >= 0 && r.right <= innerWidth && r.bottom <= innerHeight && parseFloat(getComputedStyle(document.body).paddingBottom) >= r.height;
      }, selector));
    }
    async function screenshot(page, name) {
      if (!process.env.SCREENSHOT_DIR) return;
      fs.mkdirSync(process.env.SCREENSHOT_DIR, {recursive: true});
      await page.screenshot({caret: 'initial', path: path.join(process.env.SCREENSHOT_DIR, `${name}.png`)});
    }
    async function check(name, run) { await run(); passed++; console.log(`PASS ${name}`); }

    await check('Latest footer: normal flow before attach, measured desktop/mobile, dynamic resize', async () => {
      const page = await fixture(latest, ['latest-preview']);
      assert.equal(await page.locator('form').evaluate(e => getComputedStyle(e).position), 'static');
      assert.ok(await page.locator('[name=confirm_publish]').isVisible());
      assert.equal(await page.locator('[name=confirm_publish]').getAttribute('required'), '');
      await attach(page);
      await fixed(page, 'form', '--editflow-latest-footer-height');
      await screenshot(page, 'latest-desktop');
      await page.setViewportSize({width: 375, height: 800});
      await fixed(page, 'form', '--editflow-latest-footer-height');
      await page.locator('.editflow-latest-status').evaluate(e => e.append(Object.assign(document.createElement('p'), {textContent: 'Additional lock information that wraps on a narrow screen.'})));
      await fixed(page, 'form', '--editflow-latest-footer-height');
      await page.evaluate(() => scrollTo(0, document.body.scrollHeight));
      assert.ok(await page.evaluate(() => document.querySelector('main').getBoundingClientRect().bottom <= document.querySelector('form').getBoundingClientRect().top));
      await screenshot(page, 'latest-mobile');
      await detach(page);
      await clean(page);
      await page.close();
    });
    await check('Latest confirmation: cancel/accept, repeated attach, detach and reattach', async () => {
      const page = await fixture(latest, ['latest-preview']);
      await attach(page); await attach(page);
      await page.evaluate(() => {
        window.submissions = [];
        document.addEventListener('submit', event => {
          submissions.push({blocked: event.defaultPrevented, confirmed: document.querySelector('[name=confirm_publish]').checked});
          event.preventDefault();
        });
      });
      for (const accept of [false, true]) {
        let dialogs = 0;
        const handler = async dialog => { dialogs++; await (accept ? dialog.accept() : dialog.dismiss()); };
        page.on('dialog', handler);
        await page.locator('[data-publish-confirm]').click();
        page.off('dialog', handler);
        assert.equal(dialogs, 1);
      }
      assert.deepEqual(await page.evaluate(() => submissions), [{blocked:true,confirmed:false},{blocked:false,confirmed:true}]);
      await detach(page);
      await clean(page);
      assert.ok(await page.locator('[name=confirm_publish]').isVisible());
      assert.ok(await page.locator('[name=confirm_publish]').evaluate(e => e.required && !e.checked));
      await attach(page, 'form');
      await fixed(page, 'form', '--editflow-latest-footer-height');
      await page.locator('form').evaluate(e => e.remove());
      await settle(page); await clean(page);
      await page.setViewportSize({width:900,height:800}); await settle(page); await clean(page);
      await page.close();
    });
    await check('Latest fallback: no ResizeObserver, hidden footer, missing publish controls', async () => {
      const page = await fixture(latest, ['latest-preview'], true);
      await attach(page);
      assert.equal(await page.locator('form').evaluate(e => getComputedStyle(e).position), 'static');
      await detach(page); await clean(page); await page.close();
      const hidden = await fixture('<form class="editflow-latest-footer" style="display:none"></form>', ['latest-preview']);
      await attach(hidden);
      assert.equal(await hidden.locator('form').evaluate(e => e.classList.contains('editflow-fixed-footer')), false);
      await detach(hidden); await clean(hidden); await hidden.close();
    });
    await check('Layout: responsive measurement, labels, reversible moves and reattachment', async () => {
      const page = await fixture(layout, ['layout']);
      // Reproduce the admin theme margin that previously exposed content below.
      await page.addStyleTag({content: '.editflow-layout-form .editflow-layout-actions { margin-block: 8px; }'});
      const original = await page.locator('form').innerHTML();
      for (let i=0; i<3; i++) {
        await attach(page, 'form'); await attach(page);
        await fixed(page, '.editflow-layout-actions', '--editflow-footer-height');
        assert.equal(await page.locator('.editflow-layout-controls').count(), 1);
        assert.equal(await page.locator('.editflow-layout-actions select').count(), 0);
        assert.equal(await page.locator('.editflow-layout-choices .editflow-preview-note').count(), 1);
        assert.equal(await page.locator('.editflow-layout-actions').evaluate(e => e.getBoundingClientRect().bottom), 800);
        await page.locator('.editflow-footer-details summary').click();
        assert.ok(await page.locator('.editflow-footer-details').evaluate(e => e.getBoundingClientRect().top >= 0));
        const close = page.getByRole('button', {name: 'Close save and lock details'});
        assert.equal(await close.count(), 1);
        assert.equal(await page.locator('.editflow-footer-details summary').isVisible(), false);
        await close.waitFor({state: 'visible'});
        assert.ok(await close.evaluate(e => {
          const popup = e.closest('details').getBoundingClientRect();
          return e.getBoundingClientRect().top - popup.top < 25;
        }));
        assert.equal(await close.getAttribute('type'), 'button');
        await close.click();
        assert.equal(await page.locator('.editflow-footer-details').evaluate(e => e.open), false);
        assert.equal(await page.locator('.editflow-footer-details summary').evaluate(e => e === document.activeElement), true);
        await page.locator('.editflow-footer-details summary').click();
        await close.focus();
        await page.keyboard.press('Escape');
        assert.equal(await page.locator('.editflow-footer-details').evaluate(e => e.open), false);
        assert.equal(await page.locator('.editflow-footer-details summary').evaluate(e => e === document.activeElement), true);
        assert.equal(await page.locator('.editflow-summary details:has(summary:text-is("Advanced editing options")) .editflow-discard-drafts').count(), 1);
        await page.locator('select').selectOption('published');
        assert.equal(await page.locator('[data-editorial-save]').inputValue(), 'Publish and finish editing');
        await page.locator('select').selectOption('draft');
        assert.equal(await page.locator('[data-editorial-continue]').inputValue(), 'Save draft and continue editing');
        await page.setViewportSize({width:375,height:800});
        await fixed(page, '.editflow-layout-actions', '--editflow-footer-height');
        if (i === 0) await screenshot(page, 'layout-mobile');
        await detach(page, 'form'); await clean(page);
        // once leaves an empty data-once attribute; normalize only that bookkeeping.
        assert.equal((await page.locator('form').innerHTML()).replace(/ data-once=""/g, ''), original);
      }
      await attach(page); await page.locator('form').evaluate(e => e.remove());
      await settle(page); await clean(page); await page.close();
    });
    await check('Toolbar: offsets, single instance, original links restored, removal cleanup', async () => {
      const page = await fixture(toolbar, ['editing-bar']);
      const original = await page.locator('.block-local-tasks-block').innerHTML();
      for (let i=0; i<3; i++) {
        await attach(page); await attach(page);
        assert.equal(await page.locator('.editflow-editing-bar').count(), 1);
        assert.equal(await page.locator('.editflow-editing-bar').evaluate(e => e.getBoundingClientRect().top), 40);
        await page.locator('.toolbar-tray').evaluate(e => e.classList.add('is-active'));
        await page.waitForFunction(() => document.body.style.getPropertyValue('--editflow-admin-toolbar-bottom') === '60px');
        if (i === 0) await screenshot(page, 'toolbar-desktop');
        await detach(page); await clean(page);
        assert.equal(await page.locator('.editflow-editing-bar').count(), 0);
        assert.equal(await page.locator('.block-local-tasks-block').innerHTML(), original);
        await page.locator('.toolbar-tray').evaluate(e => e.classList.remove('is-active'));
      }
      await attach(page); await page.locator('.block-local-tasks-block').evaluate(e => e.remove());
      await settle(page); await clean(page);
      assert.equal(await page.locator('.editflow-editing-bar').count(), 0);
      await page.close();
    });
    await check('Toolbar: disclosure follows admin tray, vertical/RTL placement and keyboard access', async () => {
      const page = await fixture(toolbar, ['editing-bar']);
      await attach(page);
      const toggle = page.locator('.editflow-editing-label');
      const links = page.locator('.editflow-editing-links');
      assert.equal(await toggle.getAttribute('aria-expanded'), 'false');
      assert.equal(await links.isVisible(), false);
      await toggle.focus(); await page.keyboard.press('Enter');
      assert.equal(await toggle.getAttribute('aria-expanded'), 'true');
      await page.locator('.toolbar-tray').evaluate(e => e.classList.add('is-active'));
      await settle(page);
      await page.locator('.toolbar-tray').evaluate(e => {
        e.classList.replace('toolbar-tray-horizontal', 'toolbar-tray-vertical');
        e.style.cssText = 'position:fixed;top:40px;left:0;width:240px;height:760px';
      });
      await settle(page);
      const bar = page.locator('.editflow-editing-bar');
      assert.equal(await bar.evaluate(e => e.getBoundingClientRect().left), 240);
      await page.addStyleTag({content: '.toolbar-tray-open.toolbar-vertical.toolbar-fixed { margin-inline-start: 240px; }'});
      await page.locator('body').evaluate(e => e.classList.add('toolbar-tray-open', 'toolbar-vertical', 'toolbar-fixed'));
      assert.equal(await page.locator('body').evaluate(e => getComputedStyle(e).paddingLeft), '460px');
      assert.equal(await page.locator('body').evaluate(e => getComputedStyle(e).marginInlineStart), '0px');
      assert.equal(await page.locator('.editflow-editing-bar .tab-toggle').isVisible(), false);
      assert.equal(await bar.evaluate(e => e.getBoundingClientRect().top), 40);
      assert.equal(await page.locator('.editflow-editing-bar ul.primary').evaluate(e => getComputedStyle(e).flexDirection), 'column');
      await screenshot(page, 'toolbar-vertical');
      await page.locator('.editflow-editing-bar a').first().focus();
      await page.locator('.toolbar-tray').evaluate(e => e.classList.remove('is-active'));
      await settle(page);
      assert.equal(await links.isVisible(), false);
      assert.equal(await toggle.evaluate(e => document.activeElement === e), true);
      assert.equal(await bar.evaluate(e => e.getBoundingClientRect().width), 48);
      assert.equal(await bar.evaluate(e => e.getBoundingClientRect().left), 0);
      await page.locator('.toolbar-tray').evaluate(e => e.classList.add('is-active'));
      await settle(page);
      assert.equal(await links.isVisible(), true);
      await page.evaluate(() => document.documentElement.dir = 'rtl');
      assert.equal(await bar.evaluate(e => innerWidth - e.getBoundingClientRect().right), 240);
      await page.evaluate(() => document.documentElement.dir = 'ltr');
      await page.setViewportSize({width: 390, height: 700}); await settle(page);
      assert.ok(await bar.evaluate(e => e.getBoundingClientRect().right <= innerWidth));
      await screenshot(page, 'toolbar-vertical-mobile');
      await page.locator('.toolbar-tray').evaluate(e => {
        e.classList.replace('toolbar-tray-vertical', 'toolbar-tray-horizontal');
        e.style.cssText = 'height:20px';
      });
      await settle(page);
      assert.equal(await bar.evaluate(e => e.getBoundingClientRect().top), 60);
      assert.equal(await page.evaluate(() => document.body.style.getPropertyValue('--editflow-editing-bar-width')), '0px');
      await detach(page); await clean(page); await page.close();
      const standalone = await fixture(toolbar.replace(/<div id="toolbar-administration".*?<div class="block-local-tasks-block">/, '<div class="block-local-tasks-block">'), ['editing-bar']);
      await attach(standalone);
      assert.equal(await standalone.locator('.editflow-editing-label').getAttribute('aria-expanded'), 'true');
      await detach(standalone); await clean(standalone); await standalone.close();
    });
    await check('Claro tabs: all links visible and heading clears fixed toolbar', async () => {
      const html = toolbar.replace('ul class="primary"', 'ul class="tabs tabs--primary"')
        .replaceAll('<li>', '<li class="tabs__tab">')
        .replace('<li class="tabs__tab"><a data-drupal-link-system-path="node/1/edit"', '<li class="tabs__tab is-active"><button data-drupal-nav-tabs-trigger>Toggle tabs</button><a data-drupal-link-system-path="node/1/edit"')
        .replace('Page content', '<h1>Edit layout</h1>');
      const page = await fixture(html, ['editing-bar']);
      await page.addStyleTag({path: path.join(webRoot, 'core/themes/claro/css/components/tabs.css')});
      await page.evaluate(() => {
        document.body.style.paddingTop = '60px';
        document.querySelector('.toolbar-tray').classList.add('is-active');
      });
      await attach(page);
      for (const link of await page.locator('.editflow-editing-bar li a').all()) assert.equal(await link.isVisible(), true);
      assert.equal(await page.locator('[data-drupal-nav-tabs-trigger]').isVisible(), false);
      assert.ok(await page.evaluate(() => document.querySelector('h1').getBoundingClientRect().top >= document.querySelector('.editflow-editing-bar').getBoundingClientRect().bottom));
      await detach(page); await clean(page);
      assert.equal(await page.locator('[data-drupal-nav-tabs-trigger]').isVisible(), true);
      assert.equal(await page.locator('.editflow-editing-spacer').count(), 0);
      await page.close();
    });
    await check('Missing markup and detach before queued measurement', async () => {
      const page = await fixture('<form class="editflow-layout-form"></form><div class="block-local-tasks-block"></div>', ['layout','editing-bar']);
      await attach(page); await clean(page); await page.close();
      const pending = await fixture(latest, ['latest-preview']);
      await pending.evaluate(() => { Drupal.attachBehaviors(document, drupalSettings); Drupal.detachBehaviors(document, drupalSettings, 'unload'); });
      await settle(pending); await clean(pending); await pending.close();
    });
    await check('Help focus starts at the heading and labels the close button', async () => {
      const page = await fixture('<div class="editflow-help-dialog"><button class="ui-dialog-titlebar-close"></button><div id="help" style="height:100px;overflow:auto"><div class="editflow-workflow-help"><h2>Start here</h2><p style="height:900px">Guidance</p><a href="#">Settings</a></div></div></div>', ['workflow-help']);
      await attach(page);
      await page.evaluate(() => {
        const content = document.querySelector('#help');
        content.scrollTop = 500;
        jQuery(window).trigger('dialog:aftercreate', [{}, jQuery(content)]);
      });
      assert.equal(await page.locator('#help').evaluate(e => e.scrollTop), 0);
      assert.equal(await page.locator('h2').evaluate(e => e === document.activeElement), true);
      assert.equal(await page.locator('button').getAttribute('aria-label'), 'Close workflow help');
      await page.close();
    });
    await check('Unsaved guard survives AJAX replacement and canceled submit', async () => {
      const page = await fixture('<form id="edit" data-editflow-unsaved-guard><input name="title"></form>', ['unsaved-changes']);
      const warns = () => page.evaluate(() => {
        const event = new Event('beforeunload', {cancelable: true});
        window.dispatchEvent(event);
        return event.defaultPrevented;
      });
      await attach(page);
      assert.equal(await warns(), false);
      await page.locator('input').fill('Changed');
      assert.equal(await warns(), true);
      await page.evaluate(() => {
        const old = document.querySelector('form');
        old.outerHTML = '<form id="edit" data-editflow-unsaved-guard><input name="title"></form>';
        Drupal.attachBehaviors(document.querySelector('form'), drupalSettings);
        const form = document.querySelector('form');
        form.addEventListener('submit', event => event.preventDefault());
        form.dispatchEvent(new Event('submit', {cancelable: true}));
      });
      assert.equal(await warns(), true);
      await page.evaluate(() => document.querySelector('form').remove());
      assert.equal(await warns(), false);
      await page.close();
    });
    await check('Interface settings warning tracks changes, reversions and reset', async () => {
      const page = await fixture('<form data-editflow-interface-settings><input type="checkbox" name="feature" checked><input type="checkbox" name="media_table"><input type="checkbox" name="unavailable" disabled><input type="hidden" name="form_token" value="one"><div class="editflow-settings-warning" role="status" hidden>Save configuration before leaving.</div><a href="#columns">Review columns</a></form>', ['settings-warning']);
      await attach(page);
      await attach(page);
      const warning = page.locator('.editflow-settings-warning');
      assert.equal(await warning.isVisible(), false);
      await page.locator('[name="feature"]').uncheck();
      assert.equal(await warning.isVisible(), true);
      assert.equal(await warning.evaluate(el => getComputedStyle(el).backgroundColor), 'rgb(255, 244, 206)');
      await page.locator('[name="feature"]').check();
      assert.equal(await warning.isVisible(), false);
      await page.locator('[name="media_table"]').check();
      assert.equal(await warning.isVisible(), true);
      await page.evaluate(() => document.querySelector('form').reset());
      assert.equal(await warning.isVisible(), false);
      await page.evaluate(() => {
        document.querySelector('[name="form_token"]').value = 'two';
        document.querySelector('form').dispatchEvent(new Event('change'));
      });
      assert.equal(await warning.isVisible(), false);
      await detach(page);
      await attach(page);
      await page.locator('[name="feature"]').uncheck();
      assert.equal(await warning.isVisible(), true);
      await page.close();
    });
    assert.deepEqual(errors, [], 'No browser errors');
    console.log(`${passed} browser scenarios passed; no browser errors.`);
  } finally { await browser.close(); }
})().catch(error => { console.error(error, errors); process.exitCode = 1; });

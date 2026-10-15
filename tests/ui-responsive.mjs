/** Chrome layout regression checks. Review adapter rejects all mutating requests. */
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { mkdir, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import { tmpdir } from 'node:os';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.TRADE_CRM_PLAYWRIGHT_MODULE || 'playwright');
const origin = process.env.TRADE_CRM_REVIEW_ORIGIN || 'http://127.0.0.1:8793';
assert.match(origin, /^http:\/\/127\.0\.0\.1:\d+$/, 'Review must use a loopback server.');
const token = process.env.TRADE_CRM_REVIEW_TOKEN;
assert.ok(token?.length >= 32, 'A review token is required.');
const contactId = Number(process.env.TRADE_CRM_REVIEW_CONTACT_ID || 5);
const output = process.env.TRADE_CRM_REVIEW_OUTPUT || join(tmpdir(), 'trade-crm-ui-130');
await mkdir(output, { recursive: true });
const browser = await chromium.launch({ executablePath: process.env.TRADE_CRM_CHROME_BINARY, headless: true });
const checks = [];
const failures = [];
const pages = [
  ['today', '/crm/', '.stats-grid'],
  ['contacts', '/crm/?view=contacts', '.contact-card'],
  ['profile', `/crm/?view=profile&contact=${contactId}`, '.profile-card'],
  ['create', '/crm/?view=form', '.form-panel'],
  ['edit', `/crm/?view=form&contact=${contactId}`, '.form-panel'],
  ['archive', '/crm/?view=archive', '.contacts-panel'],
  ['settings', '/crm/?view=settings', '.industry-manage-row'],
];
const widths = process.env.TRADE_CRM_REVIEW_WIDTHS ? process.env.TRADE_CRM_REVIEW_WIDTHS.split(',').map(Number) : [320, 375, 390, 430, 768, 1366];
assert.ok(widths.every((width) => [320, 375, 390, 430, 768, 1366].includes(width)), 'Use the documented review widths.');
function check(condition, message) {
  checks.push({ pass: Boolean(condition), message });
  if (!condition) failures.push(message);
}
try {
  for (const width of widths) {
    const context = await browser.newContext({ viewport: { width, height: 844 }, extraHTTPHeaders: { 'X-CRM-Review-Token': token }, serviceWorkers: 'block', locale: 'fa-IR' });
    const page = await context.newPage();
    const errors = [];
    const mutations = [];
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('request', (request) => { if (!['GET', 'HEAD'].includes(request.method()) && request.url().startsWith(origin)) mutations.push(request.method() + ' ' + request.url()); });
    for (const [name, path, ready] of pages) {
      const label = `${width}px ${name}`;
      await page.goto(origin + path, { waitUntil: 'networkidle' });
      await page.locator(ready).first().waitFor();
      await page.evaluate(() => document.fonts.ready);
      const metrics = await page.evaluate(() => {
        const visible = (element) => { const r = element.getBoundingClientRect(); const s = getComputedStyle(element); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none' && !element.closest('[aria-hidden="true"], [inert]') && r.right > 0 && r.left < innerWidth; };
        const controls = [...document.querySelectorAll('input:not([type="checkbox"]):not([type="hidden"]), textarea, select')].filter(visible);
        const buttons = [...document.querySelectorAll('button, .contact-link, .industry-search-result, .industry-option')].filter(visible);
        const bounded = [...document.querySelectorAll('h1,.heading-actions,.contact-card,.contact-link,.timeline-note,.industry-selected-chip,.privacy-note')].filter(visible);
        const heading = document.querySelector('h1');
        return {
          overflow: document.documentElement.scrollWidth - innerWidth,
          smallInputs: controls.filter((e) => parseFloat(getComputedStyle(e).fontSize) < 16).map((e) => e.className),
          smallTargets: buttons.filter((e) => { const r = e.getBoundingClientRect(); return r.width < 43.5 || r.height < 43.5; }).map((e) => ({ text: e.textContent.trim().slice(0, 60), className: e.className, width: e.getBoundingClientRect().width, height: e.getBoundingClientRect().height })),
          clipped: bounded.filter((e) => e.scrollWidth > e.clientWidth + 1 || e.getBoundingClientRect().left < -1 || e.getBoundingClientRect().right > innerWidth + 1).map((e) => e.className || e.tagName),
          heading: heading ? { text: heading.textContent, height: heading.getBoundingClientRect().height, lineHeight: parseFloat(getComputedStyle(heading).lineHeight) } : null,
          actionsSticky: [...document.querySelectorAll('.settings-card .form-actions')].some((e) => getComputedStyle(e).position === 'sticky'),
          formColumns: document.querySelector('.form-grid') ? getComputedStyle(document.querySelector('.form-grid')).gridTemplateColumns.split(' ').length : null,
          hasZoomRestriction: /user-scalable\s*=\s*no|maximum-scale\s*=\s*1(?:\D|$)/i.test(document.querySelector('meta[name="viewport"]')?.content || ''),
        };
      });
      check(metrics.overflow <= 1, `${label}: no page overflow (${metrics.overflow}px)`);
      check(metrics.smallInputs.length === 0, `${label}: inputs >=16px ${JSON.stringify(metrics.smallInputs)}`);
      check(metrics.smallTargets.length === 0, `${label}: touch controls >=44px ${JSON.stringify(metrics.smallTargets)}`);
      check(metrics.clipped.length === 0, `${label}: content is not clipped ${JSON.stringify(metrics.clipped)}`);
      check(!metrics.actionsSticky, `${label}: settings actions do not stick`);
      check(!metrics.hasZoomRestriction, `${label}: manual zoom allowed`);
      if (width <= 760 && metrics.formColumns !== null) check(metrics.formColumns === 1, `${label}: form is one column`);
      if (width === 390 && name === 'profile' && metrics.heading?.text === 'آقای امام وردی') check(metrics.heading.height <= metrics.heading.lineHeight + 1, `${label}: screenshot name fits on one line`);
      if (width === 390 || width === 1366) await page.screenshot({ path: join(output, `after-${name}-${width}.png`), fullPage: true });
      if (width === 390 && name === 'profile') await page.screenshot({ path: join(output, 'after-profile-390-viewport.png') });
      if (name === 'create' && width === 390) {
        await page.getByRole('button', { name: 'ثبت مخاطب', exact: true }).click();
        check(await page.locator('input[aria-invalid="true"]').first().evaluate((e) => e === document.activeElement), `${label}: validation focuses the invalid input`);
      }
      if (name === 'profile' && [320, 390].includes(width)) {
        const opener = page.getByRole('button', { name: 'ثبت نتیجه پیگیری', exact: true });
        if (await opener.count()) {
          await opener.click();
          const dialog = page.getByRole('dialog');
          await dialog.waitFor();
          check(await dialog.evaluate((e) => e.contains(document.activeElement)), `${label}: modal receives focus`);
          check(await page.locator('#trade-crm-root').evaluate((e) => e.inert), `${label}: modal background is inert`);
          const last = dialog.locator('button').last();
          await last.focus();
          await page.keyboard.press('Tab');
          check(await dialog.evaluate((e) => e.contains(document.activeElement)), `${label}: tab stays in modal`);
          await page.keyboard.press('Escape');
          check(await dialog.count() === 0, `${label}: Escape closes modal`);
          check(await opener.evaluate((e) => e === document.activeElement), `${label}: focus returns to opener`);
        }
      }
      console.log(`CHECK ${label}`);
    }
    check(errors.length === 0, `${width}px no runtime errors: ${JSON.stringify(errors)}`);
    check(mutations.length === 0, `${width}px real data review sends no writes: ${JSON.stringify(mutations)}`);
    await context.close();
  }
  if (process.env.TRADE_CRM_LOGIN_ORIGIN) {
    const loginOrigin = process.env.TRADE_CRM_LOGIN_ORIGIN;
    assert.match(loginOrigin, /^http:\/\/localhost(?::\d+)?\/trade-crm$/, 'Login review must use the local installation.');
    for (const width of widths) {
      const context = await browser.newContext({ viewport: { width, height: 844 }, locale: 'fa-IR' });
      const page = await context.newPage();
      for (const [name, path] of [['login', '/wp-login.php'], ['recovery', '/wp-login.php?action=lostpassword']]) {
        await page.goto(loginOrigin + path, { waitUntil: 'networkidle' });
        await page.locator('#login').waitFor();
        const metrics = await page.evaluate(() => ({
          overflow: document.documentElement.scrollWidth - innerWidth,
          smallInputs: [...document.querySelectorAll('input:not([type="hidden"]):not([type="checkbox"]):not([type="submit"])')].filter((e) => parseFloat(getComputedStyle(e).fontSize) < 16).length,
          smallLinks: [...document.querySelectorAll('#nav a,#backtoblog a,.button-primary')].filter((e) => { const r = e.getBoundingClientRect(); return r.height > 0 && (r.height < 43.5 || r.width < 43.5); }).length,
        }));
        check(metrics.overflow <= 1, `${width}px ${name}: no overflow`);
        check(metrics.smallInputs === 0, `${width}px ${name}: inputs >=16px`);
        check(metrics.smallLinks === 0, `${width}px ${name}: primary action and navigation >=44px`);
        if (width === 390) await page.screenshot({ path: join(output, `after-${name}-${width}.png`), fullPage: true });
      }
      await context.close();
    }
  }
} finally {
  await browser.close();
  await writeFile(join(output, 'responsive-results.json'), JSON.stringify({ engine: 'Chrome desktop, viewport testing; not a real iPhone/Safari test', checks, failures }, null, 2));
}
console.log(`${checks.length - failures.length}/${checks.length} checks passed. Artifacts: ${output}`);
for (const failure of failures) console.error('FAIL ' + failure);
assert.equal(failures.length, 0, 'Responsive regressions found.');

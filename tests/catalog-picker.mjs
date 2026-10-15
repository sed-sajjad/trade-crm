/** Real Chrome UI smoke test against an isolated WordPress test database only. */
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { createRequire } from 'node:module';
import { randomBytes } from 'node:crypto';
import { createServer } from 'node:net';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { tmpdir } from 'node:os';
import { mkdir } from 'node:fs/promises';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.TRADE_CRM_PLAYWRIGHT_MODULE || 'playwright');
const database = process.env.TRADE_CRM_TEST_DB_NAME || '';
assert.match(database, /^trade_crm_catalog_test_[a-z0-9_]+$/, 'A guarded isolated test DB is mandatory.');
assert.ok(process.env.TRADE_CRM_WP_ROOT, 'TRADE_CRM_WP_ROOT is mandatory.');
const testsDir = dirname(fileURLToPath(import.meta.url));
const token = randomBytes(24).toString('hex');
const portProbe = createServer();
await new Promise((resolve) => portProbe.listen(0, '127.0.0.1', resolve));
const port = portProbe.address().port;
await new Promise((resolve) => portProbe.close(resolve));
const origin = `http://127.0.0.1:${port}`;
const php = process.env.TRADE_CRM_PHP_BINARY || 'php';
const server = spawn(php, ['-S', `127.0.0.1:${port}`, '-t', process.env.TRADE_CRM_WP_ROOT, join(testsDir, 'catalog-ui-router.php')], {
  env: { ...process.env, TRADE_CRM_HTTP_TEST_TOKEN: token }, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'],
});
let serverLog = '';
server.stdout.on('data', (chunk) => { serverLog += chunk; });
server.stderr.on('data', (chunk) => { serverLog += chunk; });
const screenshots = join(tmpdir(), `trade-crm-catalog-ui-${Date.now()}`);
await mkdir(screenshots);
let browser;
try {
  let ready = false;
  for (let attempt = 0; attempt < 80; attempt++) {
    try {
      const response = await fetch(`${origin}/_crm_test_ping`, { headers: { 'X-CRM-Test-Token': token } });
      if (response.ok && await response.text() === 'ready') { ready = true; break; }
    } catch {}
    await new Promise((resolve) => setTimeout(resolve, 100));
  }
  assert.ok(ready, 'Isolated PHP test server did not start.');
  const denied = await fetch(`${origin}/_crm_test_ping`);
  assert.equal(denied.status, 404, 'HTTP test adapter must reject requests without its token.');
  browser = await chromium.launch({ executablePath: process.env.TRADE_CRM_CHROME_BINARY, headless: true });
  for (const scenario of [
    { name: 'desktop', viewport: { width: 1366, height: 900 }, isMobile: false, hasTouch: false, phone: '+989121111111' },
    { name: 'mobile-emulation', viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, phone: '+989121111112' },
  ]) {
    const context = await browser.newContext({ viewport: scenario.viewport, isMobile: scenario.isMobile, hasTouch: scenario.hasTouch, extraHTTPHeaders: { 'X-CRM-Test-Token': token }, serviceWorkers: 'block', locale: 'fa-IR' });
    const page = await context.newPage();
    const runtimeErrors = [];
    const failedApis = [];
    page.on('pageerror', (error) => runtimeErrors.push(error.message));
    page.on('response', (response) => { if (response.url().includes('/wp-json/') && response.status() >= 400) failedApis.push(`${response.status()} ${response.url()}`); });
    let savedContactId;
    try {
      await page.goto(`${origin}/crm/?view=form`);
      const name = page.getByRole('textbox', { name: 'نام مخاطب یا شرکت *', exact: true });
      const phone = page.getByRole('textbox', { name: 'شماره تماس', exact: true });
      const query = page.getByRole('textbox', { name: 'جست‌وجوی حوزه فعالیت', exact: true });
      await name.waitFor();
      const fixtureName = `آزمون مرورگر حوزه ${scenario.name}`;
      await name.fill(fixtureName);
      await phone.fill(scenario.phone);
      await query.fill('كيوي');
      const kiwi = page.locator('.industry-search-result').filter({ hasText: 'کیوی' });
      await kiwi.waitFor();
      assert.match(await kiwi.innerText(), /مواد غذایی و نوشیدنی \/ میوه و سبزی تازه \/ کیوی/);
      await kiwi.getByRole('checkbox').check();
      assert.equal(await page.locator('.industry-selected-chip').count(), 1);
      await query.fill('پسته');
      const pistachio = page.locator('.industry-search-result').filter({ hasText: 'پسته' });
      await pistachio.waitFor();
      await pistachio.getByRole('checkbox').check();
      assert.equal(await page.locator('.industry-selected-chip').count(), 2);
      await query.fill('کیوی');
      const clear = page.getByRole('button', { name: 'پاک‌کردن عبارت جست‌وجوی حوزه', exact: true });
      if (scenario.hasTouch) await clear.tap();
      else { await clear.focus(); await clear.press('Enter'); }
      assert.equal(await query.inputValue(), '');
      assert.equal(await page.locator('.industry-selected-chip').count(), 2);
      assert.equal(await name.inputValue(), fixtureName);
      assert.equal(await phone.inputValue(), scenario.phone);
      assert.ok(await page.locator('.industry-picker-tree').isVisible());
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      assert.ok(overflow <= 1, `Unexpected horizontal page overflow: ${overflow}px`);
      await page.screenshot({ path: join(screenshots, `${scenario.name}.png`), fullPage: true });
      const saveResponsePromise = page.waitForResponse((response) => response.request().method() === 'POST' && response.url().includes('/contacts'));
      await page.getByRole('button', { name: 'ثبت مخاطب', exact: true }).click();
      const saveResponse = await saveResponsePromise;
      assert.equal(saveResponse.status(), 201, 'Real contact creation must succeed.');
      const payload = await saveResponse.json();
      savedContactId = payload.contact.id;
      const stored = await context.request.get(`${origin}/wp-json/trade-crm/v1/contacts/${savedContactId}`);
      assert.equal(stored.status(), 200);
      const contact = await stored.json();
      assert.equal(contact.name, fixtureName);
      assert.deepEqual(contact.industries.map((item) => item.name).sort(), ['پسته', 'کیوی'].sort());
      assert.ok(contact.numbers.some((number) => number.number_e164 === scenario.phone));
      assert.deepEqual(runtimeErrors, [], 'Browser runtime errors occurred.');
      assert.deepEqual(failedApis, [], 'REST requests failed.');
      console.log(`PASS ${scenario.name}: Arabic Kiwi search, two selections, clear button ${scenario.hasTouch ? 'tap' : 'keyboard'}, retained name/phone, no overflow, real SQL save with both industries`);
    } finally {
      if (savedContactId) {
        const cleanup = await context.request.post(`${origin}/_crm_test_cleanup`, { data: { id: savedContactId } });
        assert.equal(cleanup.status(), 200, 'Temporary contact cleanup failed.');
      }
      await context.close();
    }
  }
  console.log(`Screenshots: ${screenshots}`);
  console.log('PASS Chrome desktop + mobile emulation; this is not a real-phone/PWA-install test.');
} catch (error) {
  console.error(error.stack || error);
  console.error(serverLog.slice(-6000));
  process.exitCode = 1;
} finally {
  if (browser) await browser.close();
  server.kill();
}

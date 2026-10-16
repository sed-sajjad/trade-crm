/** Browser regression using the production build and synthetic, read-only API fixtures. */
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { createServer } from 'node:http';
import { readFile, mkdir } from 'node:fs/promises';
import { dirname, extname, join, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { tmpdir } from 'node:os';

const { chromium, webkit } = createRequire(import.meta.url)(process.env.TRADE_CRM_PLAYWRIGHT_MODULE || 'playwright');
const build = resolve(dirname(fileURLToPath(import.meta.url)), '../assets/build');
const entry = JSON.parse(await readFile(join(build, 'manifest.json'), 'utf8'))['index.html'];
const output = process.env.TRADE_CRM_FILTER_OUTPUT || join(tmpdir(), 'trade-crm-contact-filters');
await mkdir(output, { recursive: true });
const industries = [
  { id: 10, parent_id: 0, name: 'مواد غذایی', path_label: 'مواد غذایی' },
  { id: 11, parent_id: 10, name: 'پسته', path_label: 'مواد غذایی / خشکبار / پسته' },
  { id: 12, parent_id: 10, name: 'کیوی', path_label: 'مواد غذایی / میوه / کیوی' },
  { id: 20, parent_id: 0, name: 'ماشین‌آلات', path_label: 'ماشین‌آلات' },
  ...Array.from({ length: 8 }, (_, i) => ({ id: 30 + i, parent_id: 0, name: `دسته ${i}`, path_label: `دسته ${i}` })),
];
const contacts = [
  [1, 'آزمون پسته تهران', 11, 'IR', 'تهران', 1, 'new', 'today'],
  [2, 'آزمون کیوی شیراز', 12, 'IR', 'شیراز', 2, 'following_up', 'overdue'],
  [3, 'آزمون پسته دبی', 11, 'AE', 'دبی', 2, 'customer', 'future'],
  [4, 'آزمون ماشین تبریز', 20, 'IR', 'تبریز', 1, 'closed', null],
  [5, 'بایگانی پسته', 11, 'IR', 'تهران', 1, 'new', null, true],
  [6, 'بایگانی کیوی', 12, 'AE', 'دبی', 2, 'customer', null, true],
].map(([id, name, industry, country_code, city, assigned_user_id, status, due, archived = false]) => ({
  id, name, industry, country_code, city, assigned_user_id, status, due, archived,
  company: '', numbers: [], industries: industries.filter((i) => i.id === industry), followup_at: null,
}));
const pageResult = (items, next_cursor = null) => ({ items, next_cursor, has_more: !!next_cursor });
const normalize = (value) => value.replace(/ي/g, 'ی').replace(/ك/g, 'ک');
const server = createServer(async (request, response) => {
  try {
    const url = new URL(request.url, 'http://localhost');
    const params = url.searchParams;
    const json = (value) => { response.setHeader('Content-Type', 'application/json'); response.end(JSON.stringify(value)); };
    assert.equal(request.method, 'GET', 'The filter test must never write data.');
    if (url.pathname === '/api/bootstrap') return json({ phone_regions: ['IR', 'AE'], supervisors: [{ id: 1, name: 'مسئول اول' }, { id: 2, name: 'مسئول دوم' }] });
    if (url.pathname === '/api/industries') {
      const query = normalize(params.get('q') || '');
      const matches = industries.filter((item) => query ? item.path_label.includes(query) : item.parent_id === 0);
      return json(pageResult(params.has('cursor') ? matches.slice(8) : matches.slice(0, 8), !params.has('cursor') && matches.length > 8 ? 'next' : null));
    }
    if (url.pathname.startsWith('/api/industries/')) return json(industries.find((item) => item.id === Number(url.pathname.split('/').pop())));
    if (url.pathname === '/api/contacts') {
      let matches = contacts.filter((contact) => contact.archived === (params.get('archive') === 'archived'));
      for (const [key, field] of [['status', 'status'], ['assigned_user_id', 'assigned_user_id'], ['country', 'country_code']]) {
        if (params.has(key)) matches = matches.filter((item) => String(item[field]) === params.get(key));
      }
      if (params.has('city')) matches = matches.filter((item) => item.city.includes(params.get('city')));
      if (params.has('q')) matches = matches.filter((item) => item.name.includes(params.get('q')));
      if (params.has('industry_id')) matches = matches.filter((item) => item.industry === Number(params.get('industry_id')) || (params.get('industry_id') === '10' && [11, 12].includes(item.industry)));
      if (params.has('followup')) matches = matches.filter((item) => params.get('followup') === 'has' ? !!item.due : params.get('followup') === 'none' ? !item.due : item.due === params.get('followup'));
      const offset = params.has('cursor') ? 2 : 0;
      return json(pageResult(matches.slice(offset, offset + 2), matches.length > offset + 2 ? 'page2' : null));
    }
    if (url.pathname === '/crm/') {
      const origin = `http://${request.headers.host}`;
      const config = { apiRoot: `${origin}/api/`, nonce: 'fixture', appUrl: `${origin}/crm/`, user: { id: 1, name: 'آزمایش' }, appName: 'آزمون فیلترها' };
      response.setHeader('Content-Type', 'text/html; charset=utf-8');
      response.end(`<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">${entry.css.map((path) => `<link rel="stylesheet" href="/${path}">`).join('')}<div id="trade-crm-root"></div><script>window.tradeCrmConfig=${JSON.stringify(config)}</script><script type="module" src="/${entry.file}"></script></html>`);
      return;
    }
    const path = resolve(build, '.' + url.pathname);
    assert.ok(path.startsWith(build + sep));
    response.setHeader('Content-Type', { '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.woff': 'font/woff' }[extname(path)] || 'application/octet-stream');
    response.end(await readFile(path));
  } catch (error) { response.statusCode = 500; response.end(error.message); }
});
await new Promise((done) => server.listen(0, '127.0.0.1', done));
const origin = `http://127.0.0.1:${server.address().port}`;
let checks = 0;
try {
  for (const engine of (process.env.TRADE_CRM_FILTER_ENGINES || 'webkit,chromium').split(',')) {
    const browser = await ({ chromium, webkit }[engine]).launch({ headless: true, ...(engine === 'chromium' && process.env.TRADE_CRM_CHROME_BINARY ? { executablePath: process.env.TRADE_CRM_CHROME_BINARY } : {}) });
    try {
      for (const width of [390, 1366]) {
        const mobile = width === 390;
        const context = await browser.newContext({ viewport: { width, height: 844 }, isMobile: mobile, hasTouch: mobile, locale: 'fa-IR', serviceWorkers: 'block' });
        const page = await context.newPage();
        page.setDefaultTimeout(5000);
        const errors = [];
        const queries = [];
        page.on('pageerror', (error) => errors.push(error.message));
        page.on('request', (request) => { if (new URL(request.url()).pathname === '/api/contacts') queries.push(new URL(request.url()).searchParams); });
        const press = async (locator) => mobile ? locator.tap() : locator.click();
        const names = (ids) => ids.map((id) => contacts.find((item) => item.id === id).name);
        const expectContacts = async (ids) => {
          await page.waitForFunction((expected) => JSON.stringify(Array.from(document.querySelectorAll('.contact-name'), (node) => node.textContent)) === JSON.stringify(expected), names(ids));
          checks++;
        };
        const change = async (action, expectedParams, ids) => {
          const start = queries.length;
          const response = page.waitForResponse((response) => {
            const url = new URL(response.url());
            return url.pathname === '/api/contacts' && Object.entries(expectedParams).every(([key, value]) => url.searchParams.get(key) === value);
          });
          await action();
          assert.equal((await response).status(), 200);
          await expectContacts(ids);
          assert.ok(queries.slice(start).some((params) => Object.entries(expectedParams).every(([key, value]) => params.get(key) === value)), `Missing request parameters ${JSON.stringify(expectedParams)}`);
        };
        const openPage = async (view = 'contacts') => {
          await page.goto(`${origin}/crm/?view=${view}`);
          await expectContacts(view === 'archive' ? [5, 6] : [1, 2]);
          await press(page.locator('.more-filters summary'));
        };
        const industryInput = page.getByRole('textbox', { name: 'فیلتر حوزه فعالیت', exact: true });
        const selectIndustry = async (query, label) => {
          await press(industryInput);
          await industryInput.fill(query);
          const option = page.locator('.industry-filter-results button').filter({ hasText: label });
          await option.waitFor();
          await press(option);
        };
        try {
          await openPage();
          await change(() => selectIndustry('پسته', 'پسته'), { industry_id: '11' }, [1, 3]);
          assert.equal(await industryInput.inputValue(), 'مواد غذایی / خشکبار / پسته');
          assert.equal(await page.locator('.industry-filter-results').count(), 0);
          await page.screenshot({ path: join(output, `${engine}-${width}-pistachio.png`), fullPage: true });
          await change(() => selectIndustry('كيوي', 'کیوی'), { industry_id: '12' }, [2]);
          await change(() => selectIndustry('ماشین', 'ماشین‌آلات'), { industry_id: '20' }, [4]);
          await change(() => press(page.getByRole('button', { name: 'پاک‌کردن فیلتر حوزه', exact: true })), { industry_id: null }, [1, 2]);
          await press(industryInput);
          await page.locator('.industry-filter-results button').first().waitFor();
          const more = page.locator('.industry-filter-results').getByRole('button', { name: 'نتایج بیشتر', exact: true });
          await press(more);
          await page.locator('.industry-filter-results button').filter({ hasText: 'دسته 7' }).waitFor();
          assert.equal(await page.locator('.industry-filter-results').count(), 1, 'Loading more must keep the picker open.');
          await press(page.locator('.field-label').filter({ hasText: 'حوزه فعالیت' }));
          assert.equal(await page.locator('.industry-filter-results').count(), 1, 'An internal non-focusable tap must not close the picker.');
          await press(page.getByRole('heading', { name: 'مخاطبان', exact: true }));
          assert.equal(await page.locator('.industry-filter-results').count(), 0, 'An outside tap must dismiss the picker.');
          await industryInput.focus();
          await page.keyboard.press('Escape');
          assert.equal(await page.locator('.industry-filter-results').count(), 0);
          await press(industryInput);
          assert.equal(await page.locator('.industry-filter-results').count(), 1, 'The focused input must reopen on tap after Escape.');
          await page.getByLabel('شهر', { exact: true }).focus();
          assert.equal(await page.locator('.industry-filter-results').count(), 0, 'Moving keyboard focus outside must dismiss the picker.');
          await industryInput.focus();
          await page.keyboard.press('Tab');
          await page.keyboard.press('Tab');
          assert.equal(await page.locator('.industry-filter-results').count(), 1, 'Keyboard focus may move into results.');
          await page.keyboard.press('Enter');
          await expectContacts([1, 2]);
          await change(() => press(page.getByRole('button', { name: 'نمایش مخاطبان بیشتر', exact: true })), { industry_id: '10', cursor: 'page2' }, [1, 2, 3]);
          await change(() => selectIndustry('پسته', 'پسته'), { industry_id: '11', cursor: null }, [1, 3]);
          await change(() => press(page.getByRole('button', { name: 'پاک‌کردن فیلتر حوزه', exact: true })), { industry_id: null }, [1, 2]);
          for (const [label, key, cases] of [
            ['مسئول پرونده', 'assigned_user_id', [['1', [1, 4]], ['2', [2, 3]]]],
            ['کشور', 'country', [['IR', [1, 2]], ['AE', [3]]]],
            ['فیلتر وضعیت مخاطبان', 'status', [['new', [1]], ['following_up', [2]], ['customer', [3]], ['closed', [4]]]],
            ['فیلتر موعد پیگیری', 'followup', [['today', [1]], ['overdue', [2]], ['has', [1, 2]], ['none', [4]]]],
          ]) {
            for (const [value, ids] of cases) await change(() => page.getByLabel(label, { exact: true }).selectOption(value), { [key]: value }, ids);
            await change(() => page.getByLabel(label, { exact: true }).selectOption(''), { [key]: null }, [1, 2]);
          }
          await change(() => page.getByLabel('شهر', { exact: true }).fill('شیراز'), { city: 'شیراز' }, [2]);
          await change(() => page.getByLabel('شهر', { exact: true }).fill(''), { city: null }, [1, 2]);
          await change(() => selectIndustry('پسته', 'پسته'), { industry_id: '11' }, [1, 3]);
          await change(() => page.getByLabel('کشور', { exact: true }).selectOption('AE'), { industry_id: '11', country: 'AE' }, [3]);
          await change(() => page.getByLabel('مسئول پرونده', { exact: true }).selectOption('1'), { industry_id: '11', country: 'AE', assigned_user_id: '1' }, []);
          await change(() => page.getByLabel('مسئول پرونده', { exact: true }).selectOption('2'), { industry_id: '11', country: 'AE', assigned_user_id: '2' }, [3]);
          await change(() => page.getByLabel('شهر', { exact: true }).fill('دبی'), { city: 'دبی' }, [3]);
          await change(() => page.getByLabel('جست‌وجوی مخاطبان', { exact: true }).fill('پسته'), { q: 'پسته', city: 'دبی', country: 'AE', industry_id: '11', assigned_user_id: '2' }, [3]);
          await openPage('archive');
          await change(() => selectIndustry('پسته', 'پسته'), { archive: 'archived', industry_id: '11' }, [5]);
          await change(() => press(page.getByRole('button', { name: 'پاک‌کردن فیلتر حوزه', exact: true })), { archive: 'archived', industry_id: null }, [5, 6]);
          await change(() => page.getByLabel('کشور', { exact: true }).selectOption('AE'), { country: 'AE', archive: 'archived' }, [6]);
          assert.deepEqual(errors, []);
          console.log(`PASS ${engine} ${width}: industry selection/replacement/clear, pagination, outside tap, keyboard, all filters, combinations, empty results, archive`);
        } catch (error) {
          console.error({ engine, width, errors, queries: queries.map((params) => Object.fromEntries(params)) });
          await page.screenshot({ path: join(output, `${engine}-${width}-failure.png`), fullPage: true });
          throw error;
        } finally { await context.close(); }
      }
    } finally { await browser.close(); }
  }
  console.log(`PASS ${checks} rendered-result assertions. Screenshots: ${output}. Mobile browser emulation; no physical phone tested.`);
} finally { await new Promise((done) => server.close(done)); }

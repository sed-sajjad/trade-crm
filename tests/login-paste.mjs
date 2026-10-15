/** Local login regression: native Chrome clipboard and WebKit ClipboardEvent handling. */
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { chromium, webkit } = require(process.env.TRADE_CRM_PLAYWRIGHT_MODULE || 'playwright');
const origin = process.env.TRADE_CRM_LOGIN_ORIGIN || 'http://localhost/trade-crm';
assert.match(origin, /^http:\/\/(?:localhost|127\.0\.0\.1)(?::\d+)?(?:\/trade-crm)?$/, 'Only test a local installation.');
const email = 'sample.user@example.com';
const formTail = 'رمز عبور                        در دستگاه شخصی، «مرا به خاطر بسپار» را برای ماندن ورود تا ۳۰ روز انتخاب کنید. روی دستگاه مشترک این گزینه را فعال نکنید.  مرا به خاطر بسپار                            رمز عبورتان را گم کرده‌اید؟     → رفتن به magica CRM';
const scenarios = [
  [email, email],
  [`\u200f\u00a0 ${email}\u200b\t `, email],
  [`${email}      ${formTail}`, email],
  ['a user name', 'a user name'],
  ['نام\u200cکاربری', 'نام\u200cکاربری'],
  [`${email} other text`, `${email} other text`],
  [`${email} ${formTail} another@example.com`, `${email} ${formTail} another@example.com`],
];
let checks = 0;

for (const engine of ['chromium', 'webkit']) {
  const browser = await ({ chromium, webkit }[engine]).launch({
    headless: true,
    ...(engine === 'chromium' && process.env.TRADE_CRM_CHROME_BINARY ? { executablePath: process.env.TRADE_CRM_CHROME_BINARY } : {}),
  });
  try {
    for (const width of [1366, 390]) {
      const context = await browser.newContext({ viewport: { width, height: 844 }, isMobile: width === 390, hasTouch: width === 390, locale: 'fa-IR', ...(engine === 'chromium' ? { permissions: ['clipboard-read', 'clipboard-write'] } : {}) });
      const page = await context.newPage();
      const errors = [];
      const writes = [];
      page.on('pageerror', (error) => errors.push(error.message));
      await page.route('**/*', (route) => {
        if (!['GET', 'HEAD'].includes(route.request().method())) { writes.push(route.request().method()); return route.abort(); }
        return route.continue();
      });
      await page.goto(`${origin}/wp-login.php?redirect_to=${encodeURIComponent(`${origin}/crm/`)}`, { waitUntil: 'networkidle' });
      assert.equal(await page.locator('#trade-crm-login-js').count(), 1, 'WordPress must enqueue the fix.');
      const username = page.locator('#user_login');
      async function paste(target, text) {
        await target.fill('');
        if (engine === 'chromium') {
          await page.evaluate((value) => navigator.clipboard.writeText(value), text);
          await target.focus();
          await page.keyboard.press('Control+V');
        } else {
          await target.evaluate((input, value) => {
            const clipboardData = new DataTransfer();
            clipboardData.setData('text/plain', value);
            const event = new ClipboardEvent('paste', { clipboardData, bubbles: true, cancelable: true });
            if (input.dispatchEvent(event)) input.setRangeText(value, input.selectionStart, input.selectionEnd, 'end');
          }, text);
        }
      }
      async function copy(start, end) {
        await username.focus();
        await username.evaluate((input, selection) => input.setSelectionRange(...selection), [start, end]);
        if (engine === 'chromium') {
          await page.keyboard.press('Control+C');
          return page.evaluate(() => navigator.clipboard.readText());
        }
        return username.evaluate((input) => {
          const clipboardData = new DataTransfer();
          input.dispatchEvent(new ClipboardEvent('copy', { clipboardData, bubbles: true, cancelable: true }));
          return clipboardData.getData('text/plain');
        });
      }
      for (const [pasted, expected] of scenarios) {
        await paste(username, pasted);
        assert.equal(await username.inputValue(), expected, `${engine} ${width}px paste`);
        assert.equal(await copy(0, expected.length), expected, `${engine} ${width}px copy must contain only the field selection`);
        checks += 2;
      }
      await username.fill(email);
      assert.equal(await copy(0, 6), 'sample', 'Partial copy must remain partial.');
      const password = page.locator('#user_pass');
      const originalPassword = ` ${email}\u200f ${formTail}`;
      await paste(password, originalPassword);
      assert.equal(await password.inputValue(), originalPassword, 'Password must remain exact.');
      assert.deepEqual(errors, []);
      assert.deepEqual(writes, []);
      checks += 4;
      await page.goto(`${origin}/wp-login.php?action=lostpassword`, { waitUntil: 'networkidle' });
      assert.equal(await page.locator('#trade-crm-login-js').count(), 0, 'Recovery form must not load the login-only script.');
      checks++;
      console.log(`PASS ${engine} ${width}px (${engine === 'chromium' ? 'native clipboard' : 'synthetic ClipboardEvents; not the iOS menu'})`);
      await context.close();
    }
  } finally { await browser.close(); }
}
console.log(`PASS ${checks} local login checks; no form submitted. Real iPhone/Safari menu testing is still required.`);

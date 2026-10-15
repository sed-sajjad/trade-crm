/** Run only against a local WordPress copy: PHP_BINARY=... TRADE_CRM_WP_ROOT=... node tests/site-isolation.mjs */
import { spawn } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { once } from 'node:events';
import assert from 'node:assert/strict';
import { createServer } from 'node:net';
import { writeFile } from 'node:fs/promises';
import { createContext, runInContext } from 'node:vm';

assert(process.env.TRADE_CRM_WP_ROOT, 'Set TRADE_CRM_WP_ROOT to a LOCAL WordPress directory.');
const socket = createServer();
socket.listen(0, '127.0.0.1');
await once(socket, 'listening');
const port = socket.address().port;
await new Promise(resolve => socket.close(resolve));
const token = randomBytes(24).toString('hex');
const php = spawn(process.env.PHP_BINARY || 'php', ['-S', `127.0.0.1:${port}`, fileURLToPath(new URL('./site-isolation-router.php', import.meta.url))], {
  env: { ...process.env, TRADE_CRM_HTTP_TEST_TOKEN: token }, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'],
});
let log = '';
php.stderr.on('data', chunk => { log += chunk; });
php.stdout.on('data', chunk => { log += chunk; });
const base = `http://127.0.0.1:${port}`;
const results = [];
const check = (condition, label) => { assert(condition, label); results.push(label); console.log('PASS ' + label); };
async function get(path, mode = 'on', role = '') {
  const response = await fetch(base + path, { redirect: 'manual', signal: AbortSignal.timeout(45000), headers: {
    'X-CRM-Test-Token': token, 'X-CRM-Test-Mode': mode, 'X-CRM-Test-Role': role,
  } });
  return { status: response.status, headers: Object.fromEntries(response.headers), body: await response.text() };
}
function publicSignature(result) {
  const tags = result.body.match(/<title\b[^>]*>[\s\S]*?<\/title>|<h1\b[^>]*>[\s\S]*?<\/h1>|<meta\b[^>]*>|<link\b[^>]*>|<script\b[^>]*src=[^>]*>|<script\b[^>]*type=["']application\/ld\+json["'][^>]*>[\s\S]*?<\/script>/gi) || [];
  return { status: result.status, location: result.headers.location || '', robots: result.headers['x-robots-tag'] || '', cache: result.headers['cache-control'] || '', tags };
}
try {
  let ready = false;
  for (let attempt = 0; attempt < 50; attempt++) {
    try { ready = (await get('/_crm_test_ping')).body === 'ready'; } catch {}
    if (ready) break;
    await new Promise(resolve => setTimeout(resolve, 100));
  }
  assert(ready, 'Local PHP HTTP server did not start.');
  const publicOnly = process.env.TRADE_CRM_PUBLIC_ONLY === '1';
  const mounts = publicOnly ? [''] : ['', '/subsite'];
  for (const mount of mounts) {
    const paths = publicOnly ? ['/', '/?s=test', '/wp-login.php', '/wp-login.php?action=lostpassword', '/robots.txt', '/sitemap_index.xml', '/en/', '/ar/'] : ['/', '/?s=test', '/sample-page/', '/hello-world/', '/wp-login.php', '/wp-login.php?action=lostpassword', '/robots.txt', '/wp-sitemap.xml', '/feed/', '/does-not-exist-crm-test/'];
    for (const path of paths) {
      const before = await get(mount + path, 'off');
      const after = await get(mount + path);
      assert(before.status < 500 && after.status < 500, 'HTTP server error on ' + mount + path);
      if (['/', '/wp-login.php'].includes(path)) assert.equal(before.status, 200, 'Baseline is not a working page: ' + path);
      assert(!/Fatal error:|Parse error:/.test(before.body + after.body), 'PHP error in public response');
      if (path.startsWith('/wp-login.php')) {
        const signature = publicSignature(after);
        signature.tags = signature.tags.filter(tag => !/trade-crm-login-(css|js)/.test(tag));
        assert.deepEqual(signature, publicSignature(before), 'Login headers or core assets changed: ' + mount + path);
        check(after.body.includes('trade-crm-login-css') && after.body.includes('trade-crm-login-intro') && !after.body.includes('id="language-switcher"'), `minimal login presentation: ${mount + path}`);
        const formTag = body => body.match(/<form\b[^>]*id=["'](?:loginform|lostpasswordform)["'][^>]*>/i)?.[0];
        check(Boolean(formTag(after.body)) && formTag(after.body) === formTag(before.body), `WordPress login form action and method preserved: ${mount + path}`);
      } else {
        assert.deepEqual(publicSignature(after), publicSignature(before), 'Public response changed: ' + mount + path);
        check(!after.body.includes('trade-crm-root') && !after.body.includes('trade-crm-login-css'), `unchanged public status, SEO, assets: ${mount + path}`);
      }
    }
    const home = await get(mount + '/');
    for (const flag of ['trade_crm_app', 'trade_crm_manifest', 'trade_crm_sw']) {
      const injected = await get(mount + '/?' + flag + '=1');
      const cleanQuery = await get(mount + '/?' + flag + '=1', 'off');
      assert.deepEqual(publicSignature(injected), publicSignature(cleanQuery), `Public query handling changed: ${flag}`);
      check(injected.status === home.status && !injected.body.includes('trade-crm-root') && !injected.headers.location && (injected.body.match(/<title>.*?<\/title>/)?.[0] === home.body.match(/<title>.*?<\/title>/)?.[0]), `query parameter cannot replace homepage: ${mount || '/'} ${flag}`);
    }
    const guest = await get(mount + '/crm/');
    check(guest.status === 302 && guest.headers.location.includes('wp-login.php'), `guest CRM redirects only to login: ${mount || '/'}`);
    check(guest.headers['cache-control'].includes('no-store') && guest.headers['x-robots-tag'].includes('noindex'), 'guest CRM redirect is private and noindex');
    const manifest = await get(mount + '/trade-crm-manifest.webmanifest');
    const data = JSON.parse(manifest.body);
    check(manifest.status === 200 && data.scope === base + mount + '/crm/' && data.start_url === data.scope, 'PWA scope is limited to CRM');
    check(manifest.headers['x-robots-tag'].includes('noindex'), 'manifest is noindex');
    const worker = await get(mount + '/crm/sw.js');
    check(worker.status === 200 && worker.headers['service-worker-allowed'] === base + mount + '/crm/' && worker.headers['x-robots-tag'].includes('noindex'), 'service worker has only CRM scope and noindex');
    const listeners = {};
    const deleted = [];
    const context = createContext({ URL, self: { location: new URL(base), clients: { claim: async () => {} }, addEventListener: (name, handler) => { listeners[name] = handler; } }, caches: {
      keys: async () => [runInContext('CACHE', context), runInContext('CACHE_PREFIX', context) + 'old', 'another-plugin-cache', 'trade-crm-another-installation-1.1.3'],
      delete: async key => { deleted.push(key); },
    } });
    runInContext(worker.body, context);
    let activation;
    listeners.activate({ waitUntil: promise => { activation = promise; } });
    await activation;
    check(deleted.length === 1 && deleted[0] === runInContext('CACHE_PREFIX', context) + 'old', 'worker only removes old caches belonging to this CRM installation');
    let intercepted = false;
    for (const path of ['/', '/wp-login.php', '/wp-admin/', '/wp-admin/admin-ajax.php?action=trade_crm_session', '/wp-json/wp/v2/types', '/?rest_route=/trade-crm/v1/bootstrap', '/wp-content/themes/site/style.css']) {
      listeners.fetch({ request: { url: base + mount + path, method: 'GET', mode: 'navigate' }, respondWith: () => { intercepted = true; } });
    }
    check(!intercepted, 'worker does not intercept public pages, login, admin or REST requests');
    const api = await get(mount + '/?rest_route=/trade-crm/v1/bootstrap');
    check(api.status === 401 && api.headers['cache-control'].includes('no-store') && api.headers['x-robots-tag'].includes('noindex'), 'guest API denied and not cacheable/indexable');
    const guestSession = await get(mount + '/wp-admin/admin-ajax.php?action=trade_crm_session');
    check(guestSession.status === 401 && !JSON.parse(guestSession.body).nonce && guestSession.headers['cache-control'].includes('no-store'), 'guest session renewal denied without leaking a nonce');
    const publicApiBefore = await get(mount + '/?rest_route=/wp/v2/types', 'off');
    const publicApiAfter = await get(mount + '/?rest_route=/wp/v2/types');
    check(publicApiAfter.status === publicApiBefore.status && publicApiAfter.headers['cache-control'] === publicApiBefore.headers['cache-control'] && publicApiAfter.headers['x-robots-tag'] === publicApiBefore.headers['x-robots-tag'], 'other REST namespaces keep their cache and robots policy');
    if (!publicOnly) {
      const deniedSession = await get(mount + '/wp-admin/admin-ajax.php?action=trade_crm_session', 'on', 'subscriber');
      check(deniedSession.status === 403 && !JSON.parse(deniedSession.body).nonce, 'session renewal requires the CRM capability');
      const renewedSession = await get(mount + '/wp-admin/admin-ajax.php?action=trade_crm_session', 'on', 'administrator');
      const sessionData = JSON.parse(renewedSession.body);
      check(renewedSession.status === 200 && sessionData.user_id > 0 && !!sessionData.nonce && !!sessionData.logout_url && renewedSession.headers['cache-control'].includes('no-store') && renewedSession.headers.vary.includes('Cookie'), 'valid cookie session can renew its nonce privately without a prior nonce');
      const denied = await get(mount + '/crm/', 'on', 'subscriber');
      check(denied.status === 403 && denied.headers['cache-control'].includes('no-store') && denied.headers['x-robots-tag'].includes('noindex'), 'user without CRM capability gets a private 403');
      const app = await get(mount + '/crm/', 'on', 'administrator');
      check(app.status === 200 && app.body.includes('trade-crm-root') && app.body.includes('name="robots" content="noindex') && app.headers['cache-control'].includes('no-store'), 'administrator CRM renders HTTP 200 with noindex and no-store');
      const privateApi = await get(mount + '/?rest_route=/trade-crm/v1/bootstrap', 'on', 'administrator');
      check(privateApi.status === 200 && privateApi.headers['cache-control'].includes('no-store'), 'administrator API returns private data with no-store');
    }
  }
  console.log(`All ${results.length} HTTP isolation checks passed.`);
} finally {
  php.kill();
  if (process.env.TRADE_CRM_HTTP_REPORT) {
    await writeFile(process.env.TRADE_CRM_HTTP_REPORT, JSON.stringify({ results, log }, null, 2));
  }
}

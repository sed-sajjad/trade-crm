import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { api, refreshSession } from './api';

const sessionUrl = 'https://crm.example/wp-admin/admin-ajax.php?action=trade_crm_session';
const fetchMock = vi.fn();
const stale = vi.fn();
const restored = vi.fn();
const json = (body: unknown, status = 200, headers: Record<string, string> = {}) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json', ...headers } });
const expired = () => json({ code: 'rest_cookie_invalid_nonce', message: 'Expired token' }, 403);
const session = (userId = 7) => json({ user_id: userId, nonce: 'fresh-token', logout_url: 'https://crm.example/fresh-logout' });

beforeEach(() => {
  window.tradeCrmConfig = { apiRoot: 'https://crm.example/wp-json/trade-crm/v1/', nonce: 'expired-token', sessionUrl, appUrl: 'https://crm.example/crm/', loginUrl: 'https://crm.example/login', logoutUrl: 'https://crm.example/logout', user: { id: 7, name: 'Test' }, appName: 'CRM', version: 'test' };
  fetchMock.mockReset(); stale.mockClear(); restored.mockClear();
  vi.stubGlobal('fetch', fetchMock);
  window.addEventListener('tradecrm:auth-stale', stale);
  window.addEventListener('tradecrm:auth-restored', restored);
});

afterEach(() => {
  window.removeEventListener('tradecrm:auth-stale', stale);
  window.removeEventListener('tradecrm:auth-restored', restored);
  vi.unstubAllGlobals();
});

describe('WordPress session recovery', () => {
  it('renews an expired CSRF token and replays the same write with its request ID', async () => {
    fetchMock.mockResolvedValueOnce(expired()).mockResolvedValueOnce(session()).mockResolvedValueOnce(json({ id: 42 }, 201));
    const body = JSON.stringify({ name: 'Unsent form', request_id: 'same-request' });
    expect(await api('contacts', { method: 'POST', body, headers: { 'X-Trade-CRM-Request-ID': 'same-request' } })).toEqual({ id: 42 });
    expect(fetchMock).toHaveBeenCalledTimes(3);
    expect(fetchMock.mock.calls[1]?.[0]).toBe(sessionUrl);
    for (const index of [0, 2]) {
      const init = fetchMock.mock.calls[index]?.[1];
      expect(init.body).toBe(body);
      expect(init.headers.get('X-Trade-CRM-Request-ID')).toBe('same-request');
      expect(init.credentials).toBe('same-origin');
    }
    expect(fetchMock.mock.calls[2]?.[1].headers.get('X-WP-Nonce')).toBe('fresh-token');
    expect(window.tradeCrmConfig.logoutUrl).toBe('https://crm.example/fresh-logout');
    expect(stale).not.toHaveBeenCalled();
    expect(restored).toHaveBeenCalledTimes(1);
  });

  it('shares one renewal between concurrent expired requests', async () => {
    fetchMock.mockImplementation(async (url, init) => String(url) === sessionUrl ? session() : init.headers.get('X-WP-Nonce') === 'expired-token' ? expired() : json({ ok: true }));
    await Promise.all([api('bootstrap'), api('dashboard')]);
    expect(fetchMock.mock.calls.filter(([url]) => String(url) === sessionUrl)).toHaveLength(1);
    expect(stale).not.toHaveBeenCalled();
  });

  it('uses the current nonce returned by normal REST requests', async () => {
    fetchMock.mockResolvedValueOnce(json({ ok: true }, 200, { 'X-WP-Nonce': 'newer-token' }));
    await api('bootstrap');
    expect(window.tradeCrmConfig.nonce).toBe('newer-token');
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('requires login after the cookie expires without replaying the write', async () => {
    fetchMock.mockResolvedValueOnce(expired()).mockResolvedValueOnce(json({ code: 'trade_crm_auth_required' }, 401));
    await expect(api('contacts', { method: 'POST', body: '{}' })).rejects.toMatchObject({ status: 401 });
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(stale).toHaveBeenCalledTimes(1);
    expect(restored).not.toHaveBeenCalled();
  });

  it('does not submit an old form as another user after an account switch', async () => {
    fetchMock.mockResolvedValueOnce(expired()).mockResolvedValueOnce(session(99));
    await expect(api('contacts', { method: 'POST', body: '{}' })).rejects.toMatchObject({ code: 'trade_crm_account_changed', status: 401 });
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(window.tradeCrmConfig.nonce).toBe('expired-token');
    expect(stale).toHaveBeenCalledTimes(1);
  });

  it('stops after one renewal if the new token is also rejected', async () => {
    fetchMock.mockResolvedValueOnce(expired()).mockResolvedValueOnce(session()).mockResolvedValueOnce(expired());
    await expect(api('contacts', { method: 'POST', body: '{}' })).rejects.toMatchObject({ status: 403 });
    expect(fetchMock).toHaveBeenCalledTimes(3);
    expect(stale).toHaveBeenCalledTimes(1);
  });

  it('keeps connection failures separate from an expired login', async () => {
    fetchMock.mockResolvedValueOnce(expired()).mockRejectedValueOnce(new TypeError('Offline'));
    await expect(api('bootstrap')).rejects.toThrow('Offline');
    expect(stale).not.toHaveBeenCalled();
    expect(restored).not.toHaveBeenCalled();
  });

  it('does not replay a write after an ambiguous server failure', async () => {
    fetchMock.mockResolvedValueOnce(json({ message: 'Server failure' }, 500));
    await expect(api('contacts', { method: 'POST', body: '{}' })).rejects.toMatchObject({ status: 500 });
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('does not replay a write after a connection failure', async () => {
    fetchMock.mockRejectedValueOnce(new TypeError('Connection lost'));
    await expect(api('contacts', { method: 'POST', body: '{}' })).rejects.toThrow('Connection lost');
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('respects cancellation while a shared renewal is in progress', async () => {
    const controller = new AbortController();
    fetchMock.mockResolvedValueOnce(expired()).mockImplementationOnce(async () => { controller.abort(); return session(); });
    await expect(api('contacts', { method: 'POST', body: '{}', signal: controller.signal })).rejects.toMatchObject({ name: 'AbortError' });
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it('does not replay requests after CRM access has been revoked', async () => {
    fetchMock.mockResolvedValueOnce(expired()).mockResolvedValueOnce(json({ code: 'trade_crm_forbidden' }, 403));
    await expect(api('contacts', { method: 'POST', body: '{}' })).rejects.toMatchObject({ code: 'trade_crm_forbidden' });
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(stale).toHaveBeenCalledTimes(1);
  });

  it('rejects malformed renewal responses without treating them as a logout', async () => {
    fetchMock.mockResolvedValueOnce(json({ nonce: '' }));
    await expect(refreshSession()).rejects.toThrow('بررسی نشست');
    expect(window.tradeCrmConfig.nonce).toBe('expired-token');
    expect(stale).not.toHaveBeenCalled();
  });
});

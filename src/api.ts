export type ApiError = Error & { code?: string; status?: number; data?: any };

let sessionRefresh: Promise<void> | null = null;

function authStale() { window.dispatchEvent(new CustomEvent('tradecrm:auth-stale')); }

function responseError(payload: any, status: number): ApiError {
  const error = new Error(payload?.message || 'در ارتباط با سرور مشکلی پیش آمد.') as ApiError;
  error.status = status; error.code = payload?.code; error.data = payload?.data;
  return error;
}

/** Refresh the CSRF token without extending or replacing the login session. */
export function refreshSession(): Promise<void> {
  if (sessionRefresh) return sessionRefresh;
  sessionRefresh = (async () => {
    const cfg = window.tradeCrmConfig;
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 15000);
    try {
      const response = await fetch(cfg.sessionUrl, { credentials: 'same-origin', mode: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, signal: controller.signal });
      const payload = await response.json().catch(() => null);
      if (!response.ok) {
        if (response.status === 401 || response.status === 403) authStale();
        throw responseError(payload, response.status);
      }
      if (!payload || typeof payload.nonce !== 'string' || !payload.nonce || typeof payload.user_id !== 'number') {
        throw new Error('بررسی نشست انجام نشد. دوباره تلاش کنید.');
      }
      if (payload.user_id !== cfg.user.id) {
        authStale();
        throw responseError({ code: 'trade_crm_account_changed', message: 'حساب کاربری این مرورگر تغییر کرده است. پیش از ادامه دوباره وارد حساب خود شوید.' }, 401);
      }
      cfg.nonce = payload.nonce;
      if (typeof payload.logout_url === 'string') cfg.logoutUrl = payload.logout_url;
      window.dispatchEvent(new CustomEvent('tradecrm:auth-restored'));
    } finally { window.clearTimeout(timeout); }
  })().finally(() => { sessionRefresh = null; });
  return sessionRefresh;
}

export async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
  const cfg = window.tradeCrmConfig;
  for (let attempt = 0; attempt < 2; attempt++) {
    init.signal?.throwIfAborted();
    const nonce = cfg.nonce;
    const headers = new Headers(init.headers); headers.set('X-WP-Nonce', nonce); headers.set('Accept', 'application/json');
    if (init.body) headers.set('Content-Type', 'application/json');
    const response = await fetch(new URL(path.replace(/^\//, ''), cfg.apiRoot), { ...init, headers, credentials: 'same-origin', mode: 'same-origin', cache: 'no-store' });
    const payload = await response.json().catch(() => ({}));
    if (response.status === 403 && payload?.code === 'rest_cookie_invalid_nonce' && attempt === 0) {
      // WordPress rejects this request before the mutation runs, so one replay is safe.
      // A concurrent request may already have obtained a current nonce.
      if (cfg.nonce === nonce) await refreshSession();
      continue;
    }
    const nextNonce = response.headers.get('X-WP-Nonce');
    if (nextNonce) cfg.nonce = nextNonce;
    if (!response.ok) {
      if (response.status === 401 || payload?.code === 'rest_cookie_invalid_nonce') authStale();
      throw responseError(payload, response.status);
    }
    return payload as T;
  }
  throw new Error('درخواست انجام نشد. دوباره تلاش کنید.');
}

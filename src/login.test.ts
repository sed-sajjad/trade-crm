import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import script from '../assets/login.js?raw';

const email = 'sample.user@example.com';
const guide = 'در دستگاه شخصی، «مرا به خاطر بسپار» را برای ماندن ورود تا ۳۰ روز انتخاب کنید. روی دستگاه مشترک این گزینه را فعال نکنید.';
const formTail = 'رمز عبور                        در دستگاه شخصی، «مرا به خاطر بسپار» را برای ماندن ورود تا ۳۰ روز انتخاب کنید. روی دستگاه مشترک این گزینه را فعال نکنید.  مرا به خاطر بسپار                            رمز عبورتان را گم کرده‌اید؟     → رفتن به magica CRM';
let username: HTMLInputElement;

function paste(target: HTMLInputElement, text: string) {
  const event = new Event('paste', { bubbles: true, cancelable: true });
  Object.defineProperty(event, 'clipboardData', { value: { getData: (type: string) => type === 'text/plain' ? text : '' } });
  target.dispatchEvent(event);
  return event;
}

function copy(target: HTMLInputElement, selection: [number, number]) {
  target.setSelectionRange(...selection);
  const event = new Event('copy', { bubbles: true, cancelable: true });
  const setData = vi.fn();
  Object.defineProperty(event, 'clipboardData', { value: { setData } });
  target.dispatchEvent(event);
  return { event, setData };
}

beforeEach(() => {
  document.body.innerHTML = `<form id="loginform"><input id="user_login" name="log"><label for="user_pass">رمز عبور</label><input id="user_pass" type="password"><p class="trade-crm-remember-hint">${guide}</p><label for="rememberme">مرا به خاطر بسپار</label></form>`;
  username = document.querySelector('#user_login')!;
  // Execute the same standalone script that WordPress loads, with a fresh form.
  new Function(script)();
});

afterEach(() => { document.body.innerHTML = ''; });

describe('login email paste', () => {
  it('copies only the username selection, even when page text is also selected', () => {
    username.value = email;
    const range = document.createRange();
    range.selectNodeContents(document.querySelector('#loginform')!);
    window.getSelection()!.addRange(range);
    const { event, setData } = copy(username, [0, email.length]);
    expect(event.defaultPrevented).toBe(true);
    expect(setData).toHaveBeenCalledExactlyOnceWith('text/plain', email);
    window.getSelection()!.removeAllRanges();
  });

  it('preserves partial copying and native copying with no field selection', () => {
    username.value = email;
    expect(copy(username, [0, 6]).setData).toHaveBeenCalledExactlyOnceWith('text/plain', 'sample');
    const { event, setData } = copy(username, [0, 0]);
    expect(event.defaultPrevented).toBe(false);
    expect(setData).not.toHaveBeenCalled();
  });

  it('keeps only the email when the copied text includes the rest of the login form', () => {
    const onInput = vi.fn();
    username.addEventListener('input', onInput);
    expect(paste(username, `${email}      ${formTail}`).defaultPrevented).toBe(true);
    expect(username.value).toBe(email);
    expect(onInput).toHaveBeenCalledOnce();
  });

  it('removes surrounding whitespace and invisible direction marks from an email', () => {
    expect(paste(username, `\u200f\u00a0 \n${email.slice(0, 7)}\u200b${email.slice(7)}\u2069\t `).defaultPrevented).toBe(true);
    expect(username.value).toBe(email);
  });

  it.each(['insertFromPaste', 'insertReplacementText', 'insertFromDrop', 'insertText', ''])('cleans the actual field after %s, even without a paste event', (inputType) => {
    username.value = `\u200f ${email}      ${guide}`;
    username.dispatchEvent(new InputEvent('input', { bubbles: true, inputType }));
    expect(username.value).toBe(email);
    expect(username.validity.valid).toBe(true);
  });

  it('recovers an email when native single-line insertion joins it directly to the password label', () => {
    username.value = `${email}${formTail}`;
    username.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertFromPaste' }));
    expect(username.value).toBe(email);
  });

  it('cleans a restored field on pageshow and a later autofill on change', () => {
    username.value = `${email} ${guide}`;
    window.dispatchEvent(new Event('pageshow'));
    expect(username.value).toBe(email);
    username.value = `\u200f ${email} `;
    username.dispatchEvent(new Event('change', { bubbles: true }));
    expect(username.value).toBe(email);
  });

  it('normalizes the actual form value before submission even if no input event fired', () => {
    username.value = `${email} ${guide}`;
    const form = document.querySelector<HTMLFormElement>('#loginform')!;
    const event = new Event('submit', { bubbles: true, cancelable: true });
    form.dispatchEvent(event);
    expect(new FormData(form).get('log')).toBe(email);
    expect(event.defaultPrevented).toBe(false);
  });

  it('blocks a field containing the guide without an identifiable email and clears the error after correction', () => {
    username.value = guide;
    username.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertFromPaste' }));
    expect(username.validity.customError).toBe(true);
    const event = new Event('submit', { bubbles: true, cancelable: true });
    vi.spyOn(username, 'reportValidity').mockReturnValue(false);
    document.querySelector('#loginform')!.dispatchEvent(event);
    expect(event.defaultPrevented).toBe(true);
    username.value = email;
    username.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertText' }));
    expect(username.validity.customError).toBe(false);
  });

  it('does not rewrite ordinary typing or change usernames containing spaces', () => {
    username.value = `${email} `;
    username.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertText', data: ' ' }));
    expect(username.value).toBe(`${email} `);
    username.value = 'نام\u200cکاربری با فاصله';
    username.dispatchEvent(new Event('change', { bubbles: true }));
    expect(username.value).toBe('نام\u200cکاربری با فاصله');
  });

  it('preserves a label word inside an email when the form uses English labels', () => {
    document.body.innerHTML = '<form id="loginform"><input id="user_login"><label for="user_pass">Password</label></form>';
    username = document.querySelector('#user_login')!;
    new Function(script)();
    paste(username, 'Password@example.com Password Remember Me');
    expect(username.value).toBe('Password@example.com');
    username.value = 'Password@example.com ';
    username.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertText', data: ' ' }));
    expect(username.value).toBe('Password@example.com ');
  });

  it('does not alter a composition until the user commits it', () => {
    username.value = `${email} ${guide}`;
    username.dispatchEvent(new InputEvent('input', { bubbles: true, isComposing: true }));
    expect(username.value).toBe(`${email} ${guide}`);
    username.dispatchEvent(new CompositionEvent('compositionend', { bubbles: true }));
    expect(username.value).toBe(email);
  });

  it('replaces the selection and leaves the rest of the username field intact', () => {
    username.value = 'prefix old suffix';
    username.setSelectionRange(7, 10);
    paste(username, ` ${email} `);
    expect(username.value).toBe(`prefix ${email} suffix`);
    expect(username.selectionStart).toBe(7 + email.length);
  });

  it.each([email, 'normal.user', 'نام\u200cکاربری', 'a user name', 'person@example.com other text', `first@example.com ${formTail} second@example.com`, 'Contact person@example.com', 'not-an-email رمز عبور'])('preserves native paste for %s', (text) => {
    username.value = 'existing';
    expect(paste(username, text).defaultPrevented).toBe(false);
    expect(username.value).toBe('existing');
  });

  it('does not change passwords, including spaces and invisible characters', () => {
    const password = document.querySelector<HTMLInputElement>('#user_pass')!;
    expect(paste(password, ` ${email}\u200f ${formTail}`).defaultPrevented).toBe(false);
  });

  it('does not attach to password recovery or registration forms', () => {
    document.body.innerHTML = '<form id="lostpasswordform"><input id="user_login"></form>';
    new Function(script)();
    expect(paste(document.querySelector('#user_login')!, ` ${email} `).defaultPrevented).toBe(false);
  });
});

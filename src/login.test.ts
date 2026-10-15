import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import script from '../assets/login.js?raw';

const email = 'sample.user@example.com';
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
  document.body.innerHTML = '<form id="loginform"><input id="user_login"><label for="user_pass">رمز عبور</label><input id="user_pass" type="password"></form>';
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

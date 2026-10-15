import { act, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import type { Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Modal } from './Modal';
import { useOverlayFocus } from './useOverlayFocus';

let root: Root;
let host: HTMLDivElement;

beforeEach(() => {
  vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true);
  host = document.createElement('div');
  host.inert = false;
  document.body.append(host);
  root = createRoot(host);
});

afterEach(() => {
  act(() => root.unmount());
  document.body.style.overflow = '';
  document.documentElement.style.overflow = '';
  vi.unstubAllGlobals();
});

function Harness() {
  const [open, setOpen] = useState(false);
  return <><button onClick={() => setOpen(true)}>بازکردن</button>{open && <Modal title="نتیجه پیگیری" onClose={() => setOpen(false)}><input aria-label="نتیجه"/><button>ثبت</button></Modal>}</>;
}

function DrawerHarness() {
  const [open, setOpen] = useState(false);
  const drawerRef = useRef<HTMLElement>(null);
  useOverlayFocus(drawerRef, open, () => setOpen(false));
  return <><main><button onClick={() => setOpen(true)}>منو</button></main><aside ref={drawerRef} hidden={!open} tabIndex={-1}><button onClick={() => setOpen(false)}>بستن منو</button><a href="#contacts">مخاطبان</a></aside>{open && <button data-overlay-backdrop="" tabIndex={-1} onClick={() => setOpen(false)}>پس‌زمینه</button>}</>;
}

describe('accessible modal behavior', () => {
  it('contains focus, closes with Escape, restores focus and unlocks the page', () => {
    act(() => root.render(<Harness/>));
    const opener = host.querySelector('button')!;
    opener.focus();
    act(() => opener.click());

    const dialog = document.querySelector<HTMLElement>('[role="dialog"]')!;
    const close = dialog.querySelector<HTMLButtonElement>('[aria-label="بستن"]')!;
    const save = dialog.querySelectorAll('button')[1]!;
    expect(document.getElementById(dialog.getAttribute('aria-labelledby')!)?.textContent).toBe('نتیجه پیگیری');
    expect(document.activeElement).toBe(close);
    expect(host.inert).toBe(true);
    expect(document.body.style.overflow).toBe('hidden');
    expect(document.documentElement.style.overflow).toBe('hidden');

    save.focus();
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true }));
    expect(document.activeElement).toBe(close);
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', shiftKey: true, bubbles: true, cancelable: true }));
    expect(document.activeElement).toBe(save);
    opener.focus();
    expect(document.activeElement).toBe(close);

    act(() => document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true })));
    expect(document.querySelector('[role="dialog"]')).toBeNull();
    expect(host.inert).toBe(false);
    expect(document.body.style.overflow).toBe('');
    expect(document.documentElement.style.overflow).toBe('');
    expect(document.activeElement).toBe(opener);
  });

  it('preserves an existing scroll lock and inert state when dismissed', () => {
    host.inert = true;
    document.body.style.overflow = 'clip';
    document.documentElement.style.overflow = 'clip';
    act(() => root.render(<Modal title="ویرایش" onClose={() => undefined}><input/></Modal>));
    act(() => root.render(null));
    expect(host.inert).toBe(true);
    expect(document.body.style.overflow).toBe('clip');
    expect(document.documentElement.style.overflow).toBe('clip');
  });

  it('keeps a pending submission open and uses the latest close behavior after it finishes', () => {
    const close = vi.fn();
    act(() => root.render(<Modal title="ثبت" busy onClose={close}><input/></Modal>));
    const backdrop = document.querySelector('.modal-backdrop')!;
    expect(document.querySelector<HTMLButtonElement>('[aria-label="بستن"]')!.disabled).toBe(true);
    act(() => {
      backdrop.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    });
    expect(close).not.toHaveBeenCalled();
    act(() => root.render(<Modal title="ثبت" onClose={close}><input/></Modal>));
    act(() => document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true })));
    expect(close).toHaveBeenCalledTimes(1);
  });

  it('only treats a press on the backdrop itself as dismissal', () => {
    const close = vi.fn();
    act(() => root.render(<Modal title="ثبت" onClose={close}><input/></Modal>));
    act(() => document.querySelector('input')!.dispatchEvent(new MouseEvent('mousedown', { bubbles: true })));
    expect(close).not.toHaveBeenCalled();
    act(() => document.querySelector('.modal-backdrop')!.dispatchEvent(new MouseEvent('mousedown', { bubbles: true })));
    expect(close).toHaveBeenCalledTimes(1);
  });

  it('also contains a drawer while keeping its backdrop available for dismissal', () => {
    act(() => root.render(<DrawerHarness/>));
    const opener = host.querySelector('main button') as HTMLButtonElement;
    opener.focus();
    act(() => opener.click());
    const drawer = host.querySelector('aside')!;
    const backdrop = host.querySelector<HTMLButtonElement>('[data-overlay-backdrop]')!;
    expect(document.activeElement).toBe(drawer.querySelector('button'));
    expect(host.querySelector('main')!.inert).toBe(true);
    expect(backdrop.inert).not.toBe(true);
    expect(host.inert).toBe(false);
    act(() => backdrop.click());
    expect(host.querySelector('main')!.inert).not.toBe(true);
    expect(document.activeElement).toBe(opener);
    expect(document.body.style.overflow).toBe('');
  });
});

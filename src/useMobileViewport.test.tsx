import { act } from 'react';
import { createRoot } from 'react-dom/client';
import type { Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useMobileViewport } from './useMobileViewport';

let root: Root;
let host: HTMLDivElement;
let viewport: EventTarget & { height: number; offsetTop: number; scale: number };

function Probe() { useMobileViewport(); return <><input aria-label="نام"/><button>پایان</button></>; }
const flush = () => act(() => { vi.runAllTimers(); });

beforeEach(() => {
  vi.useFakeTimers();
  vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true);
  vi.stubGlobal('innerWidth', 390);
  vi.stubGlobal('innerHeight', 844);
  vi.stubGlobal('matchMedia', () => ({ matches: window.innerWidth <= 760 }));
  vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback) => window.setTimeout(() => callback(0), 0));
  vi.stubGlobal('cancelAnimationFrame', (id: number) => window.clearTimeout(id));
  viewport = Object.assign(new EventTarget(), { height: 844, offsetTop: 0, scale: 1 });
  vi.stubGlobal('visualViewport', viewport);
  host = document.createElement('div');
  document.body.append(host);
  root = createRoot(host);
  act(() => root.render(<Probe/>));
});

afterEach(() => {
  act(() => root.unmount());
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

describe('mobile visible viewport', () => {
  it('exposes the keyboard viewport and restores navigation when editing ends', () => {
    host.querySelector('input')!.focus();
    flush();
    viewport.height = 510;
    viewport.offsetTop = 38;
    viewport.dispatchEvent(new Event('resize'));
    flush();
    expect(document.documentElement.classList.contains('crm-keyboard-open')).toBe(true);
    expect(document.documentElement.style.getPropertyValue('--crm-viewport-height')).toBe('510px');
    expect(document.documentElement.style.getPropertyValue('--crm-viewport-top')).toBe('38px');
    host.querySelector('button')!.focus();
    flush();
    expect(document.documentElement.classList.contains('crm-keyboard-open')).toBe(false);
  });

  it('does not hide navigation for browser toolbar changes or pinch zoom', () => {
    host.querySelector('input')!.focus();
    viewport.height = 760;
    viewport.dispatchEvent(new Event('resize'));
    flush();
    expect(document.documentElement.classList.contains('crm-keyboard-open')).toBe(false);
    viewport.height = 400;
    viewport.scale = 1.5;
    viewport.dispatchEvent(new Event('resize'));
    flush();
    expect(document.documentElement.classList.contains('crm-keyboard-open')).toBe(false);
  });

  it('also recognizes a keyboard that shrinks the layout viewport', () => {
    host.querySelector('input')!.focus();
    flush();
    viewport.height = 510;
    vi.stubGlobal('innerHeight', 510);
    window.dispatchEvent(new Event('resize'));
    flush();
    expect(document.documentElement.classList.contains('crm-keyboard-open')).toBe(true);
  });

  it('keeps desktop navigation and removes temporary CSS state when unmounted', () => {
    host.querySelector('input')!.focus();
    vi.stubGlobal('innerWidth', 1366);
    viewport.height = 400;
    viewport.dispatchEvent(new Event('resize'));
    flush();
    expect(document.documentElement.classList.contains('crm-keyboard-open')).toBe(false);
    act(() => root.render(null));
    expect(document.documentElement.style.getPropertyValue('--crm-viewport-height')).toBe('');
    expect(document.documentElement.style.getPropertyValue('--crm-viewport-top')).toBe('');
  });
});

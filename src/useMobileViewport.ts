import { useEffect } from 'react';

function isEditing(element: Element | null) {
  if (element instanceof HTMLTextAreaElement) return !element.disabled && !element.readOnly;
  if (element instanceof HTMLSelectElement) return !element.disabled;
  if (element instanceof HTMLInputElement) return !element.disabled && !element.readOnly && !['button', 'submit', 'reset', 'checkbox', 'radio', 'range', 'color', 'file', 'hidden'].includes(element.type);
  return element instanceof HTMLElement && element.isContentEditable;
}

/** Follow the visible iOS viewport without treating browser chrome or pinch zoom as a keyboard. */
export function useMobileViewport() {
  useEffect(() => {
    const viewport = window.visualViewport;
    const page = document.documentElement;
    const oldHeight = page.style.getPropertyValue('--crm-viewport-height');
    const oldTop = page.style.getPropertyValue('--crm-viewport-top');
    const hadKeyboardClass = page.classList.contains('crm-keyboard-open');
    let baselineWidth = window.innerWidth;
    let baselineHeight = Math.max(window.innerHeight, page.clientHeight, viewport?.height || 0);
    let frame = 0;

    const update = () => {
      frame = 0;
      const editing = isEditing(document.activeElement);
      const height = viewport?.height || window.innerHeight;
      const layoutHeight = Math.max(window.innerHeight, page.clientHeight, height);
      if (baselineWidth !== window.innerWidth || !editing) {
        baselineWidth = window.innerWidth;
        baselineHeight = layoutHeight;
      } else baselineHeight = Math.max(baselineHeight, layoutHeight);

      const keyboardOpen = window.matchMedia('(max-width: 760px)').matches && editing && (viewport?.scale || 1) <= 1.1 && baselineHeight - height > 150;
      page.classList.toggle('crm-keyboard-open', keyboardOpen);
      page.style.setProperty('--crm-viewport-height', `${Math.round(height)}px`);
      page.style.setProperty('--crm-viewport-top', `${Math.round(viewport?.offsetTop || 0)}px`);
    };
    const schedule = () => { if (!frame) frame = window.requestAnimationFrame(update); };
    update();
    viewport?.addEventListener('resize', schedule);
    viewport?.addEventListener('scroll', schedule);
    window.addEventListener('resize', schedule);
    document.addEventListener('focusin', schedule);
    document.addEventListener('focusout', schedule);

    return () => {
      if (frame) window.cancelAnimationFrame(frame);
      viewport?.removeEventListener('resize', schedule);
      viewport?.removeEventListener('scroll', schedule);
      window.removeEventListener('resize', schedule);
      document.removeEventListener('focusin', schedule);
      document.removeEventListener('focusout', schedule);
      page.classList.toggle('crm-keyboard-open', hadKeyboardClass);
      if (oldHeight) page.style.setProperty('--crm-viewport-height', oldHeight);
      else page.style.removeProperty('--crm-viewport-height');
      if (oldTop) page.style.setProperty('--crm-viewport-top', oldTop);
      else page.style.removeProperty('--crm-viewport-top');
    };
  }, []);
}

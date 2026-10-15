import { useEffect, useRef } from 'react';
import type { RefObject } from 'react';

const focusableSelector = 'a[href], button, input, textarea, select, summary, [tabindex]';

/** Keep keyboard and screen-reader navigation inside the active overlay. */
export function useOverlayFocus(containerRef: RefObject<HTMLElement>, active: boolean, onClose: () => void) {
  const closeRef = useRef(onClose);
  closeRef.current = onClose;

  useEffect(() => {
    const container = containerRef.current;
    if (!active || !container) return;

    const previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    const oldOverflow = document.body.style.overflow;
    const oldDocumentOverflow = document.documentElement.style.overflow;
    const oldOverscroll = document.body.style.overscrollBehavior;
    const outside: Array<{ element: HTMLElement; inert: boolean }> = [];
    let branch: HTMLElement = container;
    while (branch.parentElement) {
      for (const sibling of Array.from(branch.parentElement.children)) {
        if (!(sibling instanceof HTMLElement) || sibling === branch || sibling.hasAttribute('data-overlay-backdrop') || ['SCRIPT', 'STYLE', 'LINK'].includes(sibling.tagName)) continue;
        outside.push({ element: sibling, inert: sibling.inert });
        sibling.inert = true;
      }
      branch = branch.parentElement;
      if (branch === document.body) break;
    }

    document.body.style.overflow = 'hidden';
    document.documentElement.style.overflow = 'hidden';
    document.body.style.overscrollBehavior = 'none';

    const focusable = () => Array.from(container.querySelectorAll<HTMLElement>(focusableSelector)).filter((element) => {
      if (element.tabIndex < 0 || element.matches(':disabled') || element.closest('[hidden], [inert], [aria-hidden="true"]')) return false;
      const style = window.getComputedStyle(element);
      return style.display !== 'none' && style.visibility !== 'hidden';
    });
    const focusStart = () => (container.querySelector<HTMLElement>('[data-overlay-autofocus]') || focusable()[0] || container).focus({ preventScroll: true });
    focusStart();

    const keydown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        closeRef.current();
      }
      if (event.key !== 'Tab') return;
      const elements = focusable();
      const first = elements[0];
      const last = elements[elements.length - 1];
      if (!first || !last) { event.preventDefault(); container.focus(); return; }
      if (event.shiftKey && (document.activeElement === first || document.activeElement === container || !container.contains(document.activeElement))) {
        event.preventDefault(); last.focus();
      } else if (!event.shiftKey && (document.activeElement === last || !container.contains(document.activeElement))) {
        event.preventDefault(); first.focus();
      }
    };
    const focusin = (event: FocusEvent) => {
      if (event.target instanceof Node && !container.contains(event.target)) focusStart();
    };
    document.addEventListener('keydown', keydown);
    document.addEventListener('focusin', focusin);

    return () => {
      document.removeEventListener('keydown', keydown);
      document.removeEventListener('focusin', focusin);
      for (const item of outside) item.element.inert = item.inert;
      document.body.style.overflow = oldOverflow;
      document.documentElement.style.overflow = oldDocumentOverflow;
      document.body.style.overscrollBehavior = oldOverscroll;
      if (previousFocus?.isConnected && !previousFocus.closest('[inert]')) previousFocus.focus({ preventScroll: true });
    };
  }, [active, containerRef]);
}

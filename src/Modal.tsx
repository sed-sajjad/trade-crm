import { useId, useRef } from 'react';
import type { ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { X } from 'lucide-react';
import { useOverlayFocus } from './useOverlayFocus';

export function Modal({ title, children, onClose, busy = false }: { title: string; children: ReactNode; onClose: () => void; busy?: boolean }) {
  const titleId = useId();
  const dialogRef = useRef<HTMLElement>(null);
  const close = () => { if (!busy) onClose(); };
  useOverlayFocus(dialogRef, true, close);

  return createPortal(<div className="modal-backdrop" onMouseDown={(event) => { if (event.currentTarget === event.target) close(); }}>
    <section ref={dialogRef} className="modal" role="dialog" aria-modal="true" aria-busy={busy || undefined} aria-labelledby={titleId} tabIndex={-1}>
      <div className="modal-head"><h2 id={titleId}>{title}</h2><button type="button" className="icon-button" disabled={busy} onClick={close} aria-label="بستن"><X size={19}/></button></div>
      <div className="modal-body">{children}</div>
    </section>
  </div>, document.body);
}

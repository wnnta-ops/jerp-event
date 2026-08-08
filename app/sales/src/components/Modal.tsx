"use client";

import { type ReactNode, useEffect } from "react";

export function Modal({
  title,
  open,
  onClose,
  children,
  footer,
}: {
  title: string;
  open: boolean;
  onClose: () => void;
  children: ReactNode;
  footer?: ReactNode;
}) {
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && onClose();
    if (open) window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [open, onClose]);

  return (
    <div
      className={`modal-bg ${open ? "show" : ""}`}
      onClick={(e) => {
        if ((e.target as HTMLElement).classList.contains("modal-bg")) onClose();
      }}
    >
      <div className="modal">
        <div className="mh">
          <h3>{title}</h3>
          <button className="x" onClick={onClose} aria-label="Tutup">
            ×
          </button>
        </div>
        <div className="mb">{children}</div>
        {footer && <div className="mf">{footer}</div>}
      </div>
    </div>
  );
}

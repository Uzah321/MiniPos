import React from "react";
import { X } from "lucide-react";

export default function EnterpriseFormModal({
  open,
  title,
  subtitle = "",
  onClose,
  busy = false,
  width = "1180px",
  zIndex = 100010,
  children,
}) {
  if (!open) return null;

  return (
    <div
      className="sx-form-modal-backdrop"
      style={{ zIndex }}
      role="dialog"
      aria-modal="true"
      aria-label={title}
      onMouseDown={() => !busy && onClose?.()}
    >
      <section
        className="sx-form-modal-window"
        style={{ "--sx-form-modal-width": width }}
        onMouseDown={(event) => event.stopPropagation()}
      >
        <header className="sx-form-modal-header">
          <div>
            <h2>{title}</h2>
            {subtitle && <p>{subtitle}</p>}
          </div>
          <button type="button" onClick={onClose} disabled={busy} aria-label="Close form">
            <X size={17} />
          </button>
        </header>
        <div className="sx-form-modal-body">{children}</div>
      </section>
    </div>
  );
}

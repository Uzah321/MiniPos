import React from "react";
import { AlertTriangle, X } from "lucide-react";

// Confirmations share the compact card used by success and error notices.
export default function EnterpriseConfirmModal({
  open,
  title = "Confirm action",
  message,
  confirmLabel = "Confirm",
  cancelLabel = "Cancel",
  onConfirm,
  onClose,
  busy = false,
  danger = true,
}) {
  if (!open) return null;

  return (
    <div
      className="sx-dialog-backdrop fixed inset-0 flex items-center justify-center px-4"
      style={{ zIndex: 300020 }}
      role="dialog"
      aria-modal="true"
      aria-label={title}
      onMouseDown={() => !busy && onClose?.()}
      onKeyDown={(event) => {
        if (event.key === "Escape" && !busy) onClose?.();
      }}
    >
      <section
        className={`sx-dialog-window sx-compact-dialog-window border bg-white ${danger ? "sx-dialog-danger" : ""}`}
        onMouseDown={(event) => event.stopPropagation()}
      >
        <div className="flex min-h-0 flex-1 items-start gap-3 overflow-y-auto px-5 pt-5">
          <AlertTriangle className={`mt-0.5 h-6 w-6 shrink-0 ${danger ? "text-[#B42318]" : "text-[#069494]"}`} aria-hidden="true" />

          <div className="min-w-0 flex-1">
            <h2 className="text-[15px] font-semibold text-[#1F3535]">{title}</h2>
            <div className="mt-1 break-words text-[13px] leading-relaxed text-[#3D4F4F]">{message}</div>
          </div>

          <button
            type="button"
            onClick={onClose}
            disabled={busy}
            className="-mr-2 -mt-2 grid h-9 w-9 shrink-0 place-items-center text-[#627474] hover:bg-[#F2F5F5] hover:text-[#1F3535] disabled:opacity-40"
            aria-label="Close"
          >
            <X className="h-4 w-4" />
          </button>
        </div>

        <footer className="flex shrink-0 justify-end gap-2 px-5 pb-5 pt-4">
          <button type="button" disabled={busy} onClick={onClose} className="h-10 min-w-[96px] border border-[#ACB8B8] bg-white px-4 text-[13px] font-semibold text-[#1F3535] hover:bg-[#F2F5F5] disabled:opacity-50">{cancelLabel}</button>
          <button type="button" disabled={busy} onClick={onConfirm} autoFocus={!danger} className={`h-10 min-w-[96px] px-5 text-[13px] font-semibold text-white disabled:opacity-50 ${danger ? "bg-[#B42318] hover:bg-[#912018]" : "bg-[#069494] hover:bg-[#067C7C]"}`}>{busy ? "Working…" : confirmLabel}</button>
        </footer>
      </section>
    </div>
  );
}

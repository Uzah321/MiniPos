// src/components/ReusableMessageModal.jsx

import React from "react";
import { createPortal } from "react-dom";
import { AlertCircle, CheckCircle2, X } from "lucide-react";

// Success and error notices are a small card sized to the message, not the
// large form frame. Teal is the action colour for both; red only marks the
// error icon and accent line.
const ReusableMessageModal = ({
  open,
  type = "success",
  title,
  message,
  onClose,
}) => {
  if (!open) return null;

  const isSuccess = type === "success";
  const heading = title || (isSuccess ? "Success" : "Error");
  const Icon = isSuccess ? CheckCircle2 : AlertCircle;

  const modalContent = (
    <div
      className="sx-dialog-backdrop fixed inset-0 z-[100020] flex items-center justify-center px-4"
      role="dialog"
      aria-modal="true"
      aria-label={heading}
      onKeyDown={(event) => {
        if (event.key === "Escape") onClose?.();
      }}
    >
      <div className={`sx-dialog-window sx-compact-dialog-window border bg-white ${isSuccess ? "sx-dialog-success" : "sx-dialog-error"}`}>
        <div className="flex min-h-0 flex-1 items-start gap-3 overflow-y-auto px-5 pt-5">
          <Icon className={`mt-0.5 h-6 w-6 shrink-0 ${isSuccess ? "text-[#069494]" : "text-[#B42318]"}`} aria-hidden="true" />

          <div className="min-w-0 flex-1">
            <h2 className="text-[15px] font-semibold text-[#1F3535]">{heading}</h2>
            <p className="mt-1 whitespace-pre-line break-words text-[13px] leading-relaxed text-[#3D4F4F]">
              {message}
            </p>
          </div>

          <button
            type="button"
            onClick={onClose}
            className="-mr-2 -mt-2 grid h-9 w-9 shrink-0 place-items-center text-[#627474] hover:bg-[#F2F5F5] hover:text-[#1F3535]"
            aria-label="Close"
          >
            <X className="h-4 w-4" />
          </button>
        </div>

        <div className="flex shrink-0 justify-end px-5 pb-5 pt-4">
          <button
            type="button"
            onClick={onClose}
            autoFocus
            className="h-10 min-w-[96px] bg-[#069494] px-5 text-[13px] font-semibold text-white hover:bg-[#067C7C]"
          >
            Okay
          </button>
        </div>
      </div>
    </div>
  );

  return createPortal(modalContent, document.body);
};

export default ReusableMessageModal;

import React, { useState } from "react";
import { X, AlertTriangle } from "lucide-react";

const FullScreenModal = ({
  open,
  onClose,
  title = "MODAL TITLE",
  subtitle = "",
  headerContent = null,
  confirmOnClose = false,
  children,
}) => {
  const [confirmCloseOpen, setConfirmCloseOpen] = useState(false);

  if (!open) return null;

  const handleCloseClick = () => {
    if (confirmOnClose) setConfirmCloseOpen(true);
    else onClose?.();
  };

  const handleCancelClose = () => {
    setConfirmCloseOpen(false);
  };

  const handleConfirmClose = () => {
    setConfirmCloseOpen(false);
    onClose?.();
  };

  return (
    <div className="sx-modal-shell fixed inset-0 z-[99999] flex h-screen w-screen flex-col overflow-hidden bg-[#F7F8F9] text-[#293232]" role="dialog" aria-modal="true" aria-label={title}>
      <header className="sx-modal-header flex h-12 shrink-0 items-center justify-between border-b border-[#D9DDDD] bg-white pl-4 text-[#293232]">
        <div className={`flex min-w-0 items-center gap-3 pr-3 ${headerContent ? "max-w-[260px] shrink-0" : ""}`}>
          <div className="flex h-7 w-7 shrink-0 items-center justify-center border border-[#C1C8C8] bg-[#F4F6F7] text-[9px] font-medium text-[#069494]">V4</div>
          <div className="min-w-0">
          <div className="truncate text-[14px] font-medium">
            {title}
          </div>

          {subtitle && (
            <div className="truncate text-[10px] font-normal text-[#899291]">
              {subtitle}
            </div>
          )}
          </div>
        </div>

        {/* Screens that need their own toolbar put it in this one header row
            instead of stacking more bars under it. */}
        {headerContent && (
          <div className="flex h-full min-w-0 flex-1 items-center border-l border-[#D9DDDD]">
            {headerContent}
          </div>
        )}

        <button
          type="button"
          onClick={handleCloseClick}
          className="flex h-full w-[92px] shrink-0 items-center justify-center gap-1.5 border-l border-[#D9DDDD] bg-white text-[11px] font-normal text-[#677171] hover:bg-[#FFF3F2] hover:text-[#B42318]"
        >
          <X className="h-4 w-4" />
          Close
        </button>
      </header>

      <main className="sx-modal-content relative min-h-0 flex-1 overflow-hidden bg-[#F7F8F9] p-0">
        <div className="relative z-10 h-full min-h-0 overflow-hidden bg-white">
          {children}
        </div>
      </main>

      {confirmCloseOpen && (
        <div className="sx-dialog-backdrop fixed inset-0 z-[100000] flex items-center justify-center px-4">
          <div className="sx-dialog-window sx-compact-dialog-window sx-dialog-danger border border-[#BCC7C7] bg-white shadow-2xl" role="dialog" aria-modal="true" aria-label="Confirm close">
            <div className="sx-dialog-header flex items-center gap-3 border-b border-[#BCC7C7] bg-white px-4 py-3 text-[#2B3C3C]">
              <div className="flex h-9 w-9 items-center justify-center bg-[#B42318]">
                <AlertTriangle className="h-5 w-5" />
              </div>

              <div className="min-w-0">
                <div className="truncate text-[13px] font-semibold">
                  Confirm Window Close
                </div>
                <div className="truncate text-[9px] font-semibold text-[#7C8A8A]">
                  SERVENEST HOSPITALITY POS
                </div>
              </div>
            </div>

            <div className="min-h-0 flex-1 overflow-y-auto px-4 py-4 text-[#003636]">
              <p className="mb-0 text-[13px] font-semibold">
                Close this window? Unsaved changes may be lost.
              </p>
            </div>

            <div className="flex justify-end gap-2 border-t border-[#CAD5D5] bg-[#F7FAFC] px-4 py-3">
              <button
                type="button"
                onClick={handleCancelClose}
                className="h-9 min-w-[96px] border border-[#ACB8B8] bg-white px-4 text-[11px] font-black uppercase tracking-wide text-[#003636] hover:bg-[#EFF3F3]"
              >
                Stay
              </button>

              <button
                type="button"
                onClick={handleConfirmClose}
                className="h-9 min-w-[104px] bg-[#B42318] px-4 text-[11px] font-black uppercase tracking-wide text-white hover:bg-[#991B1B]"
              >
                Close
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default FullScreenModal;

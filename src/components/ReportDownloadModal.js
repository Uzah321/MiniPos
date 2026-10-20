import React from "react";
import { Eye, FileSpreadsheet, FileText, X } from "lucide-react";

export default function ReportDownloadModal({ open, title = "Report", filters = {}, busy = false, onClose, onDownload }) {
  if (!open) return null;

  return <div className="fixed inset-0 z-[250000] flex items-center justify-center bg-black/45 p-4" onMouseDown={() => !busy && onClose?.()}>
    <div className="sx-large-modal-window border border-[#ABB2B2] bg-white shadow-2xl" role="dialog" aria-modal="true" aria-label={`Download ${title}`} onMouseDown={(event) => event.stopPropagation()}>
      <header className="flex items-center justify-between border-b border-[#D8DCDC] px-6 py-5">
        <div><div className="text-[20px] font-medium text-[#262E2D]">Select a destination</div><div className="mt-1 text-[12px] text-[#7C8685]">{title}</div></div>
        <button type="button" disabled={busy} onClick={onClose} className="flex h-9 w-9 items-center justify-center text-[#677171] hover:bg-[#F3F5F6]"><X className="h-4 w-4" /></button>
      </header>
      <div className="grid min-h-0 flex-1 content-start gap-3 overflow-y-auto p-6 sm:grid-cols-3">
        <button type="button" disabled={busy} onClick={() => onDownload?.("excel")} className="flex min-h-[150px] flex-col items-center justify-center gap-4 border border-[#A1A8A8] bg-white text-[#2F7D50] hover:bg-[#F4F8F5] disabled:opacity-60"><FileSpreadsheet className="h-10 w-10" /><span className="text-[13px] font-normal text-[#323B3A]">Spreadsheet</span></button>
        <button type="button" disabled={busy} onClick={() => onDownload?.("pdf")} className="flex min-h-[150px] flex-col items-center justify-center gap-4 border border-[#A1A8A8] bg-white text-[#C93636] hover:bg-[#FFF7F7] disabled:opacity-60"><FileText className="h-10 w-10" /><span className="text-[13px] font-normal text-[#323B3A]">PDF</span></button>
        <button type="button" disabled={busy} onClick={onClose} className="flex min-h-[150px] flex-col items-center justify-center gap-4 border border-[#A1A8A8] bg-white text-[#069494] hover:bg-[#F5FAFA] disabled:opacity-60"><Eye className="h-10 w-10" /><span className="text-[13px] font-normal text-[#323B3A]">View</span></button>
      </div>
      <footer className="flex items-center justify-between border-t border-[#D8DCDC] px-6 py-4"><span className="text-[11px] text-[#7C8685]">{busy ? "Preparing file…" : ""}</span><button type="button" disabled={busy} onClick={onClose} className="h-8 px-3 text-[12px] font-medium text-[#069494]">Cancel</button></footer>
    </div>
  </div>;
}

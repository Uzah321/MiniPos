import React from "react";
import { ArrowLeft } from "lucide-react";

/** Standard results surface for reports that switch between filters and output. */
export default function ReportViewShell({
  title = "Report results",
  subtitle = "",
  backLabel = "Back to filters",
  onBackToFilters,
  backDisabled = false,
  busy = false,
  actions = null,
  children,
  className = "",
}) {
  return (
    <section
      className={`flex h-full min-h-0 flex-col bg-white ${className}`.trim()}
      aria-label={title}
      aria-busy={busy}
    >
      <header className="flex shrink-0 flex-wrap items-center justify-between gap-3 border-b border-[#D8DCDC] bg-[#F8FAFC] px-4 py-3">
        <div className="flex min-w-0 items-center gap-3">
          <button
            type="button"
            onClick={onBackToFilters}
            disabled={backDisabled || busy || typeof onBackToFilters !== "function"}
            className="flex h-8 shrink-0 items-center gap-2 border border-[#ABB2B2] bg-white px-3 text-[11px] font-semibold text-[#324443] hover:bg-[#EEF2F2] disabled:cursor-not-allowed disabled:opacity-50"
          >
            <ArrowLeft className="h-4 w-4" aria-hidden="true" />
            {backLabel}
          </button>
          <div className="min-w-0">
            <h2 className="truncate text-[13px] font-semibold text-[#293232]">{title}</h2>
            {subtitle && <p className="mt-0.5 truncate text-[10px] text-[#627474]">{subtitle}</p>}
          </div>
        </div>
        {actions && <div className="flex flex-wrap items-center justify-end gap-2">{actions}</div>}
      </header>
      <div className="min-h-0 flex-1 overflow-auto">{children}</div>
    </section>
  );
}

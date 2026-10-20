import React, { useEffect, useId, useMemo, useRef } from "react";
import { createPortal } from "react-dom";
import { Eye, FileSpreadsheet, FileText, LoaderCircle, X } from "lucide-react";

const OUTPUT_DEFINITIONS = [
  {
    key: "view",
    label: "View Report",
    description: "Open the generated report in this workspace.",
    Icon: Eye,
    accentClass: "text-[#057B7B]",
    hoverClass: "hover:border-[#069494] hover:bg-[#F5FAFA]",
  },
  {
    key: "pdf",
    label: "Download PDF",
    description: "Download a print-ready PDF from the server.",
    Icon: FileText,
    accentClass: "text-[#B42318]",
    hoverClass: "hover:border-[#D92D20] hover:bg-[#FFF7F7]",
  },
  {
    key: "excel",
    label: "Download Excel",
    description: "Download the report as an Excel workbook.",
    Icon: FileSpreadsheet,
    accentClass: "text-[#27865B]",
    hoverClass: "hover:border-[#27865B] hover:bg-[#F4F8F5]",
  },
];

const focusableElements = (container) =>
  Array.from(
    container?.querySelectorAll(
      'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
    ) || [],
  ).filter((element) => element.getAttribute("aria-hidden") !== "true");

/**
 * Centered chooser shown after a report's Generate action.
 *
 * `outputs` can override each destination with
 * `{ available, disabled, reason, label, description }`. A destination with no
 * callback is unavailable by default, making partial backend support explicit.
 */
export default function ReportOutputChooser({
  open,
  title = "Choose report output",
  reportTitle = "Report",
  description = "Select how you want to continue with the generated report.",
  outputs = {},
  busy = false,
  busyLabel = "Preparing report output…",
  onClose,
  onView,
  onDownloadPdf,
  onDownloadExcel,
}) {
  const dialogRef = useRef(null);
  const previousFocusRef = useRef(null);
  const titleId = useId();
  const descriptionId = useId();

  const handlers = useMemo(
    () => ({
      view: onView,
      pdf: onDownloadPdf,
      excel: onDownloadExcel,
    }),
    [onDownloadExcel, onDownloadPdf, onView],
  );

  const destinations = useMemo(
    () =>
      OUTPUT_DEFINITIONS.map((definition) => {
        const configuration = outputs?.[definition.key];
        const settings = configuration === false
          ? { available: false }
          : configuration === true || configuration == null
            ? {}
            : configuration;
        const available = settings.available ?? typeof handlers[definition.key] === "function";
        const disabled = busy || !available || !!settings.disabled;
        const reason = !available
          ? settings.reason || "This output is not available for this report."
          : settings.disabled
            ? settings.reason || "This output is unavailable for the selected filters."
            : "";

        return {
          ...definition,
          ...settings,
          available,
          disabled,
          reason,
          handler: handlers[definition.key],
        };
      }),
    [busy, handlers, outputs],
  );

  useEffect(() => {
    if (!open) return undefined;

    previousFocusRef.current = document.activeElement;
    const focusTimer = window.setTimeout(() => {
      const firstDestination = dialogRef.current?.querySelector(
        "[data-report-output]:not([disabled])",
      );
      (firstDestination || dialogRef.current)?.focus();
    }, 0);

    return () => {
      window.clearTimeout(focusTimer);
      const previousFocus = previousFocusRef.current;
      if (previousFocus && typeof previousFocus.focus === "function") {
        previousFocus.focus();
      }
    };
  }, [open]);

  if (!open) return null;

  const requestClose = () => {
    if (!busy) onClose?.();
  };

  const handleKeyDown = (event) => {
    if (event.key === "Escape") {
      event.preventDefault();
      requestClose();
      return;
    }

    if (event.key !== "Tab") return;

    const elements = focusableElements(dialogRef.current);
    if (!elements.length) {
      event.preventDefault();
      dialogRef.current?.focus();
      return;
    }

    const first = elements[0];
    const last = elements[elements.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  };

  const selectDestination = (destination) => {
    if (destination.disabled || typeof destination.handler !== "function") return;
    destination.handler();
  };

  return createPortal(
    <div
      ref={dialogRef}
      className="fixed inset-0 z-[250000] flex items-center justify-center bg-black/50 p-4"
      role="dialog"
      aria-modal="true"
      aria-labelledby={titleId}
      aria-describedby={descriptionId}
      aria-busy={busy}
      tabIndex={-1}
      onKeyDown={handleKeyDown}
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) requestClose();
      }}
    >
      <section className="sx-large-modal-window border border-[#ABB2B2] bg-white shadow-2xl">
        <header className="flex items-start justify-between gap-4 border-b border-[#D8DCDC] px-5 py-4">
          <div className="min-w-0">
            <h2 id={titleId} className="text-[16px] font-semibold text-[#262E2D]">
              {title}
            </h2>
            <p className="mt-1 truncate text-[12px] font-medium text-[#465757]">{reportTitle}</p>
            <p id={descriptionId} className="mt-1 text-[11px] leading-5 text-[#7C8685]">
              {description}
            </p>
          </div>
          <button
            type="button"
            disabled={busy}
            onClick={requestClose}
            aria-label="Close report output chooser"
            className="flex h-8 w-8 shrink-0 items-center justify-center border border-[#CFD6D6] text-[#677171] hover:border-[#B42318] hover:text-[#B42318] disabled:cursor-not-allowed disabled:opacity-50"
          >
            <X className="h-4 w-4" aria-hidden="true" />
          </button>
        </header>

        <div className="grid min-h-0 flex-1 content-start gap-3 overflow-y-auto p-5 md:grid-cols-3">
          {destinations.map((destination) => {
            const { Icon } = destination;
            const reasonId = `${titleId}-${destination.key}-reason`;
            return (
              <button
                key={destination.key}
                type="button"
                data-report-output={destination.key}
                disabled={destination.disabled}
                onClick={() => selectDestination(destination)}
                aria-describedby={destination.reason ? reasonId : undefined}
                className={`flex min-h-[168px] flex-col items-center justify-center gap-3 border border-[#A1A8A8] bg-white px-4 py-5 text-center ${destination.hoverClass} disabled:cursor-not-allowed disabled:border-[#CFD6D6] disabled:bg-[#F7F8F9] disabled:opacity-70`}
              >
                <Icon className={`h-9 w-9 ${destination.accentClass}`} aria-hidden="true" />
                <span className="text-[13px] font-semibold text-[#323B3A]">{destination.label}</span>
                <span className="text-[10px] leading-4 text-[#627474]">{destination.description}</span>
                {destination.reason && (
                  <span id={reasonId} className="text-[10px] font-semibold text-[#B42318]">
                    {destination.reason}
                  </span>
                )}
              </button>
            );
          })}
        </div>

        <footer className="flex min-h-14 items-center justify-between gap-4 border-t border-[#D8DCDC] bg-[#F8FAFC] px-5 py-3">
          <div className="min-w-0" aria-live="polite" role="status">
            {busy && (
              <span className="flex items-center gap-2 text-[11px] font-medium text-[#465757]">
                <LoaderCircle className="h-4 w-4 animate-spin" aria-hidden="true" />
                {busyLabel}
              </span>
            )}
          </div>
          <button
            type="button"
            disabled={busy}
            onClick={requestClose}
            className="h-8 min-w-[86px] border border-[#ABB2B2] bg-white px-4 text-[11px] font-semibold text-[#324443] hover:bg-[#EEF2F2] disabled:cursor-not-allowed disabled:opacity-50"
          >
            Cancel
          </button>
        </footer>
      </section>
    </div>,
    document.body,
  );
}

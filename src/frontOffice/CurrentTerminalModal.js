import React, { useEffect, useState } from "react";
import { Building2, Loader2, MapPin, MonitorCog, Save, X } from "lucide-react";
import {
  getCurrentTerminalSettings,
  updateCurrentTerminalSettings,
} from "../services/CurrentTerminalService";
import { saveLocalTillDeviceLabel } from "../services/TillService";

const errorMessage = (error, fallback) =>
  error?.response?.data?.message || error?.message || fallback;

const identityValue = (value) => String(value || "").trim() || "—";

export default function CurrentTerminalModal({ open, onClose, company = null }) {
  const [terminal, setTerminal] = useState(null);
  const [deviceLabel, setDeviceLabel] = useState("");
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  useEffect(() => {
    if (!open) return undefined;

    let active = true;
    setLoading(true);
    setError("");
    setNotice("");
    getCurrentTerminalSettings()
      .then((response) => {
        if (!active) return;
        const data = response?.data || response || null;
        setTerminal(data);
        setDeviceLabel(data?.device_label || "");
      })
      .catch((requestError) => {
        if (!active) return;
        setError(errorMessage(requestError, "This terminal could not be loaded."));
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => {
      active = false;
    };
  }, [open]);

  if (!open) return null;

  const branch = terminal?.branch || {};
  const companyName =
    company?.company_name || company?.name || terminal?.company_name || terminal?.company_id;
  const branchName = branch?.branch_name || branch?.name || terminal?.branch_name || terminal?.branch_id;

  const save = async (event) => {
    event.preventDefault();
    setSaving(true);
    setError("");
    setNotice("");

    try {
      const response = await updateCurrentTerminalSettings(deviceLabel);
      const data = response?.data || response || null;
      setTerminal(data);
      setDeviceLabel(data?.device_label || "");
      saveLocalTillDeviceLabel(data?.device_label || "");
      setNotice(response?.message || "Terminal label saved.");
    } catch (requestError) {
      setError(errorMessage(requestError, "The terminal label could not be saved."));
    } finally {
      setSaving(false);
    }
  };

  return (
    <div
      className="fixed inset-0 z-[100000] flex items-center justify-center bg-[#003637]/65 p-4 font-sans"
      onMouseDown={() => !saving && onClose()}
    >
      <section
        role="dialog"
        aria-modal="true"
        aria-labelledby="current-terminal-title"
        className="sx-large-modal-window border border-[#006161] bg-white shadow-2xl"
        onMouseDown={(event) => event.stopPropagation()}
      >
        <header className="flex items-start gap-3 bg-gradient-to-r from-[#067C7C] to-[#006565] px-5 py-4 text-white">
          <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-white/15">
            <MonitorCog className="h-6 w-6" />
          </span>
          <div className="min-w-0 flex-1">
            <h2 id="current-terminal-title" className="text-[17px] font-semibold">This Terminal</h2>
          </div>
          <button
            type="button"
            aria-label="Close terminal settings"
            disabled={saving}
            onClick={onClose}
            className="flex h-8 w-8 items-center justify-center rounded border border-white/30 bg-white/10 disabled:opacity-40"
          >
            <X className="h-4 w-4" />
          </button>
        </header>

        <form onSubmit={save} className="flex min-h-0 flex-1 flex-col">
          <div className="min-h-0 flex-1 space-y-4 overflow-y-auto p-5">
            {loading ? (
              <div className="flex min-h-[190px] items-center justify-center gap-2 text-[12px] text-[#566868]">
                <Loader2 className="h-4 w-4 animate-spin" /> Loading this terminal…
              </div>
            ) : (
              <>
                {error && <div role="alert" className="rounded border border-[#F5C2C0] bg-[#FFF2F1] px-3 py-2 text-[11px] text-[#9F241E]">{error}</div>}
                {notice && <div role="status" className="rounded border border-[#A9D8C6] bg-[#EFFBF6] px-3 py-2 text-[11px] text-[#136C4E]">{notice}</div>}

                <div className="grid gap-3 sm:grid-cols-2">
                  <IdentityCard icon={Building2} label="Company" value={identityValue(companyName)} />
                  <IdentityCard icon={MapPin} label="Branch" value={identityValue(branchName)} />
                  <IdentityCard label="Till Code" value={identityValue(terminal?.till_code)} />
                  <IdentityCard label="Till Name" value={identityValue(terminal?.till_name)} />
                </div>

                <div className="rounded border border-[#CCD6D6] bg-[#F8FAFC] p-4">
                  <label htmlFor="current-terminal-device-label" className="block text-[10px] font-bold uppercase tracking-wide text-[#566868]">
                    Device label
                  </label>
                  <input
                    id="current-terminal-device-label"
                    type="text"
                    maxLength={255}
                    value={deviceLabel}
                    onChange={(event) => setDeviceLabel(event.target.value)}
                    placeholder="e.g. Counter 1"
                    className="mt-2 h-10 w-full rounded border border-[#B2C1C0] bg-white px-3 text-[12px] text-[#054747] outline-none focus:border-[#067C7C] focus:ring-1 focus:ring-[#067C7C]"
                  />
                  <p className="mt-2 text-[10px] leading-4 text-[#6F7E7D]">Only this label is editable.</p>
                </div>
              </>
            )}
          </div>

          <footer className="flex justify-end gap-2 border-t border-[#D7E0E0] bg-[#F8FAFC] px-5 py-4">
            <button type="button" onClick={onClose} disabled={saving} className="h-9 rounded border border-[#A1B0AF] bg-white px-4 text-[11px] font-semibold text-[#3F4E4D] disabled:opacity-40">
              Close
            </button>
            <button type="submit" disabled={loading || saving || !terminal} className="flex h-9 items-center gap-2 rounded border border-[#006161] bg-[#067C7C] px-5 text-[11px] font-semibold text-white disabled:cursor-not-allowed disabled:opacity-45">
              {saving ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Save className="h-3.5 w-3.5" />}
              {saving ? "Saving" : "Save Label"}
            </button>
          </footer>
        </form>
      </section>
    </div>
  );
}

const IdentityCard = ({ icon: Icon, label, value }) => (
  <div className="rounded border border-[#CCD6D6] bg-[#F7FAFC] px-4 py-3">
    <div className="flex items-center gap-1.5 text-[9px] font-bold uppercase tracking-wide text-[#657878]">
      {Icon ? <Icon className="h-3.5 w-3.5" /> : null}{label}
    </div>
    <div className="mt-1 truncate text-[13px] font-semibold text-[#054747]" title={value}>{value}</div>
  </div>
);

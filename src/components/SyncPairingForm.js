import React, { useEffect, useState } from "react";
import { Cloud, KeyRound, Server } from "lucide-react";
import { useSyncStatus } from "../context/SyncStatusContext";
import { getSavedCompanyCode, normalizeCompanyCode } from "../utils/apiConfig";
import { normalizeSetupCode, serverPairingCodeError } from "../utils/setupCode";
import { requestEnterpriseConfirmation } from "./EnterpriseConfirmHost";

export default function SyncPairingForm({
  onPaired = null,
  compact = false,
  recovery = false,
  replacement = false,
  initialCloudUrl = "",
}) {
  const { pairNode, pairing, error } = useSyncStatus();
  const [values, setValues] = useState({
    company_code: getSavedCompanyCode(),
    pairing_code: "",
    cloud_url: String(initialCloudUrl || ""),
  });
  const [localError, setLocalError] = useState("");

  useEffect(() => {
    setValues((current) => ({
      ...current,
      company_code: current.company_code || getSavedCompanyCode(),
      cloud_url: current.cloud_url || String(initialCloudUrl || ""),
    }));
  }, [initialCloudUrl]);

  const update = (key, value) => {
    setValues((current) => ({ ...current, [key]: value }));
    setLocalError("");
  };

  const submit = async (event) => {
    event.preventDefault();
    const companyCode = normalizeCompanyCode(values.company_code);
    const pairingCode = normalizeSetupCode(values.pairing_code);
    const cloudUrl = String(values.cloud_url || "").trim();

    if (!cloudUrl) {
      setLocalError("The cloud server is not configured on this POS. Ask the installer to set SERVENEST_SYNC_CLOUD_URL and restart the local API.");
      return;
    }

    if (!companyCode) {
      setLocalError("Company code is required.");
      return;
    }

    const codeError = serverPairingCodeError(pairingCode);
    if (codeError) {
      setLocalError(codeError);
      return;
    }

    if (replacement && !await requestEnterpriseConfirmation({
      title: "Replace Revoked Server",
      message: "Replace this revoked POS server? This creates a new server identity with the same company, branch and till, while the old node remains revoked. Continue only with a Replace Revoked Server code issued for this exact server.",
      confirmLabel: "Replace server",
    })) {
      return;
    }

    if (recovery && !replacement && !await requestEnterpriseConfirmation({
      title: "Re-pair POS Server",
      message: "Re-pair this POS server? This rotates the server synchronization credential and does not activate an additional browser. Continue only with a Re-pair Server code issued for this same active or suspended server.",
      confirmLabel: "Re-pair server",
    })) {
      return;
    }

    try {
      const result = await pairNode({
        company_code: companyCode,
        pairing_code: pairingCode,
        cloud_url: cloudUrl,
      });
      setValues((current) => ({ ...current, pairing_code: "" }));
      onPaired?.(result);
    } catch {
      // Context exposes the server-safe error message.
    }
  };

  return (
    <form onSubmit={submit} aria-busy={pairing} className={compact ? "space-y-3" : "space-y-4"}>
      <div className="grid gap-3 md:grid-cols-2">
        <label className="block">
          <span className="mb-1 flex items-center gap-1 text-[9px] font-bold uppercase tracking-wide text-[#566868]">
            <Cloud className="h-3 w-3" /> Company Code
          </span>
          <input
            value={values.company_code}
            onChange={(event) => update("company_code", normalizeCompanyCode(event.target.value))}
            autoComplete="organization"
            placeholder="COMPANY-CODE"
            className="h-10 w-full border border-[#B2C1C0] px-3 text-[12px] font-semibold uppercase text-[#054747] outline-none focus:border-[#067C7C]"
          />
        </label>
        <label className="block">
          <span className="mb-1 flex items-center gap-1 text-[9px] font-bold uppercase tracking-wide text-[#566868]">
            <KeyRound className="h-3 w-3" /> {replacement
              ? "One-Time Revoked Server Replacement Code"
              : recovery
                ? "One-Time POS Server Re-pair Code"
                : "One-Time POS Server Pairing Code"}
          </span>
          <input
            value={values.pairing_code}
            onChange={(event) => update("pairing_code", normalizeSetupCode(event.target.value))}
            autoComplete="one-time-code"
            placeholder={replacement ? "SERVER REPLACEMENT CODE" : recovery ? "SERVER RE-PAIR CODE" : "SERVER PAIRING CODE"}
            spellCheck={false}
            aria-describedby="server-pairing-code-help"
            className="h-10 w-full border border-[#B2C1C0] px-3 text-[12px] font-semibold uppercase text-[#054747] outline-none focus:border-[#067C7C]"
          />
          <span id="server-pairing-code-help" className="mt-1 block text-[9px] leading-4 text-[#6F7E7D]">
            Server codes contain a dot. Codes beginning with TERM- activate a browser and do not belong here.
          </span>
        </label>
      </div>

      {values.cloud_url ? (
        <div className="flex items-center gap-3 border border-[#D7E0E0] bg-[#F8FAFC] px-3 py-2.5 text-[#255858]">
          <Server className="h-4 w-4 shrink-0 text-[#067C7C]" />
          <div className="min-w-0">
            <div className="text-[9px] font-bold uppercase tracking-wide text-[#6F7E7D]">Cloud server</div>
            <div className="truncate text-[11px] font-semibold" title={values.cloud_url}>{values.cloud_url}</div>
            <div className="mt-0.5 text-[9px] text-[#6F7E7D]">Configured by the installer and locked on this POS.</div>
          </div>
        </div>
      ) : (
        <div role="alert" className="border border-[#FDA29B] bg-[#FEF3F2] px-3 py-2 text-[11px] font-semibold text-[#B42318]">
          Cloud server is not configured on this POS. Set SERVENEST_SYNC_CLOUD_URL on the local API and restart it.
        </div>
      )}

      {(localError || error) && (
        <div role="alert" className="border border-[#FDA29B] bg-[#FEF3F2] px-3 py-2 text-[11px] font-semibold text-[#B42318]">
          {localError || error}
        </div>
      )}

      <div className="flex items-center justify-between gap-3 border-t border-[#D7E0E0] pt-3">
        <p className="max-w-[620px] text-[10px] leading-4 text-[#6F7E7D]">
          {replacement
            ? "This replaces only the revoked server with a new same-scope identity; the old node remains revoked. It is blocked while unsent or failed sync events remain."
            : recovery
              ? "This rotates the active or suspended POS server credential while preserving its company, branch and till binding. It does not activate this browser."
              : "This creates the POS server binding. To add a browser to an already-paired server, use a TERM- browser activation code instead."}
        </p>
        <button
          type="submit"
          disabled={pairing || !values.cloud_url}
          className="h-9 shrink-0 border border-[#006161] bg-[#067C7C] px-5 text-[11px] font-semibold text-white disabled:opacity-50"
        >
          {pairing ? "Pairing…" : replacement ? "Replace Revoked POS Server" : recovery ? "Re-pair POS Server" : "Pair POS Server"}
        </button>
      </div>
    </form>
  );
}

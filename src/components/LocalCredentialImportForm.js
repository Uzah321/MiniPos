import React, { useState } from "react";
import { Eye, EyeOff, KeyRound, TerminalSquare } from "lucide-react";
import { useSyncStatus } from "../context/SyncStatusContext";

const emptyValues = {
  local_control_token: "",
  till_token: "",
};

export default function LocalCredentialImportForm({ onImported = null, compact = false }) {
  const {
    importLocalCredentials,
    importingLocalCredentials,
    credentialImportError,
  } = useSyncStatus();
  const [values, setValues] = useState(emptyValues);
  const [visible, setVisible] = useState(false);
  const [localError, setLocalError] = useState("");

  const update = (key, value) => {
    setValues((current) => ({ ...current, [key]: value }));
    setLocalError("");
  };

  const submit = async (event) => {
    event.preventDefault();
    const localControlToken = String(values.local_control_token || "").trim();
    const tillToken = String(values.till_token || "").trim();

    if (!localControlToken || !tillToken) {
      setLocalError("Enter both values printed by the local server command.");
      return;
    }

    try {
      const status = await importLocalCredentials({
        local_control_token: localControlToken,
        till_token: tillToken,
      });
      setValues(emptyValues);
      setVisible(false);
      onImported?.(status);
    } catch {
      // The context exposes a safe operator-facing error.
    }
  };

  const inputType = visible ? "text" : "password";

  return (
    <form onSubmit={submit} className={compact ? "space-y-3" : "space-y-4"}>
      <div className="grid gap-3 md:grid-cols-2">
        <label className="block">
          <span className="mb-1 flex items-center gap-1 text-[9px] font-bold uppercase tracking-wide text-[#566868]">
            <TerminalSquare className="h-3 w-3" /> Server Recovery Code
          </span>
          <input
            type={inputType}
            value={values.local_control_token}
            onChange={(event) => update("local_control_token", event.target.value)}
            autoComplete="off"
            autoCapitalize="none"
            spellCheck={false}
            maxLength={512}
            aria-label="Server recovery code"
            placeholder="Enter server recovery code"
            className="h-10 w-full border border-[#B2C1C0] px-3 font-mono text-[11px] text-[#054747] outline-none focus:border-[#067C7C]"
          />
        </label>
        <label className="block">
          <span className="mb-1 flex items-center gap-1 text-[9px] font-bold uppercase tracking-wide text-[#566868]">
            <KeyRound className="h-3 w-3" /> Terminal Recovery Code
          </span>
          <input
            type={inputType}
            value={values.till_token}
            onChange={(event) => update("till_token", event.target.value)}
            autoComplete="off"
            autoCapitalize="none"
            spellCheck={false}
            maxLength={512}
            aria-label="Terminal recovery code"
            placeholder="Enter terminal recovery code"
            className="h-10 w-full border border-[#B2C1C0] px-3 font-mono text-[11px] text-[#054747] outline-none focus:border-[#067C7C]"
          />
        </label>
      </div>

      <label className="inline-flex cursor-pointer items-center gap-2 text-[10px] font-semibold text-[#566868]">
        <input
          type="checkbox"
          checked={visible}
          onChange={(event) => setVisible(event.target.checked)}
          className="h-3.5 w-3.5"
        />
        {visible ? <EyeOff className="h-3.5 w-3.5" /> : <Eye className="h-3.5 w-3.5" />}
        Show recovery codes while checking them
      </label>

      {(localError || credentialImportError) && (
        <div role="alert" className="border border-[#FDA29B] bg-[#FEF3F2] px-3 py-2 text-[11px] font-semibold text-[#B42318]">
          {localError || credentialImportError}
        </div>
      )}

      <div className="flex items-center justify-between gap-3 border-t border-[#D7E0E0] pt-3">
        <p className="max-w-[620px] text-[10px] leading-4 text-[#6F7E7D]">
          Both recovery codes are checked against this local POS before they are saved in this browser profile. Do not enter a cloud signing secret here.
        </p>
        <button
          type="submit"
          disabled={importingLocalCredentials}
          className="h-9 shrink-0 border border-[#006161] bg-[#067C7C] px-5 text-[11px] font-semibold text-white disabled:opacity-50"
        >
          {importingLocalCredentials ? "Verifying…" : "Verify and Save Codes"}
        </button>
      </div>
    </form>
  );
}

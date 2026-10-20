import React, { useState } from "react";
import {
  AlertTriangle,
  Building2,
  KeyRound,
  RefreshCw,
  Server,
  ShieldCheck,
  WifiOff,
  Wrench,
  X,
} from "lucide-react";
import {
  BACKEND_MODES,
  getBackendMode,
  isDedicatedFrontOffice,
  isLegacySyncContractAllowed,
} from "../config/DeploymentConfig";
import { useSyncStatus } from "../context/SyncStatusContext";
import { getLocalTill } from "../services/TillService";
import { normalizeSetupCode, terminalActivationCodeError } from "../utils/setupCode";
import LocalCredentialImportForm from "./LocalCredentialImportForm";
import SyncPairingForm from "./SyncPairingForm";

export const FRONT_OFFICE_GATE_STATES = Object.freeze({
  READY: "ready",
  LOADING: "loading",
  CONTRACT_REQUIRED: "contract_required",
  LOCAL_OFFLINE: "local_offline",
  DEPLOYMENT_MISMATCH: "deployment_mismatch",
  PAIRING_REQUIRED: "pairing_required",
  TILL_RECOVERY_REQUIRED: "till_recovery_required",
  CONFIGURATION_PENDING: "configuration_pending",
});

export const resolveFrontOfficeGateState = ({
  dedicatedFrontOffice = false,
  backendMode = "",
  initialLoading = false,
  contractAvailable = null,
  status = null,
  legacyAllowed = false,
  localTillTokenPresent = false,
} = {}) => {
  if (!dedicatedFrontOffice) return FRONT_OFFICE_GATE_STATES.READY;
  if (initialLoading && !status) return FRONT_OFFICE_GATE_STATES.LOADING;

  if (contractAvailable === false) {
    return legacyAllowed
      ? FRONT_OFFICE_GATE_STATES.READY
      : FRONT_OFFICE_GATE_STATES.CONTRACT_REQUIRED;
  }

  if (!status || status.localBackendReachable === false) {
    return FRONT_OFFICE_GATE_STATES.LOCAL_OFFLINE;
  }

  if (backendMode === BACKEND_MODES.STANDALONE) {
    if (
      status.deploymentMode !== BACKEND_MODES.STANDALONE
      || status.localOnly !== true
    ) {
      return FRONT_OFFICE_GATE_STATES.DEPLOYMENT_MISMATCH;
    }

    if (status.configurationPending) {
      return FRONT_OFFICE_GATE_STATES.CONFIGURATION_PENDING;
    }

    if (!localTillTokenPresent || status.tillSetupRequired) {
      return FRONT_OFFICE_GATE_STATES.TILL_RECOVERY_REQUIRED;
    }

    return status.sellReady === true
      ? FRONT_OFFICE_GATE_STATES.READY
      : FRONT_OFFICE_GATE_STATES.CONFIGURATION_PENDING;
  }

  if (backendMode && backendMode !== BACKEND_MODES.EDGE) {
    return FRONT_OFFICE_GATE_STATES.DEPLOYMENT_MISMATCH;
  }

  if (status.deploymentMode !== BACKEND_MODES.EDGE) {
    const legacyStatusShape = !status.deploymentMode;
    return legacyAllowed && legacyStatusShape
      ? FRONT_OFFICE_GATE_STATES.READY
      : FRONT_OFFICE_GATE_STATES.DEPLOYMENT_MISMATCH;
  }

  if (!status.paired) {
    return status.pairingRequired
      ? FRONT_OFFICE_GATE_STATES.PAIRING_REQUIRED
      : FRONT_OFFICE_GATE_STATES.DEPLOYMENT_MISMATCH;
  }

  if (!localTillTokenPresent || status.tillSetupRequired) {
    return FRONT_OFFICE_GATE_STATES.TILL_RECOVERY_REQUIRED;
  }

  if (status.configurationPending || status.sellReady !== true) {
    return FRONT_OFFICE_GATE_STATES.CONFIGURATION_PENDING;
  }

  return FRONT_OFFICE_GATE_STATES.READY;
};

const FullScreenMessage = ({ icon: Icon, tone = "danger", title, children, action = null }) => {
  const warning = tone === "warning";
  return (
    <main className="flex min-h-screen items-center justify-center bg-[#003030] p-6 text-white">
      <section className={`w-full max-w-[620px] border ${warning ? "border-[#92400E]" : "border-[#7F1D1D]"} bg-[#003535] p-6 text-center shadow-2xl`}>
        <Icon className={`mx-auto h-9 w-9 ${warning ? "text-[#FCD34D]" : "text-[#FDA29B]"}`} />
        <h1 className="mt-4 text-[17px] font-semibold">{title}</h1>
        <div className="mt-2 text-[11px] leading-5 text-white/65">{children}</div>
        {action}
      </section>
    </main>
  );
};

export const PairingCard = ({ recovery = false, replacement = false, status, onPaired }) => {
  const [advancedRecoveryOpen, setAdvancedRecoveryOpen] = useState(false);
  const repairFlow = recovery || replacement;

  return (
    <main className="flex min-h-screen items-center justify-center bg-[#003030] p-5 font-sans text-[#054747]">
      <section className="w-full max-w-[980px] border border-[#006161] bg-white shadow-2xl">
      <header className="flex items-start gap-3 border-b border-[#006161] bg-[#003535] px-5 py-4 text-white">
        <span className="grid h-10 w-10 place-items-center bg-[#067C7C]">
          {repairFlow ? <Wrench className="h-5 w-5" /> : <Building2 className="h-5 w-5" />}
        </span>
        <div className="min-w-0 flex-1">
          <div className="text-[10px] font-bold uppercase tracking-[0.2em] text-[#00BABA]">
            {replacement ? "Physical POS replacement" : recovery ? "Physical POS recovery" : "Physical POS setup"}
          </div>
          <h1 className="mt-1 text-[18px] font-semibold">
            {replacement ? "Replace this revoked POS server" : recovery ? "Re-pair this POS server" : "Pair this POS server before sign in"}
          </h1>
          <p className="mt-1 text-[10px] text-white/60">The server binding fixes this installation to one company, branch and till.</p>
        </div>
        <ShieldCheck className="h-6 w-6 text-[#86EFAC]" />
      </header>
        <div className="p-5">
        <div className="mb-4 border border-[#BEDAD9] bg-[#F1F7F7] px-4 py-3 text-[11px] leading-5 text-[#1A5151]">
          {replacement
            ? "This POS server was revoked. In Back Office, open Deployment & Sync and choose Replace Revoked Server on this exact record. Enter that dotted replacement code below. Existing sales remain in the local database, and replacement is blocked while any event is unsent or failed."
            : recovery
            ? "In Back Office, open Deployment & Sync, choose Re-pair Server for this same active or suspended POS server, then enter the company code and new server code below. Do not use a TERM- browser activation code."
            : "In Back Office, open Deployment & Sync and generate a New POS Server code for the correct branch and till. Do not use the Activate Browser action."}
        </div>
        <SyncPairingForm
          recovery={recovery}
          replacement={replacement}
          initialCloudUrl={status?.node?.cloudUrlMasked || ""}
          onPaired={onPaired}
        />
        {recovery && !replacement && (
          <div className="mt-4 border-t border-[#D7E0E0] pt-3">
            <button
              type="button"
              aria-expanded={advancedRecoveryOpen}
              aria-controls="advanced-local-credential-recovery"
              onClick={() => setAdvancedRecoveryOpen((open) => !open)}
              className="text-[10px] font-semibold text-[#566868] underline underline-offset-2"
            >
              {advancedRecoveryOpen ? "Hide advanced recovery" : "Advanced recovery codes"}
            </button>
            {advancedRecoveryOpen && (
              <section
                id="advanced-local-credential-recovery"
                aria-label="Advanced local credential recovery"
                className="mt-3 border border-[#D7E0E0] bg-[#F8FAFC] p-4"
              >
                <p className="mb-3 text-[10px] leading-4 text-[#6F7E7D]">
                  Use this only when the local service is already paired and a server administrator has reissued both browser recovery codes.
                </p>
                <LocalCredentialImportForm onImported={onPaired} compact />
              </section>
            )}
          </div>
        )}
        </div>
      </section>
    </main>
  );
};

export const TerminalActivationCard = ({ status, onActivated, standalone = false }) => {
  const {
    activateTerminal,
    terminalActivating,
    terminalActivationError,
  } = useSyncStatus();
  const [activationCode, setActivationCode] = useState("");
  const [serverRepairOpen, setServerRepairOpen] = useState(false);
  const [serverRepairMode, setServerRepairMode] = useState("");
  const [advancedRecoveryOpen, setAdvancedRecoveryOpen] = useState(false);
  const [localError, setLocalError] = useState("");

  const submit = async (event) => {
    event.preventDefault();
    const code = normalizeSetupCode(activationCode);
    const codeError = terminalActivationCodeError(code);
    if (codeError) {
      setLocalError(codeError);
      return;
    }

    setLocalError("");
    try {
      const result = await activateTerminal(code);
      setActivationCode("");
      onActivated?.(result);
    } catch {
      // The context exposes a server-safe error message.
    }
  };

  return (
    <main className="flex min-h-screen items-center justify-center bg-[#003030] p-5 font-sans text-[#054747]">
      <section className="w-full max-w-[760px] border border-[#006161] bg-white shadow-2xl">
        <header className="flex items-start gap-3 border-b border-[#006161] bg-[#003535] px-5 py-4 text-white">
          <span className="grid h-10 w-10 place-items-center bg-[#067C7C]"><KeyRound className="h-5 w-5" /></span>
          <div className="min-w-0 flex-1">
            <div className="text-[10px] font-bold uppercase tracking-[0.2em] text-[#00BABA]">Browser terminal activation</div>
            <h1 className="mt-1 text-[18px] font-semibold">
              {standalone
                ? "Activate this Front Office browser"
                : "Activate this browser on the paired POS server"}
            </h1>
            <p className="mt-1 text-[10px] text-white/60">
              {standalone
                ? "This browser will receive its own till identity from the local standalone server."
                : "The POS server is already paired. This action adds only this browser's till identity."}
            </p>
          </div>
          <ShieldCheck className="h-6 w-6 text-[#86EFAC]" />
        </header>

        <div className="space-y-4 p-5">
          <form onSubmit={submit} className="space-y-4" aria-busy={terminalActivating}>
          <div className="border border-[#BEDAD9] bg-[#F1F7F7] px-4 py-3 text-[11px] leading-5 text-[#1A5151]">
            {standalone ? (
              <>
                In the separate Back Office browser, open <strong>Till Management</strong>, choose this till and select <strong>Code</strong>. Generate a one-time activation code, then enter the <strong>TERM-</strong> code shown there. The code is displayed only in Back Office and expires shortly.
              </>
            ) : (
              <>
                In Back Office, open <strong>Deployment &amp; Sync</strong>, choose the <strong>Browser activation till</strong>, then select <strong>Activate Browser</strong> on this active POS server. Enter the <strong>TERM-</strong> code shown there. A code containing a dot pairs or re-pairs the whole server and will not work here.
              </>
            )}
          </div>

          <div className="grid gap-3 sm:grid-cols-2">
            <div className="border border-[#D5DEDE] bg-[#F8FAFC] px-3 py-2.5">
              <div className="text-[9px] font-bold uppercase tracking-wide text-[#6F7E7D]">
                {standalone ? "Local standalone installation" : "Paired branch server"}
              </div>
              <div className="mt-1 text-[12px] font-semibold text-[#054747]">{status?.node?.name || status?.branch?.name || (standalone ? "Local POS" : "Local POS server")}</div>
              <div className="mt-0.5 text-[9px] text-[#6F7E7D]">{status?.company?.name || status?.company?.code || "Company verified"}</div>
            </div>
            <label className="block">
              <span className="mb-1 flex items-center gap-1 text-[9px] font-bold uppercase tracking-wide text-[#566868]">
                <KeyRound className="h-3 w-3" /> One-Time Browser Activation Code
              </span>
              <input
                value={activationCode}
                onChange={(event) => {
                  setActivationCode(normalizeSetupCode(event.target.value));
                  setLocalError("");
                }}
                autoComplete="one-time-code"
                placeholder="TERM-XXXXXXXX-XXXXXXXX-XXXXXXXX-XXXXXXXX"
                spellCheck={false}
                aria-describedby="terminal-activation-code-help"
                className="h-11 w-full border border-[#B2C1C0] px-3 font-mono text-[12px] font-semibold uppercase text-[#054747] outline-none focus:border-[#067C7C]"
              />
              <span id="terminal-activation-code-help" className="mt-1 block text-[9px] leading-4 text-[#6F7E7D]">
                Browser activation codes always begin with TERM-. Server pairing codes contain a dot.
              </span>
            </label>
          </div>

          {(localError || terminalActivationError) && (
            <div role="alert" className="border border-[#FDA29B] bg-[#FEF3F2] px-3 py-2 text-[11px] font-semibold text-[#B42318]">
              {localError || terminalActivationError}
            </div>
          )}

          <div className="flex flex-wrap items-center justify-between gap-3 border-t border-[#D7E0E0] pt-3">
            <span className="text-[10px] text-[#6F7E7D]">
              {standalone
                ? "The Back Office browser does not receive or store this till credential."
                : "This does not change the POS server pairing."}
            </span>
            <button type="submit" disabled={terminalActivating} className="h-10 border border-[#006161] bg-[#067C7C] px-5 text-[11px] font-semibold text-white disabled:opacity-50">
              {terminalActivating ? "Activating…" : "Activate This Browser"}
            </button>
          </div>
          </form>

          {!standalone && <div className="grid gap-2 border-t border-[#D7E0E0] pt-3 sm:grid-cols-2">
            <button
              type="button"
              aria-expanded={serverRepairOpen}
              aria-controls="pos-server-repair"
              onClick={() => setServerRepairOpen((open) => !open)}
              className="text-left text-[10px] font-semibold text-[#006565] underline underline-offset-2"
            >
              {serverRepairOpen ? "Hide POS server repair / replacement" : "Need to repair or replace the POS server instead?"}
            </button>
            <button
              type="button"
              aria-expanded={advancedRecoveryOpen}
              aria-controls="browser-token-recovery"
              onClick={() => setAdvancedRecoveryOpen((open) => !open)}
              className="text-left text-[10px] font-semibold text-[#566868] underline underline-offset-2 sm:text-right"
            >
              {advancedRecoveryOpen ? "Hide browser token recovery" : "Restore browser recovery tokens"}
            </button>
          </div>}

          {!standalone && serverRepairOpen && (
            <section id="pos-server-repair" className="border border-[#6ED4D3] bg-[#EFF7F7] p-4" aria-label="POS server repair or replacement">
              <div className="mb-3 text-[10px] font-semibold text-[#054747]">Choose the Back Office action that issued your dotted server code:</div>
              <div className="mb-3 grid gap-2 sm:grid-cols-2">
                <button
                  type="button"
                  aria-pressed={serverRepairMode === "repair"}
                  onClick={() => setServerRepairMode("repair")}
                  className={`border px-3 py-2 text-left text-[10px] font-semibold ${serverRepairMode === "repair" ? "border-[#006565] bg-[#DBEDEC] text-[#006565]" : "border-[#B2C1C0] bg-white text-[#255858]"}`}
                >
                  Re-pair active / suspended server
                </button>
                <button
                  type="button"
                  aria-pressed={serverRepairMode === "replace"}
                  onClick={() => setServerRepairMode("replace")}
                  className={`border px-3 py-2 text-left text-[10px] font-semibold ${serverRepairMode === "replace" ? "border-[#92400E] bg-[#FFF8E7] text-[#704C0D]" : "border-[#B2C1C0] bg-white text-[#255858]"}`}
                >
                  Replace revoked server
                </button>
              </div>
              {serverRepairMode === "repair" && (
                <>
                  <div className="mb-3 border border-[#E7C77D] bg-[#FFF8E7] px-3 py-2 text-[10px] leading-4 text-[#704C0D]">
                    Use only a <strong>Re-pair Server</strong> code issued for this same active or suspended server. This rotates its sync credential and does not activate this browser. A revoked server must use the separate replacement workflow.
                  </div>
                  <SyncPairingForm recovery initialCloudUrl={status?.node?.cloudUrlMasked || ""} onPaired={onActivated} />
                </>
              )}
              {serverRepairMode === "replace" && (
                <>
                  <div className="mb-3 border border-[#FDA29B] bg-[#FEF3F2] px-3 py-2 text-[10px] leading-4 text-[#B42318]">
                    Use only a <strong>Replace Revoked Server</strong> code issued for this exact revoked server. Replacement preserves company, branch and till, leaves the old node revoked, and is blocked until this local server has zero unsent or failed sync events.
                  </div>
                  <SyncPairingForm replacement initialCloudUrl={status?.node?.cloudUrlMasked || ""} onPaired={onActivated} />
                </>
              )}
            </section>
          )}

          {!standalone && advancedRecoveryOpen && (
            <section id="browser-token-recovery" className="border border-[#D7E0E0] bg-[#F8FAFC] p-4" aria-label="Browser token recovery">
              <p className="mb-3 text-[10px] leading-4 text-[#6F7E7D]">
                Use these two local recovery values only when a server administrator is restoring this exact browser profile. Normal additional browsers should use the TERM- activation code above.
              </p>
              <LocalCredentialImportForm onImported={onActivated} compact />
            </section>
          )}
        </div>
      </section>
    </main>
  );
};

export default function FrontOfficePairingGate({ children }) {
  const { status, initialLoading, contractAvailable, error, refresh, runNow, running } = useSyncStatus();
  const [recoveryOpen, setRecoveryOpen] = useState(false);
  const backendMode = getBackendMode();
  const standalone = backendMode === BACKEND_MODES.STANDALONE;
  const localTillTokenPresent = Boolean(getLocalTill()?.till_token);
  const gateState = resolveFrontOfficeGateState({
    dedicatedFrontOffice: isDedicatedFrontOffice(),
    backendMode,
    initialLoading,
    contractAvailable,
    status,
    legacyAllowed: isLegacySyncContractAllowed(),
    localTillTokenPresent,
  });

  if (gateState === FRONT_OFFICE_GATE_STATES.READY) {
    const dedicatedFront = isDedicatedFrontOffice();
    return (
      <>
        {children}
        {dedicatedFront && (
          <button
            type="button"
            onClick={() => setRecoveryOpen(true)}
            className="sx-device-pairing-button fixed bottom-10 right-3 z-[999990] flex h-8 items-center gap-1.5 border border-[#006161] bg-[#003535] px-3 text-[9px] font-bold uppercase text-white/75 shadow-lg"
          >
            <Wrench className="h-3.5 w-3.5" /> {standalone ? "Browser Activation" : "Device Pairing"}
          </button>
        )}
        {recoveryOpen && (
          <div className="fixed inset-0 z-[999999] overflow-auto bg-black/75 p-5">
            <button
              type="button"
              onClick={() => setRecoveryOpen(false)}
              className="fixed right-7 top-7 grid h-9 w-9 place-items-center border border-white/30 bg-[#003535] text-white"
              aria-label="Close recovery"
            >
              <X className="h-4 w-4" />
            </button>
            <TerminalActivationCard
              status={status}
              standalone={standalone}
              onActivated={() => {
                setRecoveryOpen(false);
                refresh();
              }}
            />
          </div>
        )}
      </>
    );
  }

  if (gateState === FRONT_OFFICE_GATE_STATES.LOADING) {
    return (
      <main className="flex min-h-screen items-center justify-center bg-[#003030] p-6 text-white">
        <div role="status" className="flex items-center gap-3 border border-white/20 bg-white/5 px-5 py-4 text-[12px]">
          <Server className="h-5 w-5 animate-pulse text-[#00BABA]" /> Checking the local POS service…
        </div>
      </main>
    );
  }

  if (gateState === FRONT_OFFICE_GATE_STATES.CONTRACT_REQUIRED) {
    return (
      <FullScreenMessage
        icon={AlertTriangle}
        title="Local POS upgrade required"
        action={<button type="button" onClick={() => refresh()} className="mx-auto mt-5 flex h-9 items-center gap-2 border border-[#FDA29B] bg-[#B42318] px-5 text-[11px] font-semibold text-white"><RefreshCw className="h-3.5 w-3.5" /> Retry Local Service</button>}
      >
        This Front Office installation requires the secure synchronization contract. Upgrade or correct the local ServeNest API; trading cannot start without a verified company, shop and till binding.
      </FullScreenMessage>
    );
  }

  if (gateState === FRONT_OFFICE_GATE_STATES.LOCAL_OFFLINE) {
    return (
      <FullScreenMessage
        icon={WifiOff}
        title="Local POS service is unavailable"
        action={<button type="button" onClick={() => refresh()} className="mx-auto mt-5 flex h-9 items-center gap-2 border border-[#00BABA] bg-[#067C7C] px-5 text-[11px] font-semibold text-white"><RefreshCw className="h-3.5 w-3.5" /> Retry Local Service</button>}
      >
        <p>{error || "The browser could not verify its local ServeNest API."}</p>
        <p className="mt-2 text-[10px] text-white/45">Start the local API and retry. Cloud availability never replaces the local service used to record sales.</p>
      </FullScreenMessage>
    );
  }

  if (gateState === FRONT_OFFICE_GATE_STATES.DEPLOYMENT_MISMATCH) {
    return (
      <FullScreenMessage
        icon={AlertTriangle}
        title="Front Office deployment mismatch"
        action={<button type="button" onClick={() => refresh()} className="mx-auto mt-5 flex h-9 items-center gap-2 border border-[#FDA29B] bg-[#B42318] px-5 text-[11px] font-semibold text-white"><RefreshCw className="h-3.5 w-3.5" /> Recheck Configuration</button>}
      >
        {standalone
          ? "This Front Office browser is configured for a standalone installation, but the local API did not explicitly report standalone local-only mode. Confirm backend_mode is standalone and that this browser points to the local standalone API."
          : "The browser is configured as a physical POS, but the local API did not identify itself as an edge deployment with a valid pairing state. Set the backend to edge mode and pair this installation before trading."}
      </FullScreenMessage>
    );
  }

  if (gateState === FRONT_OFFICE_GATE_STATES.PAIRING_REQUIRED) {
    return (
      <PairingCard
        replacement={Boolean(status?.replacementRequired || status?.state === "revoked")}
        status={status}
        onPaired={() => refresh()}
      />
    );
  }

  if (gateState === FRONT_OFFICE_GATE_STATES.TILL_RECOVERY_REQUIRED) {
    return <TerminalActivationCard standalone={standalone} status={status} onActivated={() => refresh()} />;
  }

  return (
    <FullScreenMessage
      icon={ShieldCheck}
      tone="warning"
      title={standalone ? "Standalone setup is not ready" : "Finishing POS configuration"}
      action={(
        <button
          type="button"
          disabled={standalone ? false : running}
          onClick={() => (standalone ? refresh() : runNow().catch(() => {}))}
          className="mx-auto mt-5 flex h-9 items-center gap-2 border border-[#FCD34D] bg-[#92400E] px-5 text-[11px] font-semibold text-white disabled:opacity-50"
        >
          <RefreshCw className={`h-3.5 w-3.5 ${!standalone && running ? "animate-spin" : ""}`} />
          {standalone ? "Recheck Local Setup" : running ? "Synchronizing…" : "Retry Configuration Sync"}
        </button>
      )}
    >
      {standalone
        ? "The local standalone API has not reported a complete operational setup. Finish the local Back Office company, branch and till setup, then recheck it here. No cloud synchronization is used."
        : "The device identity is valid, but the first complete operational configuration has not arrived. Keep the local API running and connect to the cloud long enough to finish the download."}
    </FullScreenMessage>
  );
}

import React, { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from "react";
import {
  getSyncStatus,
  isSyncContractUnavailable,
  normalizeSyncStatus,
  pairSyncNode,
  redeemTerminalActivationCode,
  runSyncNow,
  validateLocalSyncCredentials,
} from "../services/SyncService";
import {
  getSavedCompanyCode,
  saveCompanyCode,
  saveSyncLocalControlToken,
} from "../utils/apiConfig";
import { saveLocalTill } from "../services/TillService";
import {
  BACKEND_MODES,
  getBackendMode,
  getSyncIntervalMs,
  isDedicatedFrontOffice,
} from "../config/DeploymentConfig";
import { isUnscopedPlatformAdministrator } from "../utils/workspaceAccess";

const SyncStatusContext = createContext(null);

const getMessage = (error, fallback) =>
  error?.response?.data?.message || error?.displayMessage || error?.message || fallback;

const hasUnscopedPlatformSession = () => {
  if (
    typeof window === "undefined" ||
    !/(^|\/)admin(?:\/|$)/.test(window.location.pathname)
  ) {
    return false;
  }

  try {
    return isUnscopedPlatformAdministrator(
      JSON.parse(localStorage.getItem("back_user") || "null"),
    );
  } catch {
    return false;
  }
};

export const localCredentialRecoveryStatusFromError = (error) => {
  const payload = error?.response?.data?.data;
  if (
    ![401, 428].includes(Number(error?.response?.status))
    || String(error?.response?.data?.code || "") !== "SYNC_LOCAL_CONTROL_REQUIRED"
    || payload?.deployment_mode !== "edge"
    || payload?.paired !== true
  ) {
    return null;
  }

  return normalizeSyncStatus({ data: {
    ...payload,
    // A 428 from the paired edge means the browser credential is absent or
    // invalid even if stale localStorage still contains an old value.
    till_setup_required: true,
    sell_ready: false,
  } });
};

export const isEdgeAutoSyncEligible = ({ status, online = true, dedicatedFrontOffice = false }) => (
  Boolean(dedicatedFrontOffice)
  && status?.deploymentMode === BACKEND_MODES.EDGE
  && status?.localOnly !== true
  && Boolean(status?.paired)
  && !status?.pairingRequired
  && !status?.tillSetupRequired
  && Boolean(status?.cloudConfigured)
  && online !== false
);

export const isStandaloneLocalRuntime = ({
  status = null,
  backendMode = getBackendMode(),
} = {}) => (
  backendMode === BACKEND_MODES.STANDALONE
  || status?.deploymentMode === BACKEND_MODES.STANDALONE
  || status?.localOnly === true
);

export const localTillIdentityFromPairing = (result = {}) => {
  const responseTill = result.payload?.till
    || result.payload?.identity?.till
    || result.payload?.node?.till
    || {};

  return {
    ...responseTill,
    id: responseTill.id || result.status?.till?.id || result.payload?.till_id,
    till_code: responseTill.till_code || result.status?.till?.code,
    till_name: responseTill.till_name || result.status?.till?.name,
    branch_id: responseTill.branch_id || result.status?.branch?.id || result.payload?.branch_id,
    till_token: responseTill.till_token || result.payload?.till_token,
    hierarchy: result.status?.hierarchy || result.payload?.hierarchy,
    branding: result.status?.branding || result.payload?.branding,
    shop: result.status?.shop || result.payload?.shop,
    brand_id: result.status?.brand?.id,
    brand_name: result.status?.brand?.name,
    brand_logo: result.status?.brand?.logo,
    complex_id: result.status?.complex?.id,
    complex_name: result.status?.complex?.name,
    company_id: result.status?.company?.id,
    company_name: result.status?.company?.name,
    country_id: result.status?.country?.id,
    country_name: result.status?.country?.name,
  };
};

// A temporary synchronization/status failure must never discard a verified
// local terminal identity or turn an already-mounted sale into a logout/gate
// transition. Remote equality still fails closed until a fresh proof arrives.
export const statusAfterTransientSyncFailure = (current, message) => current ? ({
  ...current,
  lastError: message,
  directions: {
    posToCloud: {
      ...(current.directions?.posToCloud || {}),
      inSync: false,
      state: "attention",
      message,
    },
    cloudToPos: {
      ...(current.directions?.cloudToPos || {}),
      inSync: false,
      state: "attention",
      message,
    },
  },
}) : current;

export function SyncStatusProvider({ children, pollInterval = 30000 }) {
  const [status, setStatus] = useState(null);
  const [initialLoading, setInitialLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [running, setRunning] = useState(false);
  const [pairing, setPairing] = useState(false);
  const [terminalActivating, setTerminalActivating] = useState(false);
  const [terminalActivationError, setTerminalActivationError] = useState("");
  const [importingLocalCredentials, setImportingLocalCredentials] = useState(false);
  const [credentialImportError, setCredentialImportError] = useState("");
  const [contractAvailable, setContractAvailable] = useState(null);
  const [error, setError] = useState("");
  const configVersionRef = useRef(null);
  const mountedRef = useRef(true);
  const autoSyncInFlightRef = useRef(false);
  const autoSyncFailuresRef = useRef(0);
  const nextAutoSyncAtRef = useRef(0);

  const acceptStatus = useCallback((nextStatus, { forceWorkspaceRefresh = false } = {}) => {
    if (!nextStatus || !mountedRef.current) return;

    if (isDedicatedFrontOffice() && nextStatus.paired) {
      if (nextStatus.company?.code && nextStatus.company.code !== getSavedCompanyCode()) {
        saveCompanyCode(nextStatus.company.code);
      }

      saveLocalTill({
        id: nextStatus.till?.id,
        till_code: nextStatus.till?.code,
        till_name: nextStatus.till?.name,
        branch_id: nextStatus.shop?.id || nextStatus.branch?.id,
        shop: nextStatus.shop || nextStatus.branch,
        hierarchy: nextStatus.hierarchy,
        branding: nextStatus.branding,
        brand_id: nextStatus.brand?.id,
        brand_name: nextStatus.brand?.name,
        brand_logo: nextStatus.brand?.logo,
        complex_id: nextStatus.complex?.id,
        complex_name: nextStatus.complex?.name,
        company_id: nextStatus.company?.id,
        company_name: nextStatus.company?.name,
        country_id: nextStatus.country?.id,
        country_name: nextStatus.country?.name,
      });
    }

    const previousVersion = configVersionRef.current;
    const nextVersion = nextStatus.configVersion;
    configVersionRef.current = nextVersion ?? previousVersion;
    setStatus(nextStatus);

    if (
      forceWorkspaceRefresh ||
      (previousVersion !== null && nextVersion !== null && String(previousVersion) !== String(nextVersion))
    ) {
      window.dispatchEvent(new CustomEvent("salexa_workspace_changed", {
        detail: { branchId: nextStatus.branch?.id || null, source: "sync" },
      }));
    }
  }, []);

  const refresh = useCallback(async ({ silent = false } = {}) => {
    if (hasUnscopedPlatformSession()) {
      if (mountedRef.current) {
        setStatus(null);
        setContractAvailable(false);
        setError("");
        setInitialLoading(false);
        setRefreshing(false);
      }
      return null;
    }

    if (!silent) setRefreshing(true);

    try {
      const nextStatus = await getSyncStatus();
      if (!mountedRef.current) return nextStatus;
      setContractAvailable(true);
      setError("");
      acceptStatus(nextStatus);
      return nextStatus;
    } catch (requestError) {
      if (!mountedRef.current) return null;

      if (isSyncContractUnavailable(requestError)) {
        setContractAvailable(false);
        setError("");
      } else if (localCredentialRecoveryStatusFromError(requestError)) {
        // The browser may have lost its one-time local recovery values while Laravel
        // still has a valid paired node. Preserve the non-secret status shape
        // so the gate can offer the authenticated recovery-code flow.
        const recoveryStatus = localCredentialRecoveryStatusFromError(requestError);
        setContractAvailable(true);
        setError("");
        acceptStatus(recoveryStatus);
        return recoveryStatus;
      } else {
        const message = Number(requestError?.response?.status) === 429
          ? "Synchronization status is busy and will retry automatically. Local trading remains available."
          : getMessage(requestError, "The local sync service could not be reached.");
        setError(message);
        // Once this browser has verified its edge identity, a transient status,
        // cloud or rate-limit failure must not unmount the sales workspace and
        // discard an in-progress cart. Keep local trading mounted, but fail both
        // remote equality indicators closed until a fresh proof arrives.
        setStatus((current) => statusAfterTransientSyncFailure(current, message));
      }
      return null;
    } finally {
      if (mountedRef.current) {
        setInitialLoading(false);
        if (!silent) setRefreshing(false);
      }
    }
  }, [acceptStatus]);

  const runNow = useCallback(async () => {
    setRunning(true);
    setError("");
    try {
      if (isStandaloneLocalRuntime({ status })) {
        const localStatus = await refresh({ silent: true });
        return { payload: { localOnly: true }, status: localStatus };
      }

      const result = await runSyncNow();
      setContractAvailable(true);
      const pulledChanges = Number(
        result.payload?.pull?.applied || result.payload?.downloaded
          || result.payload?.pulled || result.payload?.configurations_applied || 0,
      );
      const nextStatus = await refresh({ silent: true });
      if (nextStatus && pulledChanges > 0) {
        acceptStatus(nextStatus, { forceWorkspaceRefresh: true });
      }
      return result;
    } catch (requestError) {
      const message = Number(requestError?.response?.status) === 429
        ? "Synchronization is already busy and will retry automatically."
        : getMessage(requestError, "Synchronization failed.");
      setError(message);
      // A failed run can no longer leave a stale green indicator visible.
      // Preserve the local selling identity, but fail both remote equality
      // proofs closed until a later status request verifies them again.
      setStatus((current) => statusAfterTransientSyncFailure(current, message));
      throw requestError;
    } finally {
      setRunning(false);
    }
  }, [acceptStatus, refresh, status?.deploymentMode, status?.localOnly]);

  const pairNode = useCallback(async (values) => {
    setPairing(true);
    setError("");
    setCredentialImportError("");
    try {
      const result = await pairSyncNode(values);
      setContractAvailable(true);

      const companyCode =
        result.status?.company?.code || result.payload?.company_code || values.company_code;
      if (companyCode) saveCompanyCode(companyCode);

      if (result.payload?.local_control_token) {
        saveSyncLocalControlToken(result.payload.local_control_token);
      }

      const localTillIdentity = localTillIdentityFromPairing(result);
      if (localTillIdentity.till_token || localTillIdentity.id || localTillIdentity.branch_id) {
        saveLocalTill(localTillIdentity);
      }

      acceptStatus({
        ...result.status,
        // This response came from the local edge pairing endpoint. The next
        // status refresh still verifies the backend's declared mode.
        deploymentMode: result.status?.deploymentMode || "edge",
      }, { forceWorkspaceRefresh: true });
      await refresh({ silent: true });
      return result;
    } catch (requestError) {
      setError(getMessage(requestError, "Device pairing failed."));
      throw requestError;
    } finally {
      setPairing(false);
    }
  }, [acceptStatus, refresh]);

  const importLocalCredentials = useCallback(async ({
    local_control_token,
    till_token,
  }) => {
    const localControlToken = String(local_control_token || "").trim();
    const tillToken = String(till_token || "").trim();
    setImportingLocalCredentials(true);
    setCredentialImportError("");

    try {
      const verifiedStatus = await validateLocalSyncCredentials({
        local_control_token: localControlToken,
        till_token: tillToken,
      });

      if (verifiedStatus.company?.code) {
        saveCompanyCode(verifiedStatus.company.code);
      }
      saveSyncLocalControlToken(localControlToken);
      saveLocalTill({
        id: verifiedStatus.till?.id,
        till_code: verifiedStatus.till?.code,
        till_name: verifiedStatus.till?.name,
        branch_id: verifiedStatus.branch?.id,
        till_token: tillToken,
      });

      acceptStatus(verifiedStatus, { forceWorkspaceRefresh: true });
      await refresh({ silent: true });
      return verifiedStatus;
    } catch (requestError) {
      const message = Number(requestError?.response?.status) === 401
        ? "The local POS rejected one or both recovery codes. Enter the latest values issued by the local server."
        : getMessage(requestError, "The local terminal recovery codes could not be verified.");
      setCredentialImportError(message);
      throw requestError;
    } finally {
      setImportingLocalCredentials(false);
    }
  }, [acceptStatus, refresh]);

  const activateTerminal = useCallback(async (activationCode) => {
    const code = String(activationCode || "").trim().toUpperCase();
    if (!code) {
      const validationError = new Error("Enter the one-time terminal activation code.");
      validationError.displayMessage = validationError.message;
      setTerminalActivationError(validationError.message);
      throw validationError;
    }

    setTerminalActivating(true);
    setTerminalActivationError("");
    try {
      const result = await redeemTerminalActivationCode(code);
      const terminal = result?.terminal || {};
      const credential = String(result?.terminal_credential || "").trim();
      if (!terminal?.id || !terminal?.branch_id || !credential) {
        const contractError = new Error("The local POS returned an incomplete terminal activation response.");
        contractError.displayMessage = contractError.message;
        throw contractError;
      }

      saveLocalTill({
        ...terminal,
        till_token: credential,
      });

      const verifiedStatus = await refresh({ silent: true });
      if (!verifiedStatus || verifiedStatus.tillSetupRequired) {
        const verificationError = new Error("Terminal activation was saved but could not be verified by the local POS.");
        verificationError.displayMessage = verificationError.message;
        throw verificationError;
      }

      acceptStatus(verifiedStatus, { forceWorkspaceRefresh: true });
      return { result, status: verifiedStatus };
    } catch (requestError) {
      const message = Number(requestError?.response?.status) === 429
        ? "Too many activation attempts. Wait one minute, then request or enter a fresh code."
        : getMessage(requestError, "Terminal activation failed.");
      setTerminalActivationError(message);
      throw requestError;
    } finally {
      setTerminalActivating(false);
    }
  }, [acceptStatus, refresh]);

  useEffect(() => {
    mountedRef.current = true;
    refresh({ silent: true });

    const timer = window.setInterval(() => refresh({ silent: true }), pollInterval);
    const refreshOnAttention = () => refresh({ silent: true });
    window.addEventListener("online", refreshOnAttention);
    window.addEventListener("focus", refreshOnAttention);
    window.addEventListener("salexa_auth_changed", refreshOnAttention);

    return () => {
      mountedRef.current = false;
      window.clearInterval(timer);
      window.removeEventListener("online", refreshOnAttention);
      window.removeEventListener("focus", refreshOnAttention);
      window.removeEventListener("salexa_auth_changed", refreshOnAttention);
    };
  }, [pollInterval, refresh]);

  useEffect(() => {
    if (!isDedicatedFrontOffice() || isStandaloneLocalRuntime({ status })) return undefined;

    const interval = getSyncIntervalMs();
    const canAutoSync = () => isEdgeAutoSyncEligible({
      status: {
        deploymentMode: status?.deploymentMode,
        localOnly: status?.localOnly,
        paired: status?.paired,
        pairingRequired: status?.pairingRequired,
        tillSetupRequired: status?.tillSetupRequired,
        cloudConfigured: status?.cloudConfigured,
      },
      online: typeof navigator === "undefined" ? true : navigator.onLine,
      dedicatedFrontOffice: true,
    });

    const attemptAutoSync = async ({ force = false } = {}) => {
      if (!mountedRef.current || !canAutoSync() || autoSyncInFlightRef.current) return;
      if (!force && Date.now() < nextAutoSyncAtRef.current) return;

      autoSyncInFlightRef.current = true;
      try {
        await runNow();
        autoSyncFailuresRef.current = 0;
        nextAutoSyncAtRef.current = Date.now() + interval;
      } catch {
        autoSyncFailuresRef.current += 1;
        const backoff = Math.min(
          interval * (2 ** Math.min(autoSyncFailuresRef.current, 4)),
          15 * 60 * 1000,
        );
        nextAutoSyncAtRef.current = Date.now() + backoff;
      } finally {
        autoSyncInFlightRef.current = false;
      }
    };

    const timer = window.setInterval(() => attemptAutoSync(), Math.min(interval, 30000));
    const initialTimer = window.setTimeout(() => attemptAutoSync(), 1500);
    const syncWhenOnline = () => {
      nextAutoSyncAtRef.current = 0;
      attemptAutoSync({ force: true });
    };
    // Window focus is common during payment, printing and customer-display
    // hand-off. Respect the normal cadence instead of starting another run.
    const syncWhenFocused = () => attemptAutoSync();
    window.addEventListener("online", syncWhenOnline);
    window.addEventListener("focus", syncWhenFocused);

    return () => {
      window.clearInterval(timer);
      window.clearTimeout(initialTimer);
      window.removeEventListener("online", syncWhenOnline);
      window.removeEventListener("focus", syncWhenFocused);
    };
  }, [
    runNow,
    status?.cloudConfigured,
    status?.deploymentMode,
    status?.localOnly,
    status?.paired,
    status?.pairingRequired,
    status?.tillSetupRequired,
  ]);

  const value = useMemo(() => ({
    status,
    initialLoading,
    refreshing,
    running,
    pairing,
    terminalActivating,
    terminalActivationError,
    importingLocalCredentials,
    credentialImportError,
    contractAvailable,
    error,
    refresh,
    runNow,
    pairNode,
    activateTerminal,
    importLocalCredentials,
  }), [
    status,
    initialLoading,
    refreshing,
    running,
    pairing,
    terminalActivating,
    terminalActivationError,
    importingLocalCredentials,
    credentialImportError,
    contractAvailable,
    error,
    refresh,
    runNow,
    pairNode,
    activateTerminal,
    importLocalCredentials,
  ]);

  return <SyncStatusContext.Provider value={value}>{children}</SyncStatusContext.Provider>;
}

export const useSyncStatus = () => {
  const context = useContext(SyncStatusContext);
  if (!context) throw new Error("useSyncStatus must be used inside SyncStatusProvider");
  return context;
};

export const useOptionalSyncStatus = () => useContext(SyncStatusContext);

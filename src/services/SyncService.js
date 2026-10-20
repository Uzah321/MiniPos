import api from "../utils/apiBack";
import { getPreferredWorkspace } from "../config/DeploymentConfig";
import { companyHeaders, getSyncLocalControlToken } from "../utils/apiConfig";
import { normalizeHospitalityBootstrap } from "../utils/hospitalityHierarchy";

const unwrap = (source) => source?.data?.data ?? source?.data ?? source ?? {};
const asNumber = (value) => {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : 0;
};
const asBoolean = (value) =>
  value === true || value === 1 || value === "1" || String(value || "").toLowerCase() === "true";

const normalizeDirection = (source = {}, fallback = {}) => {
  const direction = source && typeof source === "object" ? source : {};
  const explicitInSync = direction.in_sync ?? direction.inSync ?? direction.synced ?? direction.equal;
  const comparisonKnown = direction.comparison_known ?? direction.comparisonKnown;
  const proofFresh = direction.fresh;
  const directionRunning = asBoolean(direction.running);
  const comparisonAccepted = comparisonKnown === undefined
    || comparisonKnown === null
    || asBoolean(comparisonKnown);
  const freshnessAccepted = proofFresh === undefined
    || proofFresh === null
    || asBoolean(proofFresh);
  const verifiedInSync = explicitInSync !== undefined
    && explicitInSync !== null
    && asBoolean(explicitInSync)
    && comparisonAccepted
    && freshnessAccepted
    && !directionRunning;
  const pending = asNumber(
    direction.pending ?? direction.pending_count ?? direction.pendingCount
    ?? direction.pending_events ?? direction.pending_changes ?? fallback.pending,
  );
  const inferredState = directionRunning
    ? "syncing"
    : verifiedInSync
      ? "synced"
      : !comparisonAccepted || !freshnessAccepted
        ? "unverified"
        : pending > 0
          ? "pending"
          : "attention";
  const reportedState = String(direction.state || direction.status || fallback.state || "").toLowerCase();
  const state = directionRunning
    ? "syncing"
    : verifiedInSync
      ? "synced"
      : ["synced", "synchronized", "complete", "equal"].includes(reportedState)
        ? inferredState
        : reportedState || inferredState;

  return {
    state,
    // Fail closed: an absent cloud acknowledgement must never render green.
    inSync: verifiedInSync,
    pending,
    localCursor: direction.local_cursor ?? direction.localCursor ?? fallback.localCursor ?? null,
    cloudCursor:
      direction.cloud_cursor ?? direction.cloudCursor ?? direction.remote_head_cursor
      ?? direction.head_cursor ?? fallback.cloudCursor ?? null,
    lastAcknowledgedAt:
      direction.last_acknowledged_at || direction.lastAcknowledgedAt
      || direction.last_success_at || fallback.lastAcknowledgedAt || null,
    checkedAt:
      direction.checked_at || direction.checkedAt || direction.last_checked_at
      || fallback.checkedAt || null,
    message: direction.message || direction.error || fallback.message || "",
  };
};

const workspaceConfig = (overrides = {}) => ({
  salexaWorkspace: getPreferredWorkspace(),
  ...overrides,
});

const localControlHeaders = () => {
  const token = getSyncLocalControlToken();
  return token ? { "X-Sync-Local-Control": token } : {};
};

export const normalizeSyncStatus = (source) => {
  const payload = unwrap(source);
  const hospitality = normalizeHospitalityBootstrap(payload);
  const sync = payload.sync || payload.status_details || payload;
  const node = payload.node || sync.node || payload.installation || {};
  const identity = payload.identity || sync.identity || node.identity || {};
  const company = hospitality.company || identity.company || node.company || payload.company || {};
  const branch = hospitality.shop || identity.shop || identity.branch || node.shop || node.branch || payload.shop || payload.branch || {};
  const till = hospitality.till || identity.till || node.till || payload.till || {};
  const rawState = String(
    sync.status || sync.state || payload.status || payload.state || "",
  ).toLowerCase();
  const deploymentMode = String(
    sync.deployment_mode || payload.deployment_mode || node.deployment_mode || "",
  ).trim().toLowerCase().replaceAll("_", "-");
  const explicitPaired = sync.paired ?? payload.paired ?? node.paired ?? node.is_paired;
  const explicitPairingRequired =
    sync.pairing_required ?? payload.pairing_required ?? payload.setup_required;
  const paired = explicitPaired === undefined || explicitPaired === null
    ? false
    : asBoolean(explicitPaired);
  const pairingRequired = explicitPairingRequired === undefined || explicitPairingRequired === null
    ? ["unpaired", "pairing_required", "setup_required"].includes(rawState) || !paired
    : asBoolean(explicitPairingRequired);
  const replacementRequired = asBoolean(
    sync.replacement_required ?? payload.replacement_required,
  ) || rawState === "revoked";
  const pendingUploads = asNumber(
    sync.pending_uploads ?? sync.pending_events ?? sync.pending_push ?? sync.outbox_pending
      ?? payload.pending_uploads ?? payload.pending_events,
  );
  const pendingDownloads = asNumber(
    sync.pending_downloads ?? sync.pending_pull ?? sync.pull?.pending ?? sync.inbox_pending
      ?? payload.pending_downloads ?? payload.pull?.pending,
  );
  const deadLetterEvents = asNumber(
    sync.dead_letter_events ?? sync.failed_events ?? payload.dead_letter_events ?? payload.failed_events,
  );
  const cloudReachable = sync.cloud_reachable ?? sync.remote_reachable ?? payload.cloud_reachable;
  const cloudConfigured = sync.cloud_configured ?? payload.cloud_configured
    ?? Boolean(
      node.cloud_url_masked || sync.cloud_url_masked || payload.cloud_url_masked
        || sync.cloud_url || payload.cloud_url,
    );
  const explicitSellReady = sync.sell_ready ?? payload.sell_ready;
  const tillSetupRequired = asBoolean(
    sync.till_setup_required ?? payload.till_setup_required,
  );
  const configurationPending = asBoolean(
    sync.configuration_pending ?? payload.configuration_pending,
  );
  const directional = sync.directions || sync.directional_status
    || payload.directions || payload.directional_status || {
      pos_to_cloud: sync.pos_to_cloud || payload.pos_to_cloud,
      cloud_to_pos: sync.cloud_to_pos || payload.cloud_to_pos,
    };
  const posToCloud = normalizeDirection(
    directional.pos_to_cloud || directional.posToCloud,
    {
      pending: pendingUploads + deadLetterEvents,
      lastAcknowledgedAt: sync.last_push_at || payload.last_push_at || null,
      message: sync.last_error || payload.last_error || "",
    },
  );
  const cloudToPos = normalizeDirection(
    directional.cloud_to_pos || directional.cloudToPos,
    {
      pending: pendingDownloads,
      localCursor: sync.pull_cursor ?? payload.pull_cursor ?? null,
      cloudCursor: sync.cloud_cursor ?? payload.cloud_cursor ?? null,
      lastAcknowledgedAt: sync.last_pull_at || payload.last_pull_at || null,
      message: sync.last_error || payload.last_error || "",
    },
  );

  return {
    raw: payload,
    state: rawState || (paired ? "ready" : "unpaired"),
    deploymentMode,
    paired,
    pairingRequired,
    replacementRequired,
    tillSetupRequired,
    configurationPending,
    sellReady:
      explicitSellReady === undefined || explicitSellReady === null
        ? null
        : asBoolean(explicitSellReady),
    localBackendReachable: true,
    cloudConfigured: asBoolean(cloudConfigured),
    cloudReachable:
      cloudReachable === undefined || cloudReachable === null ? null : asBoolean(cloudReachable),
    localOnly: asBoolean(sync.local_only ?? payload.local_only) || rawState === "local_only",
    pendingUploads,
    pendingDownloads,
    pendingTotal: pendingUploads + pendingDownloads,
    deadLetterEvents,
    directions: {
      posToCloud,
      cloudToPos,
    },
    lastSuccessAt:
      sync.last_success_at || sync.last_synced_at || sync.last_sync_at
        || payload.last_success_at || payload.last_synced_at || payload.last_sync_at || null,
    lastAttemptAt: sync.last_attempt_at || payload.last_attempt_at || null,
    lastError: sync.last_error || payload.last_error || payload.error || "",
    configVersion:
      sync.configuration_version || sync.config_version || payload.configuration_version || payload.config_version || null,
    company: {
      id: company.id || identity.company_id || node.company_id || payload.company_id || null,
      code: company.code || company.company_code || identity.company_code || node.company_code || payload.company_code || "",
      name: company.name || company.company_name || identity.company_name || node.company_name || payload.company_name || "",
    },
    country: hospitality.country,
    brand: hospitality.brand,
    complex: hospitality.complex,
    shop: {
      ...(hospitality.shop || {}),
      id: branch.id || branch.shop_id || branch.branch_id || identity.shop_id || identity.branch_id || node.shop_id || node.branch_id || payload.shop_id || payload.branch_id || null,
      code: branch.code || branch.shop_code || branch.branch_code || identity.shop_code || identity.branch_code || node.shop_code || node.branch_code || "",
      name: branch.name || branch.shop_name || branch.branch_name || identity.shop_name || identity.branch_name || node.shop_name || node.branch_name || payload.shop_name || payload.branch_name || "",
    },
    branch: {
      id: branch.id || branch.shop_id || branch.branch_id || identity.shop_id || identity.branch_id || node.shop_id || node.branch_id || payload.shop_id || payload.branch_id || null,
      code: branch.code || branch.shop_code || branch.branch_code || identity.shop_code || identity.branch_code || node.shop_code || node.branch_code || "",
      name: branch.name || branch.shop_name || branch.branch_name || identity.shop_name || identity.branch_name || node.shop_name || node.branch_name || payload.shop_name || payload.branch_name || "",
    },
    till: {
      id: till.id || identity.till_id || node.till_id || payload.till_id || null,
      code: till.code || till.till_code || identity.till_code || node.till_code || "",
      name: till.name || till.till_name || identity.till_name || node.till_name || "",
    },
    hierarchy: hospitality.hierarchy,
    branding: hospitality.branding,
    authentication: hospitality.authentication,
    node: {
      id: node.id || node.node_id || payload.node_id || null,
      name: node.name || node.node_name || node.device_label || payload.node_name || payload.node_code || "",
      cloudUrlMasked: node.cloud_url_masked || sync.cloud_url_masked || payload.cloud_url_masked || "",
      cloudUrlLocked: asBoolean(
        node.cloud_url_locked ?? sync.cloud_url_locked ?? payload.cloud_url_locked
          ?? Boolean(node.cloud_url_masked || sync.cloud_url_masked || payload.cloud_url_masked),
      ),
    },
    configurationError:
      sync.configuration_error || payload.configuration_error || "",
  };
};

export const isSyncContractUnavailable = (error) =>
  [404, 405, 501].includes(Number(error?.response?.status || 0));

export const getSyncStatus = async () => {
  const response = await api.get(
    "/api/pos/sync/status",
    workspaceConfig({ headers: localControlHeaders() }),
  );
  return normalizeSyncStatus(response);
};

const verifiedLocalIdentity = (status) => ({
  nodeId: String(status?.node?.id || ""),
  companyId: String(status?.company?.id || ""),
  branchId: String(status?.branch?.id || ""),
  tillId: String(status?.till?.id || ""),
});

const assertVerifiedEdgeIdentity = (status) => {
  const identity = verifiedLocalIdentity(status);
  if (
    status?.deploymentMode !== "edge"
    || status?.paired !== true
    || Object.values(identity).some((value) => !value)
  ) {
    const error = new Error("The local POS did not return a complete paired terminal identity.");
    error.displayMessage = error.message;
    throw error;
  }

  return identity;
};

const probeLocalCredential = async (headers) => {
  const response = await api.get(
    "/api/pos/sync/status",
    workspaceConfig({
      salexaWorkspace: "front-office",
      salexaLocalCredentialProbe: true,
      headers,
    }),
  );

  return normalizeSyncStatus(response);
};

export const validateLocalSyncCredentials = async ({
  local_control_token,
  till_token,
}) => {
  const localControlToken = String(local_control_token || "").trim();
  const tillToken = String(till_token || "").trim();

  if (!localControlToken || !tillToken) {
    const error = new Error("Both local terminal recovery codes are required.");
    error.displayMessage = error.message;
    throw error;
  }

  const controlStatus = await probeLocalCredential({
    "X-Sync-Local-Control": localControlToken,
  });
  const tillStatus = await probeLocalCredential({
    "X-Till-Token": tillToken,
  });
  const controlIdentity = assertVerifiedEdgeIdentity(controlStatus);
  const tillIdentity = assertVerifiedEdgeIdentity(tillStatus);

  if (Object.keys(controlIdentity).some((key) => controlIdentity[key] !== tillIdentity[key])) {
    const error = new Error("The two recovery codes do not belong to the same paired terminal.");
    error.displayMessage = error.message;
    throw error;
  }

  return controlStatus;
};

export const runSyncNow = async () => {
  const response = await api.post(
    "/api/pos/sync/run",
    {},
    workspaceConfig({ headers: localControlHeaders() }),
  );
  return {
    payload: unwrap(response),
    status: normalizeSyncStatus(response),
  };
};

export const pairSyncNode = async ({ company_code, pairing_code, cloud_url, node_name = "" }) => {
  const companyCode = String(company_code || "").trim().toUpperCase();
  const nodeName = String(node_name || "").trim();
  const response = await api.post(
    "/api/pos/sync/pair",
    {
      company_code: companyCode,
      pairing_code: String(pairing_code || "").trim(),
      cloud_url: String(cloud_url || "").trim(),
      ...(nodeName ? { node_name: nodeName } : {}),
    },
    workspaceConfig({
      salexaWorkspace: "front-office",
      headers: companyHeaders({ workspace: "back-office", companyCode }),
    }),
  );

  return {
    payload: unwrap(response),
    status: normalizeSyncStatus(response),
  };
};

export const listSyncNodes = async (params = {}) => {
  const response = await api.get("/api/pos/sync/nodes", workspaceConfig({ params }));
  const payload = unwrap(response);
  return Array.isArray(payload) ? payload : payload.data || payload.nodes || [];
};

export const createSyncPairingCode = async (payload) => {
  const response = await api.post(
    "/api/pos/sync/pairing-codes",
    payload,
    workspaceConfig({ salexaWorkspace: "back-office" }),
  );
  return unwrap(response);
};

export const createTerminalActivationCode = async (nodeId, tillId) => {
  const response = await api.post(
    `/api/pos/sync/nodes/${nodeId}/terminal-activation-codes`,
    { till_id: tillId },
    workspaceConfig({ salexaWorkspace: "back-office" }),
  );
  return unwrap(response);
};

export const redeemTerminalActivationCode = async (terminalActivationCode) => {
  const response = await api.post(
    "/api/pos/sync/terminal-activations/redeem",
    { terminal_activation_code: String(terminalActivationCode || "").trim().toUpperCase() },
    workspaceConfig({ salexaWorkspace: "front-office" }),
  );
  return unwrap(response);
};

export const revokeSyncNode = async (nodeId) => {
  const response = await api.delete(
    `/api/pos/sync/nodes/${nodeId}`,
    workspaceConfig({ salexaWorkspace: "back-office" }),
  );
  return unwrap(response);
};

export const DEPLOYMENT_MODES = Object.freeze({
  FRONT_OFFICE: "front-office",
  BACK_OFFICE: "back-office",
});

const VALID_MODES = new Set([
  DEPLOYMENT_MODES.FRONT_OFFICE,
  DEPLOYMENT_MODES.BACK_OFFICE,
]);

export const BACKEND_MODES = Object.freeze({
  EDGE: "edge",
  STANDALONE: "standalone",
  CLOUD: "cloud",
});

const VALID_BACKEND_MODES = new Set(Object.values(BACKEND_MODES));

const booleanValue = (value) =>
  value === true
  || value === 1
  || value === "1"
  || String(value || "").trim().toLowerCase() === "true";

export const normalizeDeploymentMode = (value) => {
  const normalized = String(value || "")
    .trim()
    .toLowerCase()
    .replaceAll("_", "-")
    .replaceAll(" ", "-");

  return VALID_MODES.has(normalized) ? normalized : DEPLOYMENT_MODES.BACK_OFFICE;
};

const runtimeConfig = () => {
  if (typeof window === "undefined") return {};

  return {
    ...(window.__SALEXA_RUNTIME_CONFIG__ || {}),
    ...(window.__SERVENEST_RUNTIME_CONFIG__ || {}),
  };
};

export const normalizeBackendMode = (value) => {
  const normalized = String(value || "")
    .trim()
    .toLowerCase()
    .replaceAll("_", "-")
    .replaceAll(" ", "-");

  return VALID_BACKEND_MODES.has(normalized) ? normalized : "";
};

export const getDeploymentMode = () =>
  normalizeDeploymentMode(
    typeof window !== "undefined"
      ? runtimeConfig().deployment_mode ||
          runtimeConfig().deploymentMode ||
          process.env.REACT_APP_DEPLOYMENT_MODE
      : process.env.REACT_APP_DEPLOYMENT_MODE,
  );

// The workspace mode and API mode describe different boundaries. Cloud builds
// launch directly into Back Office; shop edge builds launch directly into
// Front Office. Every unsupported value fails closed to Back Office and never
// exposes both experiences from one deployment.
export const getBackendMode = () =>
  normalizeBackendMode(
    typeof window !== "undefined"
      ? runtimeConfig().backend_mode
          || runtimeConfig().backendMode
          || process.env.REACT_APP_BACKEND_MODE
      : process.env.REACT_APP_BACKEND_MODE,
  );

export const isStandaloneBackend = (mode = getBackendMode()) =>
  normalizeBackendMode(mode) === BACKEND_MODES.STANDALONE;

export const deploymentAllowsWorkspace = (mode, workspace) => {
  const deploymentMode = normalizeDeploymentMode(mode);
  return deploymentMode === workspace;
};

export const getAllowedWorkspaces = (mode = getDeploymentMode()) =>
  [DEPLOYMENT_MODES.FRONT_OFFICE, DEPLOYMENT_MODES.BACK_OFFICE].filter((workspace) =>
    deploymentAllowsWorkspace(mode, workspace),
  );

export const getPreferredWorkspace = (mode = getDeploymentMode()) => {
  const deploymentMode = normalizeDeploymentMode(mode);
  return deploymentMode === DEPLOYMENT_MODES.FRONT_OFFICE
    ? DEPLOYMENT_MODES.FRONT_OFFICE
    : DEPLOYMENT_MODES.BACK_OFFICE;
};

export const isDedicatedFrontOffice = (mode = getDeploymentMode()) =>
  normalizeDeploymentMode(mode) === DEPLOYMENT_MODES.FRONT_OFFICE;

export const isDedicatedBackOffice = (mode = getDeploymentMode()) =>
  normalizeDeploymentMode(mode) === DEPLOYMENT_MODES.BACK_OFFICE;

// Compatibility with a pre-sync backend is deliberately opt-in. A physical
// POS must otherwise fail closed when its local API cannot prove the edge
// deployment and immutable pairing state.
export const isLegacySyncContractAllowed = () => booleanValue(
  (typeof window !== "undefined"
    && runtimeConfig().allow_legacy_sync_contract)
  ?? process.env.REACT_APP_ALLOW_LEGACY_SYNC_CONTRACT
  ?? false,
);

export const getSyncIntervalMs = () => {
  const configured = Number(
    (typeof window !== "undefined" && runtimeConfig().sync_interval_ms)
      || process.env.REACT_APP_SYNC_INTERVAL_MS
      || 60000,
  );

  return Number.isFinite(configured) && configured >= 15000 ? configured : 60000;
};

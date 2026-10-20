import {
  BACKEND_MODES,
  DEPLOYMENT_MODES,
  deploymentAllowsWorkspace,
  getAllowedWorkspaces,
  getBackendMode,
  getDeploymentMode,
  isLegacySyncContractAllowed,
  isStandaloneBackend,
  getSyncIntervalMs,
  normalizeBackendMode,
  normalizeDeploymentMode,
} from "./DeploymentConfig";

describe("deployment configuration", () => {
  const originalRuntimeConfig = window.__SERVENEST_RUNTIME_CONFIG__;
  const originalLegacyRuntimeConfig = window.__SALEXA_RUNTIME_CONFIG__;
  const originalBuildMode = process.env.REACT_APP_DEPLOYMENT_MODE;
  const originalBackendMode = process.env.REACT_APP_BACKEND_MODE;
  const originalLegacyFlag = process.env.REACT_APP_ALLOW_LEGACY_SYNC_CONTRACT;

  afterEach(() => {
    window.__SERVENEST_RUNTIME_CONFIG__ = originalRuntimeConfig;
    window.__SALEXA_RUNTIME_CONFIG__ = originalLegacyRuntimeConfig;
    if (originalBuildMode === undefined) delete process.env.REACT_APP_DEPLOYMENT_MODE;
    else process.env.REACT_APP_DEPLOYMENT_MODE = originalBuildMode;
    if (originalBackendMode === undefined) delete process.env.REACT_APP_BACKEND_MODE;
    else process.env.REACT_APP_BACKEND_MODE = originalBackendMode;
    if (originalLegacyFlag === undefined) delete process.env.REACT_APP_ALLOW_LEGACY_SYNC_CONTRACT;
    else process.env.REACT_APP_ALLOW_LEGACY_SYNC_CONTRACT = originalLegacyFlag;
  });

  test("normalizes dedicated modes and safely defaults to Back Office", () => {
    expect(normalizeDeploymentMode("FRONT_OFFICE")).toBe(DEPLOYMENT_MODES.FRONT_OFFICE);
    expect(normalizeDeploymentMode("back office")).toBe(DEPLOYMENT_MODES.BACK_OFFICE);
    expect(normalizeDeploymentMode("combined")).toBe(DEPLOYMENT_MODES.BACK_OFFICE);
    expect(normalizeDeploymentMode("unknown")).toBe(DEPLOYMENT_MODES.BACK_OFFICE);
    expect(getAllowedWorkspaces("combined")).toEqual([DEPLOYMENT_MODES.BACK_OFFICE]);
  });

  test("runtime config can select one workspace without rebuilding", () => {
    window.__SERVENEST_RUNTIME_CONFIG__ = { deployment_mode: "front-office" };

    expect(getDeploymentMode()).toBe(DEPLOYMENT_MODES.FRONT_OFFICE);
    expect(getAllowedWorkspaces()).toEqual([DEPLOYMENT_MODES.FRONT_OFFICE]);
    expect(deploymentAllowsWorkspace(getDeploymentMode(), DEPLOYMENT_MODES.BACK_OFFICE)).toBe(false);
  });

  test("keeps the runtime API mode separate from the browser workspace mode", () => {
    window.__SERVENEST_RUNTIME_CONFIG__ = {
      deployment_mode: "front-office",
      backend_mode: "STANDALONE",
    };

    expect(getDeploymentMode()).toBe(DEPLOYMENT_MODES.FRONT_OFFICE);
    expect(getBackendMode()).toBe(BACKEND_MODES.STANDALONE);
    expect(isStandaloneBackend()).toBe(true);
    expect(normalizeBackendMode("unknown")).toBe("");
  });

  test("an empty runtime backend override falls through to the build profile", () => {
    process.env.REACT_APP_BACKEND_MODE = "edge";
    window.__SERVENEST_RUNTIME_CONFIG__ = { backend_mode: null };

    expect(getBackendMode()).toBe(BACKEND_MODES.EDGE);
    expect(isStandaloneBackend()).toBe(false);
  });

  test("an empty runtime override falls through to the build profile", () => {
    process.env.REACT_APP_DEPLOYMENT_MODE = "back-office";
    window.__SERVENEST_RUNTIME_CONFIG__ = { deployment_mode: null };

    expect(getDeploymentMode()).toBe(DEPLOYMENT_MODES.BACK_OFFICE);
  });

  test("rejects unsafe heartbeat values", () => {
    window.__SERVENEST_RUNTIME_CONFIG__ = { sync_interval_ms: 1000 };
    expect(getSyncIntervalMs()).toBe(60000);

    window.__SERVENEST_RUNTIME_CONFIG__ = { sync_interval_ms: 45000 };
    expect(getSyncIntervalMs()).toBe(45000);
  });

  test("legacy sync compatibility is default-off and requires an explicit override", () => {
    delete process.env.REACT_APP_ALLOW_LEGACY_SYNC_CONTRACT;
    window.__SERVENEST_RUNTIME_CONFIG__ = {};
    expect(isLegacySyncContractAllowed()).toBe(false);

    window.__SERVENEST_RUNTIME_CONFIG__ = { allow_legacy_sync_contract: true };
    expect(isLegacySyncContractAllowed()).toBe(true);
  });

  test("uses the former runtime global only as a compatibility fallback", () => {
    window.__SALEXA_RUNTIME_CONFIG__ = { deployment_mode: "front-office" };
    window.__SERVENEST_RUNTIME_CONFIG__ = {};
    expect(getDeploymentMode()).toBe(DEPLOYMENT_MODES.FRONT_OFFICE);

    window.__SERVENEST_RUNTIME_CONFIG__ = { deployment_mode: "back-office" };
    expect(getDeploymentMode()).toBe(DEPLOYMENT_MODES.BACK_OFFICE);
  });
});

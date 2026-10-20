/*
 * Installation-specific ServeNest settings.
 *
 * This file is intentionally outside the compiled bundle. Set deployment_mode
 * to "front-office" on the shop edge or "back-office" on the cloud host.
 * Set backend_mode to "edge", "standalone" or "cloud" when the browser is a
 * separated Front Office or Back Office installation.
 * Keep credentials and synchronization secrets out of this browser file.
 */
window.__SERVENEST_RUNTIME_CONFIG__ = Object.assign(
  {
    // Leave null to use the REACT_APP_* build setting. Change this value on
    // an installation when the same compiled bundle needs a different mode.
    deployment_mode: null,
    // This is deliberately separate from deployment_mode: it describes the
    // Laravel API/database role rather than the workspace exposed by this UI.
    backend_mode: null,
    // When this browser is served from another terminal, point it at the one
    // shop edge API/database, for example http://192.168.1.10:8083.
    // Leave null when the SPA and API share the same host.
    api_base_url: null,
    sync_interval_ms: null,
    // Emergency compatibility only. Keep false for every distributed POS.
    allow_legacy_sync_contract: false,
  },
  // Old installations may still inject the former global. Read it only as a
  // fallback; the ServeNest object below is the canonical runtime identity.
  window.__SALEXA_RUNTIME_CONFIG__ || {},
  window.__SERVENEST_RUNTIME_CONFIG__ || {},
);

/* ServeNest Front Office settings written by the installer. Never put credentials in this file. */
window.__SERVENEST_RUNTIME_CONFIG__ = Object.assign(
  {
    deployment_mode: "front-office",
    backend_mode: "edge",
    api_base_url: window.location.origin,
    sync_interval_ms: 30000,
    allow_legacy_sync_contract: false,
  },
  window.__SERVENEST_RUNTIME_CONFIG__ || {},
);

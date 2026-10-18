/* ServeNest Back Office settings written by the installer. Never put credentials in this file. */
window.__SERVENEST_RUNTIME_CONFIG__ = Object.assign(
  {
    deployment_mode: "back-office",
    backend_mode: "cloud",
    api_base_url: window.location.origin,
    sync_interval_ms: 60000,
    allow_legacy_sync_contract: false,
  },
  window.__SERVENEST_RUNTIME_CONFIG__ || {},
);

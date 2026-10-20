import { API_BASE_URL } from "./apiConfig";

const browserOrigin = () => (
  typeof window !== "undefined" && window.location?.origin
    ? window.location.origin
    : "http://localhost"
);

const withoutApiSuffix = (pathname = "") => {
  const normalized = `/${String(pathname || "").replace(/^\/+|\/+$/g, "")}`;
  if (normalized === "/") return "";
  return normalized.replace(/\/api$/i, "");
};

/**
 * Resolve a server-owned asset path against the configured API installation.
 * React and Laravel run on separate origins during local/cloud simulation, so
 * `/assets/...` must not silently resolve against the React development port.
 */
export const publicAssetUrl = (value, apiBaseUrl = API_BASE_URL) => {
  const source = String(value || "").trim();
  if (!source) return "";
  if (/^(?:https?:|data:|blob:)/i.test(source)) return source;
  if (source.startsWith("/") && !/^\/(?:assets|storage|api\/assets)(?:\/|$)/i.test(source)) {
    return source;
  }

  const path = `/${source.replace(/^\/+/, "")}`;
  if (!apiBaseUrl) return path;

  try {
    const apiUrl = new URL(apiBaseUrl, browserOrigin());
    const installationPath = withoutApiSuffix(apiUrl.pathname);
    return new URL(`${installationPath}${path}`, apiUrl.origin).toString();
  } catch (_) {
    return path;
  }
};

export default publicAssetUrl;

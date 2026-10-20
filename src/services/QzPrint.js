// src/services/QzService.js
import qz from "qz-tray";
import api from "../utils/apiBack";

let _connectPromise = null;
let _securityInitDone = false;

/* ===============================
   CERTIFICATE FROM LARAVEL
   MUST return raw PEM string
================================= */
qz.security.setCertificatePromise(async () => {
  const res = await api.get("/api/qz/cert", {
    headers: { "X-School-Domain": window.location.hostname },
    responseType: "text",
    transformResponse: [(d) => d], // stop axios parsing
  });

  const pem = res.data;

  if (typeof pem !== "string" || !pem.includes("BEGIN CERTIFICATE")) {
    throw new Error("Cert endpoint did not return PEM text");
  }

  return pem;
});

/* ===============================
   SIGNATURE FROM LARAVEL
   MUST return base64 signature string
================================= */
qz.security.setSignaturePromise(async (toSign) => {
  const res = await api.post(
    "/api/qz/sign",
    { request: toSign },
    {
      headers: { "X-School-Domain": window.location.hostname },
      responseType: "json",
    }
  );

  const sig = res?.data?.signature;

  if (typeof sig !== "string" || sig.length < 20) {
    throw new Error("Sign endpoint did not return a valid base64 signature");
  }

  return sig;
});

/**
 * Some qz-tray.js builds call versionCompare() which expects
 * qz.websocket.connection.semver to be an array like [2,2,5].
 * If semver is missing, it crashes reading semver[0].
 *
 * This forces semver from the returned version string.
 */
function setSemverFromVersionString(versionString) {
  const v = String(versionString || "").trim();
  if (!v) return false;

  // "2.2.5" or "2.2.5-SNAPSHOT" -> [2,2,5]
  const parts = v
    .split(/[^\d]+/)
    .filter(Boolean)
    .slice(0, 3)
    .map((n) => parseInt(n, 10));

  if (parts.length < 2 || parts.some((n) => !Number.isFinite(n))) return false;

  if (qz?.websocket?.connection) {
    qz.websocket.connection.semver = parts;
    return true;
  }
  return false;
}

/**
 * Workaround for qz-tray.js `versionCompare` crash.
 * - Do NOT call setSignatureAlgorithm at module-load.
 * - Set algorithm once, after handshake.
 */
function ensureQzSecurityInitialized() {
  if (_securityInitDone) return;
  _securityInitDone = true;

  const ALG = "SHA512";

  // If semver isn't ready, set internal field directly.
  const semver = qz?.websocket?.connection?.semver;
  if (!semver) {
    qz.security.signAlgorithm = ALG;
  } else {
    qz.security.setSignatureAlgorithm(ALG);
  }
}

/* ===============================
   CONNECT
================================= */
export async function qzConnect() {
  if (qz.websocket.isActive()) {
    // Already active, still ensure security is ready
    ensureQzSecurityInitialized();
    return true;
  }

  if (_connectPromise) return _connectPromise;

  _connectPromise = (async () => {
    try {
      // simplest first
      try {
        await qz.websocket.connect();
      } catch {
        // fallbacks
        try {
          await qz.websocket.connect({ host: "localhost", port: 8181, secure: true });
        } catch {
          await qz.websocket.connect({ host: "localhost", port: 8182, secure: false });
        }
      }

      // Force handshake
      const v = await qz.api.getVersion().catch(() => null);
      if (!v) throw new Error("QZ handshake incomplete (no version returned)");

      // ✅ prevent semver[0] crash inside versionCompare
      setSemverFromVersionString(v);

      // ✅ init signature algorithm safely AFTER handshake
      ensureQzSecurityInitialized();

      return true;
    } finally {
      _connectPromise = null;
    }
  })();

  return _connectPromise;
}

/* ===============================
   PRINTERS
================================= */
export async function qzGetPrinters() {
  await qzConnect();

  let res;
  try {
    // your installed qz-tray.js: findAll() may not exist, find() does
    res = await qz.printers.find();
  } catch (e) {
    console.error("qz.printers.find failed:", e);
    return [];
  }

  if (Array.isArray(res)) return res.filter(Boolean).map(String);
  if (typeof res === "string" && res.trim()) return [res.trim()];
  return [];
}

export async function qzGetDefaultPrinter() {
  await qzConnect();
  const def = await qz.printers.getDefault().catch(() => "");
  return typeof def === "string" ? def : "";
}

function pickFirstPrinter(list) {
  if (!Array.isArray(list) || list.length === 0) return "";
  const first = list[0];
  return typeof first === "string" ? first.trim() : String(first).trim();
}

/* ===============================
   PRINT PDF BASE64
================================= */
function cleanPdfBase64(pdfBase64) {
  if (typeof pdfBase64 !== "string") return "";
  const idx = pdfBase64.indexOf("base64,");
  return (idx >= 0 ? pdfBase64.slice(idx + 7) : pdfBase64).replace(/\s+/g, "");
}

export async function qzPrintPdfBase64({ printerName = null, pdfBase64, jobName = "Receipt" }) {
  await qzConnect();

  // Guard handshake
  const version = await qz.api.getVersion().catch(() => null);
  if (!version) throw new Error("QZ handshake incomplete (no version)");

  // ✅ ensure semver exists even if the lib didn't populate it
  setSemverFromVersionString(version);

  // Ensure security init again (no-op if already done)
  ensureQzSecurityInitialized();

  const clean = cleanPdfBase64(pdfBase64);
  if (!clean || clean.length < 50) throw new Error("Invalid PDF base64");

  let printer = typeof printerName === "string" ? printerName.trim() : "";

  if (!printer) {
    const def = await qzGetDefaultPrinter().catch(() => "");
    if (def) printer = def;
  }

  if (!printer) {
    const list = await qzGetPrinters().catch(() => []);
    printer = pickFirstPrinter(list);
  }

  if (!printer) throw new Error("No printer found (set default printer or select one).");

  const config = qz.configs.create(printer, {
    jobName,
    scaleContent: true,
    rasterize: true,
  });

  // ✅ Correct QZ PDF printing payload: Pixel printing of PDF, base64 flavor
  return qz.print(config, [
    {
      type: "pixel",
      format: "pdf",
      flavor: "base64",
      data: clean,
    },
  ]);
}
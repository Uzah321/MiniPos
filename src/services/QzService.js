// // src/services/QzService.js
// import qz from "qz-tray";
// import api from "../utils/apiBack";

// let _connectPromise = null;
// let _securityInitDone = false;

// /* ===============================
//    CERTIFICATE FROM LARAVEL
//    MUST return raw PEM string
// ================================= */
// qz.security.setCertificatePromise(async () => {
//   const res = await api.get("/api/qz/cert", {
//     headers: { "X-School-Domain": window.location.hostname },
//     responseType: "text",
//     transformResponse: [(d) => d], // stop axios parsing
//   });

//   const pem = res.data;

//   if (typeof pem !== "string" || !pem.includes("BEGIN CERTIFICATE")) {
//     throw new Error("Cert endpoint did not return PEM text");
//   }

//   return pem;
// });

// /* ===============================
//    SIGNATURE FROM LARAVEL
//    MUST return base64 signature string
// ================================= */
// qz.security.setSignaturePromise(async (toSign) => {
//   const res = await api.post(
//     "/api/qz/sign",
//     { request: toSign },
//     {
//       headers: { "X-School-Domain": window.location.hostname },
//       responseType: "json",
//     }
//   );

//   const sig = res?.data?.signature;

//   if (typeof sig !== "string" || sig.length < 20) {
//     throw new Error("Sign endpoint did not return a valid base64 signature");
//   }

//   return sig;
// });

// /**
//  * Some qz-tray.js builds call versionCompare() which expects
//  * qz.websocket.connection.semver to be an array like [2,2,5].
//  * If semver is missing, it crashes reading semver[0].
//  *
//  * This forces semver from the returned version string.
//  */
// function setSemverFromVersionString(versionString) {
//   const v = String(versionString || "").trim();
//   if (!v) return false;

//   // "2.2.5" or "2.2.5-SNAPSHOT" -> [2,2,5]
//   const parts = v
//     .split(/[^\d]+/)
//     .filter(Boolean)
//     .slice(0, 3)
//     .map((n) => parseInt(n, 10));

//   if (parts.length < 2 || parts.some((n) => !Number.isFinite(n))) return false;

//   if (qz?.websocket?.connection) {
//     qz.websocket.connection.semver = parts;
//     return true;
//   }
//   return false;
// }

// /**
//  * Workaround for qz-tray.js `versionCompare` crash.
//  * - Do NOT call setSignatureAlgorithm at module-load.
//  * - Set algorithm once, after handshake.
//  */
// function ensureQzSecurityInitialized() {
//   if (_securityInitDone) return;
//   _securityInitDone = true;

//   const ALG = "SHA512";

//   // If semver isn't ready, set internal field directly.
//   const semver = qz?.websocket?.connection?.semver;
//   if (!semver) {
//     qz.security.signAlgorithm = ALG;
//   } else {
//     qz.security.setSignatureAlgorithm(ALG);
//   }
// }

// /* ===============================
//    CONNECT
// ================================= */
// export async function qzConnect() {
//   if (qz.websocket.isActive()) {
//     // Already active, still ensure security is ready
//     ensureQzSecurityInitialized();
//     return true;
//   }

//   if (_connectPromise) return _connectPromise;

//   _connectPromise = (async () => {
//     try {
//       // simplest first
//       try {
//         await qz.websocket.connect();
//       } catch {
//         // fallbacks
//         try {
//           await qz.websocket.connect({ host: "localhost", port: 8181, secure: true });
//         } catch {
//           await qz.websocket.connect({ host: "localhost", port: 8182, secure: false });
//         }
//       }

//       // Force handshake
//       const v = await qz.api.getVersion().catch(() => null);
//       if (!v) throw new Error("QZ handshake incomplete (no version returned)");

//       // ✅ prevent semver[0] crash inside versionCompare
//       setSemverFromVersionString(v);

//       // ✅ init signature algorithm safely AFTER handshake
//       ensureQzSecurityInitialized();

//       return true;
//     } finally {
//       _connectPromise = null;
//     }
//   })();

//   return _connectPromise;
// }

// /* ===============================
//    PRINTERS
// ================================= */
// export async function qzGetPrinters() {
//   await qzConnect();

//   let res;
//   try {
//     // your installed qz-tray.js: findAll() may not exist, find() does
//     res = await qz.printers.find();
//   } catch (e) {
//     console.error("qz.printers.find failed:", e);
//     return [];
//   }

//   if (Array.isArray(res)) return res.filter(Boolean).map(String);
//   if (typeof res === "string" && res.trim()) return [res.trim()];
//   return [];
// }

// export async function qzGetDefaultPrinter() {
//   await qzConnect();
//   const def = await qz.printers.getDefault().catch(() => "");
//   return typeof def === "string" ? def : "";
// }

// function pickFirstPrinter(list) {
//   if (!Array.isArray(list) || list.length === 0) return "";
//   const first = list[0];
//   return typeof first === "string" ? first.trim() : String(first).trim();
// }

// /* ===============================
//    PRINT PDF BASE64
// ================================= */



// function cleanImageBase64(imageBase64) {
//   if (!imageBase64) return "";

//   let clean = String(imageBase64).trim();

//   clean = clean.replace(/^data:image\/png;base64,/i, "");
//   clean = clean.replace(/^data:image\/jpg;base64,/i, "");
//   clean = clean.replace(/^data:image\/jpeg;base64,/i, "");
//   clean = clean.replace(/^base64,/i, "");
//   clean = clean.replace(/\s+/g, "");

//   return clean;
// }


// async function resolvePrinterName(printerName) {
//   let printer = typeof printerName === "string" ? printerName.trim() : "";

//   if (!printer) {
//     const defaultPrinter = await qzGetDefaultPrinter().catch(() => "");

//     if (defaultPrinter) {
//       printer = defaultPrinter;
//     }
//   }

//   if (!printer) {
//     const printerList = await qzGetPrinters().catch(() => []);
//     printer = pickFirstPrinter(printerList);
//   }

//   if (!printer) {
//     throw new Error("No printer found. Please install QZ Tray and connect a printer.");
//   }

//   return printer;
// }

// export async function qzPrintImageBase64({
//   printerName = null,
//   imageBase64,
//   jobName = "Image",
// }) {
//   await qzConnect();

//   const version = await qz.api.getVersion().catch(() => null);

//   if (!version) {
//     throw new Error("QZ handshake incomplete.");
//   }

//   setSemverFromVersionString(version);
//   ensureQzSecurityInitialized();

//   const clean = cleanImageBase64(imageBase64);

//   if (!clean || clean.length < 50) {
//     throw new Error("Invalid image base64.");
//   }

//   const printer = await resolvePrinterName(printerName);

//   const config = qz.configs.create(printer, {
//     jobName,
//     scaleContent: true,
//     rasterize: true,
//   });

//   return qz.print(config, [
//     {
//       type: "pixel",
//       format: "image",
//       flavor: "base64",
//       data: clean,
//       options: {
//         language: "PNG",
//       },
//     },
//   ]);
// }


// export async function qzPrintPdfUrl({
//   printerName = null,
//   pdfUrl,
//   jobName = "Receipt",
// }) {
//   if (!pdfUrl) throw new Error("PDF URL is required");

//   await qzConnect();

//   const version = await qz.api.getVersion().catch(() => null);
//   if (!version) throw new Error("QZ handshake incomplete");

//   setSemverFromVersionString(version);
//   ensureQzSecurityInitialized();

//   let printer = typeof printerName === "string" ? printerName.trim() : "";

//   if (!printer) {
//     const def = await qzGetDefaultPrinter().catch(() => "");
//     if (def) printer = def;
//   }

//   if (!printer) {
//     const list = await qzGetPrinters().catch(() => []);
//     printer = pickFirstPrinter(list);
//   }

//   if (!printer) throw new Error("No printer found.");

//   const config = qz.configs.create(printer, {
//     jobName,
//     scaleContent: true,
//     rasterize: true,
//   });

//   return qz.print(config, [
//     {
//       type: "pixel",
//       format: "pdf",
//       flavor: "file",   // ✅ file = URL
//       data: pdfUrl,
//     },
//   ]);
// }

// src/services/QzService.js
// src/services/QzService.js
import qz from "qz-tray";
import api from "../utils/apiBack";
import { getLocalTillIdentity, isFrontOfficeWorkspace } from "../utils/apiConfig";

let _connectPromise = null;
let _securityInitDone = false;

const qzRequestIdentity = () => {
  if (isFrontOfficeWorkspace()) {
    const till = getLocalTillIdentity();
    if (!till.till_token || !till.till_id) {
      throw new Error("Activate this terminal before connecting to QZ Tray.");
    }

    // apiBack attaches the stored cryptographic till token. Do not assert a
    // branch or till id in the request; the local API resolves both from it.
    return {
      frontOffice: true,
      request: { salexaWorkspace: "front-office" },
      branchId: "",
      tillId: "",
    };
  }

  // Keep the established Back Office printer-management workflow available.
  // Front Office never enters this branch and therefore never trusts these
  // legacy management filter values as terminal identity.
  const branchId = localStorage.getItem("branch_id")
    || localStorage.getItem("branchId")
    || "";
  const tillId = localStorage.getItem("till_id")
    || localStorage.getItem("tillId")
    || localStorage.getItem("pos_till_id")
    || "";

  return {
    frontOffice: false,
    request: { salexaWorkspace: "back-office" },
    branchId: String(branchId).trim(),
    tillId: String(tillId).trim(),
  };
};

/* ===============================
   CERTIFICATE FROM LARAVEL
   MUST return raw PEM string
================================= */
qz.security.setCertificatePromise(async () => {
  const identity = qzRequestIdentity();
  const res = await api.get("/api/qz/cert", {
    ...identity.request,
    params: identity.frontOffice ? undefined : {
      branch_id: identity.branchId || undefined,
      till_id: identity.tillId || undefined,
    },
    headers: identity.frontOffice ? undefined : {
      "X-Till-Id": identity.tillId || "",
    },
    responseType: "text",
    transformResponse: [(d) => d],
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
  const identity = qzRequestIdentity();
  const res = await api.post(
    "/api/qz/sign",
    {
      request: toSign,
      ...(identity.frontOffice ? {} : {
        branch_id: identity.branchId || undefined,
        till_id: identity.tillId || undefined,
      }),
    },
    {
      ...identity.request,
      headers: identity.frontOffice ? undefined : {
        "X-Till-Id": identity.tillId || "",
      },
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
 */
function setSemverFromVersionString(versionString) {
  const v = String(versionString || "").trim();

  if (!v) return false;

  const parts = v
    .split(/[^\d]+/)
    .filter(Boolean)
    .slice(0, 3)
    .map((n) => parseInt(n, 10));

  if (parts.length < 2 || parts.some((n) => !Number.isFinite(n))) {
    return false;
  }

  if (qz?.websocket?.connection) {
    qz.websocket.connection.semver = parts;
    return true;
  }

  return false;
}

/**
 * Workaround for qz-tray.js versionCompare crash.
 * Backend is signing with OPENSSL_ALGO_SHA1,
 * so frontend must also use SHA1.
 */
function ensureQzSecurityInitialized() {
  if (_securityInitDone) return;

  _securityInitDone = true;

  const ALG = "SHA1";

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
    ensureQzSecurityInitialized();
    return true;
  }

  if (_connectPromise) return _connectPromise;

  _connectPromise = (async () => {
    try {
      try {
        await qz.websocket.connect();
      } catch {
        try {
          await qz.websocket.connect({
            host: "localhost",
            port: 8181,
            secure: true,
          });
        } catch {
          await qz.websocket.connect({
            host: "localhost",
            port: 8182,
            secure: false,
          });
        }
      }

      const v = await qz.api.getVersion().catch(() => null);

      if (!v) {
        throw new Error("QZ handshake incomplete (no version returned)");
      }

      setSemverFromVersionString(v);
      ensureQzSecurityInitialized();

      return true;
    } finally {
      _connectPromise = null;
    }
  })();

  return _connectPromise;
}

export async function qzDisconnect() {
  _connectPromise = null;
  _securityInitDone = false;

  if (!qz.websocket.isActive()) return true;
  await qz.websocket.disconnect();
  return true;
}

/* ===============================
   PRINTERS
================================= */
export async function qzGetPrinters() {
  await qzConnect();

  let res;

  try {
    res = await qz.printers.find();
  } catch (e) {
    console.error("qz.printers.find failed:", e);
    return [];
  }

  if (Array.isArray(res)) {
    return res.filter(Boolean).map(String);
  }

  if (typeof res === "string" && res.trim()) {
    return [res.trim()];
  }

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
   BASE64 IMAGE CLEANER
================================= */
function cleanImageBase64(imageBase64) {
  if (!imageBase64) return "";

  let clean = String(imageBase64).trim();

  clean = clean.replace(/^data:image\/png;base64,/i, "");
  clean = clean.replace(/^data:image\/jpg;base64,/i, "");
  clean = clean.replace(/^data:image\/jpeg;base64,/i, "");
  clean = clean.replace(/^base64,/i, "");
  clean = clean.replace(/\s+/g, "");

  return clean;
}

/* ===============================
   RESOLVE PRINTER
================================= */
async function resolvePrinterName(printerName) {
  let printer = typeof printerName === "string" ? printerName.trim() : "";

  if (!printer) {
    const defaultPrinter = await qzGetDefaultPrinter().catch(() => "");

    if (defaultPrinter) {
      printer = defaultPrinter;
    }
  }

  if (!printer) {
    const printerList = await qzGetPrinters().catch(() => []);
    printer = pickFirstPrinter(printerList);
  }

  if (!printer) {
    throw new Error("No printer found. Please install QZ Tray and connect a printer.");
  }

  return printer;
}

/* ===============================
   PRINT IMAGE BASE64
================================= */
export async function qzPrintImageBase64({
  printerName = null,
  imageBase64,
  jobName = "Image",
}) {
  await qzConnect();

  const version = await qz.api.getVersion().catch(() => null);

  if (!version) {
    throw new Error("QZ handshake incomplete.");
  }

  setSemverFromVersionString(version);
  ensureQzSecurityInitialized();

  const clean = cleanImageBase64(imageBase64);

  if (!clean || clean.length < 50) {
    throw new Error("Invalid image base64.");
  }

  const printer = await resolvePrinterName(printerName);

  const config = qz.configs.create(printer, {
    jobName,
    scaleContent: true,
    rasterize: true,
  });

  return qz.print(config, [
    {
      type: "pixel",
      format: "image",
      flavor: "base64",
      data: clean,
      options: {
        language: "PNG",
      },
    },
  ]);
}

/* ===============================
   PRINT PDF URL
================================= */
export async function qzPrintPdfUrl({
  printerName = null,
  pdfUrl,
  jobName = "Receipt",
}) {
  if (!pdfUrl) {
    throw new Error("PDF URL is required");
  }

  await qzConnect();

  const version = await qz.api.getVersion().catch(() => null);

  if (!version) {
    throw new Error("QZ handshake incomplete");
  }

  setSemverFromVersionString(version);
  ensureQzSecurityInitialized();

  const printer = await resolvePrinterName(printerName);

  const config = qz.configs.create(printer, {
    jobName,
    scaleContent: true,
    rasterize: true,
  });

  return qz.print(config, [
    {
      type: "pixel",
      format: "pdf",
      flavor: "file",
      data: pdfUrl,
    },
  ]);
}


/* ===============================
   PRINT HTML WITH QZ
   Used by kitchen order tickets.
================================= */
export async function qzPrintHtml({
  printerName = null,
  html,
  jobName = "Kitchen Order",
}) {
  if (!html) {
    throw new Error("HTML content is required");
  }

  await qzConnect();

  const version = await qz.api.getVersion().catch(() => null);

  if (!version) {
    throw new Error("QZ handshake incomplete");
  }

  setSemverFromVersionString(version);
  ensureQzSecurityInitialized();

  const printer = await resolvePrinterName(printerName);

  const config = qz.configs.create(printer, {
    jobName,
    scaleContent: true,
    rasterize: true,
  });

  return qz.print(config, [
    {
      type: "pixel",
      format: "html",
      flavor: "plain",
      data: html,
    },
  ]);
}

/* ===============================
   PRINT RAW TEXT WITH QZ
   Fallback for kitchen printers that prefer plain ESC/POS text.
================================= */
export async function qzPrintRawText({
  printerName = null,
  text,
  jobName = "Kitchen Order",
}) {
  if (!text) {
    throw new Error("Raw text is required");
  }

  await qzConnect();

  const version = await qz.api.getVersion().catch(() => null);

  if (!version) {
    throw new Error("QZ handshake incomplete");
  }

  setSemverFromVersionString(version);
  ensureQzSecurityInitialized();

  const printer = await resolvePrinterName(printerName);

  const config = qz.configs.create(printer, {
    jobName,
  });

  return qz.print(config, [
    {
      type: "raw",
      format: "command",
      flavor: "plain",
      data: `${text}\n\n\n\x1D\x56\x00`,
    },
  ]);
}

/* ===============================
   OPEN CASH DRAWER
================================= */
export async function qzOpenCashDrawer({
  printerName = null,
  jobName = "Open Cash Drawer",
} = {}) {
  await qzConnect();

  const version = await qz.api.getVersion().catch(() => null);

  if (!version) {
    throw new Error("QZ handshake incomplete");
  }

  setSemverFromVersionString(version);
  ensureQzSecurityInitialized();

  const printer = await resolvePrinterName(printerName);

  const config = qz.configs.create(printer, {
    jobName,
  });

  return qz.print(config, [
    {
      type: "raw",
      format: "command",
      flavor: "plain",
      data: "\x1B\x70\x00\x19\xFA",
    },
  ]);
}

// Add these two exports to your existing src/services/QzService.js if they are missing.

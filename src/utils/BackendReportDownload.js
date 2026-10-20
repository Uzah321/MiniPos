const filenameFromHeaders = (headers = {}, fallbackName) => {
  const disposition = headers?.["content-disposition"] || headers?.["Content-Disposition"] || "";
  const utf8Match = disposition.match(/filename\*=UTF-8''([^;]+)/i);
  if (utf8Match?.[1]) return decodeURIComponent(utf8Match[1].replace(/["']/g, ""));

  const normalMatch = disposition.match(/filename="?([^";]+)"?/i);
  return normalMatch?.[1] ? normalMatch[1].replace(/["']/g, "") : fallbackName;
};

const ensureExtension = (filename, extension) => {
  const cleanName = String(filename || "").trim();
  const cleanExtension = String(extension || "").replace(/^\./, "");
  const fallback = cleanExtension ? `report.${cleanExtension}` : "report";

  if (!cleanName) return fallback;
  if (!cleanExtension || cleanName.toLowerCase().endsWith(`.${cleanExtension.toLowerCase()}`)) return cleanName;
  return `${cleanName}.${cleanExtension}`;
};

export const normalizeBackendReportParams = (params = {}) => {
  if (!params || typeof params !== "object" || Array.isArray(params)) return {};

  return Object.fromEntries(
    Object.entries(params).filter(([, value]) => value !== undefined && value !== null && value !== "")
  );
};

const responseContentType = (response) => String(
  response?.headers?.["content-type"] || response?.data?.type || ""
).toLowerCase();

const parseBackendError = async (error) => {
  const response = error?.response;
  const payload = response?.data;

  if (!(payload instanceof Blob)) return error;

  const contentType = responseContentType(response);
  if (!contentType.includes("json") && !contentType.includes("text")) return error;

  try {
    const text = await payload.text();
    const data = contentType.includes("json") ? JSON.parse(text) : { message: text };
    const message = data?.message || data?.error;

    if (message) error.message = message;
    error.response = { ...response, data };
  } catch (_) {
    // Keep the original network error when the response is not valid text/JSON.
  }

  return error;
};

const saveBackendBlob = (blobData, filename, mimeType, delivery = "download", previewWindow = null) => {
  const blob = blobData instanceof Blob ? blobData : new Blob([blobData], { type: mimeType });
  const url = window.URL.createObjectURL(blob);

  if (delivery === "open") {
    if (previewWindow && !previewWindow.closed) {
      previewWindow.location.href = url;
      window.setTimeout(() => window.URL.revokeObjectURL(url), 60000);
      return;
    }
  }

  const link = document.createElement("a");

  link.href = url;
  link.download = filename;
  link.setAttribute("download", filename);
  link.style.display = "none";
  document.body.appendChild(link);
  link.click();

  window.setTimeout(() => {
    link.remove();
    window.URL.revokeObjectURL(url);
  }, 200);
};

export const downloadBackendReport = async ({
  client,
  url,
  params = {},
  headers = {},
  fallbackName = "report",
  extension = "",
  mimeType = "application/octet-stream",
  delivery = "download",
}) => {
  const previewWindow = delivery === "open" ? window.open("", "_blank") : null;
  if (previewWindow) previewWindow.opener = null;

  try {
    const response = await client.get(url, {
      params: normalizeBackendReportParams(params),
      headers,
      responseType: "blob",
    });

    if (responseContentType(response).includes("json")) {
      const data = JSON.parse(await response.data.text());
      const downloadError = new Error(data?.message || data?.error || "The backend did not return a report file.");
      downloadError.response = { ...response, data };
      throw downloadError;
    }

    const filename = ensureExtension(filenameFromHeaders(response.headers, fallbackName), extension);
    saveBackendBlob(response.data, filename, mimeType, delivery, previewWindow);
    return response;
  } catch (error) {
    if (previewWindow && !previewWindow.closed) previewWindow.close();
    throw await parseBackendError(error);
  }
};

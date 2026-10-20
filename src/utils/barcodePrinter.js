// src/utils/barcodePrinter.js

import { qzPrintPdfUrl } from "../services/QzService";

export const getBarcodePrinterFromBackendResponse = (response) => {
  const payload = response?.data || response || {};
  return payload?.printer || payload?.print?.printer || null;
};

export const getBarcodePrintUrlFromBackendResponse = (response) => {
  const payload = response?.data || response || {};
  return (
    payload?.barcode_url ||
    payload?.label_url ||
    payload?.pdf_url ||
    payload?.print?.pdf_url ||
    payload?.print?.url ||
    ""
  );
};

export const printBarcodePreparedResponse = async (response) => {
  const payload = response?.data || response || {};
  const printer = getBarcodePrinterFromBackendResponse(payload);
  const pdfUrl = getBarcodePrintUrlFromBackendResponse(payload);

  if (!pdfUrl) {
    throw new Error("Barcode PDF URL was not returned by the server.");
  }

  if (!printer?.printer_name && !printer?.printerName && !printer?.name) {
    window.open(pdfUrl, "_blank", "noopener,noreferrer");
    throw new Error("No active barcode printer was returned. The barcode PDF was opened instead.");
  }

  await qzPrintPdfUrl({
    printerName: printer.printer_name || printer.printerName || printer.name,
    pdfUrl,
    jobName: `Barcode Labels ${new Date().toISOString().slice(0, 19)}`,
  });

  return { printer, pdfUrl };
};

export const previewPreparedBarcodePdf = (response) => {
  const pdfUrl = getBarcodePrintUrlFromBackendResponse(response);

  if (!pdfUrl) {
    throw new Error("Barcode PDF URL was not returned by the server.");
  }

  return pdfUrl;
};

// Backwards compatibility for old imports.
export const getBarcodePrinterConfig = async () => null;
export const buildBarcodePrintHtml = () => "";
export const printBarcodeLabels = async () => {
  throw new Error("Use backend barcode PDF flow instead.");
};

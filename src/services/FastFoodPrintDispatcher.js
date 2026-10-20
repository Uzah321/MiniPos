// src/services/FastFoodPrintDispatcher.js

import {
  qzPrintPdfUrl,
  qzPrintHtml,
  qzPrintRawText,
} from "./QzService";
import { acknowledgeKitchenPrintJob } from "./KitchenPrintJobService";

const getSaleResponsePayload = (saleResponse) =>
  saleResponse?.data?.data || saleResponse?.data || saleResponse || {};

const getKitchenPrint = (data) =>
  data?.kitchen_print ||
  data?.fast_food?.kitchen_print ||
  data?.print?.kitchen ||
  null;

export const dispatchSalePrints = async (saleResponse, options = {}) => {
  const data = getSaleResponsePayload(saleResponse);
  const customerPrint = data.print || {};
  const kitchenPrint = getKitchenPrint(data);

  const results = {
    customer: false,
    kitchen: false,
  };

  if (
    options.printCustomer !== false &&
    customerPrint?.auto_print &&
    customerPrint?.printer?.printer_name &&
    data.receipt_url
  ) {
    await qzPrintPdfUrl({
      printerName: customerPrint.printer.printer_name,
      pdfUrl: data.receipt_url,
      jobName: data.receipt_number || data?.sale?.invoice_number || "Receipt",
    });

    results.customer = true;
  }

  if (options.printKitchen !== false && kitchenPrint?.auto_print) {
    const printerName = kitchenPrint?.printer?.printer_name;
    const copies = Math.max(1, Math.min(5, Number(kitchenPrint?.copies || 1)));
    const jobId = kitchenPrint?.job?.id || kitchenPrint?.job_id || null;
    const orderNo =
      kitchenPrint?.payload?.order_number ||
      data?.order_number ||
      data?.fast_food?.order_number ||
      "Kitchen Order";

    if (!printerName) {
      throw new Error("No active kitchen printer was found for this branch/till.");
    }

    try {
      for (let copy = 1; copy <= copies; copy += 1) {
        const jobName = `Kitchen ${orderNo}${copies > 1 ? ` (${copy}/${copies})` : ""}`;
        if (kitchenPrint?.html) {
          await printKitchenHtml({ printerName, html: kitchenPrint.html, jobName });
        } else if (kitchenPrint?.raw_text) {
          await printKitchenRawText({ printerName, text: kitchenPrint.raw_text, jobName });
        } else if (kitchenPrint?.payload) {
          await printKitchenHtml({
            printerName,
            html: buildKitchenHtml(kitchenPrint.payload),
            jobName,
          });
        } else {
          throw new Error("Kitchen print payload was not returned by the backend.");
        }
      }
      if (jobId) {
        await acknowledgeKitchenPrintJob(jobId, "printed").catch(() => undefined);
      }
    } catch (error) {
      if (jobId) {
        await acknowledgeKitchenPrintJob(jobId, "failed", error?.message).catch(() => undefined);
      }
      throw error;
    }

    results.kitchen = true;
  }

  return results;
};

const printKitchenHtml = async ({ printerName, html, jobName }) => {
  if (typeof qzPrintHtml === "function") {
    return qzPrintHtml({
      printerName,
      html,
      jobName,
    });
  }

  return printHtmlFallback(html);
};

const printKitchenRawText = async ({ printerName, text, jobName }) => {
  if (typeof qzPrintRawText === "function") {
    return qzPrintRawText({
      printerName,
      text,
      jobName,
    });
  }

  return printHtmlFallback(`
    <!DOCTYPE html>
    <html>
    <head>
      <meta charset="utf-8">
      <style>
        body {
          margin: 0;
          padding: 8px;
          font-family: "DejaVu Sans Mono", "Courier New", monospace;
          font-size: 12px;
          font-weight: 800;
          white-space: pre-wrap;
        }
      </style>
    </head>
    <body>${escapeHtml(text)}</body>
    </html>
  `);
};

const printHtmlFallback = async (html) => {
  const win = window.open("", "_blank", "width=420,height=720");

  if (!win) {
    throw new Error("Browser blocked print window. Enable popups or add qzPrintHtml to QzService.");
  }

  win.document.open();
  win.document.write(html);
  win.document.close();

  win.focus();

  window.setTimeout(() => {
    win.print();
    win.close();
  }, 350);

  return true;
};

export const buildKitchenHtml = (payload = {}, settings = {}) => {
  const paperSize = String(settings.paper_size || "80mm").toLowerCase();
  const is58 = paperSize.includes("58");
  const width = is58 ? "48mm" : "72mm";
  const font = is58 ? "10.5px" : "12px";
  const titleFont = is58 ? "16px" : "21px";
  const orderNo = payload.order_number || "-";
  const items = Array.isArray(payload.items) ? payload.items : [];

  return `<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
@page { margin: 0; size: ${is58 ? "58mm auto" : "80mm auto"}; }
* { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
body { margin: 0; padding: 0; background: #ffffff; color: #000000; font-family: "DejaVu Sans Mono", "Courier New", monospace; font-size: ${font}; line-height: 1.35; font-weight: 800; }
.ticket { width: ${width}; max-width: ${width}; margin: 0 auto; padding: 3mm 2.5mm 2.5mm 3mm; }
.center { text-align: center; }
.title { border: 2px solid #000; padding: 5px 2px; font-size: ${titleFont}; font-weight: 900; letter-spacing: 1px; }
.order { font-size: ${titleFont}; font-weight: 900; padding: 5px 0 3px 0; }
.line { border-top: 1.4px dashed #000; margin: 7px 0; }
.kv { width: 100%; border-collapse: collapse; table-layout: fixed; }
.kv td { padding: 2px 0; vertical-align: top; word-break: break-word; }
.k { width: 36%; font-weight: 900; }
.v { width: 64%; text-align: right; font-weight: 900; }
.item { page-break-inside: avoid; margin-bottom: 10px; }
.item-name { font-size: ${is58 ? "12.5px" : "15px"}; font-weight: 900; text-transform: uppercase; }
.sub { padding-left: 8px; font-weight: 900; text-transform: uppercase; }
.note { border: 1.4px solid #000; padding: 4px; margin-top: 4px; font-weight: 900; text-transform: uppercase; }
.footer { text-align: center; font-weight: 900; margin-top: 8px; }
</style>
</head>
<body>
<div class="ticket">
  <div class="title center">KITCHEN ORDER</div>
  <div class="order center">${escapeHtml(orderNo)}</div>

  <table class="kv">
    <tr><td class="k">Invoice</td><td class="v">${escapeHtml(payload.invoice_number || "-")}</td></tr>
    <tr><td class="k">Time</td><td class="v">${escapeHtml(payload.transaction_date || "-")}</td></tr>
    <tr><td class="k">Till</td><td class="v">${escapeHtml(`${payload?.till?.code || "-"} ${payload?.till?.name || ""}`.trim())}</td></tr>
    <tr><td class="k">Service</td><td class="v">${escapeHtml(String(payload.service_type || payload.service_mode || "takeaway").replaceAll("_", " ").toUpperCase())}</td></tr>
    ${payload?.table?.name || payload?.table_name ? `<tr><td class="k">Table</td><td class="v">${escapeHtml(payload?.table?.name || payload?.table_name)}</td></tr>` : ""}
    <tr><td class="k">Cashier</td><td class="v">${escapeHtml(payload.cashier || "-")}</td></tr>
  </table>

  <div class="line"></div>

  ${items.map((item, index) => renderKitchenItem(item, index, items.length)).join("")}

  ${payload.notes ? `<div class="line"></div><div class="note">SALE NOTE: ${escapeHtml(payload.notes)}</div>` : ""}

  <div class="line"></div>
  <div class="footer">END ORDER ${escapeHtml(orderNo)}</div>
</div>
</body>
</html>`;
};

const renderKitchenItem = (item, index, length) => {
  const modifiers = Array.isArray(item?.modifiers) ? item.modifiers : [];
  const messages = Array.isArray(item?.prep_messages) ? item.prep_messages : [];

  return `<div class="item">
    <div class="item-name">${Number(item?.quantity || 0).toFixed(0)} x ${escapeHtml(item?.name || "ITEM")}</div>

    ${modifiers
      .map(
        (modifier) =>
          `<div class="sub">+ ${escapeHtml(modifier?.name || "MODIFIER")} x ${Number(
            modifier?.quantity || 1
          ).toFixed(0)}</div>`
      )
      .join("")}

    ${messages
      .map(
        (message) =>
          `<div class="sub">* ${escapeHtml(
            typeof message === "string" ? message : message?.message || ""
          )}</div>`
      )
      .join("")}

    ${item?.prep_note ? `<div class="note">NOTE: ${escapeHtml(item.prep_note)}</div>` : ""}
  </div>${index < length - 1 ? `<div class="line"></div>` : ""}`;
};

const escapeHtml = (value) =>
  String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");

export default dispatchSalePrints;

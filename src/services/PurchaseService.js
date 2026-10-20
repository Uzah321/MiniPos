// src/services/PurchaseService.js

import api from "../utils/apiBack";
import { downloadBackendReport, normalizeBackendReportParams } from "../utils/BackendReportDownload";

const companyHeaders = () => ({
  "X-Company-Domain": window.location.hostname,
});

const BASE = "/api/pos/purchases";

const purchaseDocumentDefinition = (type, id) => {
  const definitions = {
    order: { path: `orders/${id}/pdf`, fallback: `purchase-order-${id}.pdf`, title: "Purchase Order" },
    receipt: { path: `receipts/${id}/pdf`, fallback: `goods-received-${id}.pdf`, title: "Goods Received Note" },
    return: { path: `returns/${id}/pdf`, fallback: `purchase-return-${id}.pdf`, title: "Purchase Return Note" },
    invoice: { path: `supplier-invoices/${id}/pdf`, fallback: `supplier-invoice-${id}.pdf`, title: "Supplier Invoice" },
  };

  const definition = definitions[type];
  if (!definition) throw new Error("This purchase document does not support PDF download.");
  return definition;
};

const filenameFromDisposition = (headers = {}, fallback) => {
  const value = headers?.["content-disposition"] || headers?.["Content-Disposition"] || "";
  const utf8 = value.match(/filename\*=UTF-8''([^;]+)/i)?.[1];
  const basic = value.match(/filename="?([^";]+)"?/i)?.[1];
  return utf8 ? decodeURIComponent(utf8.replace(/["']/g, "")) : (basic || fallback);
};

export const getPurchasingOverview = async (params = {}) => {
  const res = await api.get(`${BASE}/overview`, { params, headers: companyHeaders() });
  return res.data;
};

export const downloadGoodsReceivedNote = async (purchaseReceiptId, preview = false) => {
  return downloadPurchaseDocument("receipt", purchaseReceiptId, preview);
};

export const downloadPurchaseDocument = async (type, id, preview = false) => {
  const definition = purchaseDocumentDefinition(type, id);
  return downloadBackendReport({
    client: api,
    url: `${BASE}/${definition.path}`,
    params: { download: preview ? 0 : 1 },
    headers: companyHeaders(),
    fallbackName: definition.fallback,
    extension: "pdf",
    mimeType: "application/pdf",
    delivery: preview ? "open" : "download",
  });
};

export const fetchPurchaseDocumentPdf = async (type, id) => {
  const definition = purchaseDocumentDefinition(type, id);
  const response = await api.get(`${BASE}/${definition.path}`, {
    params: { download: 0 },
    headers: companyHeaders(),
    responseType: "blob",
  });
  const contentType = String(response?.headers?.["content-type"] || response?.data?.type || "").toLowerCase();

  if (contentType.includes("json")) {
    const data = JSON.parse(await response.data.text());
    const error = new Error(data?.message || data?.error || "The backend did not return a PDF document.");
    error.response = { ...response, data };
    throw error;
  }

  return {
    blob: response.data instanceof Blob ? response.data : new Blob([response.data], { type: "application/pdf" }),
    fileName: filenameFromDisposition(response.headers, definition.fallback),
    title: definition.title,
  };
};

export const downloadPurchaseRegister = async (type, format = "pdf", params = {}) => {
  const safeFormat = format === "xlsx" ? "xlsx" : "pdf";
  return downloadBackendReport({
    client: api,
    url: `${BASE}/reports/${type}/export/${safeFormat}`,
    params: normalizeBackendReportParams(params),
    headers: companyHeaders(),
    fallbackName: `${type}-register.${safeFormat}`,
    extension: safeFormat,
    mimeType: safeFormat === "pdf"
      ? "application/pdf"
      : "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
  });
};

export const getPurchaseOrders = async (params = {}) => {
  const res = await api.get(`${BASE}/orders`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getPurchaseOrder = async (purchaseOrderId) => {
  const res = await api.get(`${BASE}/orders/${purchaseOrderId}`, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const savePurchaseOrder = async (payload) => {
  if (payload?.id) {
    const res = await api.put(`${BASE}/orders/${payload.id}`, payload, {
      headers: companyHeaders(),
    });

    return res.data;
  }

  const res = await api.post(`${BASE}/orders`, payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const receivePurchaseOrder = async (purchaseOrderId, payload) => {
  const url = purchaseOrderId
    ? `${BASE}/orders/${purchaseOrderId}/receive`
    : `${BASE}/receipts`;

  const res = await api.post(url, payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const getPurchaseReceipts = async (params = {}) => {
  const res = await api.get(`${BASE}/receipts`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getPurchaseReceipt = async (purchaseReceiptId) => {
  const res = await api.get(`${BASE}/receipts/${purchaseReceiptId}`, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const getPurchaseReturns = async (params = {}) => {
  const res = await api.get(`${BASE}/returns`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getPurchaseReturn = async (purchaseReturnId) => {
  const res = await api.get(`${BASE}/returns/${purchaseReturnId}`, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const savePurchaseReturn = async (payload) => {
  const res = await api.post(`${BASE}/returns`, payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const getSupplierInvoices = async (params = {}) => {
  const res = await api.get(`${BASE}/supplier-invoices`, {
    params,
    headers: companyHeaders(),
  });

  return res.data;
};

export const getSupplierInvoice = async (supplierInvoiceId) => {
  const res = await api.get(`${BASE}/supplier-invoices/${supplierInvoiceId}`, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const saveSupplierInvoice = async (payload) => {
  const res = await api.post(`${BASE}/supplier-invoices`, payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const getSupplierPayments = async (params = {}) => {
  const res = await api.get(`${BASE}/supplier-payments`, { params, headers: companyHeaders() });
  return res.data;
};

export const saveSupplierPayment = async (payload) => {
  const res = await api.post(`${BASE}/supplier-payments`, payload, { headers: companyHeaders() });
  return res.data;
};

export const reverseSupplierPayment = async (paymentId, reason) => {
  const res = await api.post(`${BASE}/supplier-payments/${paymentId}/reverse`, { reason }, { headers: companyHeaders() });
  return res.data;
};

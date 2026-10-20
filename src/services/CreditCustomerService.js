// src/services/CreditCustomerService.js

import api from "../utils/apiBack";
import { downloadBackendReport } from "../utils/BackendReportDownload";

const companyHeaders = () => ({
  "X-Company-Domain": window.location.hostname,
});

const BASE = "/api/pos/credit-customers";

export const getCreditCustomers = async (params = {}) => {
  const res = await api.get(BASE, {
    params: {
      ...params,
      per_page: params.per_page || 100,
    },
    headers: companyHeaders(),
  });

  return res.data?.data || res.data;
};

export const getCreditCustomersForPos = async (params = {}) => {
  const res = await api.get(`${BASE}/pos-lookup`, {
    params: {
      ...params,
      per_page: params.per_page || 100,
    },
    headers: companyHeaders(),
  });

  return res.data?.data || res.data;
};

export const getCreditCustomerPosAccount = async (id, params = {}) => {
  const res = await api.get(`${BASE}/${id}/pos-account`, {
    params,
    headers: companyHeaders(),
  });

  return res.data?.data || res.data;
};

export const captureCreditCustomerPosPayment = async (id, payload) => {
  const res = await api.post(`${BASE}/${id}/pos-payments`, payload, {
    headers: companyHeaders(),
  });

  // The POS response deliberately includes the refreshed customer, statement,
  // amount applied to debt and any new prepaid credit. Keep the envelope intact.
  return res.data;
};

export const getCreditCustomer = async (id) => {
  const res = await api.get(`${BASE}/${id}`, {
    headers: companyHeaders(),
  });

  return res.data?.data || res.data;
};

export const saveCreditCustomer = async (payload, id = null) => {
  const res = id
    ? await api.put(`${BASE}/${id}`, payload, {
        headers: companyHeaders(),
      })
    : await api.post(BASE, payload, {
        headers: companyHeaders(),
      });

  return res.data?.data || res.data;
};

export const approveCreditCustomer = async (id) => {
  const res = await api.post(
    `${BASE}/${id}/approve`,
    {},
    {
      headers: companyHeaders(),
    }
  );

  return res.data?.data || res.data;
};

export const blockCreditCustomer = async (id, payload = {}) => {
  const res = await api.post(`${BASE}/${id}/block`, payload, {
    headers: companyHeaders(),
  });

  return res.data?.data || res.data;
};

export const getCreditCustomerStatement = async (id, params = {}) => {
  const res = await api.get(`${BASE}/${id}/statement`, {
    params,
    headers: companyHeaders(),
  });

  return res.data?.data || res.data;
};

export const downloadCreditCustomerStatement = async (
  id,
  { paper = "a4", date_from, date_to } = {},
  delivery = "download"
) => downloadBackendReport({
  client: api,
  url: `${BASE}/${id}/statement/pdf`,
  params: { paper, date_from, date_to },
  headers: companyHeaders(),
  fallbackName: `credit-customer-statement-${paper}.pdf`,
  extension: "pdf",
  mimeType: "application/pdf",
  delivery,
});

export const downloadCreditCustomerPaymentReceipt = async (
  customerId,
  paymentId,
  { paper = "80mm" } = {},
  delivery = "open"
) => downloadBackendReport({
  client: api,
  url: `${BASE}/${customerId}/payments/${paymentId}/receipt/pdf`,
  params: {
    paper,
    download: delivery === "download" ? 1 : 0,
  },
  headers: companyHeaders(),
  fallbackName: `credit-customer-payment-${paymentId}-${paper}.pdf`,
  extension: "pdf",
  mimeType: "application/pdf",
  delivery,
});

export const captureCreditCustomerPayment = async (id, payload) => {
  const res = await api.post(`${BASE}/${id}/payments`, payload, {
    headers: companyHeaders(),
  });

  // Keep the envelope so Back Office can distinguish an idempotent recovery
  // from a newly posted payment and can use the backend docket contract.
  return res.data;
};

export const getCreditCustomerReport = async (params = {}) => {
  const res = await api.get(`${BASE}/report`, {
    params,
    headers: companyHeaders(),
  });

  return res.data?.data || res.data;
};
export const reverseCreditCustomerPayment = async (customerId, paymentId, payload = {}) => {
  const res = await api.post(
    `${BASE}/${customerId}/payments/${paymentId}/reverse`,
    payload,
    {
      headers: companyHeaders(),
    }
  );

  return res.data?.data || res.data;
};

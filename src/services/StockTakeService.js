// src/services/StockTakeService.js

import api from "../utils/apiBack";

const companyHeaders = () => ({
  "X-Company-Domain": window.location.hostname,
});

const BASE = "/api/pos";

const downloadBlob = (blob, filename) => {
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");

  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);

  setTimeout(() => URL.revokeObjectURL(url), 1000);
};

const branchHeaders = (branchId) => ({
  ...companyHeaders(),
  ...(branchId ? { "X-Branch-Id": branchId } : {}),
});

export const getStockTakes = async (params = {}) => {
  const res = await api.get(`${BASE}/stock-takes`, {
    params,
    headers: branchHeaders(params.branch_id),
  });

  return res.data;
};

export const getStockTake = async (sessionId, params = {}) => {
  const res = await api.get(`${BASE}/stock-takes/${sessionId}`, {
    params,
    headers: branchHeaders(params.branch_id),
  });

  return res.data;
};

export const downloadStockTakeCountSheet = async (params = {}) => {
  const format = params.format || "xlsx";

  const res = await api.get(`${BASE}/stock-takes/download-count-sheet`, {
    params,
    responseType: "blob",
    headers: branchHeaders(params.branch_id),
  });

  const name = `stock_take_${params.as_of_date || "sheet"}.${format}`;
  downloadBlob(res.data, name);
};

/**
 * Enterprise trading stock take:
 * 1. Download frozen balance snapshot.
 * 2. Download counted quantity input sheet.
 * 3. Begin trading stock take: zero branch stock and enable negative stock.
 * 4. Upload balance/count sheets for variance analysis.
 */
export const downloadStockTakeBalanceSnapshot = async (params = {}) => {
  const format = params.format || "xlsx";

  const res = await api.get(`${BASE}/stock-takes/download-balance-snapshot`, {
    params,
    responseType: "blob",
    headers: branchHeaders(params.branch_id),
  });

  const name = `stock_take_balance_snapshot_${params.as_of_date || "snapshot"}.${format}`;
  downloadBlob(res.data, name);
};

export const downloadTradingCountSheet = async (params = {}) => {
  const format = params.format || "xlsx";

  const res = await api.get(`${BASE}/stock-takes/download-trading-count-sheet`, {
    params,
    responseType: "blob",
    headers: branchHeaders(params.branch_id),
  });

  const name = `stock_take_count_input_${params.as_of_date || "count"}.${format}`;
  downloadBlob(res.data, name);
};

export const beginTradingStockTake = async (payload = {}) => {
  if (!payload.branch_id) throw new Error("Branch is required.");
  if (!payload.as_of_date) throw new Error("As-of date is required.");

  const res = await api.post(
    `${BASE}/stock-takes/begin-trading`,
    {
      ...payload,
      confirm: payload.confirm ? 1 : 0,
    },
    {
      headers: branchHeaders(payload.branch_id),
    }
  );

  return res.data;
};

export const uploadTradingCountSheets = async ({
  branch_id,
  as_of_date,
  session_id,
  balance_file,
  count_file,
} = {}) => {
  if (!branch_id) throw new Error("Branch is required.");
  if (!as_of_date) throw new Error("As-of date is required.");
  if (!session_id) throw new Error("Stock take session is required.");
  if (!count_file) throw new Error("Counted stock sheet is required.");

  const form = new FormData();
  form.append("branch_id", branch_id);
  form.append("as_of_date", as_of_date);
  form.append("session_id", session_id);
  form.append("count_file", count_file, count_file.name || "stock_take_count.xlsx");

  if (balance_file) {
    form.append("balance_file", balance_file, balance_file.name || "stock_take_balance_snapshot.xlsx");
  }

  const res = await api.post(`${BASE}/stock-takes/upload-trading-count-sheets`, form, {
    headers: {
      ...branchHeaders(branch_id),
      Accept: "application/json",
    },
    transformRequest: [(data) => data],
  });

  return res.data;
};

export const uploadStockTakeCountSheet = async ({
  file,
  branch_id,
  as_of_date,
  session_id,
  auto_create_session = 1,
} = {}) => {
  if (!file) throw new Error("Stock take count file is required.");
  if (!branch_id) throw new Error("Branch is required.");
  if (!as_of_date) throw new Error("As-of date is required.");

  const form = new FormData();
  form.append("file", file, file.name || "stock_take.xlsx");
  form.append("branch_id", branch_id);
  form.append("as_of_date", as_of_date);
  form.append("auto_create_session", auto_create_session ? "1" : "0");

  if (session_id) {
    form.append("session_id", session_id);
  }

  const res = await api.post(`${BASE}/stock-takes/upload-count-sheet`, form, {
    headers: {
      ...branchHeaders(branch_id),
      Accept: "application/json",
    },
    transformRequest: [(data) => data],
  });

  return res.data;
};

export const approveStockTake = async (sessionId, payload = {}) => {
  const res = await api.post(`${BASE}/stock-takes/${sessionId}/approve`, payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const postStockTakeAdjustments = async (sessionId, payload = {}) => {
  const res = await api.post(`${BASE}/stock-takes/${sessionId}/post-adjustments`, payload, {
    headers: companyHeaders(),
  });

  return res.data;
};

export const exportStockTakeVariance = async (sessionId, params = {}) => {
  const format = params.format || "xlsx";

  const res = await api.get(`${BASE}/stock-takes/${sessionId}/variance-export`, {
    params,
    responseType: "blob",
    headers: companyHeaders(),
  });

  downloadBlob(res.data, `stock_take_variance_${sessionId}.${format}`);
};

export const getStockTakeDashboard = async (params = {}) => {
  const res = await api.get(`${BASE}/stock-takes/reports/dashboard`, {
    params,
    headers: branchHeaders(params.branch_id),
  });

  return res.data;
};

export const getStockTakeVarianceReport = async (params = {}) => {
  const res = await api.get(`${BASE}/stock-takes/reports/variance`, {
    params,
    headers: branchHeaders(params.branch_id),
  });

  return res.data;
};

export const getStockTakeCategoryVarianceReport = async (params = {}) => {
  const res = await api.get(`${BASE}/stock-takes/reports/category-variance`, {
    params,
    headers: branchHeaders(params.branch_id),
  });

  return res.data;
};

export const getStockTakeBranchVarianceReport = async (params = {}) => {
  const res = await api.get(`${BASE}/stock-takes/reports/branch-variance`, {
    params,
    headers: branchHeaders(params.branch_id),
  });

  return res.data;
};

export const getStockTakeAuditReport = async (params = {}) => {
  const res = await api.get(`${BASE}/stock-takes/reports/audit`, {
    params,
    headers: branchHeaders(params.branch_id),
  });

  return res.data;
};

export const exportStockTakeReport = async (params = {}) => {
  const format = params.format || "xlsx";
  const type = params.type || "variance";

  const res = await api.get(`${BASE}/stock-takes/reports/export`, {
    params,
    responseType: "blob",
    headers: branchHeaders(params.branch_id),
  });

  downloadBlob(res.data, `stock_take_${type}_report.${format}`);
};

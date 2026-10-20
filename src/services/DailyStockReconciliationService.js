import api from "../utils/apiBack";

const companyHeaders = () => ({
  "X-Company-Domain": window.location.hostname,
});

const unwrap = (response) => response?.data?.data ?? response?.data ?? response;

export const getDayEndStatus = async (params = {}) => {
  const res = await api.get("/api/pos/day-end/status", {
    params,
    headers: companyHeaders(),
  });
  return unwrap(res);
};

export const startDayEnd = async (payload) => {
  const res = await api.post("/api/pos/day-end/start", payload, {
    headers: companyHeaders(),
  });
  return res.data;
};

export const completeDayEnd = async (payload) => {
  const res = await api.post("/api/pos/day-end/complete", payload, {
    headers: companyHeaders(),
  });
  return res.data;
};

export const rollbackDayEnd = async (payload) => {
  const res = await api.post("/api/pos/day-end/rollback", payload, {
    headers: companyHeaders(),
  });
  return res.data;
};

export const previewDailyStockReconciliation = async (params) => {
  const res = await api.get("/api/pos/daily-stock-reconciliation/preview", {
    params,
    headers: companyHeaders(),
  });
  return unwrap(res);
};

export const generateDailyStockReconciliation = async (payload) => {
  const res = await api.post("/api/pos/daily-stock-reconciliation/generate", payload, {
    headers: companyHeaders(),
  });
  return res.data;
};

export const downloadDailyStockReconciliationExcel = async (params) => {
  const res = await api.get("/api/pos/daily-stock-reconciliation/export", {
    params,
    responseType: "blob",
    headers: companyHeaders(),
  });

  const date = params?.trading_date || new Date().toISOString().slice(0, 10);
  const blob = new Blob([res.data], { type: "application/vnd.ms-excel" });
  const url = window.URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = `Daily_Stock_Reconciliation_${date}.xls`;
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.URL.revokeObjectURL(url);
};

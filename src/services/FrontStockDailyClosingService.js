import api from "../utils/apiBack";

const companyHeaders = () => ({
  "X-Company-Domain": window.location.hostname,
});

const unwrap = (response) => response?.data ?? response;

export const getFrontStockDailyClosings = async (params = {}) => {
  const response = await api.get("/api/pos/front-stock/daily-closings", {
    params,
    headers: companyHeaders(),
  });

  return unwrap(response);
};

export const getFrontStockDailyClosing = async (id) => {
  const response = await api.get(`/api/pos/front-stock/daily-closings/${id}`, {
    headers: companyHeaders(),
  });

  return unwrap(response)?.data ?? unwrap(response);
};

export const getFrontStockClosingSnapshot = async (params = {}) => {
  const response = await api.get("/api/pos/front-stock/daily-closings/snapshot", {
    params,
    headers: companyHeaders(),
  });

  return unwrap(response)?.data ?? unwrap(response);
};

export const saveFrontStockDailyClosing = async (payload) => {
  const response = await api.post("/api/pos/front-stock/daily-closings", payload, {
    headers: companyHeaders(),
  });

  return unwrap(response);
};

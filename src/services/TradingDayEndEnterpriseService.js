import api from "../utils/apiBack";
import { companyHeaders, isFrontOfficeWorkspace } from "../utils/apiConfig";

const BASE = "/api/pos/trading-day";
const workspace = () => (isFrontOfficeWorkspace() ? "front-office" : "back-office");
const dayEndBase = (activeWorkspace) => (
  activeWorkspace === "front-office" ? "/api/pos/front/trading-day" : BASE
);
const requestConfig = (activeWorkspace, config = {}) => ({
  salexaWorkspace: activeWorkspace,
  headers: companyHeaders({ workspace: activeWorkspace }),
  ...config,
});

const unwrap = (res) => res?.data?.data ?? res?.data ?? res;

export const getCurrentTradingDay = async (params = {}) => {
  const activeWorkspace = workspace();
  const res = await api.get(`${BASE}/current`, requestConfig(activeWorkspace, {
    params,
  }));

  return unwrap(res);
};

export const getDayEndPreflight = async (params = {}) => {
  const activeWorkspace = workspace();
  const res = await api.get(`${dayEndBase(activeWorkspace)}/day-end/preflight`, requestConfig(activeWorkspace, {
    params,
  }));

  return unwrap(res);
};

export const forceCloseFrontStockDays = async ({
  dates = [],
  reason = "",
  branch_id = null,
} = {}) => {
  const activeWorkspace = workspace();
  const res = await api.post(
    `${dayEndBase(activeWorkspace)}/day-end/force-front-stock-closing`,
    {
      dates,
      reason,
      force_mode: "system_expected",
      ...(branch_id ? { branch_id } : {}),
    },
    requestConfig(activeWorkspace),
  );

  return unwrap(res);
};

export const runTradingDayEnd = async (payload = {}) => {
  const activeWorkspace = workspace();
  const res = await api.post(
    `${dayEndBase(activeWorkspace)}/day-end`,
    payload,
    requestConfig(activeWorkspace),
  );

  return unwrap(res);
};

export const catchUpTradingDayEnd = async (payload = {}) => {
  const activeWorkspace = workspace();
  const res = await api.post(
    `${dayEndBase(activeWorkspace)}/day-end/catch-up`,
    payload,
    requestConfig(activeWorkspace),
  );

  return unwrap(res);
};

export const getTradingDayEndProgress = async (runKey, params = {}) => {
  if (!runKey) throw new Error("A Day End run key is required.");
  const activeWorkspace = workspace();

  const res = await api.get(
    `${dayEndBase(activeWorkspace)}/day-end/progress/${encodeURIComponent(runKey)}`,
    requestConfig(activeWorkspace, {
      params,
    }),
  );

  return unwrap(res);
};

export const rollbackTradingDayEndLock = async (payload = {}) => {
  const activeWorkspace = workspace();
  const res = await api.post(
    `${dayEndBase(activeWorkspace)}/day-end/rollback`,
    payload,
    requestConfig(activeWorkspace),
  );

  return unwrap(res);
};

import api from "../utils/apiBack";

const BASE = "/api/pos/management/hospitality/table-service";
const BACK_OFFICE_REQUEST = Object.freeze({ salexaWorkspace: "back-office" });

const unwrap = (response) => response?.data?.data ?? response?.data ?? response;

const requiredScope = (scope = {}) => {
  const branchId = String(scope.branch_id || "").trim();
  const shopId = String(scope.shop_id || "").trim();

  if (!branchId || !shopId) {
    throw new Error("Select a shop with both branch and shop scope before configuring table service.");
  }

  return { branch_id: branchId, shop_id: shopId };
};

export const getHospitalityTableServiceSettings = async (scope) => {
  const response = await api.get(`${BASE}/settings`, {
    ...BACK_OFFICE_REQUEST,
    params: requiredScope(scope),
  });
  return unwrap(response);
};

export const updateHospitalityTableServiceSettings = async (scope, payload = {}) => {
  const response = await api.put(
    `${BASE}/settings`,
    { ...payload, ...requiredScope(scope) },
    BACK_OFFICE_REQUEST,
  );
  return unwrap(response);
};

export const getHospitalityTables = async (scope) => {
  const response = await api.get(`${BASE}/tables`, {
    ...BACK_OFFICE_REQUEST,
    params: requiredScope(scope),
  });
  const payload = unwrap(response);
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.tables)) return payload.tables;
  if (Array.isArray(payload?.data)) return payload.data;
  return [];
};

export const createHospitalityTable = async (scope, payload = {}) => {
  const response = await api.post(
    `${BASE}/tables`,
    { ...payload, ...requiredScope(scope), table_type: "dining" },
    BACK_OFFICE_REQUEST,
  );
  return unwrap(response);
};

export const updateHospitalityTable = async (tableId, scope, payload = {}) => {
  const response = await api.put(
    `${BASE}/tables/${encodeURIComponent(tableId)}`,
    { ...payload, ...requiredScope(scope), table_type: "dining" },
    BACK_OFFICE_REQUEST,
  );
  return unwrap(response);
};


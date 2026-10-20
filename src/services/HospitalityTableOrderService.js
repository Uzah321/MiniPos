import api from "../utils/apiBack";

const BASE = "/api/pos/hospitality";
const FRONT_OFFICE_REQUEST = Object.freeze({ salexaWorkspace: "front-office" });

const unwrap = (payload) => payload?.data?.data ?? payload?.data ?? payload ?? {};

export const createHospitalityRequestId = () => {
  if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") {
    return crypto.randomUUID();
  }

  return `hospitality-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
};

export const getHospitalityTableService = async () => {
  const response = await api.get(`${BASE}/table-service`, FRONT_OFFICE_REQUEST);
  return unwrap(response);
};

export const getHospitalityTableOrders = async (params = {}) => {
  const response = await api.get(`${BASE}/table-orders`, {
    ...FRONT_OFFICE_REQUEST,
    params: {
      status: "open,hanging",
      ...params,
    },
  });

  const payload = unwrap(response);
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data)) return payload.data;
  if (Array.isArray(payload?.orders)) return payload.orders;
  return [];
};

export const createHospitalityTableOrder = async (payload) => {
  const response = await api.post(`${BASE}/table-orders`, payload, FRONT_OFFICE_REQUEST);
  return unwrap(response);
};

export const getHospitalityTableOrder = async (orderId) => {
  const response = await api.get(
    `${BASE}/table-orders/${encodeURIComponent(orderId)}`,
    FRONT_OFFICE_REQUEST,
  );
  return unwrap(response);
};

export const updateHospitalityTableOrder = async (orderId, payload) => {
  const response = await api.put(
    `${BASE}/table-orders/${encodeURIComponent(orderId)}`,
    payload,
    FRONT_OFFICE_REQUEST,
  );
  return unwrap(response);
};

export const hangHospitalityTableOrder = async (orderId, expectedRevision) => {
  const response = await api.post(
    `${BASE}/table-orders/${encodeURIComponent(orderId)}/hang`,
    {
      expected_revision: Number(expectedRevision || 0),
      command_id: createHospitalityRequestId(),
    },
    FRONT_OFFICE_REQUEST,
  );
  return unwrap(response);
};

export const recallHospitalityTableOrder = async (orderId, expectedRevision) => {
  const response = await api.post(
    `${BASE}/table-orders/${encodeURIComponent(orderId)}/recall`,
    {
      expected_revision: Number(expectedRevision || 0),
      command_id: createHospitalityRequestId(),
    },
    FRONT_OFFICE_REQUEST,
  );
  return unwrap(response);
};

export const clearHospitalityTableOrder = async (orderId, payload) => {
  const response = await api.post(
    `${BASE}/table-orders/${encodeURIComponent(orderId)}/clear`,
    {
      expected_revision: Number(payload?.expected_revision || 0),
      reason: String(payload?.reason || "").trim(),
      ...(payload?.manager_approval_id
        ? { manager_approval_id: payload.manager_approval_id }
        : {}),
      command_id: payload?.command_id || createHospitalityRequestId(),
    },
    FRONT_OFFICE_REQUEST,
  );
  return unwrap(response);
};

export const sendHospitalityTableOrderToKitchen = async (orderId, payload) => {
  return api.post(
    `${BASE}/table-orders/${encodeURIComponent(orderId)}/send-to-kitchen`,
    {
      expected_revision: Number(payload?.expected_revision || 0),
      command_id: payload?.command_id || createHospitalityRequestId(),
    },
    FRONT_OFFICE_REQUEST,
  );
};

export const hospitalityOrderId = (order) =>
  order?.id || order?.order_id || order?.hospitality_table_order_id || "";

export const hospitalityOrderRevision = (order) =>
  Number(order?.revision ?? order?.lock_version ?? order?.version ?? 0);


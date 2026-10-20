import api from "../utils/apiBack";

const BASE = "/api/pos/management/inventory";
const headers = () => ({ "X-Company-Domain": window.location.hostname });
const unwrap = (response) => response?.data?.data ?? response?.data ?? response;

export const getEnterpriseInventoryDashboard = async (params = {}) => unwrap(await api.get(`${BASE}/dashboard`, { params, headers: headers() }));
export const getInventoryWarehouses = async (params = {}) => unwrap(await api.get(`${BASE}/warehouses`, { params, headers: headers() }));
export const createInventoryWarehouse = async (payload = {}) => unwrap(await api.post(`${BASE}/warehouses`, payload, { headers: headers() }));
export const createInventoryLocation = async (warehouseId, payload = {}) => unwrap(await api.post(`${BASE}/warehouses/${warehouseId}/locations`, payload, { headers: headers() }));
export const updateInventoryWarehouse = async (warehouseId, payload = {}) => unwrap(await api.put(`${BASE}/warehouses/${warehouseId}`, payload, { headers: headers() }));
export const deleteInventoryWarehouse = async (warehouseId) => unwrap(await api.delete(`${BASE}/warehouses/${warehouseId}`, { headers: headers() }));
export const getWarehouseProducts = async (warehouseId, params = {}) => unwrap(await api.get(`${BASE}/warehouses/${warehouseId}/products`, { params, headers: headers() }));
export const updateInventoryLocation = async (locationId, payload = {}) => unwrap(await api.put(`${BASE}/locations/${locationId}`, payload, { headers: headers() }));
export const getInventoryBatches = async (params = {}) => unwrap(await api.get(`${BASE}/batches`, { params, headers: headers() }));
export const getInventoryBatch = async (batchId) => unwrap(await api.get(`${BASE}/batches/${batchId}`, { headers: headers() }));
export const updateInventoryBatch = async (batchId, payload = {}) => unwrap(await api.put(`${BASE}/batches/${batchId}`, payload, { headers: headers() }));
export const reconcileInventoryBatches = async () => unwrap(await api.post(`${BASE}/batches/reconcile`, {}, { headers: headers() }));
export const getInventoryMovements = async (params = {}) => unwrap(await api.get(`${BASE}/movements`, { params, headers: headers() }));
export const getInventoryAlerts = async (params = {}) => unwrap(await api.get(`${BASE}/alerts`, { params, headers: headers() }));
export const refreshInventoryAlerts = async () => unwrap(await api.post(`${BASE}/alerts/refresh`, {}, { headers: headers() }));
export const getInventoryRecalls = async (params = {}) => unwrap(await api.get(`${BASE}/recalls`, { params, headers: headers() }));
export const createInventoryRecall = async (payload = {}) => unwrap(await api.post(`${BASE}/recalls`, payload, { headers: headers() }));
export const closeInventoryRecall = async (recallId, payload = {}) => unwrap(await api.post(`${BASE}/recalls/${recallId}/close`, payload, { headers: headers() }));

const VARIANCE_AGENT_BASE = `${BASE}/variance-agent`;

export const getInventoryVarianceAgentSettings = async () => {
  const overview = unwrap(await api.get(VARIANCE_AGENT_BASE, { headers: headers() }));
  return overview?.settings || { enabled: false };
};
export const updateInventoryVarianceAgentSettings = async (payload = {}) => unwrap(await api.patch(`${VARIANCE_AGENT_BASE}/settings`, payload, { headers: headers() }));
export const getInventoryVarianceOverview = async (params = {}) => unwrap(await api.get(VARIANCE_AGENT_BASE, { params, headers: headers() }));
export const getInventoryVariances = async (params = {}) => unwrap(await api.get(`${VARIANCE_AGENT_BASE}/variances`, { params, headers: headers() }));
export const getInventoryVariance = async (varianceId) => unwrap(await api.get(`${VARIANCE_AGENT_BASE}/variances/${varianceId}`, { headers: headers() }));
export const runInventoryVarianceScan = async (payload = {}) => unwrap(await api.post(`${VARIANCE_AGENT_BASE}/scan`, payload, { headers: headers() }));

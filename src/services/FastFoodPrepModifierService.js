import api from '../utils/apiBack';

const companyHeaders = () => ({
  'X-Company-Domain': window.location.hostname,
});

const unwrap = (response) => response?.data ?? response;

export const getFastFoodProductOptions = async (productId, params = {}) => {
  // Back Office configuration must never depend on a browser/device till.
  // POS keeps using the till-bound /options endpoint; this dedicated route is
  // authenticated, Hospitality-only and branch-scoped by the controller.
  const res = await api.get(`/api/pos/fast-food/products/${productId}/configuration-options`, {
    params,
    headers: companyHeaders(),
  });
  return unwrap(res);
};

// Till-bound options for the selling screen. The configuration route above is
// Back Office-only, so a cashier's Front Office session is rejected there.
export const getPosFastFoodProductOptions = async (productId, params = {}) => {
  const res = await api.get(`/api/pos/fast-food/products/${productId}/options`, {
    params,
    headers: companyHeaders(),
  });
  return unwrap(res);
};

export const getFinishedGoodMenuItemsForConfiguration = async (params = {}) => {
  const res = await api.get('/api/pos/products', {
    params: {
      product_type: 'finished_good',
      product_types: ['finished_good'],
      is_active: true,
      ...params,
    },
    headers: companyHeaders(),
  });
  return unwrap(res);
};

export const getPrepMessages = async (params = {}) => {
  const res = await api.get('/api/pos/fast-food/prep-messages', { params, headers: companyHeaders() });
  return unwrap(res);
};

export const createPrepMessage = async (payload) => {
  const res = await api.post('/api/pos/fast-food/prep-messages', payload, { headers: companyHeaders() });
  return unwrap(res);
};

export const updatePrepMessage = async (id, payload) => {
  const res = await api.put(`/api/pos/fast-food/prep-messages/${id}`, payload, { headers: companyHeaders() });
  return unwrap(res);
};

export const deletePrepMessage = async (id) => {
  const res = await api.delete(`/api/pos/fast-food/prep-messages/${id}`, { headers: companyHeaders() });
  return unwrap(res);
};

export const assignPrepMessageToProducts = async (prepMessageId, productIds = [], extra = {}) => {
  const res = await api.post(`/api/pos/fast-food/prep-messages/${prepMessageId}/assign-products`, {
    product_ids: productIds,
    ...extra,
  }, { headers: companyHeaders() });
  return unwrap(res);
};

export const removePrepMessageFromProduct = async (prepMessageId, productId, extra = {}) => {
  const res = await api.delete(
    `/api/pos/fast-food/prep-messages/${prepMessageId}/products/${productId}`,
    { data: extra, headers: companyHeaders() }
  );
  return unwrap(res);
};

export const getModifiers = async (params = {}) => {
  const res = await api.get('/api/pos/fast-food/modifiers', { params, headers: companyHeaders() });
  return unwrap(res);
};

export const createModifier = async (payload) => {
  const res = await api.post('/api/pos/fast-food/modifiers', payload, { headers: companyHeaders() });
  return unwrap(res);
};

export const updateModifier = async (id, payload) => {
  const res = await api.put(`/api/pos/fast-food/modifiers/${id}`, payload, { headers: companyHeaders() });
  return unwrap(res);
};

export const deleteModifier = async (id) => {
  const res = await api.delete(`/api/pos/fast-food/modifiers/${id}`, { headers: companyHeaders() });
  return unwrap(res);
};

export const assignModifierToProducts = async (modifierId, productIds = [], extra = {}) => {
  const res = await api.post(`/api/pos/fast-food/modifiers/${modifierId}/assign-products`, {
    product_ids: productIds,
    ...extra,
  }, { headers: companyHeaders() });
  return unwrap(res);
};

export const removeModifierFromProduct = async (modifierId, productId, extra = {}) => {
  const res = await api.delete(
    `/api/pos/fast-food/modifiers/${modifierId}/products/${productId}`,
    { data: extra, headers: companyHeaders() }
  );
  return unwrap(res);
};

export default {
  getFastFoodProductOptions,
  getPosFastFoodProductOptions,
  getFinishedGoodMenuItemsForConfiguration,
  getPrepMessages,
  createPrepMessage,
  updatePrepMessage,
  deletePrepMessage,
  assignPrepMessageToProducts,
  removePrepMessageFromProduct,
  getModifiers,
  createModifier,
  updateModifier,
  deleteModifier,
  assignModifierToProducts,
  removeModifierFromProduct,
};

import api from "../utils/apiBack";
import { companyHeaders, isFrontOfficeWorkspace } from "../utils/apiConfig";

const workspace = () => (isFrontOfficeWorkspace() ? "front-office" : "back-office");
const quotationBase = (activeWorkspace) => (
  activeWorkspace === "front-office"
    ? "/api/pos/front/quotations"
    : "/api/pos/quotations"
);
const requestConfig = (activeWorkspace, config = {}) => ({
  salexaWorkspace: activeWorkspace,
  headers: companyHeaders({ workspace: activeWorkspace }),
  ...config,
});

export const getQuotations = async (params = {}) => {
  const activeWorkspace = workspace();
  const res = await api.get(quotationBase(activeWorkspace), requestConfig(activeWorkspace, {
    params,
  }));
  return res.data;
};

export const getQuotation = async (id) => {
  const activeWorkspace = workspace();
  const res = await api.get(`${quotationBase(activeWorkspace)}/${id}`, requestConfig(activeWorkspace));
  return res.data;
};

export const saveQuotation = async (payload = {}) => {
  const activeWorkspace = workspace();
  const base = quotationBase(activeWorkspace);
  if (payload.id) {
    const res = await api.put(`${base}/${payload.id}`, payload, requestConfig(activeWorkspace));
    return res.data;
  }

  const res = await api.post(base, payload, requestConfig(activeWorkspace));
  return res.data;
};

export const issueQuotation = async (id) => {
  const activeWorkspace = workspace();
  const res = await api.post(
    `${quotationBase(activeWorkspace)}/${id}/issue`,
    {},
    requestConfig(activeWorkspace),
  );
  return res.data;
};

export const cancelQuotation = async (id) => {
  const activeWorkspace = workspace();
  const res = await api.post(
    `${quotationBase(activeWorkspace)}/${id}/cancel`,
    {},
    requestConfig(activeWorkspace),
  );
  return res.data;
};

export const lookupQuotationByCode = async (quoteCode) => {
  const activeWorkspace = workspace();
  const res = await api.get(
    `${quotationBase(activeWorkspace)}/code/${encodeURIComponent(quoteCode)}`,
    requestConfig(activeWorkspace),
  );
  return res.data;
};

export const markQuotationConverted = async (quoteCode, sales_transaction_id) => {
  const activeWorkspace = workspace();
  const res = await api.post(
    `${quotationBase(activeWorkspace)}/code/${encodeURIComponent(quoteCode)}/convert`,
    { sales_transaction_id },
    requestConfig(activeWorkspace),
  );
  return res.data;
};

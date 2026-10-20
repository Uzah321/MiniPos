// src/services/BranchService.js

import api from "../utils/apiBack";

const companyHeaders = () => ({
  "X-Company-Domain": window.location.hostname,
});

/*
 * Product import needs a branch_id because product_stocks, product_costs
 * and product_prices are branch-based.
 *
 * If your branch endpoint is different, only change the BRANCH_ENDPOINT below.
 */
const BRANCH_ENDPOINT = "/api/pos/company-branches";

export const getBranchesForProductImport = async (params = {}) => {
  const res = await api.get(BRANCH_ENDPOINT, {
    params: {
      ...params,
      per_page: params.per_page || 300,
    },
    headers: companyHeaders(),
  });

  return res.data;
};

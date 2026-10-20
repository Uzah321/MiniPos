const finiteAmount = (value, fallback = 0) => {
  const numeric = Number(value);
  return Number.isFinite(numeric) ? numeric : Number(fallback || 0);
};

// SaleService currently returns the JSON body, while a few tests/callers pass
// an axios-shaped response. Normal and idempotent duplicate responses share the
// same canonical data envelope, so both must resolve to the identical payload.
export const getSaleResponsePayload = (response) =>
  response?.data?.data || response?.data || response || {};

export const resolveCreditedSaleChange = (salePayload, fallbackComputedChange = 0) => {
  const creditedChange = finiteAmount(
    salePayload?.change_credit_amount ?? salePayload?.credited_change?.change_amount,
    0
  );
  const cashChangeReturned = finiteAmount(
    salePayload?.credited_change?.returned_change_amount ?? salePayload?.change,
    Math.max(finiteAmount(fallbackComputedChange, 0) - creditedChange, 0)
  );

  return { creditedChange, cashChangeReturned };
};

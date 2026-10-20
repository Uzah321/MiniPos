const isGranted = (value) => value === true
  || value === 1
  || value === "1"
  || String(value || "").toLowerCase() === "true";

export const cashupPermissionFlags = (payload) => (
  payload?.permissions
  || payload?.data?.permissions
  || payload?.data?.data?.permissions
  || {}
);

export const canViewCashupFinancialAmounts = (payload) => {
  const permissions = cashupPermissionFlags(payload);
  return isGranted(
    permissions.can_view_cashup_financial_amounts
    ?? permissions.can_view_expected_cashup_amounts
  );
};


import React from "react";
import ReportViewNavigator from "./ReportViewNavigator";

export const MANAGEMENT_REPORT_KEYS = {
  PROFIT_LOSS: "profit-loss",
  MONTHLY_PROFIT_COMPARISON: "monthly-profit-comparison",
  PRICE_ADJUSTMENT: "price-adjustment-history",
  DISCOUNTS: "discount-report",
  TAX: "tax-report",
};

export const getManagementReportItems = () => [
  {
    key: MANAGEMENT_REPORT_KEYS.PROFIT_LOSS,
    label: "Profit & Loss",
    description: "Turnover, cost of sales, margin and operating result",
  },
  {
    key: MANAGEMENT_REPORT_KEYS.MONTHLY_PROFIT_COMPARISON,
    label: "Monthly Profit Comparison",
    description: "Current month versus prior month sales, cost and profit",
  },
  {
    key: MANAGEMENT_REPORT_KEYS.DISCOUNTS,
    label: "Discount Report",
    description: "Discount value, approval and cashier analysis",
  },
  {
    key: MANAGEMENT_REPORT_KEYS.TAX,
    label: "Tax Reports",
    description: "Tax totals, transaction detail and fiscal audit",
  },
];

export default function ManagementReportNavigator({
  activeKey,
  onNavigate,
  groups = [],
  className = "",
}) {
  const managementGroup = {
    title: "Management reports",
    items: getManagementReportItems(),
    activeKey,
    onChange: (key) => {
      if (key !== activeKey) onNavigate?.(key);
    },
  };

  return (
    <ReportViewNavigator
      groups={[managementGroup, ...groups]}
      className={className}
    />
  );
}

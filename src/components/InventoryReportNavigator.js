import React from "react";
import ReportViewNavigator from "./ReportViewNavigator";

export const LEGACY_STOCK_ADJUSTMENT_REPORT_KEY = "stock-adjustment-history";

const HOSPITALITY_INVENTORY_REPORTS = [
  { key: "dashboard", label: "Stock Dashboard", description: "Inventory summary, alerts and movement activity" },
  { key: "company-balance", label: "Company Balance", description: "Consolidated company stock balance" },
  { key: "branch-balance", label: "Branch Balance", description: "Stock balance by branch and product" },
  { key: "current-balance", label: "Current Balance", description: "Current on-hand, reserved and available stock" },
  { key: "as-of", label: "As-Of Balance", description: "Historical stock balance to a selected date" },
  { key: "movements", label: "Stock Movements", description: "Complete inventory movement ledger" },
  { key: "received", label: "Stock Received", description: "Goods received and stock-in history" },
  { key: "adjustments", label: "Stock Adjustments", description: "Adjustment, stock-take and wastage movement report" },
  {
    key: LEGACY_STOCK_ADJUSTMENT_REPORT_KEY,
    label: "Adjustment Audit History",
    description: "Detailed product stock adjustment audit",
  },
  { key: "valuation", label: "Stock Valuation", description: "Inventory value using product costs" },
  { key: "low-stock", label: "Low Stock", description: "Products below reorder level" },
  { key: "out-of-stock", label: "Out of Stock", description: "Products with no available stock" },
  { key: "negative-stock", label: "Negative Stock", description: "Products below zero stock" },
  { key: "category-summary", label: "Category Summary", description: "Inventory grouped by category" },
  { key: "supplier-summary", label: "Supplier Summary", description: "Inventory grouped by supplier" },
];

export const getHospitalityInventoryReportItems = () => HOSPITALITY_INVENTORY_REPORTS;

// Historical export retained for integrations that still import the old helper name.
export const getRetailInventoryReportItems = getHospitalityInventoryReportItems;

export default function InventoryReportNavigator({ activeKey, onNavigate, className = "" }) {
  return (
    <ReportViewNavigator
      title="Hospitality inventory reports"
      items={HOSPITALITY_INVENTORY_REPORTS}
      activeKey={activeKey}
      onChange={(key) => {
        if (key !== activeKey) onNavigate?.(key);
      }}
      className={className}
    />
  );
}

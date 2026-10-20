import React from "react";
import ReportViewNavigator from "./ReportViewNavigator";

export const HOSPITALITY_REPORT_KEYS = {
  DAILY_SALES: "daily-food-sales-report",
  DETAILED_SALES: "food-industry-sales-report",
  SHIFT: "shift-report",
  CLOSING_STOCK: "closing-stock-movement-report",
  STOCK_ISSUED: "stock-issued-to-front-report",
  STOCK_WASTAGE: "stock-wastage-report",
  MENU_RECIPE_DSR: "menu-recipe-dsr",
  MENU_RECIPE_COST: "menu-item-recipe-cost-report",
};

const SALES_ITEMS = [
  {
    key: HOSPITALITY_REPORT_KEYS.DAILY_SALES,
    label: "Daily Sales",
    description: "Trading-day turnover and recipe cost of sales",
  },
  {
    key: HOSPITALITY_REPORT_KEYS.DETAILED_SALES,
    label: "Food Industry Sales",
    description: "Menu turnover, recipe cost, gross profit and trading-date performance",
  },
  {
    key: HOSPITALITY_REPORT_KEYS.SHIFT,
    label: "Shift Report",
    description: "Shift sales, cashup totals and cashier compliance",
  },
];

const INVENTORY_ITEMS = [
  {
    key: HOSPITALITY_REPORT_KEYS.CLOSING_STOCK,
    label: "Closing Stock Movement",
    description: "Opening, movement and closing stock evidence",
  },
  {
    key: HOSPITALITY_REPORT_KEYS.STOCK_ISSUED,
    label: "Stock Issued to Front",
    description: "BOH issues, opening stock and issue cost",
  },
  {
    key: HOSPITALITY_REPORT_KEYS.STOCK_WASTAGE,
    label: "Stock Wastage",
    description: "Loss quantity, reason and cost impact",
  },
  {
    key: HOSPITALITY_REPORT_KEYS.MENU_RECIPE_DSR,
    label: "Daily Stock Reconciliation",
    description: "Daily recipe consumption and stock reconciliation",
  },
  {
    key: HOSPITALITY_REPORT_KEYS.MENU_RECIPE_COST,
    label: "Menu Recipe Cost",
    description: "Recipe-derived menu cost and gross margin",
  },
];

export const getHospitalityReportItems = (section = "sales") => (
  section === "inventory" ? INVENTORY_ITEMS : SALES_ITEMS
);

export default function HospitalityReportNavigator({
  section = "sales",
  activeKey,
  onNavigate,
  groups = [],
  className = "",
}) {
  const baseGroup = {
    title: section === "inventory" ? "Hospitality inventory reports" : "Hospitality sales reports",
    items: getHospitalityReportItems(section),
    activeKey,
    onChange: (key) => {
      if (key !== activeKey) onNavigate?.(key);
    },
  };

  return <ReportViewNavigator groups={[baseGroup, ...groups]} className={className} />;
}

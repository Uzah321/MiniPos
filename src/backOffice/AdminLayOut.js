import React, { useCallback, useEffect, useMemo, useState } from "react";
import BackOfficeThemeProvider from "./BackOfficeThemeProvider";
import { Box, CircularProgress, MenuItem, Select, Typography } from "@mui/material";
import {
  LogOut,
  Building2,
  MapPin,
  Monitor,
  User,
  Menu,
  X,
  ChevronRight,
  ChevronDown,
  ShieldAlert,
  Maximize2,
  Minimize2,
  Home,
  ShoppingCart,
  Boxes,
  Truck,
  BarChart3,
  Factory,
  Settings,
  Plug,
  FolderOpen,
  ArrowLeft,
  FileText,
  Users,
  Package,
  Calculator,
  Search,
  ArrowUpRight,
} from "lucide-react";
import { Link, Outlet, useLocation } from "react-router-dom";
import { useAdminAuth } from "./context/AdminAuthContext";
import { getAuthorisedCompany, logout as logoutRemote } from "../services/AuthenticationService";
import { useWorkspace } from "../context/WorkspaceContext";
import useBusinessMode from "../hooks/useBusinessMode";
import { getProfitAndLossReport, getSalesDashboard } from "../services/SalesReportService";
import { getCurrencies } from "../services/CurrencyService";
import { getCurrentTradingDay } from "../services/TradingDayEndEnterpriseService";
import {
  isPlatformAdministrator,
  isUnscopedPlatformAdministrator,
  isSuperAdministrator,
} from "../utils/workspaceAccess";
import { PLATFORM_FALLBACK_LOGO } from "../utils/hospitalityHierarchy";

import UserManagementFullModal from "./pages/admin/UserManagment";
import Roles from "./pages/admin/Roles";
import GeneralSettings from "./pages/admin/GeneralSettings";
import ShiftConfiguration from "./pages/admin/ShiftConfiguration";
import CreateSaleModal from "./pages/sales/CreateSale";
import CompanyLicenseModal from "./pages/admin/CompanyLicenseModal";
import BranchManagementModal from "./pages/admin/BranchManagementModal";
import PlatformCompanyManagementModal from "./pages/admin/PlatformCompanyManagementModal";
import HospitalityStructureManagementModal from "./pages/admin/HospitalityStructureManagementModal";
import AuthenticationAdministrationModal from "./pages/admin/AuthenticationAdministrationModal";
import ProductCategoryModal from "./pages/product/ProductCategoryModal";
import SupplierModal from "./pages/purchases/SupplierModal";
import ProductManagementFullModal from "./pages/product/ProductManagementFullModal";
import InventoryManagementModal from "./boh/inventory/InventoryManagementModal";
import PriceLevelModal from "./pages/product/PriceLevelModal";
import RolePriceLevelAssignModal from "./pages/product/RolePriceLevelAssignModal";
import ProductPriceAdjustmentModal from "./pages/product/ProductPriceAdjustmentModal";
import ProductPriceAdjustmentHistoryModal from "./pages/report/ProductPriceAdjustmentHistoryModal";
import ProductPriceListModal from "./pages/product/ProductPriceListModal";
import ReceiveStockModal from "./pages/purchases/ReceiveStockModal";
import BulkStockAdjustmentModal from "./pages/product/BulkStockAdjustmentModal";
import StockAdjustmentHistoryModal from "./pages/report/StockAdjustmentHistoryModal";
import CurrencyManagementModal from "./pages/admin/CurrencyManagementModal";
import PaymentTypeManagementModal from "./pages/admin/PaymentTypeManagementModal";
import TillManagementModal from "./pages/admin/TillManagementModal";
import DeploymentSyncModal from "./pages/admin/DeploymentSyncModal";
import PrinterManagementModal from "./pages/intergration/PrinterManagementModal";
import SyncStatusBadge from "../components/SyncStatusBadge";
import PosReportsModal from "./pages/report/PosReportsModal";
import StockReportsModal from "./pages/report/StockReportsModal";
import ReceiptActionsModal from "./pages/sales/ReceiptActionsModal";
import ReceiptRegisterModal from "./pages/sales/ReceiptRegisterModal";
import ProductImportModal from "./pages/product/ProductImportModal";
import StockTakeModal from "./pages/product/StockTakeModal";
import StockTransferModal from "./pages/product/StockTransferModal";
import EnterpriseShiftBanner from "../components/EnterpriseShiftBanner";
import EnterpriseCashupModal from "../components/EnterpriseCashupModal";
import CancelledReceiptsModal from "./pages/sales/CancelledReceiptsModal";
import ProductBarcodePrintModal from "./pages/product/ProductBarcodePrintModal";
import DiscountManagementModal from "./pages/discounts/DiscountManagementModal";
import DiscountReportsModal from "./pages/report/DiscountReportsModal";
import QuotationDeskModal from "./pages/quotation/QuotationDeskModal";
import TaxProductTaxManagementModal from "./pages/product/TaxProductTaxManagementModal";
import ZimraFiscalisationModal from "./pages/intergration/ZimraFiscalisationModal";
import EnterpriseTaxReportModal from "./pages/report/EnterpriseTaxReportPage";
import ProfitAndLossReport from "./pages/report/ProfitAndLossReport";
import MonthlyProfitComparisonReport from "./pages/report/MonthlyProfitComparisonReport";
import PurchaseManagementModal from "./pages/purchases/PurchaseManagementModal";

import buildInfo from "../buildInfo.json";
import ManufacturingRecipeRegisterModal from "./pages/manufacturing/ManufacturingRecipeRegisterModal";
// import ManufacturingRecipeFormModal from "./pages/manufacturing/ManufacturingRecipeFormModal";
import ManufacturingPlanRegisterModal from "./pages/manufacturing/ManufacturingPlanRegisterModal";
import ManufacturingPlanFormModal from "./pages/manufacturing/ManufacturingPlanFormModal";
// import ManufacturingMaterialRequirementsModal from "./pages/manufacturing/ManufacturingMaterialRequirementsModal";
import ManufacturingOrderRegisterModal from "./pages/manufacturing/ManufacturingOrderRegisterModal";
import ManufacturingOrderFormModal from "./pages/manufacturing/ManufacturingOrderFormModal";
import ManufacturingOrderWorkflowModal from "./pages/manufacturing/ManufacturingOrderWorkflowModal";
// import ManufacturingProductionReportModal from "./pages/manufacturing/ManufacturingProductionReportModal";
// import ManufacturingCostReportModal from "./pages/manufacturing/ManufacturingCostReportModal";
// import ManufacturingMaterialUsageReportModal from "./pages/manufacturing/ManufacturingMaterialUsageReportModal";
// import ManufacturingWastageReportModal from "./pages/manufacturing/ManufacturingWastageReportModal";
// import ManufacturingVarianceReportModal from "./pages/manufacturing/ManufacturingVarianceReportModal";
import ManufacturingRecipeManagementModal from "./pages/manufacturing/ManufacturingRecipeManagementModal";
import ManufacturingPlanManagementModal from "./pages/manufacturing/ManufacturingPlanManagementModal";
// import ManufacturingOrderWorkflowModal from "./pages/manufacturing/ManufacturingOrderWorkflowModal";
import ManufacturingReportsModal from "./pages/manufacturing/ManufacturingReportsModal";
import CreditCustomerManagementModal from "./pages/sales/CreditCustomerManagementModal";
import {
  BackStockRegisterModal,
  FrontStockRegisterModal,
} from "./boh/inventory/StockAreaRegisterModal";
import IssueStockToFrontModal from "./boh/inventory/IssueStockToFrontModal";
import IssuedStockModal from "./boh/inventory/IssuedStockModal";
import DailyFrontStockClosingModal from "./boh/inventory/DailyFrontStockClosingModal";
import StockWastageModal from "./boh/inventory/StockWastageModal";
import MenuItemManagementModal from "./boh/menuItems/MenuItemManagementModal";
import MenuItemRecipeCostReportModal from "./boh/menuItems/MenuItemRecipeCostReportModal";
import MenuItemPrepMessageConfigurationModal from "./boh/menuItems/MenuItemPrepMessageConfigurationModal";
import MenuItemModifierConfigurationModal from "./boh/menuItems/MenuItemModifierConfigurationModal";
import MenuRecipeDailyStockReconciliationModal from "./boh/reports/MenuRecipeDailyStockReconciliationModal";
import ClosingStockMovementReportModal from "./boh/reports/ClosingStockMovementReportModal";
import StockIssuedToFrontReportModal from "./boh/reports/StockIssuedToFrontReportModal";
import StockWastageReportModal from "./boh/reports/StockWastageReportModal";
import FoodIndustrySalesReportModal from "./boh/reports/FoodIndustrySalesReportModal";
import DailyFoodIndustrySalesReportModal from "./boh/reports/DailyFoodIndustrySalesReportModal";
import ShiftReportModal from "./boh/reports/ShiftReportModal";
import FoodRecipeBomModal from "./boh/foodManufacturing/FoodRecipeBomModal";
import FoodManufacturingPlanningModal from "./boh/foodManufacturing/FoodManufacturingPlanningModal";
import FoodManufacturingBatchModal from "./boh/foodManufacturing/FoodManufacturingBatchModal";
import FoodManufacturingReportsModal from "./boh/foodManufacturing/FoodManufacturingReportsModal";
import BackStockMonthEndStockTakeModal from "./boh/monthend/BackStockMonthEndStockTakeModal";
import HospitalityMonthEndModal from "./boh/monthend/HospitalityMonthEndModal";
import DayEndEnterpriseGuardModal from "./boh/dayend/DayEndEnterpriseGuardModal";
import HospitalityTableServiceModal from "./boh/hospitality/HospitalityTableServiceModal";

const normalizePermissionName = (value) =>
  String(value || "")
    .trim()
    .toLowerCase()
    .replaceAll(" ", "_")
    .replaceAll("-", "_");

const extractPermissionNames = (user) => {
  const buckets = [
    user?.role?.permissions,
    user?.permissions,
    user?.role_permissions,
    user?.permission_names,
    user?.data?.role?.permissions,
    user?.data?.permissions,
  ];

  const names = new Set();

  buckets.forEach((bucket) => {
    if (!bucket) return;

    if (Array.isArray(bucket)) {
      bucket.forEach((item) => {
        if (typeof item === "string") {
          names.add(normalizePermissionName(item));
          return;
        }

        const permissionName =
          item?.name ||
          item?.permission_name ||
          item?.key ||
          item?.slug ||
          item?.permission?.name;

        if (permissionName) {
          names.add(normalizePermissionName(permissionName));
        }
      });

      return;
    }

    if (typeof bucket === "object") {
      Object.entries(bucket).forEach(([key, value]) => {
        if (value === true || value === 1 || value === "1" || value === "true") {
          names.add(normalizePermissionName(key));
        }
      });
    }
  });

  return names;
};

const userIsSuperAdmin = isSuperAdministrator;
const userIsPlatformAdmin = isPlatformAdministrator;

const hasMenuPermission = (user, permission) => {
  if (!permission) return true;
  if (userIsSuperAdmin(user)) return true;

  const permissions = extractPermissionNames(user);

  if (Array.isArray(permission)) {
    return permission.some((item) => permissions.has(normalizePermissionName(item)));
  }

  return permissions.has(normalizePermissionName(permission));
};

const buildVersion = buildInfo?.displayVersion || buildInfo?.version || "1.0.0";

const resolveTradingDayDate = (payload) =>
  payload?.trading_date ||
  payload?.current_trading_date ||
  payload?.date ||
  payload?.data?.trading_date ||
  payload?.data?.current_trading_date ||
  null;

const filterNavigationByPermission = (items, user) => {
  return (items || [])
    .map((item) => {
      if (item.superAdminOnly && !userIsSuperAdmin(user)) return null;
      if (item.platformAdminOnly && !userIsPlatformAdmin(user)) return null;

      const allowed = (item.platformAdminAllowed && userIsPlatformAdmin(user))
        || hasMenuPermission(user, item.permission);

      if (!allowed) return null;

      if (Array.isArray(item.children) && item.children.length) {
        const children = filterNavigationByPermission(item.children, user);

        if (!children.length && !item.href && !item.modal) return null;

        return {
          ...item,
          children,
        };
      }

      return item;
    })
    .filter(Boolean);
};

const getLeafCount = (item) => {
  if (!Array.isArray(item?.children) || item.children.length === 0) return 1;

  return item.children.reduce((sum, child) => sum + getLeafCount(child), 0);
};

const getTileIcon = (item) => {
  const name = normalizePermissionName(item?.name);

  if (name.includes("sales")) return ShoppingCart;
  if (name.includes("product") || name.includes("stock") || name.includes("inventory")) return Boxes;
  if (name.includes("purchase") || name.includes("supplier") || name.includes("receive")) return Truck;
  if (name.includes("report") || name.includes("profit") || name.includes("tax")) return BarChart3;
  if (name.includes("manufacturing") || name.includes("production") || name.includes("recipe")) return Factory;
  if (name.includes("admin") || name.includes("setting") || name.includes("currency") || name.includes("till")) return Settings;
  if (name.includes("integration") || name.includes("printer") || name.includes("fiscal")) return Plug;
  if (name.includes("customer") || name.includes("user") || name.includes("employee")) return Users;
  if (name.includes("price") || name.includes("cash") || name.includes("payment")) return Calculator;
  if (Array.isArray(item?.children) && item.children.length > 0) return FolderOpen;

  return FileText;
};

const resolveNodeFromPath = (navigation, path) => {
  let items = navigation || [];
  let node = null;

  path.forEach((name) => {
    node = items.find((item) => item.name === name) || null;
    items = node?.children || [];
  });

  return node;
};

const AdminTileLaunchpad = ({
  navigation,
  activePath,
  setActivePath,
  onOpenItem,
  businessMode,
  platformOnly = false,
}) => {
  const [query, setQuery] = useState("");
  const [expanded, setExpanded] = useState(() => new Set());
  const [selectedName, setSelectedName] = useState("Overview");

  const searchableItems = useMemo(() => {
    const rows = [];
    const visit = (items, path = []) => {
      (items || []).forEach((item) => {
        const nextPath = [...path, item.name];
        if (item.children?.length) visit(item.children, nextPath);
        else rows.push({ ...item, path: nextPath });
      });
    };
    visit(navigation);
    return rows;
  }, [navigation]);

  const searchResults = query.trim()
    ? searchableItems.filter((item) => item.path.join(" ").toLowerCase().includes(query.trim().toLowerCase())).slice(0, 30)
    : [];

  const toggleSection = (key, path) => {
    setExpanded((current) => {
      const next = new Set(current);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });
    setActivePath(path);
    setSelectedName(path[path.length - 1] || "Overview");
  };

  const openLeaf = (item) => {
    setSelectedName(item.name);
    onOpenItem(item);
  };

  const renderNavigation = (items, path = [], depth = 0) => (items || []).map((item) => {
    const nextPath = [...path, item.name];
    const key = nextPath.join(" / ");
    const hasChildren = Array.isArray(item.children) && item.children.length > 0;
    const isExpanded = expanded.has(key);
    const rowClass = `flex min-h-10 w-full items-center gap-2 border-l-2 pr-3 text-left text-[12px] transition ${selectedName === item.name ? "border-[#069494] bg-[#F1F8F7] text-[#008585]" : "border-transparent text-[#485353] hover:bg-[#F4F6F7]"}`;
    const rowStyle = { paddingLeft: `${14 + depth * 16}px` };

    if (!hasChildren && item.href) {
      return <Link key={key} to={item.href} onClick={() => setSelectedName(item.name)} className={rowClass} style={rowStyle}><span className="h-1.5 w-1.5 shrink-0 bg-[#ACB4B4]" /><span className="min-w-0 flex-1 truncate">{item.name}</span></Link>;
    }

    return <div key={key}>
      <button type="button" onClick={() => hasChildren ? toggleSection(key, nextPath) : openLeaf(item)} className={rowClass} style={rowStyle}>
        {hasChildren ? (isExpanded ? <ChevronDown className="h-3.5 w-3.5 shrink-0" /> : <ChevronRight className="h-3.5 w-3.5 shrink-0" />) : <span className="h-1.5 w-1.5 shrink-0 bg-[#ACB4B4]" />}
        <span className="min-w-0 flex-1 truncate">{item.name}</span>
        {hasChildren && <span className="text-[10px] text-[#929C9B]">{getLeafCount(item)}</span>}
      </button>
      {hasChildren && isExpanded && <div>{renderNavigation(item.children, nextPath, depth + 1)}</div>}
    </div>;
  });

  return (
    <div className="flex h-full min-h-0 bg-white text-[#293232]">
      <aside className="hidden w-[286px] shrink-0 border-r border-[#D9DDDD] bg-white lg:flex lg:flex-col">
        <div className="border-b border-[#D9DDDD] px-5 py-4">
          <div className="text-[16px] font-medium text-[#212929]">Back Office</div>
          <div className="mt-1 text-[11px] text-[#7A8483]">
            {platformOnly ? "Platform administration" : `${businessMode} workspace`}
          </div>
        </div>
        <label className="mx-3 mt-3 flex h-10 items-center border-b border-[#B0B7B7] bg-[#F6F7F8] px-3 focus-within:border-[#069494]">
          <Search className="h-4 w-4 text-[#6B7675]" />
          <input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search" className="h-full min-w-0 flex-1 bg-transparent px-3 text-[13px] font-normal outline-none" />
        </label>
        <nav className="mt-3 min-h-0 flex-1 overflow-y-auto pb-5">
          <button type="button" onClick={() => { setActivePath([]); setSelectedName("Overview"); setQuery(""); }} className={`flex min-h-10 w-full items-center gap-2 border-l-2 px-4 text-left text-[12px] ${selectedName === "Overview" ? "border-[#069494] bg-[#F1F8F7] text-[#008585]" : "border-transparent text-[#485353] hover:bg-[#F4F6F7]"}`}><span className="h-1.5 w-1.5 bg-[#069494]" />Overview</button>
          {query.trim() ? searchResults.map((item) => item.href
            ? <Link key={item.path.join("/")} to={item.href} className="flex min-h-10 items-center border-l-2 border-transparent px-4 text-[12px] text-[#485353] hover:bg-[#F4F6F7]">{item.name}</Link>
            : <button key={item.path.join("/")} type="button" onClick={() => openLeaf(item)} className="flex min-h-10 w-full items-center border-l-2 border-transparent px-4 text-left text-[12px] text-[#485353] hover:bg-[#F4F6F7]">{item.name}</button>) : renderNavigation(navigation)}
        </nav>
      </aside>

      <section className="flex min-w-0 flex-1 flex-col bg-[#FCFCFC]">
        <div className="flex min-h-[68px] shrink-0 items-center justify-between border-b border-[#D9DDDD] bg-white px-5">
          <div>
            <div className="text-[15px] font-medium text-[#212929]">{query.trim() ? "Search results" : selectedName}</div>
            <div className="mt-0.5 text-[11px] text-[#7C8685]">{query.trim() ? `${searchResults.length} matching functions` : activePath.length ? activePath.join(" / ") : "Management overview"}</div>
          </div>
          <div className="flex items-center gap-0 border border-[#D3D8D8] bg-white text-[12px] text-[#586261]">
            <div className="border-r border-[#D3D8D8] px-4 py-2.5">
              {platformOnly ? "No company selected" : "All branches"}
            </div>
            <div className="px-4 py-2.5">{platformOnly ? "Platform" : businessMode}</div>
          </div>
        </div>

        <div className="min-h-0 flex-1 overflow-y-auto p-5">
          {query.trim() ? <div className="mx-auto max-w-[1120px] border border-[#D9DDDD] bg-white">
            <div className="flex min-h-12 items-center justify-between border-b border-[#D9DDDD] px-4"><div className="text-[14px] font-medium text-[#293232]">Functions</div><div className="text-[11px] text-[#899291]">{searchResults.length} matches</div></div>
            {searchResults.length ? searchResults.map((item) => {
              const row = <><span className="min-w-0 flex-1 truncate">{item.name}</span><span className="hidden text-[11px] text-[#8C9494] md:block">{item.path.slice(0, -1).join(" / ")}</span><ChevronRight className="h-4 w-4 text-[#899291]" /></>;
              return item.href ? <Link key={item.path.join("/")} to={item.href} className="flex min-h-12 items-center gap-4 border-b border-[#E4E7E9] px-4 text-[13px] text-[#404A49] hover:bg-[#F5F8F9]">{row}</Link> : <button key={item.path.join("/")} type="button" onClick={() => openLeaf(item)} className="flex min-h-12 w-full items-center gap-4 border-b border-[#E4E7E9] px-4 text-left text-[13px] text-[#404A49] hover:bg-[#F5F8F9]">{row}</button>;
            }) : <div className="px-4 py-12 text-center text-[13px] text-[#7C8685]">No matching function.</div>}
          </div> : platformOnly ? (
            <div className="mx-auto max-w-[760px] border border-[#BEDAD9] bg-white shadow-sm">
              <div className="border-b-4 border-[#069494] bg-[#004142] px-6 py-5 text-white">
                <div className="text-[10px] font-black uppercase tracking-[0.18em] text-[#6DDBDB]">System Owner</div>
                <div className="mt-1 text-[20px] font-semibold">Platform Administration</div>
              </div>
              <div className="p-6">
                <p className="text-[13px] leading-6 text-[#556261]">
                  No company is selected. Platform management is available in this session.
                  Choose a management area from the left navigation.
                </p>
                <button
                  type="button"
                  onClick={() => {
                    const companyItem = navigation.find((item) => item.modal === "company-management");
                    if (companyItem) onOpenItem(companyItem);
                  }}
                  className="mt-5 h-11 bg-[#069494] px-6 text-[11px] font-black uppercase tracking-[0.12em] text-white hover:bg-[#008585]"
                >
                  Open Company Management
                </button>
              </div>
            </div>
          ) : <SalesManagementDashboard businessMode={businessMode} />}
        </div>
      </section>
    </div>
  );
};

const dashboardPayload = (response) => response?.data?.data || response?.data || response || {};
const dashboardMoney = (value, currency = "") => `${currency ? `${currency} ` : ""}${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const dashboardBoolean = (value) => value === true || value === 1 || value === "1" || String(value).toLowerCase() === "true";

function SalesManagementDashboard({ businessMode }) {
  const [period, setPeriod] = useState("today");
  const [payload, setPayload] = useState({});
  const [profitLoss, setProfitLoss] = useState({});
  const [branches, setBranches] = useState([]);
  const [selectedBranch, setSelectedBranch] = useState("");
  const [currencies, setCurrencies] = useState([]);
  const [selectedCurrency, setSelectedCurrency] = useState("");
  const [currencyReady, setCurrencyReady] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [comparisonOpen, setComparisonOpen] = useState(false);

  const loadDashboard = async () => {
    try {
      setLoading(true);
      setError("");
      const now = new Date();
      const to = now.toISOString().slice(0, 10);
      const fromDate = new Date(now);
      if (period === "week") fromDate.setDate(now.getDate() - 6);
      if (period === "month") fromDate.setDate(1);
      const from = fromDate.toISOString().slice(0, 10);
      const params = {
        date_from: from,
        date_to: to,
        ...(selectedBranch ? { branch_id: selectedBranch } : {}),
        ...(selectedCurrency ? { currency_code: selectedCurrency } : {}),
        ...(businessMode ? { business_mode: businessMode } : {}),
      };
      const [dashboardResult, profitResult] = await Promise.all([
        getSalesDashboard(params),
        getProfitAndLossReport({ ...params, per_page: 25 }),
      ]);
      setPayload(dashboardPayload(dashboardResult));
      setProfitLoss(dashboardPayload(profitResult));
    } catch (requestError) {
      setError(requestError?.response?.data?.message || requestError?.message || "Sales dashboard is unavailable.");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    Promise.allSettled([getAuthorisedCompany(), getCurrencies({ per_page: 300, is_active: true })]).then(([companyResult, currencyResult]) => {
      const result = companyResult.status === "fulfilled" ? companyResult.value : {};
      const source = result?.data?.company?.branches || result?.company?.branches || result?.data?.branches || result?.branches || [];
      setBranches(Array.isArray(source) ? source : []);
      const currencyPayload = currencyResult.status === "fulfilled" ? currencyResult.value?.data || currencyResult.value || {} : {};
      const currencyRows = currencyPayload?.data?.data || currencyPayload?.data || currencyPayload?.currencies || currencyPayload;
      const rows = Array.isArray(currencyRows) ? currencyRows : [];
      const baseCurrency = rows.find((row) => dashboardBoolean(row?.is_base_currency)) || rows[0] || null;
      setCurrencies(rows);
      setSelectedCurrency((current) => current || baseCurrency?.code || baseCurrency?.currency_code || "");
      setCurrencyReady(true);
    });
  }, []);
  useEffect(() => { if (currencyReady && selectedCurrency) loadDashboard(); }, [period, businessMode, selectedBranch, selectedCurrency, currencyReady]);

  const summary = payload?.base_summary || payload?.summary || {};
  const management = payload?.management_summary || profitLoss?.summary || {};
  const plSummary = profitLoss?.summary || management;
  const costing = payload?.costing || profitLoss?.costing || {};
  const currency = selectedCurrency || management.currency_code || management.base_currency_code || summary.base_currency_code || summary.currency_code || "";
  const totalSales = management.sales_value ?? summary.base_net_sales ?? summary.net_sales ?? 0;
  const invoices = summary.transaction_count ?? summary.invoice_count ?? 0;
  const averageBasket = summary.average_basket ?? (invoices ? Number(totalSales) / Number(invoices) : 0);
  const paymentRows = Array.isArray(payload?.payment_summary) ? payload.payment_summary : [];
  const currencyRows = Array.isArray(payload?.currency_summary) ? payload.currency_summary : [];
  const selectedCurrencyRow = selectedCurrency ? currencyRows.find((row) => String(row.currency_code).toUpperCase() === String(selectedCurrency).toUpperCase()) : null;
  const baseCurrency = management.base_currency_code || selectedCurrencyRow?.base_currency_code || summary.base_currency_code || "";
  const tax = Number(selectedCurrency ? (summary.tax ?? selectedCurrencyRow?.tax ?? 0) : (summary.base_tax ?? 0));
  const invoiced = Number(selectedCurrency ? (selectedCurrencyRow?.invoiced_value ?? management.transaction_sales_value ?? totalSales) : (management.invoiced_value ?? summary.base_invoiced_value ?? totalSales));
  const discounts = Number(selectedCurrency ? (summary.discounts ?? selectedCurrencyRow?.discount ?? 0) : (summary.base_discounts ?? 0));
  const cost = Number(management.cost_value ?? plSummary.cost_value ?? 0);
  const grossProfit = Number(management.estimated_profit ?? plSummary.gross_profit ?? (Number(totalSales) - cost));
  const cashouts = Number(plSummary.cashout_total ?? profitLoss?.cashouts?.summary?.cashout_total ?? 0);
  const cashins = Number(plSummary.cashin_total ?? profitLoss?.cashouts?.summary?.cashin_total ?? 0);
  const netProfit = Number(plSummary.net_profit_after_cashouts ?? (grossProfit - cashouts + cashins));
  const costPercent = Number(totalSales) > 0 ? (cost / Number(totalSales)) * 100 : 0;
  const marginPercent = Number(totalSales) > 0 ? (grossProfit / Number(totalSales)) * 100 : 0;
  const trendSource = period === "today" && Array.isArray(payload?.hourly_sales) ? payload.hourly_sales : (Array.isArray(payload?.daily_sales) ? payload.daily_sales : []);
  const trendRows = trendSource.reduce((rows, row) => {
    const key = row.sale_date || row.hour || row.period || rows.length;
    const current = rows.get(key) || { sales: 0, invoices: 0 };
    current.sales += Number(row.total ?? row.money_received_value ?? row.sales_value ?? row.base_total ?? 0);
    current.invoices += Number(row.transaction_count || 0);
    rows.set(key, current);
    return rows;
  }, new Map());
  const trend = Array.from(trendRows.values());
  const salesTrend = trend.map((row) => row.sales);
  const invoiceTrend = trend.map((row) => row.invoices);
  const basketTrend = trend.map((row) => row.invoices ? row.sales / row.invoices : 0);
  const selectedBranchLabel = selectedBranch ? (branches.find((row) => String(row.id) === String(selectedBranch))?.branch_name || branches.find((row) => String(row.id) === String(selectedBranch))?.name || "Selected branch") : "All branches selected";
  const periodLabel = period === "today" ? "Today" : period === "week" ? "Last 7 days" : "This month";
  const currencyOptions = currencies.map((row) => row.code || row.currency_code).filter(Boolean);
  const costingLabel = costing.business_mode === "hospitality" || String(businessMode).toLowerCase().includes("hospital") || String(businessMode).toLowerCase().includes("food")
    ? "Recipe / BOM ingredient cost"
    : "Weighted average product cost";
  const activityComparison = payload?.activity_comparison || {};

  return <Box sx={{ width: "100%", maxWidth: 980, mx: "auto", pb: 4 }}>
    <Box sx={{ mb: 2.5, borderBottom: "2px solid #00b1b1", width: "fit-content", minWidth: 290, pb: 1 }}><Typography sx={{ fontSize: 15, fontWeight: 400, color: "#069494", letterSpacing: .2 }}>MANAGEMENT OVERVIEW</Typography></Box>
    <Box sx={{ display: "grid", gridTemplateColumns: { xs: "1fr", sm: "repeat(3, minmax(0, 1fr))" }, gap: 1.5, mb: 2 }}>
      <OverviewSelector title="Branch" value={selectedBranchLabel}><Select displayEmpty size="small" value={selectedBranch} onChange={(event) => setSelectedBranch(event.target.value)}><MenuItem value="">All branches</MenuItem>{branches.map((branch) => <MenuItem key={branch.id} value={String(branch.id)}>{branch.branch_name || branch.name || branch.code || "Branch"}</MenuItem>)}</Select></OverviewSelector>
      <OverviewSelector title="Period" value={periodLabel}><Select size="small" value={period} onChange={(event) => setPeriod(event.target.value)}><MenuItem value="today">Today</MenuItem><MenuItem value="week">Last 7 days</MenuItem><MenuItem value="month">This month</MenuItem></Select></OverviewSelector>
      <OverviewSelector title="Currency" value={selectedCurrency || "Loading base currency"}><Select size="small" value={selectedCurrency} onChange={(event) => setSelectedCurrency(event.target.value)} disabled={!currencyOptions.length}>{currencyOptions.map((codeValue) => <MenuItem key={codeValue} value={codeValue}>{codeValue}{currencies.some((row) => (row.code || row.currency_code) === codeValue && dashboardBoolean(row.is_base_currency)) ? " · Base" : ""}</MenuItem>)}</Select></OverviewSelector>
    </Box>
    <Box sx={{ mb: 2, border: "1px solid #bedad9", borderLeft: "4px solid #067c7c", bgcolor: "#f4fafa", px: 1.75, py: 1.25 }}>
      <Typography sx={{ fontSize: 12, fontWeight: 700, color: "#004d4d" }}>Cash-basis management reporting</Typography>
      <Typography sx={{ mt: .35, fontSize: 11.5, lineHeight: 1.55, color: "#425958" }}>
        Only {currency || "the selected currency"} sales are included. Cost of sales is converted into that sale currency using each sale's saved exchange rate, while base equivalents remain available separately.
      </Typography>
    </Box>
    {error && <Box sx={{ mb: 2, border: "1px solid #fda29b", bgcolor: "#fef3f2", color: "#b42318", p: 1.5, fontSize: 12 }}>{error}</Box>}
    {loading ? <Box sx={{ minHeight: 360, display: "flex", alignItems: "center", justifyContent: "center" }}><CircularProgress size={28} /></Box> : <>
      <Box sx={{ display: "grid", gridTemplateColumns: { xs: "1fr", md: "1fr 1fr" }, gap: 1.5, alignItems: "stretch" }}>
        <FinancialStatement title="Cash-basis turnover" headline={dashboardMoney(totalSales, currency)} subtitle={`${Number(invoices || 0).toLocaleString()} transactions · ${currency || "base currency"}`} rows={[
          ...paymentRows.map((row) => [row.payment_name || row.money_code || "Payment", dashboardMoney(row.amount ?? row.base_amount, row.currency_code || currency)]),
          ["Total payments", dashboardMoney(totalSales, currency), true],
          ["Invoiced sales", dashboardMoney(invoiced, currency)],
          ["Less: Discounts", dashboardMoney(discounts, currency)],
          ["Less: Tax", dashboardMoney(tax, currency)],
          ["Turnover exclusive", dashboardMoney(Math.max(0, Number(totalSales) - tax), currency), true],
        ]} />
        <FinancialStatement title="Cost of Sales" headline={`${costPercent.toFixed(2)}%`} subtitle={`${costingLabel} · ${currency || "base currency"}`} rows={[
          ["Actual sales", dashboardMoney(totalSales, currency), true],
          ["Less: Cost of sales", dashboardMoney(cost, currency)],
          ["Gross profit", dashboardMoney(grossProfit, currency), true, `${marginPercent.toFixed(2)}%`],
          ["Less: Operating cashouts", dashboardMoney(cashouts, currency)],
          ["Add: Cash movements in", dashboardMoney(cashins, currency)],
          ["Net operating result", dashboardMoney(netProfit, currency), true],
          ["Voided receipts", Number(summary.void_count || 0).toLocaleString()],
          ["Refunds", Number(summary.refund_count || 0).toLocaleString()],
        ]} />
      </Box>
      <Box sx={{ mt: 1.5, display: "grid", gridTemplateColumns: { xs: "1fr", md: "repeat(3, 1fr)" }, gap: 1.5 }}>
        <DashboardMetric title="Sales activity" value={dashboardMoney(totalSales, currency)} accent="#64bcbc" points={salesTrend} onClick={() => setComparisonOpen(true)} />
        <DashboardMetric title="Transaction activity" value={Number(invoices || 0).toLocaleString()} accent="#e6b65e" points={invoiceTrend} onClick={() => setComparisonOpen(true)} />
        <DashboardMetric title="Average basket" value={dashboardMoney(averageBasket, currency)} accent="#8fbd57" points={basketTrend} onClick={() => setComparisonOpen(true)} />
      </Box>
      <Box sx={{ mt: 1.5, border: "1px solid #dadede", bgcolor: "#fff", overflow: "hidden" }}>
        <Box sx={{ px: 1.75, py: 1.35, borderBottom: "1px solid #e5e8e8" }}>
          <Typography sx={{ fontSize: 14, fontWeight: 600, color: "#293232" }}>Sales by transaction currency</Typography>
          <Typography sx={{ mt: .3, fontSize: 11, color: "#6B7776" }}>Each selected transaction currency is reported independently, with its base-currency equivalent shown separately.</Typography>
        </Box>
        <Box sx={{ overflowX: "auto" }}>
          <Box sx={{ minWidth: 650 }}>
            <Box sx={{ display: "grid", gridTemplateColumns: "100px 100px 1fr 1fr 1fr", gap: 1, px: 1.75, py: 1, bgcolor: "#f7f9fb", borderBottom: "1px solid #e5e8e8" }}>
              {["Currency", "Sales", "Invoiced", "Money received", `Base equivalent${baseCurrency ? ` (${baseCurrency})` : ""}`].map((heading) => <Typography key={heading} sx={{ fontSize: 10.5, fontWeight: 700, color: "#5B6868", textAlign: heading === "Currency" ? "left" : "right" }}>{heading}</Typography>)}
            </Box>
            {currencyRows.length ? currencyRows.map((row) => <Box key={`${row.currency_code}-${row.base_currency_code}`} sx={{ display: "grid", gridTemplateColumns: "100px 100px 1fr 1fr 1fr", gap: 1, px: 1.75, py: 1.1, borderBottom: "1px solid #edf0f2", alignItems: "center" }}>
              <Typography sx={{ fontSize: 12, fontWeight: 700, color: "#004d4d" }}>{row.currency_code || "—"}</Typography>
              <Typography sx={{ fontSize: 12, textAlign: "right" }}>{Number(row.transaction_count || 0).toLocaleString()}</Typography>
              <Typography sx={{ fontSize: 12, textAlign: "right", fontVariantNumeric: "tabular-nums" }}>{dashboardMoney(row.invoiced_value, row.currency_code)}</Typography>
              <Typography sx={{ fontSize: 12, textAlign: "right", fontVariantNumeric: "tabular-nums" }}>{dashboardMoney(row.money_received_value, row.currency_code)}</Typography>
              <Typography sx={{ fontSize: 12, fontWeight: 600, textAlign: "right", fontVariantNumeric: "tabular-nums" }}>{dashboardMoney(row.base_money_received_value, row.base_currency_code || currency)}</Typography>
            </Box>) : <Typography sx={{ px: 1.75, py: 2, fontSize: 12, color: "#768180" }}>No sales were found for the selected filters.</Typography>}
          </Box>
        </Box>
      </Box>
    </>}
    {comparisonOpen && <SalesActivityComparisonModal comparison={activityComparison} currency={currency} onClose={() => setComparisonOpen(false)} />}
  </Box>;
}

function OverviewSelector({ title, value, children }) {
  return <Box sx={{ minHeight: 82, border: "1px solid #dadede", bgcolor: "#fff", px: 1.75, py: 1.25, position: "relative", "& .MuiInputBase-root": { position: "absolute", inset: 0, opacity: 0, cursor: "pointer" } }}><Typography sx={{ fontSize: 14, fontWeight: 500, color: "#273131" }}>{title}</Typography><Typography sx={{ mt: .5, fontSize: 13, color: "#505a59" }}>{value}</Typography>{children}</Box>;
}

function FinancialStatement({ title, headline, subtitle, rows }) {
  return <Box sx={{ border: "1px solid #dadede", bgcolor: "#fff", minHeight: 430, p: 1.75 }}><Box sx={{ display: "flex", justifyContent: "space-between", alignItems: "baseline" }}><Typography sx={{ fontSize: 14, color: "#293232" }}>{title}</Typography><Typography sx={{ fontSize: 11, color: "#8c9494" }}>{subtitle}</Typography></Box><Typography sx={{ my: 2.5, textAlign: "center", fontSize: 32, fontWeight: 500, color: "#212727" }}>{headline}</Typography><Box>{rows.map(([label, value, strong, aside], index) => <Box key={`${label}-${index}`} sx={{ minHeight: 28, display: "grid", gridTemplateColumns: "1fr auto auto", gap: 1.5, alignItems: "center", borderTop: strong ? "1px solid #d0d5d5" : "none", mt: strong ? .75 : 0, pt: strong ? .75 : 0 }}><Typography sx={{ fontSize: 12, fontWeight: strong ? 600 : 400 }}>{label}</Typography>{aside && <Typography sx={{ fontSize: 11, color: "#79a943" }}>{aside}</Typography>}<Typography sx={{ fontSize: 12, fontWeight: strong ? 600 : 400, fontVariantNumeric: "tabular-nums", textAlign: "right" }}>{value}</Typography></Box>)}</Box></Box>;
}

function DashboardMetric({ title, value, accent, points = [], onClick }) {
  const values = points.length ? points : [0, 0];
  const max = Math.max(...values, 1);
  const step = values.length > 1 ? 100 / (values.length - 1) : 100;
  const line = values.map((point, index) => `${index * step},${36 - (Number(point || 0) / max) * 30}`).join(" ");
  const area = `0,40 ${line} 100,40`;
  return <Box component="button" type="button" onClick={onClick} sx={{ minHeight: 142, border: "1px solid", borderColor: "divider", bgcolor: "background.paper", position: "relative", overflow: "hidden", px: 2, pt: 1.5, textAlign: "left", cursor: onClick ? "pointer" : "default", "&:hover": onClick ? { borderColor: accent, transform: "translateY(-1px)", boxShadow: 2 } : {} }}><Typography sx={{ fontSize: 13, color: "#485353" }}>{title}</Typography><Typography sx={{ mt: 1.5, textAlign: "center", fontSize: 32, lineHeight: 1, fontWeight: 500, color: "#293232" }}>{value}</Typography><Typography sx={{ mt: 1, textAlign: "center", fontSize: 9, color: "#627474", textTransform: "uppercase" }}>Click to compare</Typography><Box component="svg" viewBox="0 0 100 40" preserveAspectRatio="none" sx={{ position: "absolute", left: 0, right: 0, bottom: 0, width: "100%", height: 54, pointerEvents: "none" }}><polygon points={area} fill={accent} opacity="0.34" /><polyline points={line} fill="none" stroke={accent} strokeWidth="1.4" vectorEffect="non-scaling-stroke" /></Box></Box>;
}

function comparisonPeriodLabel(period) {
  const from = period?.date_from || "—";
  const to = period?.date_to || from;
  return from === to ? from : `${from} – ${to}`;
}

function ComparisonBarChart({ metric, currency, currentPeriod, previousPeriod }) {
  const isCount = metric?.key === "transaction_activity";
  const current = Math.max(0, Number(metric?.current || 0));
  const previous = Math.max(0, Number(metric?.previous || 0));
  const axisMaximum = Math.max(current, previous, 1);
  const plotTop = 28;
  const plotBottom = 184;
  const plotHeight = plotBottom - plotTop;
  const currentHeight = (current / axisMaximum) * plotHeight;
  const previousHeight = (previous / axisMaximum) * plotHeight;
  const currentColor = "#067c7c";
  const previousColor = "#99d0cf";
  const formatValue = (value) => isCount ? Math.round(Number(value || 0)).toLocaleString() : dashboardMoney(value, currency);
  const formatAxisValue = (value) => {
    const compact = new Intl.NumberFormat(undefined, { notation: "compact", maximumFractionDigits: 1 }).format(value);
    return isCount ? compact : `${currency ? `${currency} ` : ""}${compact}`;
  };
  const directionColor = metric?.direction === "down" ? "#b42318" : metric?.direction === "up" ? "#027a48" : "#627474";

  return <Box sx={{ bgcolor: "#fff", border: "1px solid #ccd7d7", minWidth: 0 }}>
    <Box sx={{ px: 2, pt: 1.75, display: "flex", justifyContent: "space-between", gap: 1, alignItems: "flex-start" }}>
      <Box>
        <Typography sx={{ fontSize: 13, fontWeight: 800, color: "#1b4b4b" }}>{metric?.label}</Typography>
        <Typography sx={{ mt: .2, fontSize: 10, color: "#627474" }}>Y-axis: {isCount ? "Number of transactions" : `Value (${currency || "reporting currency"})`}</Typography>
      </Box>
      <Typography sx={{ whiteSpace: "nowrap", fontSize: 11, fontWeight: 800, color: directionColor }}>{metric?.direction === "up" ? "▲" : metric?.direction === "down" ? "▼" : "●"} {Math.abs(Number(metric?.change_percent || 0)).toFixed(2)}%</Typography>
    </Box>
    <Box component="svg" role="img" aria-label={`${metric?.label}: current period ${formatValue(current)}, previous month ${formatValue(previous)}`} viewBox="0 0 420 238" sx={{ display: "block", width: "100%", height: { xs: 225, md: 245 } }}>
      <text x="14" y="16" fontSize="9" fill="#627474">{isCount ? "Transactions" : currency || "Value"}</text>
      {[0, .25, .5, .75, 1].map((fraction) => {
        const y = plotBottom - (fraction * plotHeight);
        return <g key={fraction}>
          <line x1="72" y1={y} x2="402" y2={y} stroke={fraction === 0 ? "#839392" : "#dde4e3"} strokeWidth={fraction === 0 ? "1.2" : "1"} strokeDasharray={fraction === 0 ? undefined : "4 4"} />
          <text x="66" y={y + 3} textAnchor="end" fontSize="9" fill="#627474">{formatAxisValue(axisMaximum * fraction)}</text>
        </g>;
      })}
      <line x1="72" y1={plotTop} x2="72" y2={plotBottom} stroke="#839392" strokeWidth="1.2" />
      <rect x="122" y={plotBottom - currentHeight} width="82" height={currentHeight} rx="3" fill={currentColor} />
      <rect x="270" y={plotBottom - previousHeight} width="82" height={previousHeight} rx="3" fill={previousColor} />
      <text x="163" y={Math.max(plotTop - 5, plotBottom - currentHeight - 7)} textAnchor="middle" fontSize="10" fontWeight="700" fill="#004d4d">{formatValue(current)}</text>
      <text x="311" y={Math.max(plotTop - 5, plotBottom - previousHeight - 7)} textAnchor="middle" fontSize="10" fontWeight="700" fill="#536767">{formatValue(previous)}</text>
      <text x="163" y="204" textAnchor="middle" fontSize="10" fontWeight="700" fill="#1b4b4b">Current period</text>
      <text x="311" y="204" textAnchor="middle" fontSize="10" fontWeight="700" fill="#1b4b4b">Previous month</text>
      <text x="163" y="220" textAnchor="middle" fontSize="8.5" fill="#627474">{comparisonPeriodLabel(currentPeriod)}</text>
      <text x="311" y="220" textAnchor="middle" fontSize="8.5" fill="#627474">{comparisonPeriodLabel(previousPeriod)}</text>
      <text x="237" y="236" textAnchor="middle" fontSize="9" fill="#627474">Comparison period</text>
    </Box>
    <Box sx={{ px: 2, pb: 1.5, display: "flex", justifyContent: "center", flexWrap: "wrap", gap: 2 }}>
      <Box sx={{ display: "flex", alignItems: "center", gap: .65 }}><Box sx={{ width: 12, height: 12, bgcolor: currentColor }} /><Typography sx={{ fontSize: 10.5, color: "#425958" }}>Current period</Typography></Box>
      <Box sx={{ display: "flex", alignItems: "center", gap: .65 }}><Box sx={{ width: 12, height: 12, bgcolor: previousColor }} /><Typography sx={{ fontSize: 10.5, color: "#425958" }}>Previous month</Typography></Box>
    </Box>
  </Box>;
}

function SalesActivityComparisonModal({ comparison, currency, onClose }) {
  const metrics = Array.isArray(comparison?.metrics) ? comparison.metrics : [];
  const currentPeriod = comparison?.current_period || {};
  const previousPeriod = comparison?.previous_period || {};
  return <Box sx={{ position: "fixed", inset: 0, zIndex: 14000, bgcolor: "rgba(0,54,54,.62)", display: "flex", alignItems: "center", justifyContent: "center", p: { xs: .75, sm: 2 } }} onClick={onClose}>
    <Box role="dialog" aria-modal="true" aria-labelledby="sales-comparison-title" sx={{ width: "min(1320px, 98vw)", maxHeight: "94vh", overflow: "auto", bgcolor: "#fff", border: "1px solid #069494", boxShadow: 24 }} onClick={(event) => event.stopPropagation()}>
      <Box sx={{ bgcolor: "#004142", color: "#fff", px: { xs: 1.5, sm: 2.5 }, py: 1.75, display: "flex", justifyContent: "space-between", alignItems: "center", gap: 2, position: "sticky", top: 0, zIndex: 2 }}><Box><Typography id="sales-comparison-title" sx={{ fontSize: 17, fontWeight: 800 }}>Sales activity comparison</Typography><Typography sx={{ mt: .25, fontSize: 11.5, opacity: .82 }}>Current: {comparisonPeriodLabel(currentPeriod)} · Previous month: {comparisonPeriodLabel(previousPeriod)}</Typography></Box><Box component="button" aria-label="Close sales activity comparison" type="button" onClick={onClose} sx={{ flex: "0 0 auto", bgcolor: "#b42318", color: "#fff", border: 0, width: 38, height: 38, fontSize: 22, cursor: "pointer" }}>×</Box></Box>
      <Box sx={{ p: { xs: 1, sm: 2.5 }, bgcolor: "#f5f8f8" }}>
        <Box sx={{ mb: 1.5, bgcolor: "#eff7f7", border: "1px solid #bedad9", px: 1.5, py: 1 }}><Typography sx={{ fontSize: 11, color: "#425958" }}>Each graph uses its own labeled scale. The dark bar is the selected current period and the light bar is the equivalent period from the previous month.</Typography></Box>
        <Box sx={{ display: "grid", gridTemplateColumns: { xs: "1fr", lg: "repeat(3, minmax(0, 1fr))" }, gap: 1.5 }}>{metrics.map((metric) => <ComparisonBarChart key={metric.key} metric={metric} currency={currency} currentPeriod={currentPeriod} previousPeriod={previousPeriod} />)}</Box>
        {!metrics.length && <Box sx={{ bgcolor: "#fff", border: "1px solid #d1dada", p: 3, textAlign: "center" }}><Typography sx={{ fontSize: 12, color: "#627474" }}>No comparison data is available for the selected period.</Typography></Box>}
        <Box sx={{ mt: 2, bgcolor: "#fff", border: "1px solid #bedad9", borderLeft: "4px solid #067c7c", p: 2 }}><Typography sx={{ fontSize: 12, fontWeight: 800 }}>AI tracking insights</Typography>{(comparison?.insights || []).map((insight) => <Typography key={insight} sx={{ mt: .75, fontSize: 12, color: "#425958" }}>• {insight}</Typography>)}</Box>
      </Box>
    </Box>
  </Box>;
}

export default function AdminLayout() {
  const [openMenu, setOpenMenu] = useState(null);
  const [openNestedMenus, setOpenNestedMenus] = useState({});
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [isFullscreen, setIsFullscreen] = useState(false);
  const [landingPath, setLandingPath] = useState([]);
  const { mode: businessMode, isHospitality, loading: businessModeLoading } = useBusinessMode();

  const [userManagementOpen, setUserManagementOpen] = useState(false);
  const [roleOpen, setRoleOpen] = useState(false);
  const [licenseOpen, setLicenseOpen] = useState(false);
  const [companyManagementOpen, setCompanyManagementOpen] = useState(false);
  const [hospitalityStructureSection, setHospitalityStructureSection] = useState(null);
  const [authenticationAdministrationOpen, setAuthenticationAdministrationOpen] = useState(false);
  const [openCategoryManagement, setCategoryManagementOpen] = useState(false);
  const [openProductManagement, setProductManagementOpen] = useState(false);
  const [productManagementInitialTab, setProductManagementInitialTab] = useState("register");
  const [inventoryManagementOpen, setInventoryManagementOpen] = useState(false);
  const [inventoryManagementInitialTab, setInventoryManagementInitialTab] = useState("overview");
  const [openSupplierManagement, setSupplierManagementOpen] = useState(false);
  const [openBranchSettings, setBranchSettings] = useState(false);
  const [createSaleOpen, setCreateSaleOpen] = useState(false);
  const [shiftSettingsOpen, setShiftSettingsOpen] = useState(false);
  const [generalSettingsOpen, setSetGeneralSettingsOpen] = useState(false);
  const [priceLevelOpen, setPriceLevelOpen] = useState(false);
  const [rolePriceLevelOpen, setRolePriceLevelOpen] = useState(false);
  const [stockAdjustmentHistoryOpen, setStockAdjustmentHistoryOpen] = useState(false);

  const [priceListOpen, setPriceListOpen] = useState(false);
  const [priceAdjustmentOpen, setPriceAdjustmentOpen] = useState(false);
  const [priceHistoryOpen, setPriceHistoryOpen] = useState(false);
  const [selectedPriceProduct, setSelectedPriceProduct] = useState(null);
  const [selectedProductPrice, setSelectedProductPrice] = useState(null);
  const [priceListRefreshKey, setPriceListRefreshKey] = useState(0);
  const [receiveStockOpen, setReceiveStockOpen] = useState(false);
  const [bulkStockAdjustmentOpen, setBulkStockAdjustmentOpen] = useState(false);
  const [currencyOpen, setCurrencyOpen] = useState(false);
  const [paymentTypeOpen, setPaymentTypeOpen] = useState(false);
  const [tillManagementOpen, setTillManagementOpen] = useState(false);
  const [deploymentSyncOpen, setDeploymentSyncOpen] = useState(false);
  const [isProcessingLogout, setIsProcessingLogout] = useState(false);
  const [printerOpen, setPrinterOpen] = useState(false);
  const [posReportOpen, setPosReportOpen] = useState(false);
  const [selectedPosReport, setSelectedPosReport] = useState(null);
  const [stockReportOpen, setStockReportOpen] = useState(false);
  const [selectedStockReport, setSelectedStockReport] = useState(null);
  const [showReceiptActions, setShowReceiptActions] = useState(false);
  const [receiptRegisterOpen, setReceiptRegisterOpen] = useState(false);
  const [productImportOpen, setProductImportOpen] = useState(false);
  const [stockTakeOpen, setStockTakeOpen] = useState(false);
  const [stockTransferOpen, setStockTransferOpen] = useState(false);
  const [enterpriseCashupOpen, setEnterpriseCashupOpen] = useState(false);
  const [cancelledReceiptOpen, setCancelledReceiptOpen] = useState(false);
  const [productBarcodePrintOpen, setProductBarcodePrintOpen] = useState(false);
  const [permissionDeniedMessage, setPermissionDeniedMessage] = useState("");

  const [discountManagementOpen, setDiscountManagementOpen] = useState(false);
  const [discountReportOpen, setDiscountReportOpen] = useState(false);
  const [qoutationOpen, setQoutationOpen] = useState(false);
  const [taxManagementOpen, setTaxManagementOpen] = useState(false);
  const [fiscalDeviceOpen, setFiscalDeviceOpen] = useState(false);
  const [fiscalReportOpen, setFiscalReportOpen] = useState(false);
  const [profitReportOpen, setProfitReportOpen] = useState(false);
  const [monthlyProfitComparisonOpen, setMonthlyProfitComparisonOpen] = useState(false);

const [manufacturingRecipeRegisterOpen, setManufacturingRecipeRegisterOpen] = useState(false);
const [manufacturingRecipeFormOpen, setManufacturingRecipeFormOpen] = useState(false);

const [manufacturingPlanRegisterOpen, setManufacturingPlanRegisterOpen] = useState(false);
const [manufacturingPlanFormOpen, setManufacturingPlanFormOpen] = useState(false);

const [manufacturingMaterialRequirementsOpen, setManufacturingMaterialRequirementsOpen] = useState(false);

const [manufacturingOrderRegisterOpen, setManufacturingOrderRegisterOpen] = useState(false);
const [manufacturingOrderFormOpen, setManufacturingOrderFormOpen] = useState(false);
const [manufacturingOrderWorkflowOpen, setManufacturingOrderWorkflowOpen] = useState(false);

const [manufacturingProductionReportOpen, setManufacturingProductionReportOpen] = useState(false);
const [manufacturingCostReportOpen, setManufacturingCostReportOpen] = useState(false);
const [manufacturingMaterialUsageReportOpen, setManufacturingMaterialUsageReportOpen] = useState(false);
const [manufacturingWastageReportOpen, setManufacturingWastageReportOpen] = useState(false);
const [manufacturingVarianceReportOpen, setManufacturingVarianceReportOpen] = useState(false);

const [creditCustomerOpen, setCreditCustomerOpen] = useState(false);


const [manufacturingRecipeManagementOpen, setManufacturingRecipeManagementOpen] = useState(false);
const [manufacturingPlanManagementOpen, setManufacturingPlanManagementOpen] = useState(false);
// const [manufacturingOrderWorkflowOpen, setManufacturingOrderWorkflowOpen] = useState(false);
const [manufacturingReportsOpen, setManufacturingReportsOpen] = useState(false);

   const [purchaseManagementOpen, setPurchaseManagementOpen] = useState(false);
   const [purchaseInitialTab, setPurchaseInitialTab] = useState("orders");

  const [dayEndGuardOpen, setDayEndGuardOpen] = useState(false);
  const [monthEndStockTakeOpen, setMonthEndStockTakeOpen] = useState(false);
  const [hospitalityMonthEndOpen, setHospitalityMonthEndOpen] = useState(false);
  const [frontStockManagementOpen, setFrontStockManagementOpen] = useState(false);
  const [backStockManagementOpen, setBackStockManagementOpen] = useState(false);
  const [issueStockToFrontOpen, setIssueStockToFrontOpen] = useState(false);
  const [issuedStockOpen, setIssuedStockOpen] = useState(false);
  const [dailyFrontStockClosingOpen, setDailyFrontStockClosingOpen] = useState(false);
  const [stockWastageOpen, setStockWastageOpen] = useState(false);
  const [stockWastageReportOpen, setStockWastageReportOpen] = useState(false);
  const [menuItemManagementOpen, setMenuItemManagementOpen] = useState(false);
  const [recipeMenuItem, setRecipeMenuItem] = useState(null);
  const [menuItemRecipeCostReportOpen, setMenuItemRecipeCostReportOpen] = useState(false);
  const [prepMessageConfigurationOpen, setPrepMessageConfigurationOpen] = useState(false);
  const [modifierConfigurationOpen, setModifierConfigurationOpen] = useState(false);
  const [hospitalityTableServiceOpen, setHospitalityTableServiceOpen] = useState(false);
  const [menuRecipeDsrOpen, setMenuRecipeDsrOpen] = useState(false);
  const [closingStockMovementReportOpen, setClosingStockMovementReportOpen] = useState(false);
  const [stockIssuedToFrontReportOpen, setStockIssuedToFrontReportOpen] = useState(false);
  const [foodIndustrySalesReportOpen, setFoodIndustrySalesReportOpen] = useState(false);
  const [dailyFoodSalesReportOpen, setDailyFoodSalesReportOpen] = useState(false);
  const [shiftReportOpen, setShiftReportOpen] = useState(false);
  const [foodRecipeBomOpen, setFoodRecipeBomOpen] = useState(false);
  const [foodManufacturingPlanningOpen, setFoodManufacturingPlanningOpen] = useState(false);
  const [foodManufacturingBatchOpen, setFoodManufacturingBatchOpen] = useState(false);
  const [foodManufacturingReportsOpen, setFoodManufacturingReportsOpen] = useState(false);
  const [currentTradingDay, setCurrentTradingDay] = useState(null);

  const { user, logout: clearAdminSession } = useAdminAuth();
  const { company: workspaceCompany } = useWorkspace();
  const { pathname } = useLocation();
  const platformOnly = isUnscopedPlatformAdministrator(user);
  const activeCompany = platformOnly
    ? null
    : workspaceCompany || user?.selectedCompany || user?.company || null;
  const activeCompanyId = platformOnly
    ? ""
    : activeCompany?.id || user?.selectedCompanyId || user?.company_id || "";
  const activeCompanyName = platformOnly
    ? "Platform Administration"
    : activeCompany?.company_name ||
      activeCompany?.name ||
      user?.company_name ||
      "Company not selected";

  useEffect(() => {
    if (platformOnly) setCompanyManagementOpen(true);
  }, [platformOnly]);
  const activeBranchId =
    user?.selectedBranchId ||
    user?.selectedBranch?.id ||
    user?.selectedBranch?.branch_id ||
    user?.selected_branch_id ||
    user?.branch_id ||
    user?.branch?.id ||
    user?.branch?.branch_id ||
    "";

  const loadCurrentTradingDay = useCallback(async () => {
    try {
      const payload = await getCurrentTradingDay(
        activeBranchId ? { branch_id: activeBranchId } : {}
      );
      setCurrentTradingDay(resolveTradingDayDate(payload));
    } catch (error) {
      console.error("Failed to load hospitality trading day:", error);
      setCurrentTradingDay(null);
    }
  }, [activeBranchId]);

  useEffect(() => {
    if (!isHospitality) {
      setCurrentTradingDay(null);
      return;
    }

    setCurrentTradingDay(null);
    loadCurrentTradingDay();
  }, [activeBranchId, isHospitality, loadCurrentTradingDay]);
  useEffect(() => {
    setLandingPath([]);
    setOpenMenu(null);
    setOpenNestedMenus({});
    setMobileMenuOpen(false);

    setProductManagementOpen(false);
    setInventoryManagementOpen(false);
    setCategoryManagementOpen(false);
    setPriceLevelOpen(false);
    setRolePriceLevelOpen(false);
    setPriceListOpen(false);
    setPriceAdjustmentOpen(false);
    setPriceHistoryOpen(false);
    setProductImportOpen(false);
    setProductBarcodePrintOpen(false);
    setStockTakeOpen(false);
    setPurchaseManagementOpen(false);
    setPosReportOpen(false);
    setSelectedPosReport(null);
    setStockReportOpen(false);
    setSelectedStockReport(null);
    setManufacturingRecipeManagementOpen(false);
    setManufacturingPlanManagementOpen(false);
    setManufacturingOrderWorkflowOpen(false);
    setManufacturingReportsOpen(false);
    setMonthlyProfitComparisonOpen(false);

    setDayEndGuardOpen(false);
    setMonthEndStockTakeOpen(false);
    setHospitalityMonthEndOpen(false);
    setFrontStockManagementOpen(false);
    setBackStockManagementOpen(false);
    setIssueStockToFrontOpen(false);
    setDailyFrontStockClosingOpen(false);
    setStockWastageOpen(false);
    setStockWastageReportOpen(false);
    setMenuItemManagementOpen(false);
    setRecipeMenuItem(null);
    setMenuItemRecipeCostReportOpen(false);
    setPrepMessageConfigurationOpen(false);
    setModifierConfigurationOpen(false);
    setHospitalityTableServiceOpen(false);
    setMenuRecipeDsrOpen(false);
    setClosingStockMovementReportOpen(false);
    setStockIssuedToFrontReportOpen(false);
    setFoodIndustrySalesReportOpen(false);
    setDailyFoodSalesReportOpen(false);
    setShiftReportOpen(false);
    setFoodRecipeBomOpen(false);
    setFoodManufacturingPlanningOpen(false);
    setFoodManufacturingBatchOpen(false);
    setFoodManufacturingReportsOpen(false);
    setAuthenticationAdministrationOpen(false);
  }, [isHospitality]);


  

  const legacyNavigationCatalog = [
    {
      name: "Sales Control",
      children: [
        {
          name: "Void/Reprint Receipt",
          modal: "pos-more-button",
          permission: ["void_sales", "reprint_receipt"],
        },
        {
          name: "Cancelled Receipts",
          modal: "cancelled-receipt",
          permission: "view_cancelled_receipts",
        },
        {
          name: "Receipt Register",
          modal: "receipt-register",
          permission: "view_receipt_register",
        },
        {
          name: "Qoutation Desk",
          modal: "qoutation-desk",
          permission: ["view_quotation_modal", "view_qoutation_modal"],
        },
        {
          name: "Discounts",
          modal: "manage-discount",
          permission: "view_discount_modal",
        },
        {
  name: "Credit Customers",
  modal: "credit-customers",
  permission: "view_credit_customers",
},
      ],
    },
    {
      name: "Products",
      children: [
        {
          name: "Product Register",
          modal: "product-management",
          permission: "product_management",
        },
        {
          name: "Pricing",
          modal: "product-pricing",
          permission: ["product_management", "price_management", "view_price_list"],
        },
        {
          name: "Stock & Costing",
          modal: "product-stock-costing",
          permission: ["product_management", "inventory_management", "view_stock_reports"],
        },
        {
          name: "Pack Relationships",
          modal: "product-pack-relationships",
          permission: ["product_management", "inventory_management"],
        },
        {
          name: "Categories",
          modal: "category-management",
          permission: "view_product_category",
        },
        {
          name: "Barcode Printing",
          modal: "product-barcode-printing",
          permission: "product_barcode_printing",
        },
        {
          name: "Brand Menu Import",
          modal: "product-import",
          permission: "import_products",
        },
        {
          name: "Price",
          children: [
            {
              name: "Pricing Levels",
              modal: "price-lavel",
              permission: "price_management",
            },
            {
              name: "Price List",
              modal: "price-list",
              permission: "view_price_list",
            },
            {
              name: "Adjust Product Price",
              modal: "adjust-price",
              permission: "add_price",
            },
            {
              name: "Assign Role Price",
              modal: "role-price",
              permission: "assign_role_price",
            },
          ],
        },
      ],
    },
    {
      name: "Inventory",
      children: [
        {
          name: "Inventory Overview",
          modal: "inventory-overview",
          permission: ["inventory_dashboard", "inventory_management", "view_stock_reports"],
        },
        {
          name: "Warehouses & Locations",
          modal: "warehouse-management",
          permission: ["manage_warehouses", "inventory_management", "view_stock_reports"],
        },
        {
          name: "Batches & Expiry",
          modal: "batch-expiry",
          permission: ["view_inventory_batches", "manage_inventory_batches", "inventory_management", "view_stock_reports"],
        },
        {
          name: "Stock Movements",
          modal: "stock-movements",
          permission: ["view_inventory_movements", "inventory_management", "view_stock_reports"],
        },
        {
          name: "Alerts & Recalls",
          modal: "inventory-alerts",
          permission: ["view_inventory_alerts", "manage_inventory_alerts", "view_inventory_recalls", "inventory_management"],
        },
        { name: "Stock Take", modal: "stock-take", permission: "stock_take" },
        {
          name: "Stock Adjustment",
          modal: "bulk-stock-adjustment",
          permission: "adjust_stock",
        },
        {
          name: "Warehouse Transfers",
          modal: "stock-transfers",
          permission: ["stock_transfers", "request_warehouse_transfer", "approve_warehouse_transfer", "dispatch_warehouse_transfer", "receive_warehouse_transfer"],
        },
      ],
    },
    {
      name: "Purchasing",
      children: [
        {
          name: "Supplier Management",
          modal: "supplier-management",
          permission: "view_supplier",
        },
        {
          name: "Purchase Management",
          modal: "purchase-management",
          permission: "purchase_orders",
        },
        {
          name: "Goods Received",
          modal: "purchase-receipts",
          permission: ["view_purchase_receipts", "receive_stock", "purchase_orders"],
        },
      ],
    },
    {
      name: "Reports",
      children: [
        {
          name: "Sales Reports",
          children: [
            {
              name: "Sales Dashboard",
              modal: "sales-report-dashboard",
              permission: "view_sales_reports",
            },
            {
              name: "Daily Sales Report",
              modal: "sales-report-daily-sales",
              permission: "view_sales_reports",
            },
            {
              name: "Sales List",
              modal: "sales-report-sales-list",
              permission: "view_sales_reports",
            },
            {
              name: "Payment Summary",
              modal: "sales-report-payments",
              permission: "view_sales_reports",
            },
            {
              name: "Product Sales",
              modal: "sales-report-products",
              permission: "view_sales_reports",
            },
            {
              name: "Cashier Sales",
              modal: "sales-report-cashiers",
              permission: "view_sales_reports",
            },
            {
              name: "Cashup History",
              modal: "sales-report-cashups",
              permission: "cash_up_reports",
            },
            {
              name: "Cashout Report",
              modal: "cashout-report",
              permission: "cash_up_reports",
            },
            {
              name: "Shift Sales",
              modal: "sales-report-shift-sales",
              permission: "view_sales_reports",
            },
            {
              name: "Day Ends",
              modal: "sales-report-day-ends",
              permission: "view_day_end_reports",
            },
          ],
        },
        {
          name: "Inventory Reports",
          children: [
            {
              name: "Stock Dashboard",
              modal: "stock-report-dashboard",
              permission: "view_stock_reports",
            },
            {
              name: "Company Balance",
              modal: "stock-report-company-balance",
              permission: "view_stock_reports",
            },
            {
              name: "Shop Balance",
              modal: "stock-report-branch-balance",
              permission: "view_stock_reports",
            },
            {
              name: "Current Balance",
              modal: "stock-report-current-balance",
              permission: "view_stock_reports",
            },
            {
              name: "As-Of Balance",
              modal: "stock-report-as-of",
              permission: "view_stock_reports",
            },
            {
              name: "Stock Movements",
              modal: "stock-report-movements",
              permission: "view_stock_reports",
            },
            {
              name: "Stock Received",
              modal: "stock-report-received",
              permission: "view_stock_reports",
            },
            {
              name: "Stock Adjustments",
              modal: "stock-report-adjustments",
              permission: "view_stock_reports",
            },
            {
              name: "Stock Valuation",
              modal: "stock-report-valuation",
              permission: "view_stock_reports",
            },
            {
              name: "Low Stock",
              modal: "stock-report-low-stock",
              permission: "view_stock_reports",
            },
            {
              name: "Out Of Stock",
              modal: "stock-report-out-of-stock",
              permission: "view_stock_reports",
            },
            {
              name: "Negative Stock",
              modal: "stock-report-negative-stock",
              permission: "view_stock_reports",
            },
            {
              name: "Category Summary",
              modal: "stock-report-category-summary",
              permission: "view_stock_reports",
            },
            {
              name: "Supplier Summary",
              modal: "stock-report-supplier-summary",
              permission: "view_stock_reports",
            },
            {
              name: "Old Adjustment History",
              modal: "stock-adjustment-history",
              permission: "view_stock_reports",
            },
          ],
        },
        { name: "Profit & Loss", modal: "profit-loss", permission: "view_profit_loss" },
        {
          name: "Price Adjustment",
          modal: "price-adjustment-history",
          permission: "view_price_adjustment_history",
        },
        {
          name: "Discount Report",
          modal: "discount-report",
          permission: "view_discount_reports",
        },
        { name: "Tax Reports", modal: "tax-report", permission: "tax_management" },
        {
          name: "Manufacturing Reports",
          modal: "manufacturing-reports",
          permission: "manufacturing_view_reports",
        },
      ],
    },


{
  name: "Manufacturing",
  children: [
    {
      name: "BOM / Recipes",
      modal: "manufacturing-recipes",
      permission: "manufacturing_view",
    },
    {
      name: "Production Planning",
      modal: "manufacturing-planning",
      permission: "manufacturing_view",
    },
    {
      name: "Production Workflow",
      modal: "manufacturing-workflow",
      permission: "manufacturing_view",
    },
  ],
},
    {
      name: "Store Configuration",
      children: [
        {
          name: "Country Management",
          modal: "country-management",
          platformAdminOnly: true,
        },
        {
          name: "Company Management",
          modal: "company-management",
          platformAdminOnly: true,
        },
        {
          name: "Brand Management",
          modal: "brand-management",
          platformAdminOnly: true,
        },
        {
          name: "Complex Management",
          modal: "complex-management",
          platformAdminOnly: true,
        },
        {
          name: "Shop Management",
          modal: "shop-management",
          permission: "manage_branch_settings",
          platformAdminAllowed: true,
        },
        {
          name: "Till Management",
          modal: "platform-till-management",
          permission: "till_management",
          platformAdminAllowed: true,
        },
        {
          name: "Shift Settings",
          modal: "shift-settings",
          permission: "manage_shift_settings",
        },
        {
          name: "Currency Management",
          modal: "currency-management",
          permission: "view_currency",
        },
        {
          name: "Payment Method",
          modal: "payment-type-management",
          permission: "view_payment_type",
        },
        { name: "License", modal: "license-settings", permission: "manage_license" },
        { name: "Tax Management", modal: "tax-management", permission: "tax_management" },
      ],
    },
    {
      name: "People & Security",
      children: [
        { name: "User Management", modal: "user-management", permission: "manage_user" },
        { name: "Roles & Permissions", modal: "roles-permissions", permission: "view_role_permission" },
        {
          name: "Authentication & Access",
          modal: "authentication-administration",
          permission: ["manage_access_assignments", "manage_authentication_policies"],
          platformAdminAllowed: true,
        },
      ],
    },
    // {
    //   name: "Security",
    //   children: [
    //     {
    //       name: "Audit Logs",
    //       href: "/admin/audit-logs",
    //       permission: "view_audit_logs",
    //     },
    //     {
    //       name: "Manager Approvals",
    //       href: "/admin/manager-approvals",
    //       permission: "view_manager_approvals",
    //     },
    //     {
    //       name: "Login Sessions",
    //       href: "/admin/login-sessions",
    //       permission: "view_login_sessions",
    //     },
    //   ],
    // },
    {
      name: "Devices & Integrations",
      children: [
        {
          name: "Printer & Order Routing",
          modal: "printer-management",
          permission: "printer_management",
        },
        {
          name: "Fiscal Device / ZIMRA",
          modal: "fiscal-device",
          permission: "manage_integrations",
        },
        {
          name: "Barcode Scanner Settings",
          modal: "barcode-scanner-settings",
          permission: "manage_general_settings",
        },
        {
          name: "Deployment & Sync",
          modal: "deployment-sync",
          permission: ["till_management", "manage_license"],
        },
      ],
    },
  ];

  const hospitalityOperationalNavigation = [
    {
      name: "General Procedure",
      children: [
        {
          name: "Run Day End",
          modal: "day-end-guard",
          permission: "general_procedure",
        },
      ],
    },
    {
      name: "Inventory",
      children: [
        {
          name: "Inventory Overview",
          modal: "inventory-overview",
          permission: ["inventory_dashboard", "inventory_management", "view_stock_reports"],
        },
        {
          name: "Stock Item Management",
          modal: "stock-item-management",
          permission: ["product_management", "inventory_management"],
        },
        {
          name: "Category Management",
          modal: "category-management",
          permission: "view_product_category",
        },
        {
          name: "Warehouses & Locations",
          modal: "warehouse-management",
          permission: ["manage_warehouses", "inventory_management"],
        },
        {
          name: "Batches & Expiry",
          modal: "batch-expiry",
          permission: ["view_inventory_batches", "manage_inventory_batches", "inventory_management"],
        },
        {
          name: "Stock Movements",
          modal: "stock-movements",
          permission: ["view_inventory_movements", "inventory_management", "view_stock_reports"],
        },
        {
          name: "Alerts & Recalls",
          modal: "inventory-recalls",
          permission: ["view_inventory_alerts", "view_inventory_recalls", "manage_inventory_recalls", "inventory_management"],
        },
        {
          name: "Back Stock Management",
          modal: "back-stock-management",
          permission: "inventory_management",
        },
        {
          name: "Front Stock Management",
          modal: "front-stock-management",
          permission: "inventory_management",
        },
        {
          name: "Issue Stock",
          modal: "issue-stock-to-front",
          permission: ["boh_issue_stock", "inventory_management"],
        },
        {
          name: "Issued Stock",
          modal: "issued-stock",
          permission: ["boh_issue_stock", "view_stock_reports", "inventory_management"],
        },
        {
          name: "Stock Wastage",
          modal: "stock-wastage",
          permission: "inventory_management",
        },
        {
          name: "Stock Takes & Reconciliation",
          children: [
            {
              name: "Back Stock Month-End Stock Take",
              modal: "monthend-stock-take",
              permission: ["general_procedure", "stock_take", "inventory_management"],
            },
            {
              name: "Front Stock Daily Count & Reconciliation",
              modal: "daily-front-stock-closing",
              permission: ["inventory_management", "stock_take"],
            },
          ],
        },
        {
          name: "Stock Operations",
          children: [
            {
              name: "Stock Adjustment",
              modal: "bulk-stock-adjustment",
              permission: "adjust_stock",
            },
            {
              name: "Warehouse Transfers",
              modal: "stock-transfers",
              permission: ["stock_transfers", "request_warehouse_transfer", "approve_warehouse_transfer", "dispatch_warehouse_transfer", "receive_warehouse_transfer"],
            },
          ],
        },
      ],
    },
    {
      name: "Purchases",
      children: [
        {
          name: "Supplier Management",
          modal: "supplier-management",
          permission: "view_supplier",
        },
        {
          name: "Purchase Orders",
          modal: "purchase-management",
          permission: "purchase_orders",
        },
        {
          name: "Goods Received",
          modal: "purchase-receipts",
          permission: ["view_purchase_receipts", "receive_stock", "purchase_orders"],
        },
        {
          name: "Purchase Returns",
          modal: "purchase-returns",
          permission: ["purchase_returns", "view_purchase_returns_page"],
        },
        {
          name: "Supplier Invoices",
          modal: "supplier-invoices",
          permission: ["supplier_invoices", "view_supplier_invoices_page"],
        },
        {
          name: "Supplier Payments",
          modal: "supplier-payments",
          permission: ["view_supplier_payments", "supplier_payments", "supplier_invoices"],
        },
      ],
    },
    {
      name: "Menu Items",
      children: [
        {
          name: "Dining & Table Service",
          modal: "hospitality-table-service",
          permission: ["view_hospitality_tables", "manage_hospitality_tables"],
        },
        {
          name: "Menu Item Management",
          modal: "menu-item-management",
          permission: "product_management",
        },
        {
          // Menu items need a price level, and the retail Products menu that
          // also holds these is not shown in Hospitality.
          name: "Pricing",
          children: [
            {
              name: "Price Levels",
              modal: "price-lavel",
              permission: "price_management",
            },
            {
              name: "Assign Role Price",
              modal: "role-price",
              permission: "assign_role_price",
            },
          ],
        },
        {
          name: "Brand Menu Import",
          modal: "product-import",
          permission: "import_products",
        },
        {
          name: "Extras",
          children: [
            {
              name: "Prep Message Configuration",
              modal: "prep-message-configuration",
              permission: "product_management",
            },
            {
              name: "Modifier Configuration",
              modal: "modifier-configuration",
              permission: "product_management",
            },
          ],
        },
      ],
    },
    {
      name: "Reports",
      children: [
        {
          name: "Management Reports",
          children: [
            { name: "Profit & Loss", modal: "profit-loss", permission: "view_profit_loss" },
            { name: "Monthly Profit Comparison", modal: "monthly-profit-comparison", permission: "view_profit_loss" },
            { name: "Tax Reports", modal: "tax-report", permission: "tax_management" },
            { name: "Discount Report", modal: "discount-report", permission: "view_discount_reports" },
          ],
        },
        {
          name: "Sales Reports",
          children: [
            { name: "Daily Sales Report", modal: "daily-food-sales-report", permission: "view_sales_reports" },
            { name: "Detailed Sales Report", modal: "food-industry-sales-report", permission: "view_sales_reports" },
            { name: "Payments", modal: "sales-report-payments", permission: "view_sales_reports" },
            { name: "Cashiers", modal: "sales-report-cashiers", permission: "view_sales_reports" },
            { name: "Shift Report", modal: "shift-report", permission: "cash_up_reports" },
          ],
        },
        {
          name: "Cash Management Reports",
          children: [
            { name: "Cashup History", modal: "sales-report-cashups", permission: "cash_up_reports" },
            { name: "Cashout Report", modal: "cashout-report", permission: "cash_up_reports" },
          ],
        },
        {
          name: "Purchase Reports",
          children: [
            { name: "Purchase Management", modal: "purchase-management", permission: "purchase_orders" },
            { name: "Stock Received", modal: "stock-report-received", permission: "view_stock_reports" },
          ],
        },
        {
          name: "Inventory Reports",
          children: [
            { name: "Shop Balance", modal: "stock-report-branch-balance", permission: "view_stock_reports" },
            { name: "Current Balance", modal: "stock-report-current-balance", permission: "view_stock_reports" },
            { name: "As-Of Balance", modal: "stock-report-as-of", permission: "view_stock_reports" },
            { name: "Stock Movements", modal: "stock-report-movements", permission: "view_stock_reports" },
            { name: "Stock Adjustments", modal: "stock-report-adjustments", permission: "view_stock_reports" },
            { name: "Stock Valuation", modal: "stock-report-valuation", permission: "view_stock_reports" },
            { name: "Low Stock", modal: "stock-report-low-stock", permission: "view_stock_reports" },
            { name: "Out Of Stock", modal: "stock-report-out-of-stock", permission: "view_stock_reports" },
            { name: "Stock Wastage Report", modal: "stock-wastage-report", permission: "view_stock_reports" },
            { name: "Stock Issues", modal: "stock-issued-to-front-report", permission: "view_stock_reports" },
            { name: "Closing Stock Movement Report", modal: "closing-stock-movement-report", permission: "view_stock_reports" },
            { name: "Daily Stock Reconciliation", modal: "menu-recipe-dsr", permission: "inventory_management" },
          ],
        },
        {
          name: "Menu Reports",
          children: [
            { name: "Menu Recipe Cost Report", modal: "menu-item-recipe-cost-report", permission: "inventory_management" },
          ],
        },
        {
          name: "Manufacturing Reports",
          children: [
            { name: "Food Production Reports", modal: "food-manufacturing-reports", permission: ["food_manufacturing_view", "view_production_cost_reports", "view_sales_reports"] },
          ],
        },
        {
          name: "Employee Reports",
          children: [
            { name: "Shift Report", modal: "shift-report", permission: "cash_up_reports" },
            { name: "Cashup History", modal: "sales-report-cashups", permission: "cash_up_reports" },
          ],
        },
        {
          name: "Loyalty Reports",
          children: [
            { name: "Credit Customers", modal: "credit-customers", permission: "view_sales_reports" },
          ],
        },
      ],
    },
    {
      name: "Food Manufacturing",
      children: [
        { name: "Food Recipes / BOM", modal: "food-recipes-bom", permission: ["food_manufacturing_view", "view_production_recipes"] },
        { name: "Production Planning", modal: "food-manufacturing-planning", permission: ["food_manufacturing_view", "view_production_plans"] },
        { name: "Record Production", modal: "food-manufacturing-batches", permission: ["food_manufacturing_view", "view_production_orders"] },
      ],
    },
  ];

  const sharedAdministrationNames = new Set([
    "Store Configuration",
    "People & Security",
    "Devices & Integrations",
  ]);
  const sharedAdministrationNavigation = legacyNavigationCatalog.filter((item) =>
    sharedAdministrationNames.has(item.name)
  );
  const navigation = platformOnly
    ? [{
        name: "Country Management",
        modal: "country-management",
        platformAdminOnly: true,
      }, {
        name: "Company Management",
        modal: "company-management",
        platformAdminOnly: true,
      }, {
        name: "Brand Management",
        modal: "brand-management",
        platformAdminOnly: true,
      }, {
        name: "Complex Management",
        modal: "complex-management",
        platformAdminOnly: true,
      }, {
        name: "Shop Management",
        modal: "shop-management",
        platformAdminOnly: true,
      }, {
        name: "Till Management",
        modal: "platform-till-management",
        platformAdminOnly: true,
      }]
    : businessModeLoading
      ? []
      : [
          ...hospitalityOperationalNavigation,
          ...sharedAdministrationNavigation,
        ];

  const permittedNavigation = filterNavigationByPermission(navigation, user);

  const denyPermission = (name = "this page") => {
    setPermissionDeniedMessage(
      `Access denied. Your role does not have permission to open ${name}.`
    );
    window.setTimeout(() => setPermissionDeniedMessage(""), 4500);
  };

  const canOpenChild = (child) =>
    (!child?.superAdminOnly || userIsSuperAdmin(user)) &&
    (!child?.platformAdminOnly || userIsPlatformAdmin(user)) &&
    ((child?.platformAdminAllowed && userIsPlatformAdmin(user))
      || hasMenuPermission(user, child?.permission));

  const secureOpenChildModal = (child, openFn) => {
    if (!canOpenChild(child)) {
      denyPermission(child?.name || "this page");
      return;
    }

    openFn();
  };

  const openPosReport = (reportKey) => {
    setSelectedPosReport(reportKey);
    setPosReportOpen(true);
  };

  const openStockReport = (reportKey) => {
    setSelectedStockReport(reportKey);
    setStockReportOpen(true);
  };

  const openHospitalityOnly = (openModal) => {
    if (!isHospitality) return;
    openModal();
  };

  const openPlatformStructure = (section, label) => {
    if (!userIsPlatformAdmin(user)) {
      denyPermission(label);
      return;
    }
    setHospitalityStructureSection(section);
  };

  const modalHandlers = {
    "country-management": () => openPlatformStructure("countries", "Country Management"),
    "company-management": () => {
      if (!userIsPlatformAdmin(user)) {
        denyPermission("Company Management");
        return;
      }
      setCompanyManagementOpen(true);
    },
    "brand-management": () => openPlatformStructure("brands", "Brand Management"),
    "complex-management": () => openPlatformStructure("complexes", "Complex Management"),
    "shop-management": () => userIsPlatformAdmin(user)
      ? openPlatformStructure("shops", "Shop Management")
      : setBranchSettings(true),
    "platform-till-management": () => userIsPlatformAdmin(user)
      ? openPlatformStructure("tills", "Till Management")
      : setTillManagementOpen(true),
    "user-management": () => setUserManagementOpen(true),
    "authentication-administration": () => setAuthenticationAdministrationOpen(true),
    "role-price": () => setRolePriceLevelOpen(true),
    "price-lavel": () => setPriceLevelOpen(true),
    "roles-permissions": () => setRoleOpen(true),
    "general-settings": () => setSetGeneralSettingsOpen(true),
    "shift-settings": () => setShiftSettingsOpen(true),
    "create-sale": () => setCreateSaleOpen(true),
    "license-settings": () => setLicenseOpen(true),
    "branch-settings": () => setBranchSettings(true),
    "category-management": () => setCategoryManagementOpen(true),
    "supplier-management": () => setSupplierManagementOpen(true),
    "product-management": () => { setProductManagementInitialTab("register"); setProductManagementOpen(true); },
    "product-pricing": () => { setProductManagementInitialTab("pricing"); setProductManagementOpen(true); },
    "product-stock-costing": () => { setProductManagementInitialTab("stock"); setProductManagementOpen(true); },
    "product-pack-relationships": () => { setProductManagementInitialTab("break"); setProductManagementOpen(true); },
    "inventory-overview": () => { setInventoryManagementInitialTab("overview"); setInventoryManagementOpen(true); },
    "stock-item-management": () => openHospitalityOnly(() => { setInventoryManagementInitialTab("register"); setInventoryManagementOpen(true); }),
    "warehouse-management": () => { setInventoryManagementInitialTab("warehouses"); setInventoryManagementOpen(true); },
    "batch-expiry": () => { setInventoryManagementInitialTab("batches"); setInventoryManagementOpen(true); },
    "stock-movements": () => { setInventoryManagementInitialTab("movements"); setInventoryManagementOpen(true); },
    "inventory-alerts": () => { setInventoryManagementInitialTab("recalls"); setInventoryManagementOpen(true); },
    "inventory-recalls": () => { setInventoryManagementInitialTab("recalls"); setInventoryManagementOpen(true); },
    "adjust-price": () => setPriceAdjustmentOpen(true),
    "price-list": () => setPriceListOpen(true),
    "price-adjustment-history": () => setPriceHistoryOpen(true),
    "receive-stock": () => { setPurchaseInitialTab("receipts"); setPurchaseManagementOpen(true); },
    "purchase-receipts": () => { setPurchaseInitialTab("receipts"); setPurchaseManagementOpen(true); },
    "purchase-returns": () => { setPurchaseInitialTab("returns"); setPurchaseManagementOpen(true); },
    "supplier-invoices": () => { setPurchaseInitialTab("invoices"); setPurchaseManagementOpen(true); },
    "supplier-payments": () => { setPurchaseInitialTab("payments"); setPurchaseManagementOpen(true); },
    "bulk-stock-adjustment": () => setBulkStockAdjustmentOpen(true),
    "stock-adjustment-history": () => setStockAdjustmentHistoryOpen(true),
    "currency-management": () => setCurrencyOpen(true),
    "payment-type-management": () => setPaymentTypeOpen(true),
    "till-management": () => setTillManagementOpen(true),
    "deployment-sync": () => setDeploymentSyncOpen(true),
    "printer-management": () => setPrinterOpen(true),
    "barcode-scanner-settings": () => setSetGeneralSettingsOpen(true),
    "pos-more-button": () => setShowReceiptActions(true),
    "receipt-register": () => setReceiptRegisterOpen(true),
    "product-import": () => setProductImportOpen(true),
    "stock-transfers": () => setStockTransferOpen(true),
    "cancelled-receipt": () => setCancelledReceiptOpen(true),
    "product-barcode-printing": () => setProductBarcodePrintOpen(true),
    "manage-discount": () => setDiscountManagementOpen(true),
    "discount-report": () => setDiscountReportOpen(true),
    "qoutation-desk": () => setQoutationOpen(true),
    "tax-management": () => setTaxManagementOpen(true),
    "fiscal-device": () => setFiscalDeviceOpen(true),
    "tax-report": () => setFiscalReportOpen(true),
    "profit-loss": () => setProfitReportOpen(true),
    "monthly-profit-comparison": () => openHospitalityOnly(() => setMonthlyProfitComparisonOpen(true)),
      "purchase-management": () => { setPurchaseInitialTab("orders"); setPurchaseManagementOpen(true); },
      "credit-customers": () => setCreditCustomerOpen(true),
      "day-end-guard": () => openHospitalityOnly(() => setDayEndGuardOpen(true)),
      "monthend-stock-take": () => openHospitalityOnly(() => setMonthEndStockTakeOpen(true)),
      "monthend-management": () => openHospitalityOnly(() => setHospitalityMonthEndOpen(true)),
      "back-stock-management": () => setBackStockManagementOpen(true),
      "front-stock-management": () => setFrontStockManagementOpen(true),
      "issue-stock-to-front": () => setIssueStockToFrontOpen(true),
      "issued-stock": () => setIssuedStockOpen(true),
      "daily-front-stock-closing": () => openHospitalityOnly(() => setDailyFrontStockClosingOpen(true)),
      "stock-wastage": () => setStockWastageOpen(true),
      "stock-wastage-report": () => setStockWastageReportOpen(true),
      "menu-item-management": () => setMenuItemManagementOpen(true),
      "menu-item-recipe-cost-report": () => setMenuItemRecipeCostReportOpen(true),
      "prep-message-configuration": () => openHospitalityOnly(() => setPrepMessageConfigurationOpen(true)),
      "modifier-configuration": () => openHospitalityOnly(() => setModifierConfigurationOpen(true)),
      "hospitality-table-service": () => openHospitalityOnly(() => setHospitalityTableServiceOpen(true)),
      "menu-recipe-dsr": () => setMenuRecipeDsrOpen(true),
      "closing-stock-movement-report": () => setClosingStockMovementReportOpen(true),
      "stock-issued-to-front-report": () => setStockIssuedToFrontReportOpen(true),
      "food-industry-sales-report": () => setFoodIndustrySalesReportOpen(true),
      "daily-food-sales-report": () => setDailyFoodSalesReportOpen(true),
      "shift-report": () => setShiftReportOpen(true),
      "food-recipes-bom": () => setFoodRecipeBomOpen(true),
      "food-manufacturing-planning": () => setFoodManufacturingPlanningOpen(true),
      "food-manufacturing-batches": () => setFoodManufacturingBatchOpen(true),
      "food-manufacturing-reports": () => setFoodManufacturingReportsOpen(true),


//        "manufacturing-recipe-register": () => setManufacturingRecipeRegisterOpen(true),
// "manufacturing-create-recipe": () => setManufacturingRecipeFormOpen(true),

// "manufacturing-plan-register": () => setManufacturingPlanRegisterOpen(true),
// "manufacturing-create-plan": () => setManufacturingPlanFormOpen(true),

// "manufacturing-material-requirements": () => setManufacturingMaterialRequirementsOpen(true),

// "manufacturing-order-register": () => setManufacturingOrderRegisterOpen(true),
// "manufacturing-create-order": () => setManufacturingOrderFormOpen(true),
// "manufacturing-order-workflow": () => setManufacturingOrderWorkflowOpen(true),

// "manufacturing-production-report": () => setManufacturingProductionReportOpen(true),
// "manufacturing-cost-report": () => setManufacturingCostReportOpen(true),
// "manufacturing-material-usage-report": () => setManufacturingMaterialUsageReportOpen(true),
// "manufacturing-wastage-report": () => setManufacturingWastageReportOpen(true),
// "manufacturing-variance-report": () => setManufacturingVarianceReportOpen(true),

"manufacturing-recipes": () => setManufacturingRecipeManagementOpen(true),
"manufacturing-planning": () => setManufacturingPlanManagementOpen(true),
"manufacturing-workflow": () => setManufacturingOrderWorkflowOpen(true),
"manufacturing-reports": () => setManufacturingReportsOpen(true),

    "sales-report-dashboard": () => openPosReport("dashboard"),
    "sales-report-daily-sales": () => openPosReport("daily-sales"),
    "sales-report-sales-list": () => openPosReport("sales-list"),
    "sales-report-payments": () => openPosReport("payments"),
    "sales-report-products": () => openPosReport("products"),
    "sales-report-cashiers": () => openPosReport("cashiers"),
    "sales-report-cashups": () => openPosReport("cashups"),
    "cashout-report": () => openPosReport("cashouts"),
    "sales-report-shift-sales": () => openPosReport("shift-sales"),
    "sales-report-day-ends": () => openPosReport("day-ends"),

    "stock-report-dashboard": () => openStockReport("dashboard"),
    "stock-report-company-balance": () => openStockReport("company-balance"),
    "stock-report-branch-balance": () => openStockReport("branch-balance"),
    "stock-report-current-balance": () => openStockReport("current-balance"),
    "stock-report-as-of": () => openStockReport("as-of"),
    "stock-report-movements": () => openStockReport("movements"),
    "stock-report-received": () => openStockReport("received"),
    "stock-report-adjustments": () => openStockReport("adjustments"),
    "stock-report-valuation": () => openStockReport("valuation"),
    "stock-report-low-stock": () => openStockReport("low-stock"),
    "stock-report-out-of-stock": () => openStockReport("out-of-stock"),
    "stock-report-negative-stock": () => openStockReport("negative-stock"),
    "stock-report-category-summary": () => openStockReport("category-summary"),
    "stock-report-supplier-summary": () => openStockReport("supplier-summary"),
  };

  const navigateManagementReport = (reportKey) => {
    if (reportKey === "price-adjustment-history" && isHospitality) return;
    if (reportKey === "monthly-profit-comparison" && !isHospitality) return;

    setProfitReportOpen(reportKey === "profit-loss");
    setMonthlyProfitComparisonOpen(reportKey === "monthly-profit-comparison");
    setPriceHistoryOpen(reportKey === "price-adjustment-history");
    setDiscountReportOpen(reportKey === "discount-report");
    setFiscalReportOpen(reportKey === "tax-report");
  };

  const navigateInventoryReport = (reportKey) => {
    if (isHospitality) return;

    if (reportKey === "stock-adjustment-history") {
      setStockReportOpen(false);
      setSelectedStockReport(null);
      setStockAdjustmentHistoryOpen(true);
      return;
    }

    setStockAdjustmentHistoryOpen(false);
    setSelectedStockReport(reportKey);
    setStockReportOpen(true);
  };

  const navigateHospitalityReport = (reportKey) => {
    if (!isHospitality) return;

    const supported = new Set([
      "daily-food-sales-report",
      "food-industry-sales-report",
      "shift-report",
      "closing-stock-movement-report",
      "stock-issued-to-front-report",
      "stock-wastage-report",
      "menu-recipe-dsr",
      "menu-item-recipe-cost-report",
    ]);
    if (!supported.has(reportKey)) return;

    setDailyFoodSalesReportOpen(reportKey === "daily-food-sales-report");
    setFoodIndustrySalesReportOpen(reportKey === "food-industry-sales-report");
    setShiftReportOpen(reportKey === "shift-report");
    setClosingStockMovementReportOpen(reportKey === "closing-stock-movement-report");
    setStockIssuedToFrontReportOpen(reportKey === "stock-issued-to-front-report");
    setStockWastageReportOpen(reportKey === "stock-wastage-report");
    setMenuRecipeDsrOpen(reportKey === "menu-recipe-dsr");
    setMenuItemRecipeCostReportOpen(reportKey === "menu-item-recipe-cost-report");
  };

  const isLinkActive = (href) => href && pathname.startsWith(href);

  const hasActiveChild = (children = []) => {
    return children.some((child) => {
      if (child.href && isLinkActive(child.href)) return true;
      if (child.children) return hasActiveChild(child.children);
      return false;
    });
  };

  const closeMenus = () => {
    setOpenMenu(null);
    setOpenNestedMenus({});
    setMobileMenuOpen(false);
  };

  const openTopMenu = (menuName, isOpen) => {
    if (isOpen) {
      setOpenMenu(null);
      setOpenNestedMenus({});
      return;
    }

    setOpenMenu(menuName);
    setOpenNestedMenus({});
  };

  const openNestedMenuOnly = (key, level) => {
    setOpenNestedMenus((prev) => {
      const next = {};

      Object.entries(prev).forEach(([openKey, meta]) => {
        if (meta.level < level) {
          next[openKey] = meta;
        }
      });

      next[key] = { level };
      return next;
    });
  };

  const closeNestedMenuAndChildren = (key) => {
    setOpenNestedMenus((prev) => {
      const next = {};

      Object.entries(prev).forEach(([openKey, meta]) => {
        if (openKey !== key && !openKey.startsWith(`${key}||`)) {
          next[openKey] = meta;
        }
      });

      return next;
    });
  };

  const toggleNestedMenu = (key, level = 0) => {
    const isCurrentlyOpen = !!openNestedMenus[key];

    if (isCurrentlyOpen) {
      closeNestedMenuAndChildren(key);
      return;
    }

    openNestedMenuOnly(key, level);
  };

  const handleChildClick = (child) => {
    if (child.children?.length) return;

    if (!canOpenChild(child)) {
      denyPermission(child.name);
      return;
    }

    if (child.modal && modalHandlers[child.modal]) {
      closeMenus();
      modalHandlers[child.modal]();
      return;
    }

    closeMenus();
  };

  const openLandingItem = (item) => {
    if (!item || item.children?.length) return;

    if (!canOpenChild(item)) {
      denyPermission(item.name);
      return;
    }

    if (item.modal && modalHandlers[item.modal]) {
      closeMenus();
      modalHandlers[item.modal]();
      return;
    }

    closeMenus();
  };

  const renderDesktopChild = (child, level = 0, parentKey = "") => {
    const key = `${parentKey}||${child.name}`;
    const hasChildren =
      Array.isArray(child.children) && child.children.length > 0;
    const isOpen = !!openNestedMenus[key];
    const isActive = child.href
      ? isLinkActive(child.href)
      : hasActiveChild(child.children);

    if (hasChildren) {
      return (
        <div
          key={key}
          className="relative overflow-visible"
          onMouseEnter={() => openNestedMenuOnly(key, level)}
        >
          <button
            type="button"
            onClick={() => toggleNestedMenu(key, level)}
            className={`w-full h-9 px-4 text-left text-[12px] font-bold border-b border-[#EFF2F2] flex items-center justify-between ${
              isActive
                ? "bg-[#E8F7F5] text-[#069495]"
                : "text-[#003636] hover:bg-[#E8F7F5] hover:text-[#069495]"
            }`}
          >
            <span>{child.name}</span>
            <ChevronRight className="w-3.5 h-3.5" />
          </button>

          {isOpen && (
            <div className="absolute top-0 left-full w-64 bg-white border border-[#CAD5D5] shadow-xl z-[10000]">
              <div className="h-[3px] bg-[#00BCBC]" />
              {child.children.map((nestedChild) =>
                renderDesktopChild(nestedChild, level + 1, key)
              )}
            </div>
          )}
        </div>
      );
    }

    if (child.modal) {
      return (
        <button
          key={key}
          type="button"
          onClick={() => handleChildClick(child)}
          className="block w-full h-9 text-left px-4 text-[12px] font-semibold border-b border-[#EFF2F2] text-[#003636] hover:bg-[#E8F7F5] hover:text-[#069495]"
        >
          {child.name}
        </button>
      );
    }

    return (
      <Link
        key={key}
        to={child.href}
        onClick={() => handleChildClick(child)}
        className={`block h-9 px-4 leading-9 text-[12px] font-semibold border-b border-[#EFF2F2] ${
          isLinkActive(child.href)
            ? "bg-[#E8F7F5] text-[#069495]"
            : "text-[#003636] hover:bg-[#E8F7F5] hover:text-[#069495]"
        }`}
      >
        {child.name}
      </Link>
    );
  };

  const renderMobileChild = (child, level = 0, parentKey = "") => {
    const key = `${parentKey}||${child.name}`;
    const hasChildren =
      Array.isArray(child.children) && child.children.length > 0;
    const isOpen = !!openNestedMenus[key];
    const isActive = child.href
      ? isLinkActive(child.href)
      : hasActiveChild(child.children);

    const paddingLeft =
      level === 0 ? "pl-6" : level === 1 ? "pl-10" : "pl-14";

    if (hasChildren) {
      return (
        <div key={key} className="border-b border-[#EFF2F2]">
          <button
            type="button"
            onClick={() => toggleNestedMenu(key, level)}
            className={`w-full h-11 pr-4 ${paddingLeft} text-left text-[12px] font-bold flex items-center justify-between ${
              isActive
                ? "bg-[#E8F7F5] text-[#069495]"
                : "text-[#003636] hover:bg-[#E8F7F5] hover:text-[#069495]"
            }`}
          >
            <span>{child.name}</span>
            {isOpen ? (
              <ChevronDown className="w-3.5 h-3.5" />
            ) : (
              <ChevronRight className="w-3.5 h-3.5" />
            )}
          </button>

          {isOpen && (
            <div className="bg-white">
              {child.children.map((nestedChild) =>
                renderMobileChild(nestedChild, level + 1, key)
              )}
            </div>
          )}
        </div>
      );
    }

    if (child.modal) {
      return (
        <button
          key={key}
          type="button"
          onClick={() => handleChildClick(child)}
          className={`block w-full h-11 text-left pr-4 ${paddingLeft} text-[12px] font-semibold border-b border-[#EFF2F2] text-[#003636] hover:bg-[#E8F7F5] hover:text-[#069495]`}
        >
          {child.name}
        </button>
      );
    }

    return (
      <Link
        key={key}
        to={child.href}
        onClick={() => handleChildClick(child)}
        className={`block h-11 leading-11 pr-4 ${paddingLeft} text-[12px] font-semibold border-b border-[#EFF2F2] ${
          isLinkActive(child.href)
            ? "bg-[#E8F7F5] text-[#069495]"
            : "text-[#003636] hover:bg-[#E8F7F5] hover:text-[#069495]"
        }`}
      >
        {child.name}
      </Link>
    );
  };

  const toggleFullscreen = async () => {
    try {
      if (!document.fullscreenElement) {
        await document.documentElement.requestFullscreen();
        setIsFullscreen(true);
        return;
      }

      await document.exitFullscreen();
      setIsFullscreen(false);
    } catch (error) {
      console.error("Fullscreen failed:", error);
    }
  };

  useEffect(() => {
    const handleFullscreenChange = () => {
      setIsFullscreen(Boolean(document.fullscreenElement));
    };

    document.addEventListener("fullscreenchange", handleFullscreenChange);

    return () => {
      document.removeEventListener("fullscreenchange", handleFullscreenChange);
    };
  }, []);

  const handleLogout = async () => {
    try {
      setIsProcessingLogout(true);
      await logoutRemote();
    } catch (error) {
      console.warn("Remote logout failed; the local Back Office session was cleared.", error);
    } finally {
      clearAdminSession();
      localStorage.removeItem("back_authToken");
      localStorage.removeItem("back_user");
      window.location.href = "/";
      setIsProcessingLogout(false);
    }
  };

  useEffect(() => {
    const close = (e) => {
      if (!e.target.closest(".pos-menu-area")) {
        setOpenMenu(null);
        setOpenNestedMenus({});
      }
    };

    document.addEventListener("mousedown", close);
    return () => document.removeEventListener("mousedown", close);
  }, []);

  const today = new Date().toLocaleDateString("en-GB");
  const time = new Date().toLocaleTimeString([], {
    hour: "2-digit",
    minute: "2-digit",
  });

  const branchName =
    user?.selectedBranch?.branch_name ||
    user?.selectedBranch?.name ||
    user?.branch?.branch_name ||
    user?.branch?.name ||
    (platformOnly ? "No company selected" : "Branch");

  const branchCode =
    user?.selectedBranch?.branch_code ||
    user?.selectedBranch?.code ||
    user?.branch?.branch_code ||
    user?.branch?.code ||
    "";

  const branchDisplay = branchCode ? `${branchName} (${branchCode})` : branchName;
  const isAdminLanding = pathname === "/admin" || pathname === "/admin/";

  return (
    <BackOfficeThemeProvider>
    <div className="sx-backoffice-v4 h-screen flex flex-col bg-white text-[#003636] overflow-hidden font-sans">
      <header className="h-14 bg-white border-b border-[#D9DDDD] flex items-center text-[#293232] shrink-0 z-50">
        <div className="flex h-full w-[286px] shrink-0 items-center border-r border-[#E2E5E5] px-3">
          <button type="button" onClick={() => setMobileMenuOpen(true)} className="mr-3 flex h-9 w-9 items-center justify-center text-[#4E5857] hover:bg-[#F2F4F5] lg:hidden" aria-label="Open menu"><Menu className="h-5 w-5" /></button>
          <img src={PLATFORM_FALLBACK_LOGO} alt="ServeNest" className="h-9 w-[138px] object-contain object-left" />
          <div className="ml-auto text-[10px] text-[#8C9494]">V4</div>
        </div>

        <div className="flex min-w-0 flex-1 items-center justify-end">
          <div className="hidden h-14 min-w-0 items-center gap-3 border-r border-[#E2E5E5] px-4 lg:flex">
            <Building2 className="h-4 w-4 shrink-0 text-[#505959]" />
            <div className="min-w-0 leading-[1.25]">
              <div className="max-w-[260px] truncate text-[12px] font-semibold text-[#222828]">{activeCompanyName}</div>
              <div className="max-w-[260px] truncate text-[10px] text-[#7D8585]">
                {platformOnly ? "Platform administration" : `${branchDisplay} · Hospitality`}
              </div>
            </div>
          </div>
          <div className="hidden h-14 min-w-0 items-center gap-3 border-r border-[#E2E5E5] px-4 md:flex">
            <User className="h-4 w-4 shrink-0 text-[#505959]" />
            <div className="min-w-0 leading-[1.25]"><div className="max-w-[180px] truncate text-[12px] text-[#222828]">{user?.name || "User"}</div><div className="text-[10px] text-[#7D8585]">Back office</div></div>
          </div>
          <div className="hidden h-14 items-center border-r border-[#E2E5E5] px-4 text-[10px] text-[#7D8585] xl:flex"><span>{today}</span><span className="ml-2 text-[#505959]">{time}</span></div>
          <Link to="/admin" className="hidden h-14 w-14 items-center justify-center border-r border-[#E2E5E5] text-[#505959] hover:bg-[#F4F6F7] hover:text-[#069494] sm:flex" title="Back office home"><Home className="h-4 w-4" /></Link>
          <button type="button" onClick={() => window.history.back()} className="hidden h-14 w-14 items-center justify-center border-r border-[#E2E5E5] bg-white text-[#505959] hover:bg-[#F4F6F7] sm:flex" title="Back"><ArrowLeft className="h-4 w-4" /></button>
          <button type="button" onClick={toggleFullscreen} className="hidden h-14 w-14 items-center justify-center border-r border-[#E2E5E5] bg-white text-[#505959] hover:bg-[#F4F6F7] md:flex" title={isFullscreen ? "Exit fullscreen" : "Fullscreen"}>{isFullscreen ? <Minimize2 className="h-4 w-4" /> : <Maximize2 className="h-4 w-4" />}</button>
          <button type="button" onClick={handleLogout} disabled={isProcessingLogout} className="flex h-14 w-14 items-center justify-center bg-white text-[#505959] hover:bg-[#FFF3F2] hover:text-[#B42318] disabled:opacity-60" title="Log out"><LogOut className="h-4 w-4" /></button>
        </div>
      </header>

      {permissionDeniedMessage && (
        <div className="fixed right-4 top-16 z-[100000] flex max-w-[420px] items-start gap-3 border border-[#FDA29B] bg-[#FEF3F2] px-4 py-3 text-[#B42318] shadow-xl">
          <ShieldAlert className="mt-0.5 h-5 w-5 shrink-0" />
          <div>
            <div className="text-[12px] font-black uppercase tracking-wide">
              Permission Required
            </div>
            <div className="mt-1 text-[12px] font-semibold">
              {permissionDeniedMessage}
            </div>
          </div>
        </div>
      )}

      {mobileMenuOpen && (
        <div className="fixed inset-0 z-[9999] lg:hidden">
          <button
            type="button"
            className="absolute inset-0 bg-black/50"
            onClick={() => {
              setMobileMenuOpen(false);
              setOpenMenu(null);
              setOpenNestedMenus({});
            }}
          />

          <aside className="absolute top-0 left-0 bottom-0 w-[86vw] max-w-[360px] bg-[#004142] text-white border-r-4 border-[#00BCBC] overflow-y-auto">
            <div className="h-16 flex items-center justify-between px-4 border-b border-white/15 bg-[#003B3C]">
              <div className="flex items-center">
                <div className="flex h-9 w-9 items-center justify-center bg-[#00BCBC] text-white"><Building2 className="h-5 w-5" /></div>
                <div className="ml-3 leading-tight">
                  <img src={PLATFORM_FALLBACK_LOGO} alt="ServeNest" className="h-8 w-[150px] object-contain object-left" />
                  <div className="text-[9px] tracking-[0.38em] font-bold text-white/70">
                    VERSION 4
                  </div>
                </div>
              </div>

              <button
                type="button"
                onClick={() => {
                  setMobileMenuOpen(false);
                  setOpenMenu(null);
                  setOpenNestedMenus({});
                }}
                className="h-10 w-10 border border-white/15 flex items-center justify-center hover:bg-white/10"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="px-4 py-3 bg-[#003637] border-b border-white/10 text-[12px] font-semibold">
              <div>{user?.name || "Admin User"}</div>
              <div className="mt-1 text-[#00BCBC]">MANAGEMENT WORKSPACE</div>
              <div className="text-white/70 mt-1 text-[10px]">
                COMPANY: {activeCompanyName}
              </div>
              {platformOnly ? (
                <div className="text-white/70 mt-1 text-[10px]">SCOPE: PLATFORM MANAGEMENT</div>
              ) : (
                <>
                  <div className="text-white/70 mt-1 text-[10px]">SHOP: {branchDisplay}</div>
                  <div className="text-white/70 mt-1 text-[10px]">MODE: HOSPITALITY</div>
                </>
              )}
            </div>

            <nav>
              {permittedNavigation.map((item) => {
                const hasChildren = Array.isArray(item.children) && item.children.length > 0;
                const isOpen = openMenu === item.name;
                const isActive = item.href ? isLinkActive(item.href) : hasActiveChild(item.children);

                if (!hasChildren && item.href) {
                  return (
                    <Link
                      key={item.name}
                      to={item.href}
                      onClick={closeMenus}
                      className={`w-full h-12 px-4 text-left text-[13px] font-bold uppercase tracking-wide flex items-center ${
                        isActive
                          ? "bg-[#00BCBC] text-[#004141]"
                          : "text-white hover:bg-white/10"
                      }`}
                    >
                      {item.name}
                    </Link>
                  );
                }

                return (
                  <div key={item.name} className="border-b border-white/10">
                    <button
                      type="button"
                      onClick={() => openTopMenu(item.name, isOpen)}
                      className={`w-full h-12 px-4 text-left text-[13px] font-bold uppercase tracking-wide flex items-center justify-between ${
                        isOpen || isActive
                          ? "bg-[#00BCBC] text-[#004141]"
                          : "text-white hover:bg-white/10"
                      }`}
                    >
                      <span>{item.name}</span>
                      {isOpen ? (
                        <ChevronDown className="w-4 h-4" />
                      ) : (
                        <ChevronRight className="w-4 h-4" />
                      )}
                    </button>

                    {isOpen && hasChildren && (
                      <div className="bg-white">
                        {item.children.map((child) =>
                          renderMobileChild(child, 0, item.name)
                        )}
                      </div>
                    )}
                  </div>
                );
              })}
            </nav>

            <button
              type="button"
              onClick={toggleFullscreen}
              className="w-full h-12 px-4 mt-4 bg-[#003637] border-y border-white/10 text-white text-left text-[13px] font-bold uppercase tracking-wide flex items-center gap-2"
            >
              {isFullscreen ? (
                <Minimize2 className="w-4 h-4" />
              ) : (
                <Maximize2 className="w-4 h-4" />
              )}
              {isFullscreen ? "Exit Fullscreen" : "Fullscreen"}
            </button>

            <button
              type="button"
              onClick={handleLogout}
              disabled={isProcessingLogout}
              className="w-full h-12 px-4 mt-0 bg-red-700 text-white text-left text-[13px] font-bold uppercase tracking-wide disabled:opacity-60"
            >
              {isProcessingLogout ? "Logging out" : "Logout"}
            </button>
          </aside>
        </div>
      )}

      <main className="relative flex-1 overflow-hidden bg-white z-10">
        {businessModeLoading ? (
          <Box className="flex h-full items-center justify-center gap-3 text-[#687272]">
            <CircularProgress size={22} />
            <Typography variant="body2">Loading operating mode...</Typography>
          </Box>
        ) : isAdminLanding ? (
          <AdminTileLaunchpad
            key={businessMode}
            navigation={permittedNavigation}
            activePath={landingPath}
            setActivePath={setLandingPath}
            onOpenItem={openLandingItem}
            businessMode={businessMode}
            platformOnly={platformOnly}
          />
        ) : (
          <div className="h-full min-h-0 overflow-auto bg-white">
            <Outlet />
          </div>
        )}
      </main>

      <footer className="h-6 bg-[#F5F6F7] border-t border-[#D9DDDD] text-[#687272] flex items-center justify-between px-3 sm:px-5 text-[9px] font-normal shrink-0">
        <span>READY</span>

        <div className="hidden md:flex items-center gap-8">
          <span>{today}</span>
          <span>{time}</span>
          {!platformOnly && <SyncStatusBadge className="align-middle" />}
          <span>WORKSPACE: BACK OFFICE</span>
          <span className="max-w-[260px] truncate">COMPANY: {activeCompanyName}</span>
          {platformOnly ? (
            <span>SCOPE: PLATFORM MANAGEMENT</span>
          ) : (
            <>
              <span>MODE: {businessModeLoading ? "LOADING" : "HOSPITALITY"}</span>
              <span className="max-w-[260px] truncate">SHOP: {branchDisplay}</span>
            </>
          )}
        </div>

        <span className="truncate ml-3">ServeNest POS v4 · {buildVersion}</span>
      </footer>

      <UserManagementFullModal
        open={userManagementOpen}
        onClose={() => setUserManagementOpen(false)}
      />

      <Roles open={roleOpen} onClose={() => setRoleOpen(false)} />

      <AuthenticationAdministrationModal
        open={authenticationAdministrationOpen}
        onClose={() => setAuthenticationAdministrationOpen(false)}
        isPlatformAdmin={userIsPlatformAdmin(user)}
        canManageAssignments={hasMenuPermission(user, "manage_access_assignments")}
        canManagePolicies={hasMenuPermission(user, "manage_authentication_policies")}
      />

      <GeneralSettings
        open={generalSettingsOpen}
        onClose={() => setSetGeneralSettingsOpen(false)}
      />

      <ShiftConfiguration
        open={shiftSettingsOpen}
        onClose={() => setShiftSettingsOpen(false)}
      />

      {!isHospitality && (
        <CreateSaleModal
          open={createSaleOpen}
          onClose={() => setCreateSaleOpen(false)}
        />
      )}

      <CompanyLicenseModal
        open={licenseOpen}
        onClose={() => setLicenseOpen(false)}
        companyId={activeCompanyId}
      />

      <PlatformCompanyManagementModal
        open={companyManagementOpen}
        onClose={() => setCompanyManagementOpen(false)}
        isPlatformAdmin={userIsPlatformAdmin(user)}
      />

      <HospitalityStructureManagementModal
        open={Boolean(hospitalityStructureSection)}
        onClose={() => setHospitalityStructureSection(null)}
        section={hospitalityStructureSection || "countries"}
        isPlatformAdmin={userIsPlatformAdmin(user)}
        onOpenShopSettings={!platformOnly && activeCompanyId ? () => {
          setHospitalityStructureSection(null);
          setBranchSettings(true);
        } : undefined}
      />

      <BranchManagementModal
        open={openBranchSettings}
        onClose={() => setBranchSettings(false)}
        adminMode
        companyId={activeCompanyId}
      />

      <ProductCategoryModal
        open={openCategoryManagement}
        onClose={() => setCategoryManagementOpen(false)}
      />

      <SupplierModal
        open={openSupplierManagement}
        onClose={() => setSupplierManagementOpen(false)}
      />

      {!isHospitality && (
        <ProductManagementFullModal
          open={openProductManagement}
          onClose={() => setProductManagementOpen(false)}
          currentUser={user}
          initialTab={productManagementInitialTab}
        />
      )}

      <InventoryManagementModal
        open={inventoryManagementOpen}
        onClose={() => setInventoryManagementOpen(false)}
        currentUser={user}
        initialTab={inventoryManagementInitialTab}
        isHospitality={isHospitality}
      />

      {/* Price levels are needed in every mode: Hospitality menu items are priced per level. */}
      <PriceLevelModal
        open={priceLevelOpen}
        onClose={() => setPriceLevelOpen(false)}
        currentUser={user}
      />

      <RolePriceLevelAssignModal
        open={rolePriceLevelOpen}
        onClose={() => setRolePriceLevelOpen(false)}
        currentUser={user}
      />

      {!isHospitality && (
        <>
          <ProductPriceListModal
            key={priceListRefreshKey}
            open={priceListOpen}
            onClose={() => setPriceListOpen(false)}
            onOpenAdjustment={() => setPriceAdjustmentOpen(true)}
            onAdjustPrice={({ product, price }) => {
              setSelectedPriceProduct(product);
              setSelectedProductPrice(price);
              setPriceAdjustmentOpen(true);
            }}
            onViewHistory={(product) => {
              setSelectedPriceProduct(product);
              setPriceHistoryOpen(true);
            }}
          />

          <ProductPriceAdjustmentModal
            open={priceAdjustmentOpen}
            onClose={() => setPriceAdjustmentOpen(false)}
            selectedProduct={selectedPriceProduct}
            selectedPrice={selectedProductPrice}
            onSaved={() => {
              setPriceListRefreshKey((prev) => prev + 1);
            }}
          />

          <ProductPriceAdjustmentHistoryModal
            open={priceHistoryOpen}
            onClose={() => setPriceHistoryOpen(false)}
            selectedProduct={selectedPriceProduct}
            businessModeLabel="Hospitality"
            onNavigateReport={navigateManagementReport}
          />
        </>
      )}

      {!isHospitality && (
        <ReceiveStockModal
          open={receiveStockOpen}
          onClose={() => setReceiveStockOpen(false)}
          currentBranchId={user?.branch_id || ""}
          currentBranchName={user?.branch?.branch_name || user?.branch?.name || ""}
          onSaved={() => {}}
        />
      )}

      <BulkStockAdjustmentModal
        open={bulkStockAdjustmentOpen}
        onClose={() => setBulkStockAdjustmentOpen(false)}
        currentBranchId={user?.branch_id || ""}
        currentBranchName={user?.branch?.branch_name || user?.branch?.name || ""}
        onSaved={() => {}}
      />

      {!isHospitality && (
        <StockAdjustmentHistoryModal
          open={stockAdjustmentHistoryOpen}
          onClose={() => setStockAdjustmentHistoryOpen(false)}
          currentBranchId={user?.branch_id || ""}
          currentBranchName={user?.branch?.branch_name || user?.branch?.name || ""}
          businessModeLabel="Hospitality"
          onNavigateReport={navigateInventoryReport}
        />
      )}

      <PosReportsModal
        open={posReportOpen}
        onClose={() => {
          setPosReportOpen(false);
          setSelectedPosReport(null);
        }}
        initialReport={selectedPosReport || "dashboard"}
        title="HOSPITALITY REPORTS"
        businessMode="fast_food_boh"
        useTradingDate
        branchId={activeBranchId}
        tradingDate={currentTradingDay || ""}
        allowedReports={["payments", "cashiers", "cashups", "cashouts"]}
      />

      <StockReportsModal
        open={stockReportOpen}
        onClose={() => {
          setStockReportOpen(false);
          setSelectedStockReport(null);
        }}
        initialReport={selectedStockReport}
        businessModeLabel="Hospitality"
        allowedReports={isHospitality ? ["branch-balance", "current-balance", "as-of", "movements", "received", "adjustments", "valuation", "low-stock", "out-of-stock"] : null}
        onNavigateReport={navigateInventoryReport}
      />

      <CurrencyManagementModal
        open={currencyOpen}
        onClose={() => setCurrencyOpen(false)}
      />

      <PaymentTypeManagementModal
        open={paymentTypeOpen}
        onClose={() => setPaymentTypeOpen(false)}
        canView={hasMenuPermission(user, "view_payment_type")}
        canCreate={hasMenuPermission(user, "create_payment_type")}
        canUpdate={hasMenuPermission(user, "update_payment_type")}
        canDelete={hasMenuPermission(user, "delete_payment_type")}
      />

      <TillManagementModal
        open={tillManagementOpen}
        onClose={() => setTillManagementOpen(false)}
        currentUser={user}
      />

      <DeploymentSyncModal
        open={deploymentSyncOpen}
        onClose={() => setDeploymentSyncOpen(false)}
      />

      <PrinterManagementModal
        open={printerOpen}
        onClose={() => setPrinterOpen(false)}
        currentUser={user}
      />

      {!isHospitality && (
        <ReceiptActionsModal
          open={showReceiptActions}
          onClose={() => setShowReceiptActions(false)}
        />
      )}

      {!isHospitality && (
        <ReceiptRegisterModal
          open={receiptRegisterOpen}
          onClose={() => setReceiptRegisterOpen(false)}
        />
      )}

      <ProductImportModal
        open={productImportOpen}
        onClose={() => setProductImportOpen(false)}
        isHospitality={isHospitality}
        onImported={() => {
          window.dispatchEvent(new Event("servenest_products_imported"));
        }}
      />

      {!isHospitality && (
        <StockTakeModal
          open={stockTakeOpen}
          onClose={() => setStockTakeOpen(false)}
        />
      )}

      {!isHospitality && (
        <CancelledReceiptsModal
          open={cancelledReceiptOpen}
          onClose={() => setCancelledReceiptOpen(false)}
        />
      )}

      <StockTransferModal
        open={stockTransferOpen}
        onClose={() => setStockTransferOpen(false)}
      />

      <EnterpriseCashupModal
        open={enterpriseCashupOpen}
        onClose={() => setEnterpriseCashupOpen(false)}
        closeShiftDefault
      />

      {!isHospitality && (
        <ProductBarcodePrintModal
          open={productBarcodePrintOpen}
          onClose={() => setProductBarcodePrintOpen(false)}
        />
      )}

      {!isHospitality && (
        <DiscountManagementModal
          open={discountManagementOpen}
          onClose={() => setDiscountManagementOpen(false)}
        />
      )}

      <DiscountReportsModal
        open={discountReportOpen}
        onClose={() => setDiscountReportOpen(false)}
        businessModeLabel="Hospitality"
        onNavigateReport={navigateManagementReport}
      />

      <TaxProductTaxManagementModal
        open={taxManagementOpen}
        onClose={() => setTaxManagementOpen(false)}
      />

      <ZimraFiscalisationModal
        open={fiscalDeviceOpen}
        onClose={() => setFiscalDeviceOpen(false)}
      />

      <EnterpriseTaxReportModal
        open={fiscalReportOpen}
        onClose={() => setFiscalReportOpen(false)}
        businessModeLabel="Hospitality"
        onNavigateReport={navigateManagementReport}
      />

      <ProfitAndLossReport
        open={profitReportOpen}
        onClose={() => setProfitReportOpen(false)}
        businessModeLabel="Hospitality"
        onNavigateReport={navigateManagementReport}
      />

      {isHospitality && (
        <MonthlyProfitComparisonReport
          open={monthlyProfitComparisonOpen}
          onClose={() => setMonthlyProfitComparisonOpen(false)}
          onNavigateReport={navigateManagementReport}
        />
      )}

      <PurchaseManagementModal
        open={purchaseManagementOpen}
        onClose={() => setPurchaseManagementOpen(false)}
        currentUser={user}
        initialTab={purchaseInitialTab}
      />

{/* <ManufacturingRecipeRegisterModal
  open={manufacturingRecipeRegisterOpen}
  onClose={() => setManufacturingRecipeRegisterOpen(false)}
  currentUser={user}
/> */}

{/* <ManufacturingRecipeFormModal
  open={manufacturingRecipeFormOpen}
  onClose={() => setManufacturingRecipeFormOpen(false)}
  currentUser={user}
/> */}

{/* <ManufacturingPlanRegisterModal
  open={manufacturingPlanRegisterOpen}
  onClose={() => setManufacturingPlanRegisterOpen(false)}
  currentUser={user}
/>

<ManufacturingPlanFormModal
  open={manufacturingPlanFormOpen}
  onClose={() => setManufacturingPlanFormOpen(false)}
  currentUser={user}
/> */}

{/* <ManufacturingMaterialRequirementsModal
  open={manufacturingMaterialRequirementsOpen}
  onClose={() => setManufacturingMaterialRequirementsOpen(false)}
  currentUser={user}
/> */}

{/* <ManufacturingOrderRegisterModal
  open={manufacturingOrderRegisterOpen}
  onClose={() => setManufacturingOrderRegisterOpen(false)}
  currentUser={user}
/>

<ManufacturingOrderFormModal
  open={manufacturingOrderFormOpen}
  onClose={() => setManufacturingOrderFormOpen(false)}
  currentUser={user}
/>

<ManufacturingOrderWorkflowModal
  open={manufacturingOrderWorkflowOpen}
  onClose={() => setManufacturingOrderWorkflowOpen(false)}
  currentUser={user}
/> */}

{/* <ManufacturingProductionReportModal
  open={manufacturingProductionReportOpen}
  onClose={() => setManufacturingProductionReportOpen(false)}
  currentUser={user}
/> */}

{/* <ManufacturingCostReportModal
  open={manufacturingCostReportOpen}
  onClose={() => setManufacturingCostReportOpen(false)}
  currentUser={user}
/> */}

{/* <ManufacturingMaterialUsageReportModal
  open={manufacturingMaterialUsageReportOpen}
  onClose={() => setManufacturingMaterialUsageReportOpen(false)}
  currentUser={user}
/> */}

{/* <ManufacturingWastageReportModal
  open={manufacturingWastageReportOpen}
  onClose={() => setManufacturingWastageReportOpen(false)}
  currentUser={user}
/> */}

{/* <ManufacturingVarianceReportModal
  open={manufacturingVarianceReportOpen}
  onClose={() => setManufacturingVarianceReportOpen(false)}
  currentUser={user}
/> */}

      {!isHospitality && (
        <>
          <ManufacturingRecipeManagementModal
            open={manufacturingRecipeManagementOpen}
            onClose={() => setManufacturingRecipeManagementOpen(false)}
            currentUser={user}
          />

          <ManufacturingPlanManagementModal
            open={manufacturingPlanManagementOpen}
            onClose={() => setManufacturingPlanManagementOpen(false)}
            currentUser={user}
          />

          <ManufacturingOrderWorkflowModal
            open={manufacturingOrderWorkflowOpen}
            onClose={() => setManufacturingOrderWorkflowOpen(false)}
            currentUser={user}
          />

          <ManufacturingReportsModal
            open={manufacturingReportsOpen}
            onClose={() => setManufacturingReportsOpen(false)}
            currentUser={user}
            businessModeLabel="Hospitality"
          />
        </>
      )}

<CreditCustomerManagementModal
  open={creditCustomerOpen}
  onClose={() => setCreditCustomerOpen(false)}
/>

      {isHospitality && (
        <>
          <DayEndEnterpriseGuardModal
            open={dayEndGuardOpen}
            branchId={activeBranchId}
            branchLabel={branchDisplay}
            onOpenIssueStock={() => {
              setDayEndGuardOpen(false);
              setIssueStockToFrontOpen(true);
            }}
            onOpenFrontStockReconciliation={() => {
              setDayEndGuardOpen(false);
              setDailyFrontStockClosingOpen(true);
            }}
            onClose={() => {
              setDayEndGuardOpen(false);
              loadCurrentTradingDay();
            }}
            onCompleted={loadCurrentTradingDay}
          />

          <BackStockMonthEndStockTakeModal
            open={monthEndStockTakeOpen}
            onClose={() => setMonthEndStockTakeOpen(false)}
            currentUser={user}
          />

          <HospitalityMonthEndModal
            open={hospitalityMonthEndOpen}
            onClose={() => setHospitalityMonthEndOpen(false)}
            currentUser={user}
          />

          <FrontStockRegisterModal
            open={frontStockManagementOpen}
            onClose={() => setFrontStockManagementOpen(false)}
            currentUser={user}
          />

          <BackStockRegisterModal
            open={backStockManagementOpen}
            onClose={() => setBackStockManagementOpen(false)}
            currentUser={user}
          />

          <IssueStockToFrontModal
            open={issueStockToFrontOpen}
            onClose={() => setIssueStockToFrontOpen(false)}
            currentUser={user}
          />

          <IssuedStockModal
            open={issuedStockOpen}
            onClose={() => setIssuedStockOpen(false)}
            currentUser={user}
          />

          <DailyFrontStockClosingModal
            open={dailyFrontStockClosingOpen}
            onClose={() => setDailyFrontStockClosingOpen(false)}
            onOpenIssueStock={() => {
              setDailyFrontStockClosingOpen(false);
              setIssueStockToFrontOpen(true);
            }}
            defaultCurrency="USD"
            currentUser={user}
          />

          <StockWastageModal
            open={stockWastageOpen}
            onClose={() => setStockWastageOpen(false)}
            currentUser={user}
          />

          <MenuItemManagementModal
            open={menuItemManagementOpen}
            onClose={() => setMenuItemManagementOpen(false)}
            currentUser={user}
            onCreateRecipeForMenuItem={(product) => {
              setRecipeMenuItem(product);
              setFoodRecipeBomOpen(true);
            }}
          />

          <HospitalityTableServiceModal
            open={hospitalityTableServiceOpen}
            onClose={() => setHospitalityTableServiceOpen(false)}
            currentUser={user}
          />

          <MenuItemRecipeCostReportModal
            open={menuItemRecipeCostReportOpen}
            onClose={() => setMenuItemRecipeCostReportOpen(false)}
            currentUser={user}
            onNavigateReport={navigateHospitalityReport}
          />

          <MenuItemPrepMessageConfigurationModal
            open={prepMessageConfigurationOpen}
            onClose={() => setPrepMessageConfigurationOpen(false)}
            currentUser={user}
          />

          <MenuItemModifierConfigurationModal
            open={modifierConfigurationOpen}
            onClose={() => setModifierConfigurationOpen(false)}
            currentUser={user}
          />

          <MenuRecipeDailyStockReconciliationModal
            open={menuRecipeDsrOpen}
            onClose={() => setMenuRecipeDsrOpen(false)}
            currentUser={user}
            onNavigateReport={navigateHospitalityReport}
          />

          <ClosingStockMovementReportModal
            open={closingStockMovementReportOpen}
            onClose={() => setClosingStockMovementReportOpen(false)}
            currentUser={user}
            onNavigateReport={navigateHospitalityReport}
          />

          <StockIssuedToFrontReportModal
            open={stockIssuedToFrontReportOpen}
            onClose={() => setStockIssuedToFrontReportOpen(false)}
            currentUser={user}
            onNavigateReport={navigateHospitalityReport}
          />

          <StockWastageReportModal
            open={stockWastageReportOpen}
            onClose={() => setStockWastageReportOpen(false)}
            currentUser={user}
            onNavigateReport={navigateHospitalityReport}
          />

          <FoodIndustrySalesReportModal
            open={foodIndustrySalesReportOpen}
            onClose={() => setFoodIndustrySalesReportOpen(false)}
            currentUser={user}
            onNavigateReport={navigateHospitalityReport}
          />

          <DailyFoodIndustrySalesReportModal
            open={dailyFoodSalesReportOpen}
            onClose={() => setDailyFoodSalesReportOpen(false)}
            currentUser={user}
            onNavigateReport={navigateHospitalityReport}
          />

          <ShiftReportModal
            open={shiftReportOpen}
            onClose={() => setShiftReportOpen(false)}
            currentUser={user}
            businessMode="fast_food_boh"
            tradingDate={currentTradingDay || ""}
            onNavigateReport={isHospitality ? navigateHospitalityReport : (reportKey) => {
              setShiftReportOpen(false);
              openPosReport(reportKey);
            }}
          />

          <FoodRecipeBomModal
            open={foodRecipeBomOpen}
            onClose={() => {
              setFoodRecipeBomOpen(false);
              setRecipeMenuItem(null);
            }}
            currentUser={user}
            initialMenuItem={recipeMenuItem}
          />

          <FoodManufacturingPlanningModal
            open={foodManufacturingPlanningOpen}
            onClose={() => setFoodManufacturingPlanningOpen(false)}
            currentUser={user}
          />

          <FoodManufacturingBatchModal
            open={foodManufacturingBatchOpen}
            onClose={() => setFoodManufacturingBatchOpen(false)}
            currentUser={user}
          />

          <FoodManufacturingReportsModal
            open={foodManufacturingReportsOpen}
            onClose={() => setFoodManufacturingReportsOpen(false)}
            currentUser={user}
          />
        </>
      )}


      {!isHospitality && qoutationOpen && (
        <QuotationDeskModal
          open={qoutationOpen}
          onClose={() => setQoutationOpen(false)}
        />
      )}
    </div>
    </BackOfficeThemeProvider>
  );
}

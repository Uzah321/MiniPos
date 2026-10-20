// import React, { useEffect, useState } from "react";
// import { Link, Outlet, useLocation } from "react-router-dom";
// import { useAdminAuth } from "./context/AdminAuthContext";
// import { logout } from "../services/AuthenticationService";
// import {
//   getCurrentTradingDay,
//   getDayEndPreflight,
//   runTradingDayEnd,
// } from "../services/TradingDayEndEnterpriseService";

// import UserManagementFullModal from "./pages/admin/UserManagment";
// import Roles from "./pages/admin/Roles";
// import GeneralSettings from "./pages/admin/GeneralSettings";
// import ShiftConfiguration from "./pages/admin/ShiftConfiguration";
// import CreateSaleModal from "./pages/sales/CreateSale";
// import CompanyLicenseModal from "./pages/admin/CompanyLicenseModal";
// import BranchManagementModal from "./pages/admin/BranchManagementModal";
// import ProductCategoryModal from "./pages/product/ProductCategoryModal";
// import SupplierModal from "./pages/purchases/SupplierModal";
// import ProductManagementFullModal from "./pages/product/ProductManagementFullModal";
// import PriceLevelModal from "./pages/product/PriceLevelModal";
// import RolePriceLevelAssignModal from "./pages/product/RolePriceLevelAssignModal";
// import ProductPriceAdjustmentModal from "./pages/product/ProductPriceAdjustmentModal";
// import ProductPriceAdjustmentHistoryModal from "./pages/report/ProductPriceAdjustmentHistoryModal";
// import ProductPriceListModal from "./pages/product/ProductPriceListModal";
// import ReceiveStockModal from "./pages/purchases/ReceiveStockModal";
// import BulkStockAdjustmentModal from "./pages/product/BulkStockAdjustmentModal";
// import StockAdjustmentHistoryModal from "./pages/report/StockAdjustmentHistoryModal";
// import CurrencyManagementModal from "./pages/admin/CurrencyManagementModal";
// import PaymentTypeManagementModal from "./pages/admin/PaymentTypeManagementModal";
// import TillManagementModal from "./pages/admin/TillManagementModal";
// import PrinterManagementModal from "./pages/intergration/PrinterManagementModal";
// import PosReportsModal from "./pages/report/PosReportsModal";
// import StockReportsModal from "./pages/report/StockReportsModal";
// import ReceiptActionsModal from "./pages/sales/ReceiptActionsModal";
// import ReceiptRegisterModal from "./pages/sales/ReceiptRegisterModal";
// import ProductImportModal from "./pages/product/ProductImportModal";
// import StockTakeModal from "./pages/product/StockTakeModal";
// import StockTransferModal from "./pages/product/StockTransferModal";
// import EnterpriseCashupModal from "../components/EnterpriseCashupModal";
// import CancelledReceiptsModal from "./pages/sales/CancelledReceiptsModal";
// import ProductBarcodePrintModal from "./pages/product/ProductBarcodePrintModal";
// import DiscountManagementModal from "./pages/discounts/DiscountManagementModal";
// import DiscountReportsModal from "./pages/report/DiscountReportsModal";
// import QuotationDeskModal from "./pages/quotation/QuotationDeskModal";
// import TaxProductTaxManagementModal from "./pages/product/TaxProductTaxManagementModal";
// import ZimraFiscalisationModal from "./pages/intergration/ZimraFiscalisationModal";
// import EnterpriseTaxReportModal from "./pages/report/EnterpriseTaxReportPage";
// import ProfitAndLossReport from "./pages/report/ProfitAndLossReport";
// import PurchaseManagementModal from "./pages/purchases/PurchaseManagementModal";

// import ManufacturingRecipeRegisterModal from "./pages/manufacturing/ManufacturingRecipeRegisterModal";
// // import ManufacturingRecipeFormModal from "./pages/manufacturing/ManufacturingRecipeFormModal";
// import ManufacturingPlanRegisterModal from "./pages/manufacturing/ManufacturingPlanRegisterModal";
// import ManufacturingPlanFormModal from "./pages/manufacturing/ManufacturingPlanFormModal";
// // import ManufacturingMaterialRequirementsModal from "./pages/manufacturing/ManufacturingMaterialRequirementsModal";
// import ManufacturingOrderRegisterModal from "./pages/manufacturing/ManufacturingOrderRegisterModal";
// import ManufacturingOrderFormModal from "./pages/manufacturing/ManufacturingOrderFormModal";
// import ManufacturingOrderWorkflowModal from "./pages/manufacturing/ManufacturingOrderWorkflowModal";
// // import ManufacturingProductionReportModal from "./pages/manufacturing/ManufacturingProductionReportModal";
// // import ManufacturingCostReportModal from "./pages/manufacturing/ManufacturingCostReportModal";
// // import ManufacturingMaterialUsageReportModal from "./pages/manufacturing/ManufacturingMaterialUsageReportModal";
// // import ManufacturingWastageReportModal from "./pages/manufacturing/ManufacturingWastageReportModal";
// // import ManufacturingVarianceReportModal from "./pages/manufacturing/ManufacturingVarianceReportModal";
// import ManufacturingRecipeManagementModal from "./pages/manufacturing/ManufacturingRecipeManagementModal";
// import ManufacturingPlanManagementModal from "./pages/manufacturing/ManufacturingPlanManagementModal";
// // import ManufacturingOrderWorkflowModal from "./pages/manufacturing/ManufacturingOrderWorkflowModal";
// import ManufacturingReportsModal from "./pages/manufacturing/ManufacturingReportsModal";
// import CreditCustomerManagementModal from "./pages/sales/CreditCustomerManagementModal";
// import InventoryManagementModal from "./boh/inventory/InventoryManagementModal";
// import {
//   BackStockRegisterModal,
//   FrontStockRegisterModal,
// } from "./boh/inventory/StockAreaRegisterModal";
// import IssueStockToFrontModal from "./boh/inventory/IssueStockToFrontModal";
// import FoodRecipeBomModal from "./boh/foodManufacturing/FoodRecipeBomModal";
// import FoodManufacturingBatchModal from "./boh/foodManufacturing/FoodManufacturingBatchModal";
// import FoodManufacturingReportsModal from "./boh/foodManufacturing/FoodManufacturingReportsModal";
// import MenuItemManagementModal from "./boh/menuItems/MenuItemManagementModal";
// import MenuItemRecipeCostReportModal from "./boh/menuItems/MenuItemRecipeCostReportModal";
// import MenuItemPrepMessageConfigurationModal from "./boh/menuItems/MenuItemPrepMessageConfigurationModal";
// import MenuItemModifierConfigurationModal from "./boh/menuItems/MenuItemModifierConfigurationModal";
// import DailyFrontStockClosingModal from "./boh/inventory/DailyFrontStockClosingModal";
// import DailyStockReconciliationReportModal from "./boh/reports/DailyStockReconciliationReportModal";
// import MenuRecipeDailyStockReconciliationModal from "./boh/reports/MenuRecipeDailyStockReconciliationModal";
// import ClosingStockMovementReportModal from "./boh/reports/ClosingStockMovementReportModal";
// import StockIssuedToFrontReportModal from "./boh/reports/StockIssuedToFrontReportModal";
// import StockWastageModal from "./boh/inventory/StockWastageModal";
// import StockWastageReportModal from "./boh/reports/StockWastageReportModal";
// import FoodIndustrySalesReportModal from "./boh/reports/FoodIndustrySalesReportModal";
// import DailyFoodIndustrySalesReportModal from "./boh/reports/DailyFoodIndustrySalesReportModal";
// import CashupHistoryReportModal from "./boh/reports/CashupHistoryReportModal";
// import CashoutReportModal from "./boh/reports/CashoutReportModal";
// import ShiftReportModal from "./boh/reports/ShiftReportModal";
// import DayEndEnterpriseGuardModal from "./boh/dayend/DayEndEnterpriseGuardModal";
// import FoodManufacturingPlanningModal from "./boh/foodManufacturing/FoodManufacturingPlanningModal";

// const normalizePermissionName = (value) =>
//   String(value || "")
//     .trim()
//     .toLowerCase()
//     .replaceAll(" ", "_")
//     .replaceAll("-", "_");

// const extractPermissionNames = (user) => {
//   const buckets = [
//     user?.role?.permissions,
//     user?.permissions,
//     user?.role_permissions,
//     user?.permission_names,
//     user?.data?.role?.permissions,
//     user?.data?.permissions,
//   ];

//   const names = new Set();

//   buckets.forEach((bucket) => {
//     if (!bucket) return;

//     if (Array.isArray(bucket)) {
//       bucket.forEach((item) => {
//         if (typeof item === "string") {
//           names.add(normalizePermissionName(item));
//           return;
//         }

//         const permissionName =
//           item?.name ||
//           item?.permission_name ||
//           item?.key ||
//           item?.slug ||
//           item?.permission?.name;

//         if (permissionName) {
//           names.add(normalizePermissionName(permissionName));
//         }
//       });

//       return;
//     }

//     if (typeof bucket === "object") {
//       Object.entries(bucket).forEach(([key, value]) => {
//         if (
//           value === true ||
//           value === 1 ||
//           value === "1" ||
//           value === "true"
//         ) {
//           names.add(normalizePermissionName(key));
//         }
//       });
//     }
//   });

//   return names;
// };

// const userIsSuperAdmin = (user) => {
//   const roleName = String(
//     user?.role?.name || user?.role_name || user?.role || "",
//   )
//     .toLowerCase()
//     .replaceAll(" ", "_");

//   return Boolean(
//     user?.is_super_admin ||
//     user?.is_admin ||
//     roleName === "super_admin" ||
//     roleName === "admin" ||
//     roleName === "administrator",
//   );
// };

// const hasMenuPermission = (user, permission) => {
//   if (!permission) return true;
//   if (userIsSuperAdmin(user)) return true;

//   const permissions = extractPermissionNames(user);

//   if (Array.isArray(permission)) {
//     return permission.some((item) =>
//       permissions.has(normalizePermissionName(item)),
//     );
//   }

//   return permissions.has(normalizePermissionName(permission));
// };

// const formatTradingDate = (value) => {
//   if (!value) return "NOT SET";

//   const raw = typeof value === "string" ? value.slice(0, 10) : value;
//   const parsed = new Date(`${raw}T00:00:00`);

//   if (Number.isNaN(parsed.getTime())) {
//     return String(value);
//   }

//   return parsed.toLocaleDateString("en-GB", {
//     day: "2-digit",
//     month: "short",
//     year: "numeric",
//   });
// };

// const resolveTradingDayDate = (payload) =>
//   payload?.trading_date ||
//   payload?.current_trading_date ||
//   payload?.date ||
//   payload?.data?.trading_date ||
//   payload?.data?.current_trading_date ||
//   null;

// const resolveTradingDayStatus = (payload) =>
//   payload?.status || payload?.data?.status || "open";

// const filterNavigationByPermission = (items, user) => {
//   return (items || [])
//     .map((item) => {
//       const allowed = hasMenuPermission(user, item.permission);

//       if (!allowed) return null;

//       if (Array.isArray(item.children) && item.children.length) {
//         const children = filterNavigationByPermission(item.children, user);

//         if (!children.length && !item.href && !item.modal) return null;

//         return {
//           ...item,
//           children,
//         };
//       }

//       return item;
//     })
//     .filter(Boolean);
// };

// const EnterpriseNoticeDialog = ({ title, message, tone = "info", onClose }) => {
//   const toneClass =
//     tone === "danger"
//       ? "border-[#B42318] bg-[#FEF3F2] text-[#B42318]"
//       : tone === "success"
//         ? "border-[#027A48] bg-[#ECFDF3] text-[#027A48]"
//         : "border-[#004141] bg-white text-[#004141]";

//   return (
//     <div className="fixed inset-0 z-[100002] flex items-center justify-center bg-black/45 px-4">
//       <div className={`w-full max-w-[560px] border shadow-2xl ${toneClass}`}>
//         <div className="border-b border-black/10 bg-white/70 px-5 py-4">
//           <div className="text-[15px] font-black uppercase tracking-wide">
//             {title}
//           </div>
//         </div>
//         <div className="whitespace-pre-line px-5 py-5 text-[13px] font-semibold text-[#004141]">
//           {message}
//         </div>
//         <div className="flex justify-end border-t border-black/10 bg-white/70 px-5 py-4">
//           <button
//             type="button"
//             onClick={onClose}
//             className="h-10 min-w-[110px] border border-[#004141] bg-[#004142] px-4 text-[11px] font-black uppercase tracking-wide text-white"
//           >
//             OK
//           </button>
//         </div>
//       </div>
//     </div>
//   );
// };


// const DayEndBlockedDialog = ({ open, payload, message, onClose, onOpenGuard }) => {
//   if (!open) return null;

//   const missingRows = Array.isArray(payload?.missing_front_stock_closings)
//     ? payload.missing_front_stock_closings
//     : Array.isArray(payload?.blocking_front_stock_closings)
//       ? payload.blocking_front_stock_closings
//       : [];

//   const visibleRows = missingRows.slice(0, 8);
//   const extraCount = Math.max(missingRows.length - visibleRows.length, 0);

//   return (
//     <div className="fixed inset-0 z-[100003] flex items-center justify-center bg-black/50 px-4">
//       <div className="w-full max-w-[720px] border border-[#BAC8C8] bg-white shadow-2xl">
//         <div className="border-b border-[#CAD5D5] bg-[#004142] px-5 py-4 text-white">
//           <div className="text-[15px] font-black uppercase tracking-wide">
//             Day End Needs Attention
//           </div>
//           <div className="mt-1 text-[11px] font-semibold text-white/75">
//             Missing Daily Front Stock Closings must be fixed before Day End can continue.
//           </div>
//         </div>

//         <div className="px-5 py-4">
//           <div className="border border-[#FEDF89] bg-[#FFFAEB] p-3 text-[12px] font-semibold text-[#B54708]">
//             {message || "Daily front stock closing is required before Day End."}
//           </div>

//           <div className="mt-4 border border-[#D8E1E0]">
//             <div className="grid grid-cols-[150px_1fr_130px] border-b border-[#D8E1E0] bg-[#F8FAFC] px-3 py-2 text-[10px] font-black uppercase tracking-wide text-[#5F6C6C]">
//               <div>Trading Date</div>
//               <div>Issue</div>
//               <div className="text-right">Status</div>
//             </div>

//             {visibleRows.length ? (
//               visibleRows.map((row) => (
//                 <div
//                   key={row.date || row.trading_date || row.closing_date}
//                   className="grid grid-cols-[150px_1fr_130px] border-b border-[#EFF2F2] px-3 py-2 text-[12px] font-semibold text-[#004141] last:border-b-0"
//                 >
//                   <div>{formatTradingDate(row.date || row.trading_date || row.closing_date)}</div>
//                   <div>{row.message || row.issue || "Front stock closing is missing."}</div>
//                   <div className="text-right text-[#B42318]">Missing</div>
//                 </div>
//               ))
//             ) : (
//               <div className="px-3 py-6 text-center text-[12px] font-semibold text-[#5F6C6C]">
//                 Open the Day End Guard to inspect the missing stock closing calendar.
//               </div>
//             )}
//           </div>

//           {extraCount > 0 && (
//             <div className="mt-2 text-[11px] font-semibold text-[#5F6C6C]">
//               + {extraCount} more missing day{extraCount === 1 ? "" : "s"}. Open the guard to view and fix all.
//             </div>
//           )}
//         </div>

//         <div className="flex justify-end gap-2 border-t border-[#E2E9E9] bg-[#F8FAFC] px-5 py-4">
//           <button
//             type="button"
//             onClick={onClose}
//             className="h-10 w-[110px] border border-[#ACB8B8] bg-white text-[11px] font-black uppercase tracking-wide text-[#004141]"
//           >
//             Close
//           </button>
//           <button
//             type="button"
//             onClick={onOpenGuard}
//             className="h-10 min-w-[190px] border border-[#008A8A] bg-[#00BCBC] px-4 text-[11px] font-black uppercase tracking-wide text-[#004141]"
//           >
//             Open Day End Guard
//           </button>
//         </div>
//       </div>
//     </div>
//   );
// };

// export default function BohAdminLayout() {
//   const [openMenu, setOpenMenu] = useState(null);
//   const [openNestedMenus, setOpenNestedMenus] = useState({});
//   const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
//   const [isFullscreen, setIsFullscreen] = useState(false);

//   const [userManagementOpen, setUserManagementOpen] = useState(false);
//   const [roleOpen, setRoleOpen] = useState(false);
//   const [licenseOpen, setLicenseOpen] = useState(false);
//   const [openCategoryManagement, setCategoryManagementOpen] = useState(false);
//   const [openProductManagement, setProductManagementOpen] = useState(false);
//   const [openSupplierManagement, setSupplierManagementOpen] = useState(false);
//   const [openBranchSettings, setBranchSettings] = useState(false);
//   const [createSaleOpen, setCreateSaleOpen] = useState(false);
//   const [shiftSettingsOpen, setShiftSettingsOpen] = useState(false);
//   const [generalSettingsOpen, setSetGeneralSettingsOpen] = useState(false);
//   const [priceLevelOpen, setPriceLevelOpen] = useState(false);
//   const [rolePriceLevelOpen, setRolePriceLevelOpen] = useState(false);
//   const [stockAdjustmentHistoryOpen, setStockAdjustmentHistoryOpen] =
//     useState(false);

//   const [priceListOpen, setPriceListOpen] = useState(false);
//   const [priceAdjustmentOpen, setPriceAdjustmentOpen] = useState(false);
//   const [priceHistoryOpen, setPriceHistoryOpen] = useState(false);
//   const [selectedPriceProduct, setSelectedPriceProduct] = useState(null);
//   const [selectedProductPrice, setSelectedProductPrice] = useState(null);
//   const [priceListRefreshKey, setPriceListRefreshKey] = useState(0);
//   const [receiveStockOpen, setReceiveStockOpen] = useState(false);
//   const [bulkStockAdjustmentOpen, setBulkStockAdjustmentOpen] = useState(false);
//   const [currencyOpen, setCurrencyOpen] = useState(false);
//   const [paymentTypeOpen, setPaymentTypeOpen] = useState(false);
//   const [tillManagementOpen, setTillManagementOpen] = useState(false);
//   const [isProcessingLogout, setIsProcessingLogout] = useState(false);
//   const [printerOpen, setPrinterOpen] = useState(false);
//   const [posReportOpen, setPosReportOpen] = useState(false);
//   const [selectedPosReport, setSelectedPosReport] = useState(null);
//   const [stockReportOpen, setStockReportOpen] = useState(false);
//   const [selectedStockReport, setSelectedStockReport] = useState(null);
//   const [showReceiptActions, setShowReceiptActions] = useState(false);
//   const [receiptRegisterOpen, setReceiptRegisterOpen] = useState(false);
//   const [productImportOpen, setProductImportOpen] = useState(false);
//   const [stockTakeOpen, setStockTakeOpen] = useState(false);
//   const [stockTransferOpen, setStockTransferOpen] = useState(false);
//   const [enterpriseCashupOpen, setEnterpriseCashupOpen] = useState(false);
//   const [cancelledReceiptOpen, setCancelledReceiptOpen] = useState(false);
//   const [productBarcodePrintOpen, setProductBarcodePrintOpen] = useState(false);
//   const [permissionDeniedMessage, setPermissionDeniedMessage] = useState("");
//   const [dailyFoodSalesReportOpen, setDailyFoodSalesReportOpen] =
//     useState(false);

//   const [cashupHistoryReportOpen, setCashupHistoryReportOpen] = useState(false);
//   const [cashoutReportOpen, setCashoutReportOpen] = useState(false);
//   const [shiftReportOpen, setShiftReportOpen] = useState(false);

//   const [discountManagementOpen, setDiscountManagementOpen] = useState(false);
//   const [discountReportOpen, setDiscountReportOpen] = useState(false);
//   const [qoutationOpen, setQoutationOpen] = useState(false);
//   const [taxManagementOpen, setTaxManagementOpen] = useState(false);
//   const [fiscalDeviceOpen, setFiscalDeviceOpen] = useState(false);
//   const [fiscalReportOpen, setFiscalReportOpen] = useState(false);
//   const [profitReportOpen, setProfitReportOpen] = useState(false);
//   const [dailyFrontStockClosingOpen, setDailyFrontStockClosingOpen] =
//     useState(false);
//   const [menuRecipeDsrOpen, setMenuRecipeDsrOpen] = useState(false);
//   const [currentTradingDay, setCurrentTradingDay] = useState(null);
//   const [tradingDayStatus, setTradingDayStatus] = useState("open");
//   const [dayEndConfirmOpen, setDayEndConfirmOpen] = useState(false);
//   const [releaseLockConfirmOpen, setReleaseLockConfirmOpen] = useState(false);
//   const [dayEndProcessing, setDayEndProcessing] = useState(false);
//   const [dayEndProgress, setDayEndProgress] = useState(0);
//   const [dayEndGuardOpen, setDayEndGuardOpen] = useState(false);
//   const [enterpriseNotice, setEnterpriseNotice] = useState(null);
//   const [dayEndBlockedOpen, setDayEndBlockedOpen] = useState(false);
//   const [dayEndBlockedPayload, setDayEndBlockedPayload] = useState(null);
//   const [dayEndBlockedMessage, setDayEndBlockedMessage] = useState("");

//   const [manufacturingRecipeRegisterOpen, setManufacturingRecipeRegisterOpen] =
//     useState(false);
//   const [manufacturingRecipeFormOpen, setManufacturingRecipeFormOpen] =
//     useState(false);

//   const [manufacturingPlanRegisterOpen, setManufacturingPlanRegisterOpen] =
//     useState(false);
//   const [manufacturingPlanFormOpen, setManufacturingPlanFormOpen] =
//     useState(false);

//   const [
//     manufacturingMaterialRequirementsOpen,
//     setManufacturingMaterialRequirementsOpen,
//   ] = useState(false);

//   const [manufacturingOrderRegisterOpen, setManufacturingOrderRegisterOpen] =
//     useState(false);
//   const [manufacturingOrderFormOpen, setManufacturingOrderFormOpen] =
//     useState(false);
//   const [manufacturingOrderWorkflowOpen, setManufacturingOrderWorkflowOpen] =
//     useState(false);

//   const [
//     manufacturingProductionReportOpen,
//     setManufacturingProductionReportOpen,
//   ] = useState(false);
//   const [manufacturingCostReportOpen, setManufacturingCostReportOpen] =
//     useState(false);
//   const [
//     manufacturingMaterialUsageReportOpen,
//     setManufacturingMaterialUsageReportOpen,
//   ] = useState(false);
//   const [manufacturingWastageReportOpen, setManufacturingWastageReportOpen] =
//     useState(false);
//   const [manufacturingVarianceReportOpen, setManufacturingVarianceReportOpen] =
//     useState(false);
//   const [frontStockManagementOpen, setFrontManagementStockOpen] =
//     useState(false);
//   const [backStockManagementOpen, setBackManagementStockOpen] = useState(false);
//   const [issueStockToFrontOpen, setIssueStockToFrontOpen] = useState(false);
//   const [dailyStockReconciliationOpen, setDailyStockReconciliationOpen] =
//     useState(false);
//   const [closingStockMovementReportOpen, setClosingStockMovementReportOpen] =
//     useState(false);

//   const [creditCustomerOpen, setCreditCustomerOpen] = useState(false);
//   const [foodRecipeBomOpen, setFoodRecipeBomOpen] = useState(false);
//   const [foodManufacturingBatchOpen, setFoodManufacturingBatchOpen] =
//     useState(false);
//   const [foodManufacturingReportsOpen, setFoodManufacturingReportsOpen] =
//     useState(false);

//   const [menuItemManagementOpen, setMenuItemManagementOpen] = useState(false);
//   const [recipeMenuItem, setRecipeMenuItem] = useState(null);
//   const [menuItemRecipeCostReportOpen, setMenuItemRecipeCostReportOpen] =
//     useState(false);
//   const [prepMessageConfigurationOpen, setPrepMessageConfigurationOpen] =
//     useState(false);
//   const [modifierConfigurationOpen, setModifierConfigurationOpen] =
//     useState(false);

//   const [stockWastageOpen, setStockWastageOpen] = useState(false);
//   const [stockWastageReportOpen, setStockWastageReportOpen] = useState(false);

//   const [stockIssuedToFrontReportOpen, setStockIssuedToFrontReportOpen] =
//     useState(false);

//   const [
//     manufacturingRecipeManagementOpen,
//     setManufacturingRecipeManagementOpen,
//   ] = useState(false);
//   const [manufacturingPlanManagementOpen, setManufacturingPlanManagementOpen] =
//     useState(false);
//   // const [manufacturingOrderWorkflowOpen, setManufacturingOrderWorkflowOpen] = useState(false);
//   const [manufacturingReportsOpen, setManufacturingReportsOpen] =
//     useState(false);

//     const [foodManufacturingPlanningOpen, setFoodManufacturingPlanningOpen] =
//   useState(false);

//   const [purchaseManagementOpen, setPurchaseManagementOpen] = useState(false);
//   const [foodIndustrySalesReportOpen, setFoodIndustrySalesReportOpen] =
//     useState(false);

//   const { user } = useAdminAuth();
//   const { pathname } = useLocation();

//   const loadCurrentTradingDay = async () => {
//     try {
//       const payload = await getCurrentTradingDay();
//       setCurrentTradingDay(resolveTradingDayDate(payload));
//       setTradingDayStatus(resolveTradingDayStatus(payload));
//     } catch (error) {
//       console.error("Failed to load current trading day:", error);
//     }
//   };

//   const showEnterpriseNotice = ({
//     title = "Notice",
//     message = "",
//     tone = "info",
//   } = {}) => {
//     setEnterpriseNotice({ title, message, tone });
//   };

//   useEffect(() => {
//     loadCurrentTradingDay();
//   }, []);

//   useEffect(() => {
//     if (!dayEndProcessing) return undefined;

//     setDayEndProgress(8);

//     const timer = window.setInterval(() => {
//       setDayEndProgress((prev) => {
//         if (prev >= 92) return prev;
//         return prev + 4;
//       });
//     }, 450);

//     return () => window.clearInterval(timer);
//   }, [dayEndProcessing]);

//   const handleRunDayEnd = async () => {
//     setDayEndConfirmOpen(false);
//     setDayEndProcessing(true);
//     setDayEndProgress(10);
//     setDayEndBlockedOpen(false);
//     setDayEndBlockedPayload(null);
//     setDayEndBlockedMessage("");

//     try {
//       const result = await runTradingDayEnd({});
//       setDayEndProgress(100);
//       await loadCurrentTradingDay();
//       showEnterpriseNotice({
//         title: "Day End Completed",
//         message:
//           result?.message ||
//           "Day End completed successfully and the next trading day was opened.",
//         tone: "success",
//       });
//       window.setTimeout(() => setDayEndProcessing(false), 650);
//     } catch (error) {
//       setDayEndProcessing(false);
//       setDayEndProgress(0);

//       const message =
//         error?.response?.data?.message || error?.message || "Day End failed.";

//       const looksLikeMissingClosing =
//         error?.response?.status === 422 &&
//         String(message).toLowerCase().includes("front stock closing");

//       if (looksLikeMissingClosing) {
//         let preflightPayload = error?.response?.data?.data || null;

//         try {
//           preflightPayload = await getDayEndPreflight({ date_to: new Date().toISOString().slice(0, 10) });
//         } catch {
//           // Keep the original Day End error visible even if the preflight lookup fails.
//         }

//         setDayEndBlockedMessage(message);
//         setDayEndBlockedPayload(preflightPayload);
//         setDayEndBlockedOpen(true);
//         return;
//       }

//       showEnterpriseNotice({
//         title: "Day End Failed",
//         message,
//         tone: "danger",
//       });
//     }
//   };

//   const handleReleaseLock = async () => {
//     setReleaseLockConfirmOpen(false);
//     setDayEndProcessing(true);
//     setDayEndProgress(20);

//     try {
//       const { rollbackTradingDayEndLock } = await import(
//         "../services/TradingDayEndEnterpriseService"
//       );
//       await rollbackTradingDayEndLock({});
//       setDayEndProgress(100);
//       await loadCurrentTradingDay();
//       showEnterpriseNotice({
//         title: "Day End Lock Released",
//         message: "The Day End lock has been released and the trading day was refreshed.",
//         tone: "success",
//       });
//       window.setTimeout(() => setDayEndProcessing(false), 650);
//     } catch (error) {
//       setDayEndProcessing(false);
//       setDayEndProgress(0);
//       showEnterpriseNotice({
//         title: "Release Lock Failed",
//         message:
//           error?.response?.data?.message ||
//           error?.message ||
//           "Release lock failed.",
//         tone: "danger",
//       });
//     }
//   };

//   const navigation = [
//     {
//       name: "Inventory",
//       children: [
//         {
//           name: "Stock Item Management",
//           modal: "product-management",
//           permission: "inventory_management",
//         },
//         {
//           name: "Back Stock Management",
//           modal: "back-stock-management",
//           permission: "inventory_management",
//         },

//         {
//           name: "Front Stock Management",

//           children: [
//             {
//               name: "Front Stock Management",
//               modal: "front-stock-management",
//               permission: "inventory_management",
//             },
//             {
//               name: "Daily Front Stock Closing",
//               modal: "daily-front-stock-closing",
//               permission: "inventory_management",
//             },
//           ],
//         },
//         {
//           name: "Issue Stock",
//           modal: "issue-stock-to-front",
//           permission: "inventory_management",
//         },
//         {
//           name: "Stock Wastage",
//           modal: "stock-wastage",
//           permission: "inventory_management",
//         },

//         {
//           name: "Department Management",
//           modal: "category-management",
//           permission: "view_product_category",
//         },
//         {
//           name: "Barcode Printing",
//           modal: "product-barcode-printing",
//           permission: "product_barcode_printing",
//         },
//         {
//           name: "Import Products",
//           modal: "product-import",
//           permission: "import_products",
//         },
//         {
//           name: "Price",
//           children: [
//             {
//               name: "Pricing Levels",
//               modal: "price-lavel",
//               permission: "price_management",
//             },
//             {
//               name: "Price List",
//               modal: "price-list",
//               permission: "view_price_list",
//             },
//             {
//               name: "Adjust Product Price",
//               modal: "adjust-price",
//               permission: "add_price",
//             },
//             {
//               name: "Assign Role Price",
//               modal: "role-price",
//               permission: "assign_role_price",
//             },
//           ],
//         },
//         {
//           name: "Stock",
//           children: [
//             {
//               name: "Stock Take",
//               modal: "stock-take",
//               permission: "stock_take",
//             },
//             {
//               name: "Stock Adjustment",
//               modal: "bulk-stock-adjustment",
//               permission: "adjust_stock",
//             },
//             {
//               name: "Interbranch Transfers",
//               modal: "stock-transfers",
//               permission: "stock_transfers",
//             },
//           ],
//         },
//       ],
//     },
//     {
//       name: "Purchases",
//       children: [
//         {
//           name: "Supplier Management",
//           modal: "supplier-management",
//           permission: "view_supplier",
//         },
//         {
//           name: "Purchase Management",
//           modal: "purchase-management",
//           permission: "purchase_orders",
//         },
//         {
//           name: "Quick Receive",
//           modal: "receive-stock",
//           permission: "receive_stock",
//         },
//       ],
//     },
//     {
//       name: "Menu Items",
//       children: [
//         {
//           name: "Menu Item Management",
//           modal: "menu-item-management",
//           permission: "inventory_management",
//         },
//         {
//           name: "Menu Recipe Cost Report",
//           modal: "menu-item-recipe-cost-report",
//           permission: "inventory_management",
//         },
        
//         {
//           name: "Extras",

//           children: [
//             {
//               name: "Prep Message Configuration",
//               modal: "prep-message-configuration",
//               permission: "inventory_management",
//             },
//             {
//               name: "Modifier Configuration",
//               modal: "modifier-configuration",
//               permission: "inventory_management",
//             },
//           ],
//         },
//       ],
//     },
//     {
//       name: "Reports",
//       children: [
//         {
//           name: "Sales Reports",
//           children: [
//             // {
//             //   name: "Sales Dashboard",
//             //   modal: "sales-report-dashboard",
//             //   permission: "view_sales_reports",
//             // },
//             {
//               name: "Daily Report Sales (DRS)",
//               modal: "daily-food-sales-report",
//               permission: "view_sales_reports",
//             },
//                {
//               name: "Detailed Sales Reports",
//               modal: "food-industry-sales-report",
//               permission: "view_sales_reports",
//             },
//             // {
//             //   name: "Sales List",
//             //   modal: "sales-report-sales-list",
//             //   permission: "view_sales_reports",
//             // },
//             // {
//             //   name: "Payment Summary",
//             //   modal: "sales-report-payments",
//             //   permission: "view_sales_reports",
//             // },
//             // {
//             //   name: "Product Sales",
//             //   modal: "sales-report-products",
//             //   permission: "view_sales_reports",
//             // },
//             // {
//             //   name: "Cashier Sales",
//             //   modal: "sales-report-cashiers",
//             //   permission: "view_sales_reports",
//             // },
//             // {
//             //   name: "Cashup History",
//             //   modal: "sales-report-cashups",
//             //   permission: "cash_up_reports",
//             // },
//             // {
//             //   name: "Cashout Report",
//             //   modal: "cashout-report",
//             //   permission: "cash_up_reports",
//             // },
//             {
//               name: "Shift Report",
//               modal: "shift-report",
//               permission: "cash_up_reports",
//             },
//             // {
//             //   name: "Shift Sales",
//             //   modal: "sales-report-shift-sales",
//             //   permission: "view_sales_reports",
//             // },
         
//           ],
//         },
//             {
//           name: "Cash Management",
//           children: [
//             // {
//             //   name: "Sales Dashboard",
//             //   modal: "sales-report-dashboard",
//             //   permission: "view_sales_reports",
//             // },
//             // {
//             //   name: "Daily Report Sales (DRS)",
//             //   modal: "daily-food-sales-report",
//             //   permission: "view_sales_reports",
//             // },
//             //    {
//             //   name: "Detailed Sales Reports",
//             //   modal: "food-industry-sales-report",
//             //   permission: "view_sales_reports",
//             // },
//             // {
//             //   name: "Sales List",
//             //   modal: "sales-report-sales-list",
//             //   permission: "view_sales_reports",
//             // },
//             // {
//             //   name: "Payment Summary",
//             //   modal: "sales-report-payments",
//             //   permission: "view_sales_reports",
//             // },
//             // {
//             //   name: "Product Sales",
//             //   modal: "sales-report-products",
//             //   permission: "view_sales_reports",
//             // },
//             // {
//             //   name: "Cashier Sales",
//             //   modal: "sales-report-cashiers",
//             //   permission: "view_sales_reports",
//             // },
//             {
//               name: "Cashup History",
//               modal: "sales-report-cashups",
//               permission: "cash_up_reports",
//             },
//             {
//               name: "Cashout Report",
//               modal: "cashout-report",
//               permission: "cash_up_reports",
//             },
//             // {
//             //   name: "Shift Report",
//             //   modal: "shift-report",
//             //   permission: "cash_up_reports",
//             // },
//             // {
//             //   name: "Shift Sales",
//             //   modal: "sales-report-shift-sales",
//             //   permission: "view_sales_reports",
//             // },
         
//           ],
//         },
//         {
//           name: "Inventory Reports",
//           children: [
//             // {
//             //   name: "Stock Dashboard",
//             //   modal: "stock-report-dashboard",
//             //   permission: "view_stock_reports",
//             // },
//             // {
//             //   name: "Company Balance",
//             //   modal: "stock-report-company-balance",
//             //   permission: "view_stock_reports",
//             // },
//             {
//               name: "Branch Balance",
//               modal: "stock-report-branch-balance",
//               permission: "view_stock_reports",
//             },
//             {
//               name: "Current Balance",
//               modal: "stock-report-current-balance",
//               permission: "view_stock_reports",
//             },
//             {
//               name: "As-Of Balance",
//               modal: "stock-report-as-of",
//               permission: "view_stock_reports",
//             },
//             {
//               name: "Stock Movements",
//               modal: "stock-report-movements",
//               permission: "view_stock_reports",
//             },
//             {
//               name: "Stock Received",
//               modal: "stock-report-received",
//               permission: "view_stock_reports",
//             },
//             {
//               name: "Stock Adjustments",
//               modal: "stock-report-adjustments",
//               permission: "view_stock_reports",
//             },
//             {
//               name: "Stock Valuation",
//               modal: "stock-report-valuation",
//               permission: "view_stock_reports",
//             },
//             {
//               name: "Low Stock",
//               modal: "stock-report-low-stock",
//               permission: "view_stock_reports",
//             },
//             {
//               name: "Out Of Stock",
//               modal: "stock-report-out-of-stock",
//               permission: "view_stock_reports",
//             },
//             {
//               name: "Stock Wastage Report",
//               modal: "stock-wastage-report",
//               permission: "view_stock_reports",
//             },
//             // {
//             //   name: "Negative Stock",
//             //   modal: "stock-report-negative-stock",
//             //   permission: "view_stock_reports",
//             // },
//             {
//               name: "Category Summary",
//               modal: "stock-report-category-summary",
//               permission: "view_stock_reports",
//             },
//             // {
//             //   name: "Supplier Summary",
//             //   modal: "stock-report-supplier-summary",
//             //   permission: "view_stock_reports",
//             // },
//             {
//               name: "Stock Issued To Front Report",
//               modal: "stock-issued-to-front-report",
//               permission: "view_stock_reports",
//             },

//             {
//               name: "Closing Stock Movement Report",
//               modal: "closing-stock-movement-report",
//               permission: "view_stock_reports",
//             },
//             {
//               name: "Menu Recipe Daily Stock Reconciliation",
//               modal: "menu-recipe-dsr",
//               permission: "inventory_management",
//             },
//           ],
//         },
//         {
//           name: "Profit & Loss",
//           modal: "profit-loss",
//           permission: "view_profit_loss",
//         },
//         {
//           name: "Price Adjustment",
//           modal: "price-adjustment-history",
//           permission: "view_price_adjustment_history",
//         },
//         {
//           name: "Discount Report",
//           modal: "discount-report",
//           permission: "view_discount_reports",
//         },
//         {
//           name: "Tax Reports",
//           modal: "tax-report",
//           permission: "tax_management",
//         },
//       ],
//     },

// {
//   name: "Food Manufacturing",
//   children: [
//     {
//       name: "Food Recipes / BOM",
//       modal: "food-recipes-bom",
//       permission: "food_manufacturing_view",
//     },
//     {
//       name: "Production Planning",
//       modal: "food-manufacturing-planning",
//       permission: "food_manufacturing_batches",
//     },
//     {
//       name: "Record Production",
//       modal: "food-manufacturing-batches",
//       permission: "food_manufacturing_batches",
//     },
//     {
//       name: "Production Reports",
//       modal: "food-manufacturing-reports",
//       permission: "food_manufacturing_reports",
//     },
//   ],
// },,
//   ];

//   const permittedNavigation = filterNavigationByPermission(navigation, user);

//   const denyPermission = (name = "this page") => {
//     setPermissionDeniedMessage(
//       `Access denied. Your role does not have permission to open ${name}.`,
//     );
//     window.setTimeout(() => setPermissionDeniedMessage(""), 4500);
//   };

//   const canOpenChild = (child) => hasMenuPermission(user, child?.permission);

//   const secureOpenChildModal = (child, openFn) => {
//     if (!canOpenChild(child)) {
//       denyPermission(child?.name || "this page");
//       return;
//     }

//     openFn();
//   };

//   const openPosReport = (reportKey) => {
//     setSelectedPosReport(reportKey);
//     setPosReportOpen(true);
//   };

//   const openStockReport = (reportKey) => {
//     setSelectedStockReport(reportKey);
//     setStockReportOpen(true);
//   };

//   const modalHandlers = {
//     "user-management": () => setUserManagementOpen(true),
//     "role-price": () => setRolePriceLevelOpen(true),
//     "price-lavel": () => setPriceLevelOpen(true),
//     "roles-permissions": () => setRoleOpen(true),
//     "general-settings": () => setSetGeneralSettingsOpen(true),
//     "shift-settings": () => setShiftSettingsOpen(true),
//     "create-sale": () => setCreateSaleOpen(true),
//     "license-settings": () => setLicenseOpen(true),
//     "branch-settings": () => setBranchSettings(true),
//     "category-management": () => setCategoryManagementOpen(true),
//     "supplier-management": () => setSupplierManagementOpen(true),
//     "product-management": () => setProductManagementOpen(true),
//     "adjust-price": () => setPriceAdjustmentOpen(true),
//     "price-list": () => setPriceListOpen(true),
//     "price-adjustment-history": () => setPriceHistoryOpen(true),
//     "receive-stock": () => setReceiveStockOpen(true),
//     "bulk-stock-adjustment": () => setBulkStockAdjustmentOpen(true),
//     "stock-adjustment-history": () => setStockAdjustmentHistoryOpen(true),
//     "currency-management": () => setCurrencyOpen(true),
//     "payment-type-management": () => setPaymentTypeOpen(true),
//     "till-management": () => setTillManagementOpen(true),
//     "printer-management": () => setPrinterOpen(true),
//     "pos-more-button": () => setShowReceiptActions(true),
//     "receipt-register": () => setReceiptRegisterOpen(true),
//     "product-import": () => setProductImportOpen(true),
//     "stock-take": () => setStockTakeOpen(true),
//     "stock-transfers": () => setStockTransferOpen(true),
//     "cancelled-receipt": () => setCancelledReceiptOpen(true),
//     "product-barcode-printing": () => setProductBarcodePrintOpen(true),
//     "manage-discount": () => setDiscountManagementOpen(true),
//     "discount-report": () => setDiscountReportOpen(true),
//     "qoutation-desk": () => setQoutationOpen(true),
//     "tax-management": () => setTaxManagementOpen(true),
//     "fiscal-device": () => setFiscalDeviceOpen(true),
//     "tax-report": () => setFiscalReportOpen(true),
//     "profit-loss": () => setProfitReportOpen(true),
//     "purchase-management": () => setPurchaseManagementOpen(true),
//     "credit-customers": () => setCreditCustomerOpen(true),
//     "back-stock-management": () => setBackManagementStockOpen(true),
//     "front-stock-management": () => setFrontManagementStockOpen(true),
//     "issue-stock-to-front": () => setIssueStockToFrontOpen(true),
//     "daily-front-stock-closing": () => setDailyFrontStockClosingOpen(true),
//     "daily-stock-reconciliation": () => setDailyStockReconciliationOpen(true),
//     "menu-recipe-dsr": () => setMenuRecipeDsrOpen(true),
//     "closing-stock-movement-report": () =>
//       setClosingStockMovementReportOpen(true),
//     "stock-issued-to-front-report": () => setStockIssuedToFrontReportOpen(true),
//     "food-industry-sales-report": () => setFoodIndustrySalesReportOpen(true),
//     "daily-food-sales-report": () => setDailyFoodSalesReportOpen(true),
//     "food-recipes-bom": () => setFoodRecipeBomOpen(true),
//     "food-manufacturing-batches": () => setFoodManufacturingBatchOpen(true),
//     "food-manufacturing-reports": () => setFoodManufacturingReportsOpen(true),
//     "food-manufacturing-planning": () => setFoodManufacturingPlanningOpen(true),

//     "menu-item-management": () => setMenuItemManagementOpen(true),
//     "prep-message-configuration": () => setPrepMessageConfigurationOpen(true),
//     "modifier-configuration": () => setModifierConfigurationOpen(true),

//     "manufacturing-recipes": () => setManufacturingRecipeManagementOpen(true),
//     "manufacturing-planning": () => setManufacturingPlanManagementOpen(true),
//     "manufacturing-workflow": () => setManufacturingOrderWorkflowOpen(true),
//     "manufacturing-reports": () => setManufacturingReportsOpen(true),
//     "menu-item-recipe-cost-report": () => setMenuItemRecipeCostReportOpen(true),
//     "stock-wastage": () => setStockWastageOpen(true),
//     "stock-wastage-report": () => setStockWastageReportOpen(true),

//     "sales-report-dashboard": () => openPosReport("dashboard"),
//     "sales-report-daily-sales": () => openPosReport("daily-sales"),
//     "sales-report-sales-list": () => openPosReport("sales-list"),
//     "sales-report-payments": () => openPosReport("payments"),
//     "sales-report-products": () => openPosReport("products"),
//     "sales-report-cashiers": () => openPosReport("cashiers"),
//     "sales-report-cashups": () => setCashupHistoryReportOpen(true),
//     "cashout-report": () => setCashoutReportOpen(true),
//     "shift-report": () => setShiftReportOpen(true),
//     "sales-report-shift-sales": () => openPosReport("shift-sales"),
//     "sales-report-day-ends": () => openPosReport("day-ends"),

//     "stock-report-dashboard": () => openStockReport("dashboard"),
//     "stock-report-company-balance": () => openStockReport("company-balance"),
//     "stock-report-branch-balance": () => openStockReport("branch-balance"),
//     "stock-report-current-balance": () => openStockReport("current-balance"),
//     "stock-report-as-of": () => openStockReport("as-of"),
//     "stock-report-movements": () => openStockReport("movements"),
//     "stock-report-received": () => openStockReport("received"),
//     "stock-report-adjustments": () => openStockReport("adjustments"),
//     "stock-report-valuation": () => openStockReport("valuation"),
//     "stock-report-low-stock": () => openStockReport("low-stock"),
//     "stock-report-out-of-stock": () => openStockReport("out-of-stock"),
//     "stock-report-negative-stock": () => openStockReport("negative-stock"),
//     "stock-report-category-summary": () => openStockReport("category-summary"),
//     "stock-report-supplier-summary": () => openStockReport("supplier-summary"),
//   };

//   const isLinkActive = (href) => href && pathname.startsWith(href);

//   const hasActiveChild = (children = []) => {
//     return children.some((child) => {
//       if (child.href && isLinkActive(child.href)) return true;
//       if (child.children) return hasActiveChild(child.children);
//       return false;
//     });
//   };

//   const closeMenus = () => {
//     setOpenMenu(null);
//     setOpenNestedMenus({});
//     setMobileMenuOpen(false);
//   };

//   const openTopMenu = (menuName, isOpen) => {
//     if (isOpen) {
//       setOpenMenu(null);
//       setOpenNestedMenus({});
//       return;
//     }

//     setOpenMenu(menuName);
//     setOpenNestedMenus({});
//   };

//   const openNestedMenuOnly = (key, level) => {
//     setOpenNestedMenus((prev) => {
//       const next = {};

//       Object.entries(prev).forEach(([openKey, meta]) => {
//         if (meta.level < level) {
//           next[openKey] = meta;
//         }
//       });

//       next[key] = { level };
//       return next;
//     });
//   };

//   const closeNestedMenuAndChildren = (key) => {
//     setOpenNestedMenus((prev) => {
//       const next = {};

//       Object.entries(prev).forEach(([openKey, meta]) => {
//         if (openKey !== key && !openKey.startsWith(`${key}||`)) {
//           next[openKey] = meta;
//         }
//       });

//       return next;
//     });
//   };

//   const toggleNestedMenu = (key, level = 0) => {
//     const isCurrentlyOpen = !!openNestedMenus[key];

//     if (isCurrentlyOpen) {
//       closeNestedMenuAndChildren(key);
//       return;
//     }

//     openNestedMenuOnly(key, level);
//   };

//   const handleChildClick = (child) => {
//     if (child.children?.length) return;

//     if (!hasMenuPermission(user, child.permission)) {
//       denyPermission(child.name);
//       return;
//     }

//     if (child.modal && modalHandlers[child.modal]) {
//       closeMenus();
//       modalHandlers[child.modal]();
//       return;
//     }

//     closeMenus();
//   };

//   const renderDesktopChild = (child, level = 0, parentKey = "") => {
//     const key = `${parentKey}||${child.name}`;
//     const hasChildren =
//       Array.isArray(child.children) && child.children.length > 0;
//     const isOpen = !!openNestedMenus[key];
//     const isActive = child.href
//       ? isLinkActive(child.href)
//       : hasActiveChild(child.children);

//     if (hasChildren) {
//       return (
//         <div
//           key={key}
//           className="relative overflow-visible group"
//           onMouseEnter={() => openNestedMenuOnly(key, level)}
//         >
//           <button
//             type="button"
//             onClick={() => toggleNestedMenu(key, level)}
//             className={`w-full h-10 px-4 text-left text-[12px] font-black border-b border-[#EFF2F2] flex items-center justify-between ${
//               isActive
//                 ? "bg-[#EBF6F6] text-[#008989]"
//                 : "text-[#003636] hover:bg-[#EBF6F6] hover:text-[#008989]"
//             }`}
//           >
//             <span>{child.name}</span>
//           </button>

//           {isOpen && (
//             <div className="absolute top-0 left-full w-72 bg-white border border-[#BAC8C8] shadow-[0_18px_45px_rgba(0,54,54,0.24)] z-[100001]">
//               <div className="h-[3px] bg-[#15D7D7]" />
//               {child.children.map((nestedChild) =>
//                 renderDesktopChild(nestedChild, level + 1, key),
//               )}
//             </div>
//           )}
//         </div>
//       );
//     }

//     if (child.modal) {
//       return (
//         <button
//           key={key}
//           type="button"
//           onClick={() => handleChildClick(child)}
//           className="block w-full h-10 text-left px-4 text-[12px] font-bold border-b border-[#EFF2F2] text-[#003636] hover:bg-[#EBF6F6] hover:text-[#008989]"
//         >
//           {child.name}
//         </button>
//       );
//     }

//     return (
//       <Link
//         key={key}
//         to={child.href}
//         onClick={() => handleChildClick(child)}
//         className={`block h-10 px-4 leading-10 text-[12px] font-bold border-b border-[#EFF2F2] ${
//           isLinkActive(child.href)
//             ? "bg-[#EBF6F6] text-[#008989]"
//             : "text-[#003636] hover:bg-[#EBF6F6] hover:text-[#008989]"
//         }`}
//       >
//         {child.name}
//       </Link>
//     );
//   };

//   const renderMobileChild = (child, level = 0, parentKey = "") => {
//     const key = `${parentKey}||${child.name}`;
//     const hasChildren =
//       Array.isArray(child.children) && child.children.length > 0;
//     const isOpen = !!openNestedMenus[key];
//     const isActive = child.href
//       ? isLinkActive(child.href)
//       : hasActiveChild(child.children);

//     const paddingLeft = level === 0 ? "pl-6" : level === 1 ? "pl-10" : "pl-14";

//     if (hasChildren) {
//       return (
//         <div key={key} className="border-b border-[#EFF2F2]">
//           <button
//             type="button"
//             onClick={() => toggleNestedMenu(key, level)}
//             className={`w-full h-11 pr-4 ${paddingLeft} text-left text-[12px] font-bold flex items-center justify-between ${
//               isActive
//                 ? "bg-[#EBF6F6] text-[#008989]"
//                 : "text-[#003636] hover:bg-[#EBF6F6] hover:text-[#008989]"
//             }`}
//           >
//             <span>{child.name}</span>
//           </button>

//           {isOpen && (
//             <div className="bg-white">
//               {child.children.map((nestedChild) =>
//                 renderMobileChild(nestedChild, level + 1, key),
//               )}
//             </div>
//           )}
//         </div>
//       );
//     }

//     if (child.modal) {
//       return (
//         <button
//           key={key}
//           type="button"
//           onClick={() => handleChildClick(child)}
//           className={`block w-full h-11 text-left pr-4 ${paddingLeft} text-[12px] font-semibold border-b border-[#EFF2F2] text-[#003636] hover:bg-[#EBF6F6] hover:text-[#008989]`}
//         >
//           {child.name}
//         </button>
//       );
//     }

//     return (
//       <Link
//         key={key}
//         to={child.href}
//         onClick={() => handleChildClick(child)}
//         className={`block h-11 leading-11 pr-4 ${paddingLeft} text-[12px] font-semibold border-b border-[#EFF2F2] ${
//           isLinkActive(child.href)
//             ? "bg-[#EBF6F6] text-[#008989]"
//             : "text-[#003636] hover:bg-[#EBF6F6] hover:text-[#008989]"
//         }`}
//       >
//         {child.name}
//       </Link>
//     );
//   };

//   const toggleFullscreen = async () => {
//     try {
//       if (!document.fullscreenElement) {
//         await document.documentElement.requestFullscreen();
//         setIsFullscreen(true);
//         return;
//       }

//       await document.exitFullscreen();
//       setIsFullscreen(false);
//     } catch (error) {
//       console.error("Fullscreen failed:", error);
//     }
//   };

//   useEffect(() => {
//     const handleFullscreenChange = () => {
//       setIsFullscreen(Boolean(document.fullscreenElement));
//     };

//     document.addEventListener("fullscreenchange", handleFullscreenChange);

//     return () => {
//       document.removeEventListener("fullscreenchange", handleFullscreenChange);
//     };
//   }, []);

//   const handleLogout = async () => {
//     try {
//       setIsProcessingLogout(true);
//       await logout();
//       localStorage.removeItem("back_authToken");
//       localStorage.removeItem("back_user");
//       window.location.href = "/login";
//     } catch (error) {
//       showEnterpriseNotice({
//         title: "Logout Failed",
//         message: error?.response?.data?.message || "Failed to log out.",
//         tone: "danger",
//       });
//     } finally {
//       setIsProcessingLogout(false);
//     }
//   };

//   useEffect(() => {
//     const close = (e) => {
//       if (!e.target.closest(".pos-menu-area")) {
//         setOpenMenu(null);
//         setOpenNestedMenus({});
//       }
//     };

//     document.addEventListener("mousedown", close);
//     return () => document.removeEventListener("mousedown", close);
//   }, []);

//   return (
//     <div className="h-screen flex flex-col bg-white text-[#003636] overflow-hidden font-sans">
//       <header className="pos-menu-area shrink-0 bg-[#004142] text-white border-b-2 border-[#00BCBC] shadow-[0_8px_20px_rgba(0,65,65,0.22)] z-[9000] relative overflow-visible">
//         <div className="h-14 flex items-stretch overflow-visible">
//           <Link
//             to="/admin"
//             onClick={closeMenus}
//             className="min-w-[108px] px-4 border-r border-white/15 flex flex-col items-center justify-center text-center hover:bg-white/10"
//             title="Back to POS"
//           >
//             <span className="text-[15px] font-black leading-none tracking-wide text-white">
//               SERVENEST
//             </span>
//             <span className="mt-1 text-[9px] font-black uppercase tracking-[0.28em] text-[#00BCBC]">
//               BOH
//             </span>
//           </Link>

//           <nav className="hidden lg:flex h-full flex-1 items-stretch overflow-visible">
//             {permittedNavigation.map((item) => {
//               const hasChildren =
//                 Array.isArray(item.children) && item.children.length > 0;
//               const isOpen = openMenu === `toolbar-${item.name}`;
//               const isActive = hasChildren
//                 ? hasActiveChild(item.children)
//                 : isLinkActive(item.href);

//               if (!hasChildren && item.href) {
//                 return (
//                   <Link
//                     key={`toolbar-${item.name}`}
//                     to={item.href}
//                     onClick={closeMenus}
//                     className={`min-w-[118px] px-4 border-r border-white/10 flex items-center justify-center text-center text-[12px] font-black uppercase tracking-wide transition-colors ${
//                       isActive
//                         ? "bg-[#00BCBC] text-[#004141]"
//                         : "text-white hover:bg-white/10 hover:text-[#00BCBC]"
//                     }`}
//                   >
//                     {item.name}
//                   </Link>
//                 );
//               }

//               return (
//                 <div
//                   key={`toolbar-${item.name}`}
//                   className="relative min-w-[118px] border-r border-white/10 overflow-visible"
//                 >
//                   <button
//                     type="button"
//                     onClick={() => openTopMenu(`toolbar-${item.name}`, isOpen)}
//                     className={`h-full w-full px-4 text-center text-[12px] font-black uppercase tracking-wide transition-colors ${
//                       isOpen || isActive
//                         ? "bg-[#00BCBC] text-[#004141]"
//                         : "text-white hover:bg-white/10 hover:text-[#00BCBC]"
//                     }`}
//                   >
//                     {item.name}
//                   </button>

//                   {isOpen && hasChildren && (
//                     <div className="absolute left-0 top-full w-[310px] bg-white border border-[#BAC8C8] shadow-[0_20px_50px_rgba(0,54,54,0.28)] z-[100000] text-[#003636]">
//                       <div className="h-1 bg-[#00BCBC]" />
//                       <div className="px-4 py-2 border-b border-[#E2E9E9] bg-[#F8FAFC] text-[11px] font-black uppercase tracking-wide text-[#004141]">
//                         {item.name}
//                       </div>
//                       {item.children.map((child) =>
//                         renderDesktopChild(child, 0, `toolbar-${item.name}`),
//                       )}
//                     </div>
//                   )}
//                 </div>
//               );
//             })}
//           </nav>

//           <button
//             type="button"
//             onClick={() => {
//               setMobileMenuOpen(true);
//               setOpenMenu(null);
//               setOpenNestedMenus({});
//             }}
//             className="lg:hidden ml-auto h-full px-4 border-l border-white/15 text-[12px] font-black uppercase tracking-wide text-white hover:bg-white/10"
//           >
//             Menu
//           </button>

//           <button
//             type="button"
//             onClick={toggleFullscreen}
//             className="hidden sm:flex h-full px-4 bg-[#003838] border-l border-white/15 text-[11px] font-black uppercase tracking-wide hover:bg-white/10 hover:text-[#00BCBC] items-center"
//             title={isFullscreen ? "Exit fullscreen" : "Enter fullscreen"}
//           >
//             {isFullscreen ? "Exit Full" : "Fullscreen"}
//           </button>

//           <button
//             type="button"
//             onClick={handleLogout}
//             disabled={isProcessingLogout}
//             className="hidden sm:flex h-full px-4 bg-[#003838] border-l border-white/15 text-[11px] font-black uppercase tracking-wide hover:bg-red-700 items-center disabled:opacity-60"
//           >
//             {isProcessingLogout ? "Logging out" : "Logout"}
//           </button>
//         </div>
//       </header>

//       <section className="shrink-0 border-b border-[#BAC8C8] bg-[#EFF3F3] px-2 py-2">
//         <div className="flex items-center justify-between gap-3">
//           <div className="flex items-center gap-2">
//             <button
//               type="button"
//               onClick={() => setDayEndConfirmOpen(true)}
//               disabled={dayEndProcessing}
//               className="h-10 w-[128px] border border-[#B38A18] bg-[#F4C542] text-[#3B2A00] text-[11px] font-black uppercase tracking-wide shadow-[0_3px_0_#B38A18] disabled:opacity-60"
//             >
//               Run Day End
//             </button>

//             <button
//               type="button"
//               onClick={() => setReleaseLockConfirmOpen(true)}
//               disabled={dayEndProcessing}
//               className="h-10 w-[128px] border border-[#ACB8B8] bg-white text-[#004141] text-[11px] font-black uppercase tracking-wide shadow-[0_3px_0_#ACB8B8] disabled:opacity-60"
//             >
//               Release Lock
//             </button>

//             <button
//               type="button"
//               onClick={() => setMenuRecipeDsrOpen(true)}
//               className="h-10 w-[128px] border border-[#008A8A] bg-[#00BCBC] text-[#004141] text-[11px] font-black uppercase tracking-wide shadow-[0_3px_0_#008A8A]"
//             >
//               D.S.R
//             </button>
//           </div>

//           <div className="min-w-[230px] border border-[#ACB8B8] bg-white px-3 py-1.5 text-right">
//             <div className="text-[9px] font-black uppercase tracking-[0.22em] text-[#5F6C6C]">
//               Trading Date
//             </div>
//             <div className="text-[14px] font-black text-[#004141]">
//               {formatTradingDate(currentTradingDay)}
//             </div>
//             <div className="text-[9px] font-bold uppercase tracking-wide text-[#057B7C]">
//               {tradingDayStatus}
//             </div>
//           </div>
//         </div>
//       </section>

//       {permissionDeniedMessage && (
//         <div className="fixed right-4 top-24 z-[100000] max-w-[420px] border border-[#FDA29B] bg-[#FEF3F2] px-4 py-3 text-[#B42318] shadow-xl">
//           <div className="text-[12px] font-black uppercase tracking-wide">
//             Permission Required
//           </div>
//           <div className="mt-1 text-[12px] font-semibold">
//             {permissionDeniedMessage}
//           </div>
//         </div>
//       )}

//       {enterpriseNotice && (
//         <EnterpriseNoticeDialog
//           title={enterpriseNotice.title}
//           message={enterpriseNotice.message}
//           tone={enterpriseNotice.tone}
//           onClose={() => setEnterpriseNotice(null)}
//         />
//       )}

//       <DayEndBlockedDialog
//         open={dayEndBlockedOpen}
//         payload={dayEndBlockedPayload}
//         message={dayEndBlockedMessage}
//         onClose={() => setDayEndBlockedOpen(false)}
//         onOpenGuard={() => {
//           setDayEndBlockedOpen(false);
//           setDayEndGuardOpen(true);
//         }}
//       />

//       {mobileMenuOpen && (
//         <div className="fixed inset-0 z-[9999] lg:hidden">
//           <button
//             type="button"
//             className="absolute inset-0 bg-black/50"
//             onClick={() => {
//               setMobileMenuOpen(false);
//               setOpenMenu(null);
//               setOpenNestedMenus({});
//             }}
//           />

//           <aside className="absolute top-0 left-0 right-0 max-h-[92vh] bg-white text-[#003636] border-b border-[#CAD5D5] overflow-y-auto shadow-2xl">
//             <div className="h-12 flex items-center justify-between px-4 border-b border-[#D8E1E0] bg-[#F8FAFC]">
//               <div className="leading-tight">
//                 <div className="text-[15px] font-black tracking-wide">
//                   SERVENEST POS
//                 </div>
//                 <div className="text-[9px] tracking-[0.24em] font-bold text-[#5F6C6C]">
//                   BACK OFFICE
//                 </div>
//               </div>

//               <button
//                 type="button"
//                 onClick={() => {
//                   setMobileMenuOpen(false);
//                   setOpenMenu(null);
//                   setOpenNestedMenus({});
//                 }}
//                 className="h-9 px-3 border border-[#CAD5D5] text-[11px] font-semibold hover:bg-[#F0F5F5]"
//               >
//                 Close
//               </button>
//             </div>

//             <div className="grid grid-cols-2 text-[11px] font-bold border-b border-[#D8E1E0] bg-[#F4F7F7]">
//               <Link
//                 to="/admin"
//                 onClick={closeMenus}
//                 className="h-11 px-4 border-r border-[#D8E1E0] flex items-center uppercase tracking-wide hover:bg-white"
//               >
//                 POS
//               </Link>

//               <button
//                 type="button"
//                 onClick={toggleFullscreen}
//                 className="h-11 px-4 flex items-center uppercase tracking-wide hover:bg-white"
//               >
//                 {isFullscreen ? "Exit Full" : "Fullscreen"}
//               </button>
//             </div>

//             <nav>
//               {permittedNavigation.map((item) => {
//                 const hasChildren =
//                   Array.isArray(item.children) && item.children.length > 0;
//                 const isOpen = openMenu === item.name;
//                 const isActive = hasChildren
//                   ? hasActiveChild(item.children)
//                   : isLinkActive(item.href);

//                 if (!hasChildren && item.href) {
//                   return (
//                     <Link
//                       key={item.name}
//                       to={item.href}
//                       onClick={closeMenus}
//                       className={`w-full h-12 px-4 text-left text-[13px] font-black uppercase tracking-wide flex items-center ${
//                         isActive
//                           ? "bg-[#F0F5F5] text-[#057B7C]"
//                           : "text-[#003636] hover:bg-[#F0F5F5]"
//                       }`}
//                     >
//                       {item.name}
//                     </Link>
//                   );
//                 }

//                 return (
//                   <div key={item.name} className="border-b border-[#D8E1E0]">
//                     <button
//                       type="button"
//                       onClick={() => openTopMenu(item.name, isOpen)}
//                       className={`w-full h-12 px-4 text-left text-[13px] font-black uppercase tracking-wide flex items-center ${
//                         isOpen || isActive
//                           ? "bg-[#F0F5F5] text-[#057B7C]"
//                           : "text-[#003636] hover:bg-[#F0F5F5]"
//                       }`}
//                     >
//                       <span>{item.name}</span>
//                     </button>

//                     {isOpen && hasChildren && (
//                       <div className="bg-white">
//                         {item.children.map((child) =>
//                           renderMobileChild(child, 0, item.name),
//                         )}
//                       </div>
//                     )}
//                   </div>
//                 );
//               })}
//             </nav>

//             <button
//               type="button"
//               onClick={handleLogout}
//               disabled={isProcessingLogout}
//               className="w-full h-12 px-4 bg-[#FEE2E2] text-[#991B1B] text-left text-[13px] font-black uppercase tracking-wide disabled:opacity-60"
//             >
//               {isProcessingLogout ? "Logging out" : "Logout"}
//             </button>
//           </aside>
//         </div>
//       )}

//       <main className="relative flex-1 bg-white overflow-y-auto z-10 border-t border-[#EFF2F2]">
//         <div className="absolute inset-0 pointer-events-none">
//           <div className="absolute right-8 bottom-8 text-right opacity-30">
//             <div className="text-[42px] sm:text-[56px] font-black tracking-tight text-[#003636] leading-none">
//               BOH
//             </div>
//             <div className="text-[18px] sm:text-[24px] font-black tracking-[0.5em] text-[#057B7C]">
//               STOCK
//             </div>
//           </div>
//         </div>

//         <div className="relative z-10 min-h-full">
//           <Outlet />
//         </div>
//       </main>

//       {dayEndProcessing && (
//         <div className="shrink-0 border-t border-[#ACB8B8] bg-[#F8FAFC]">
//           <div className="h-2 bg-[#D8E1E0]">
//             <div
//               className="h-full bg-[#00BCBC] transition-all duration-300"
//               style={{ width: `${dayEndProgress}%` }}
//             />
//           </div>
//           <div className="px-3 py-1 text-[10px] font-black uppercase tracking-wide text-[#004141]">
//             Day End Processing {Math.round(dayEndProgress)}%
//           </div>
//         </div>
//       )}

//       {dayEndConfirmOpen && (
//         <div className="fixed inset-0 z-[100000] flex items-center justify-center bg-black/55 px-4">
//           <div className="w-full max-w-[520px] border border-[#ACB8B8] bg-white shadow-2xl">
//             <div className="border-b border-[#CAD5D5] bg-[#004142] px-5 py-4 text-white">
//               <div className="text-[15px] font-black uppercase tracking-wide">
//                 Confirm Day End
//               </div>
//               <div className="mt-1 text-[11px] font-semibold text-white/75">
//                 Trading date: {formatTradingDate(currentTradingDay)}
//               </div>
//             </div>
//             <div className="px-5 py-5 text-[13px] font-semibold text-[#004141]">
//               Are you sure you want to run Day End for this trading date? POS
//               will be locked while the process is running.
//             </div>
//             <div className="flex justify-end gap-2 border-t border-[#E2E9E9] bg-[#F8FAFC] px-5 py-4">
//               <button
//                 type="button"
//                 onClick={() => setDayEndConfirmOpen(false)}
//                 className="h-10 w-[110px] border border-[#ACB8B8] bg-white text-[11px] font-black uppercase tracking-wide text-[#004141]"
//               >
//                 Cancel
//               </button>
//               <button
//                 type="button"
//                 onClick={handleRunDayEnd}
//                 className="h-10 w-[130px] border border-[#B38A18] bg-[#F4C542] text-[11px] font-black uppercase tracking-wide text-[#3B2A00]"
//               >
//                 Run Day End
//               </button>
//             </div>
//           </div>
//         </div>
//       )}

//       {releaseLockConfirmOpen && (
//         <div className="fixed inset-0 z-[100000] flex items-center justify-center bg-black/55 px-4">
//           <div className="w-full max-w-[520px] border border-[#ACB8B8] bg-white shadow-2xl">
//             <div className="border-b border-[#CAD5D5] bg-[#004142] px-5 py-4 text-white">
//               <div className="text-[15px] font-black uppercase tracking-wide">
//                 Release Day End Lock
//               </div>
//               <div className="mt-1 text-[11px] font-semibold text-white/75">
//                 Trading date: {formatTradingDate(currentTradingDay)}
//               </div>
//             </div>
//             <div className="px-5 py-5 text-[13px] font-semibold text-[#004141]">
//               Are you sure you want to release the running Day End lock? Use
//               this only if Day End was interrupted.
//             </div>
//             <div className="flex justify-end gap-2 border-t border-[#E2E9E9] bg-[#F8FAFC] px-5 py-4">
//               <button
//                 type="button"
//                 onClick={() => setReleaseLockConfirmOpen(false)}
//                 className="h-10 w-[110px] border border-[#ACB8B8] bg-white text-[11px] font-black uppercase tracking-wide text-[#004141]"
//               >
//                 Cancel
//               </button>
//               <button
//                 type="button"
//                 onClick={handleReleaseLock}
//                 className="h-10 w-[130px] border border-[#ACB8B8] bg-white text-[11px] font-black uppercase tracking-wide text-[#004141]"
//               >
//                 Release Lock
//               </button>
//             </div>
//           </div>
//         </div>
//       )}

//       <DayEndEnterpriseGuardModal
//         open={dayEndGuardOpen}
//         onClose={() => {
//           setDayEndGuardOpen(false);
//           loadCurrentTradingDay();
//         }}
//         onCompleted={() => {
//           loadCurrentTradingDay();
//         }}
//       />

//       <UserManagementFullModal
//         open={userManagementOpen}
//         onClose={() => setUserManagementOpen(false)}
//       />

//       <Roles open={roleOpen} onClose={() => setRoleOpen(false)} />

//       <GeneralSettings
//         open={generalSettingsOpen}
//         onClose={() => setSetGeneralSettingsOpen(false)}
//       />

//       <ShiftConfiguration
//         open={shiftSettingsOpen}
//         onClose={() => setShiftSettingsOpen(false)}
//       />

//       <CreateSaleModal
//         open={createSaleOpen}
//         onClose={() => setCreateSaleOpen(false)}
//       />

//       <CompanyLicenseModal
//         open={licenseOpen}
//         onClose={() => setLicenseOpen(false)}
//         companyId={user?.company?.id}
//       />

//       <BranchManagementModal
//         open={openBranchSettings}
//         onClose={() => setBranchSettings(false)}
//         adminMode
//         companyId={user?.company?.id}
//       />

//       <ProductCategoryModal
//         open={openCategoryManagement}
//         onClose={() => setCategoryManagementOpen(false)}
//       />

//       <SupplierModal
//         open={openSupplierManagement}
//         onClose={() => setSupplierManagementOpen(false)}
//       />

//       <InventoryManagementModal
//         open={openProductManagement}
//         onClose={() => setProductManagementOpen(false)}
//         currentUser={user}
//       />

//       <PriceLevelModal
//         open={priceLevelOpen}
//         onClose={() => setPriceLevelOpen(false)}
//         currentUser={user}
//       />

//       <RolePriceLevelAssignModal
//         open={rolePriceLevelOpen}
//         onClose={() => setRolePriceLevelOpen(false)}
//         currentUser={user}
//       />

//       <ProductPriceListModal
//         key={priceListRefreshKey}
//         open={priceListOpen}
//         onClose={() => setPriceListOpen(false)}
//         onOpenAdjustment={() => setPriceAdjustmentOpen(true)}
//         onAdjustPrice={({ product, price }) => {
//           setSelectedPriceProduct(product);
//           setSelectedProductPrice(price);
//           setPriceAdjustmentOpen(true);
//         }}
//         onViewHistory={(product) => {
//           setSelectedPriceProduct(product);
//           setPriceHistoryOpen(true);
//         }}
//       />

//       <ProductPriceAdjustmentModal
//         open={priceAdjustmentOpen}
//         onClose={() => setPriceAdjustmentOpen(false)}
//         selectedProduct={selectedPriceProduct}
//         selectedPrice={selectedProductPrice}
//         onSaved={() => {
//           setPriceListRefreshKey((prev) => prev + 1);
//         }}
//       />

//       <ProductPriceAdjustmentHistoryModal
//         open={priceHistoryOpen}
//         onClose={() => setPriceHistoryOpen(false)}
//         selectedProduct={selectedPriceProduct}
//       />

//       <ReceiveStockModal
//         open={receiveStockOpen}
//         onClose={() => setReceiveStockOpen(false)}
//         currentBranchId={user?.branch_id || ""}
//         currentBranchName={
//           user?.branch?.branch_name || user?.branch?.name || ""
//         }
//         onSaved={() => {}}
//       />

//       <BulkStockAdjustmentModal
//         open={bulkStockAdjustmentOpen}
//         onClose={() => setBulkStockAdjustmentOpen(false)}
//         currentBranchId={user?.branch_id || ""}
//         currentBranchName={
//           user?.branch?.branch_name || user?.branch?.name || ""
//         }
//         onSaved={() => {}}
//       />

//       <StockAdjustmentHistoryModal
//         open={stockAdjustmentHistoryOpen}
//         onClose={() => setStockAdjustmentHistoryOpen(false)}
//         currentBranchId={user?.branch_id || ""}
//         currentBranchName={
//           user?.branch?.branch_name || user?.branch?.name || ""
//         }
//       />

//       <PosReportsModal
//         open={posReportOpen}
//         onClose={() => {
//           setPosReportOpen(false);
//           setSelectedPosReport(null);
//         }}
//         initialReport={selectedPosReport || "payments"}
//       />

//       <StockReportsModal
//         open={stockReportOpen}
//         onClose={() => {
//           setStockReportOpen(false);
//           setSelectedStockReport(null);
//         }}
//         initialReport={selectedStockReport}
//       />

//       <CurrencyManagementModal
//         open={currencyOpen}
//         onClose={() => setCurrencyOpen(false)}
//       />

//       <PaymentTypeManagementModal
//         open={paymentTypeOpen}
//         onClose={() => setPaymentTypeOpen(false)}
//       />

//       <TillManagementModal
//         open={tillManagementOpen}
//         onClose={() => setTillManagementOpen(false)}
//         currentUser={user}
//       />

//       <PrinterManagementModal
//         open={printerOpen}
//         onClose={() => setPrinterOpen(false)}
//         currentUser={user}
//       />

//       <ReceiptActionsModal
//         open={showReceiptActions}
//         onClose={() => setShowReceiptActions(false)}
//       />

//       <ReceiptRegisterModal
//         open={receiptRegisterOpen}
//         onClose={() => setReceiptRegisterOpen(false)}
//       />

//       <ProductImportModal
//         open={productImportOpen}
//         onClose={() => setProductImportOpen(false)}
//         onImported={() => {
//           window.dispatchEvent(new Event("salexa_products_imported"));
//         }}
//       />

//       <StockTakeModal
//         open={stockTakeOpen}
//         onClose={() => setStockTakeOpen(false)}
//       />

//       <CancelledReceiptsModal
//         open={cancelledReceiptOpen}
//         onClose={() => setCancelledReceiptOpen(false)}
//       />

//       <StockTransferModal
//         open={stockTransferOpen}
//         onClose={() => setStockTransferOpen(false)}
//       />

//       <EnterpriseCashupModal
//         open={enterpriseCashupOpen}
//         onClose={() => setEnterpriseCashupOpen(false)}
//         closeShiftDefault
//       />

//       <ProductBarcodePrintModal
//         open={productBarcodePrintOpen}
//         onClose={() => setProductBarcodePrintOpen(false)}
//       />

//       <DiscountManagementModal
//         open={discountManagementOpen}
//         onClose={() => setDiscountManagementOpen(false)}
//       />

//       <DiscountReportsModal
//         open={discountReportOpen}
//         onClose={() => setDiscountReportOpen(false)}
//       />

//       <TaxProductTaxManagementModal
//         open={taxManagementOpen}
//         onClose={() => setTaxManagementOpen(false)}
//       />

//       <ZimraFiscalisationModal
//         open={fiscalDeviceOpen}
//         onClose={() => setFiscalDeviceOpen(false)}
//       />

//       <EnterpriseTaxReportModal
//         open={fiscalReportOpen}
//         onClose={() => setFiscalReportOpen(false)}
//       />

//       <ProfitAndLossReport
//         open={profitReportOpen}
//         onClose={() => setProfitReportOpen(false)}
//       />

//       <PurchaseManagementModal
//         open={purchaseManagementOpen}
//         onClose={() => setPurchaseManagementOpen(false)}
//       />

//       <ManufacturingRecipeManagementModal
//         open={manufacturingRecipeManagementOpen}
//         onClose={() => setManufacturingRecipeManagementOpen(false)}
//         currentUser={user}
//       />

//       <ManufacturingPlanManagementModal
//         open={manufacturingPlanManagementOpen}
//         onClose={() => setManufacturingPlanManagementOpen(false)}
//         currentUser={user}
//       />

//       <ManufacturingOrderWorkflowModal
//         open={manufacturingOrderWorkflowOpen}
//         onClose={() => setManufacturingOrderWorkflowOpen(false)}
//         currentUser={user}
//       />

//       <ManufacturingReportsModal
//         open={manufacturingReportsOpen}
//         onClose={() => setManufacturingReportsOpen(false)}
//         currentUser={user}
//       />

//       <FrontStockRegisterModal
//         open={frontStockManagementOpen}
//         onClose={() => setFrontManagementStockOpen(false)}
//         currentUser={user}
//       />

//       <BackStockRegisterModal
//         open={backStockManagementOpen}
//         onClose={() => setBackManagementStockOpen(false)}
//         currentUser={user}
//       />

//       <IssueStockToFrontModal
//         open={issueStockToFrontOpen}
//         onClose={() => setIssueStockToFrontOpen(false)}
//         currentUser={user}
//       />
//       {/* 
// <FoodRecipeBomModal
//   open={foodRecipeBomOpen}
//   onClose={() => setFoodRecipeBomOpen(false)}
//   currentUser={user}
// /> */}

//       <FoodManufacturingBatchModal
//         open={foodManufacturingBatchOpen}
//         onClose={() => setFoodManufacturingBatchOpen(false)}
//         currentUser={user}
//       />

//       <FoodManufacturingReportsModal
//         open={foodManufacturingReportsOpen}
//         onClose={() => setFoodManufacturingReportsOpen(false)}
//         currentUser={user}
//       />

//       {/* <MenuItemManagementModal
//   open={menuItemManagementOpen}
//   onClose={() => setMenuItemManagementOpen(false)}
//   currentUser={user}
// /> */}

//       <MenuItemManagementModal
//         open={menuItemManagementOpen}
//         onClose={() => setMenuItemManagementOpen(false)}
//         currentUser={user}
//         onCreateRecipeForMenuItem={(product) => {
//           setRecipeMenuItem(product);
//           setFoodRecipeBomOpen(true);
//         }}
//       />

//       <FoodRecipeBomModal
//         open={foodRecipeBomOpen}
//         onClose={() => {
//           setFoodRecipeBomOpen(false);
//           setRecipeMenuItem(null);
//         }}
//         currentUser={user}
//         initialMenuItem={recipeMenuItem}
//       />

//       <MenuItemRecipeCostReportModal
//         open={menuItemRecipeCostReportOpen}
//         onClose={() => setMenuItemRecipeCostReportOpen(false)}
//         currentUser={user}
//       />

//       <MenuItemPrepMessageConfigurationModal
//         open={prepMessageConfigurationOpen}
//         onClose={() => setPrepMessageConfigurationOpen(false)}
//         currentUser={user}
//       />

//       <MenuItemModifierConfigurationModal
//         open={modifierConfigurationOpen}
//         onClose={() => setModifierConfigurationOpen(false)}
//         currentUser={user}
//       />

//       <DailyFrontStockClosingModal
//         open={dailyFrontStockClosingOpen}
//         onClose={() => setDailyFrontStockClosingOpen(false)}
//         defaultCurrency="USD"
//       />

//       <DailyStockReconciliationReportModal
//         open={dailyStockReconciliationOpen}
//         onClose={() => setDailyStockReconciliationOpen(false)}
//         currentUser={user}
//       />
//       <MenuRecipeDailyStockReconciliationModal
//         open={menuRecipeDsrOpen}
//         onClose={() => setMenuRecipeDsrOpen(false)}
//         currentUser={user}
//       />
//       <ClosingStockMovementReportModal
//         open={closingStockMovementReportOpen}
//         onClose={() => setClosingStockMovementReportOpen(false)}
//         currentUser={user}
//       />

//       <StockIssuedToFrontReportModal
//         open={stockIssuedToFrontReportOpen}
//         onClose={() => setStockIssuedToFrontReportOpen(false)}
//         currentUser={user}
//       />

//       <StockWastageModal
//         open={stockWastageOpen}
//         onClose={() => setStockWastageOpen(false)}
//         currentUser={user}
//       />

//       <StockWastageReportModal
//         open={stockWastageReportOpen}
//         onClose={() => setStockWastageReportOpen(false)}
//         currentUser={user}
//       />

//       <CashupHistoryReportModal
//         open={cashupHistoryReportOpen}
//         onClose={() => setCashupHistoryReportOpen(false)}
//         currentUser={user}
//       />

//       <CashoutReportModal
//         open={cashoutReportOpen}
//         onClose={() => setCashoutReportOpen(false)}
//         currentUser={user}
//       />

//       <ShiftReportModal
//         open={shiftReportOpen}
//         onClose={() => setShiftReportOpen(false)}
//         currentUser={user}
//       />

//       <FoodIndustrySalesReportModal
//         open={foodIndustrySalesReportOpen}
//         onClose={() => setFoodIndustrySalesReportOpen(false)}
//         currentUser={user}
//       />

//       <DailyFoodIndustrySalesReportModal
//         open={dailyFoodSalesReportOpen}
//         onClose={() => setDailyFoodSalesReportOpen(false)}
//         currentUser={user}
//       />

//       <FoodManufacturingPlanningModal
//   open={foodManufacturingPlanningOpen}
//   onClose={() => setFoodManufacturingPlanningOpen(false)}
//   currentUser={user}
//   onRecordProduction={() => {
//     setFoodManufacturingPlanningOpen(false);
//     setFoodManufacturingBatchOpen(true);
//   }}
// />
//     </div>
//   );
// }
import React, { useEffect, useState } from "react";
import BackOfficeThemeProvider from "./BackOfficeThemeProvider";
import { Link, Outlet, useLocation } from "react-router-dom";
import { useAdminAuth } from "./context/AdminAuthContext";
import { logout } from "../services/AuthenticationService";
import {
  getCurrentTradingDay,
  getDayEndPreflight,
  runTradingDayEnd,
} from "../services/TradingDayEndEnterpriseService";

import UserManagementFullModal from "./pages/admin/UserManagment";
import Roles from "./pages/admin/Roles";
import GeneralSettings from "./pages/admin/GeneralSettings";
import ShiftConfiguration from "./pages/admin/ShiftConfiguration";
import CreateSaleModal from "./pages/sales/CreateSale";
import CompanyLicenseModal from "./pages/admin/CompanyLicenseModal";
import BranchManagementModal from "./pages/admin/BranchManagementModal";
import ProductCategoryModal from "./pages/product/ProductCategoryModal";
import SupplierModal from "./pages/purchases/SupplierModal";
import ProductManagementFullModal from "./pages/product/ProductManagementFullModal";
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
import PrinterManagementModal from "./pages/intergration/PrinterManagementModal";
import PosReportsModal from "./pages/report/PosReportsModal";
import StockReportsModal from "./pages/report/StockReportsModal";
import ReceiptActionsModal from "./pages/sales/ReceiptActionsModal";
import ReceiptRegisterModal from "./pages/sales/ReceiptRegisterModal";
import ProductImportModal from "./pages/product/ProductImportModal";
import StockTakeModal from "./pages/product/StockTakeModal";
import StockTransferModal from "./pages/product/StockTransferModal";
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
import PurchaseManagementModal from "./pages/purchases/PurchaseManagementModal";

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
import InventoryManagementModal from "./boh/inventory/InventoryManagementModal";
import {
  BackStockRegisterModal,
  FrontStockRegisterModal,
} from "./boh/inventory/StockAreaRegisterModal";
import IssueStockToFrontModal from "./boh/inventory/IssueStockToFrontModal";
import FoodRecipeBomModal from "./boh/foodManufacturing/FoodRecipeBomModal";
import FoodManufacturingBatchModal from "./boh/foodManufacturing/FoodManufacturingBatchModal";
import FoodManufacturingReportsModal from "./boh/foodManufacturing/FoodManufacturingReportsModal";
import MenuItemManagementModal from "./boh/menuItems/MenuItemManagementModal";
import MenuItemRecipeCostReportModal from "./boh/menuItems/MenuItemRecipeCostReportModal";
import MenuItemPrepMessageConfigurationModal from "./boh/menuItems/MenuItemPrepMessageConfigurationModal";
import MenuItemModifierConfigurationModal from "./boh/menuItems/MenuItemModifierConfigurationModal";
import DailyFrontStockClosingModal from "./boh/inventory/DailyFrontStockClosingModal";
import DailyStockReconciliationReportModal from "./boh/reports/DailyStockReconciliationReportModal";
import MenuRecipeDailyStockReconciliationModal from "./boh/reports/MenuRecipeDailyStockReconciliationModal";
import ClosingStockMovementReportModal from "./boh/reports/ClosingStockMovementReportModal";
import StockIssuedToFrontReportModal from "./boh/reports/StockIssuedToFrontReportModal";
import StockWastageModal from "./boh/inventory/StockWastageModal";
import StockWastageReportModal from "./boh/reports/StockWastageReportModal";
import FoodIndustrySalesReportModal from "./boh/reports/FoodIndustrySalesReportModal";
import DailyFoodIndustrySalesReportModal from "./boh/reports/DailyFoodIndustrySalesReportModal";
import CashupHistoryReportModal from "./boh/reports/CashupHistoryReportModal";
import CashoutReportModal from "./boh/reports/CashoutReportModal";
import ShiftReportModal from "./boh/reports/ShiftReportModal";
import DayEndEnterpriseGuardModal from "./boh/dayend/DayEndEnterpriseGuardModal";
import FoodManufacturingPlanningModal from "./boh/foodManufacturing/FoodManufacturingPlanningModal";
import BackStockMonthEndStockTakeModal from "./boh/monthend/BackStockMonthEndStockTakeModal";
import HospitalityMonthEndModal from "./boh/monthend/HospitalityMonthEndModal";

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
        if (
          value === true ||
          value === 1 ||
          value === "1" ||
          value === "true"
        ) {
          names.add(normalizePermissionName(key));
        }
      });
    }
  });

  return names;
};

const userIsSuperAdmin = (user) => {
  const roleName = String(
    user?.role?.name || user?.role_name || user?.role || "",
  )
    .toLowerCase()
    .replaceAll(" ", "_")
    .replaceAll("-", "_");
  const userType = String(user?.user_type || "")
    .toLowerCase()
    .replaceAll(" ", "_")
    .replaceAll("-", "_");

  return Boolean(
    user?.is_super_admin ||
    userType === "super_admin" ||
    roleName === "super_admin" ||
    roleName === "super_administrator",
  );
};

const hasMenuPermission = (user, permission) => {
  if (!permission) return true;
  if (userIsSuperAdmin(user)) return true;

  const permissions = extractPermissionNames(user);

  if (Array.isArray(permission)) {
    return permission.some((item) =>
      permissions.has(normalizePermissionName(item)),
    );
  }

  return permissions.has(normalizePermissionName(permission));
};

const formatTradingDate = (value) => {
  if (!value) return "NOT SET";

  const raw = typeof value === "string" ? value.slice(0, 10) : value;
  const parsed = new Date(`${raw}T00:00:00`);

  if (Number.isNaN(parsed.getTime())) {
    return String(value);
  }

  return parsed.toLocaleDateString("en-GB", {
    day: "2-digit",
    month: "short",
    year: "numeric",
  });
};

const resolveTradingDayDate = (payload) =>
  payload?.trading_date ||
  payload?.current_trading_date ||
  payload?.date ||
  payload?.data?.trading_date ||
  payload?.data?.current_trading_date ||
  null;

const resolveTradingDayStatus = (payload) =>
  payload?.status || payload?.data?.status || "open";

const filterNavigationByPermission = (items, user) => {
  return (items || [])
    .map((item) => {
      const allowed = hasMenuPermission(user, item.permission);

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

const EnterpriseNoticeDialog = ({ title, message, tone = "info", onClose }) => {
  const toneClass =
    tone === "danger"
      ? "border-[#B42318] bg-[#FEF3F2] text-[#B42318]"
      : tone === "success"
        ? "border-[#027A48] bg-[#ECFDF3] text-[#027A48]"
        : "border-[#004141] bg-white text-[#004141]";

  return (
    <div className="fixed inset-0 z-[100002] flex items-center justify-center bg-black/45 px-4">
      <div className={`w-full max-w-[560px] border shadow-2xl ${toneClass}`}>
        <div className="border-b border-black/10 bg-white/70 px-5 py-4">
          <div className="text-[15px] font-black uppercase tracking-wide">
            {title}
          </div>
        </div>
        <div className="whitespace-pre-line px-5 py-5 text-[13px] font-semibold text-[#004141]">
          {message}
        </div>
        <div className="flex justify-end border-t border-black/10 bg-white/70 px-5 py-4">
          <button
            type="button"
            onClick={onClose}
            className="h-10 min-w-[110px] border border-[#004141] bg-[#004142] px-4 text-[11px] font-black uppercase tracking-wide text-white"
          >
            OK
          </button>
        </div>
      </div>
    </div>
  );
};


const DayEndBlockedDialog = ({ open, payload, message, onClose, onOpenGuard }) => {
  if (!open) return null;

  const missingRows = Array.isArray(payload?.missing_front_stock_closings)
    ? payload.missing_front_stock_closings
    : Array.isArray(payload?.blocking_front_stock_closings)
      ? payload.blocking_front_stock_closings
      : [];

  const visibleRows = missingRows.slice(0, 8);
  const extraCount = Math.max(missingRows.length - visibleRows.length, 0);

  return (
    <div className="fixed inset-0 z-[100003] flex items-center justify-center bg-black/50 px-4">
      <div className="w-full max-w-[720px] border border-[#BAC8C8] bg-white shadow-2xl">
        <div className="border-b border-[#CAD5D5] bg-[#004142] px-5 py-4 text-white">
          <div className="text-[15px] font-black uppercase tracking-wide">
            Day End Needs Attention
          </div>
          <div className="mt-1 text-[11px] font-semibold text-white/75">
            Missing Daily Front Stock Closings must be fixed before Day End can continue.
          </div>
        </div>

        <div className="px-5 py-4">
          <div className="border border-[#FEDF89] bg-[#FFFAEB] p-3 text-[12px] font-semibold text-[#B54708]">
            {message || "Daily front stock closing is required before Day End."}
          </div>

          <div className="mt-4 border border-[#D8E1E0]">
            <div className="grid grid-cols-[150px_1fr_130px] border-b border-[#D8E1E0] bg-[#F8FAFC] px-3 py-2 text-[10px] font-black uppercase tracking-wide text-[#5F6C6C]">
              <div>Trading Date</div>
              <div>Issue</div>
              <div className="text-right">Status</div>
            </div>

            {visibleRows.length ? (
              visibleRows.map((row) => (
                <div
                  key={row.date || row.trading_date || row.closing_date}
                  className="grid grid-cols-[150px_1fr_130px] border-b border-[#EFF2F2] px-3 py-2 text-[12px] font-semibold text-[#004141] last:border-b-0"
                >
                  <div>{formatTradingDate(row.date || row.trading_date || row.closing_date)}</div>
                  <div>{row.message || row.issue || "Front stock closing is missing."}</div>
                  <div className="text-right text-[#B42318]">Missing</div>
                </div>
              ))
            ) : (
              <div className="px-3 py-6 text-center text-[12px] font-semibold text-[#5F6C6C]">
                Open the Day End Guard to inspect the missing stock closing calendar.
              </div>
            )}
          </div>

          {extraCount > 0 && (
            <div className="mt-2 text-[11px] font-semibold text-[#5F6C6C]">
              + {extraCount} more missing day{extraCount === 1 ? "" : "s"}. Open the guard to view and fix all.
            </div>
          )}
        </div>

        <div className="flex justify-end gap-2 border-t border-[#E2E9E9] bg-[#F8FAFC] px-5 py-4">
          <button
            type="button"
            onClick={onClose}
            className="h-10 w-[110px] border border-[#ACB8B8] bg-white text-[11px] font-black uppercase tracking-wide text-[#004141]"
          >
            Close
          </button>
          <button
            type="button"
            onClick={onOpenGuard}
            className="h-10 min-w-[190px] border border-[#008A8A] bg-[#00BCBC] px-4 text-[11px] font-black uppercase tracking-wide text-[#004141]"
          >
            Open Day End Guard
          </button>
        </div>
      </div>
    </div>
  );
};

export default function BohAdminLayout() {
  const [openMenu, setOpenMenu] = useState(null);
  const [openNestedMenus, setOpenNestedMenus] = useState({});
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [isFullscreen, setIsFullscreen] = useState(false);

  const [userManagementOpen, setUserManagementOpen] = useState(false);
  const [roleOpen, setRoleOpen] = useState(false);
  const [licenseOpen, setLicenseOpen] = useState(false);
  const [openCategoryManagement, setCategoryManagementOpen] = useState(false);
  const [openProductManagement, setProductManagementOpen] = useState(false);
  const [inventoryInitialTab, setInventoryInitialTab] = useState("overview");
  const [openSupplierManagement, setSupplierManagementOpen] = useState(false);
  const [openBranchSettings, setBranchSettings] = useState(false);
  const [createSaleOpen, setCreateSaleOpen] = useState(false);
  const [shiftSettingsOpen, setShiftSettingsOpen] = useState(false);
  const [generalSettingsOpen, setSetGeneralSettingsOpen] = useState(false);
  const [priceLevelOpen, setPriceLevelOpen] = useState(false);
  const [rolePriceLevelOpen, setRolePriceLevelOpen] = useState(false);
  const [stockAdjustmentHistoryOpen, setStockAdjustmentHistoryOpen] =
    useState(false);

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
  const [dailyFoodSalesReportOpen, setDailyFoodSalesReportOpen] =
    useState(false);

  const [cashupHistoryReportOpen, setCashupHistoryReportOpen] = useState(false);
  const [cashoutReportOpen, setCashoutReportOpen] = useState(false);
  const [shiftReportOpen, setShiftReportOpen] = useState(false);

  const [discountManagementOpen, setDiscountManagementOpen] = useState(false);
  const [discountReportOpen, setDiscountReportOpen] = useState(false);
  const [qoutationOpen, setQoutationOpen] = useState(false);
  const [taxManagementOpen, setTaxManagementOpen] = useState(false);
  const [fiscalDeviceOpen, setFiscalDeviceOpen] = useState(false);
  const [fiscalReportOpen, setFiscalReportOpen] = useState(false);
  const [profitReportOpen, setProfitReportOpen] = useState(false);
  const [dailyFrontStockClosingOpen, setDailyFrontStockClosingOpen] =
    useState(false);
  const [menuRecipeDsrOpen, setMenuRecipeDsrOpen] = useState(false);
  const [currentTradingDay, setCurrentTradingDay] = useState(null);
  const [tradingDayStatus, setTradingDayStatus] = useState("open");
  const [dayEndConfirmOpen, setDayEndConfirmOpen] = useState(false);
  const [releaseLockConfirmOpen, setReleaseLockConfirmOpen] = useState(false);
  const [dayEndProcessing, setDayEndProcessing] = useState(false);
  const [dayEndProgress, setDayEndProgress] = useState(0);
  const [dayEndGuardOpen, setDayEndGuardOpen] = useState(false);
  const [enterpriseNotice, setEnterpriseNotice] = useState(null);
  const [dayEndBlockedOpen, setDayEndBlockedOpen] = useState(false);
  const [dayEndBlockedPayload, setDayEndBlockedPayload] = useState(null);
  const [dayEndBlockedMessage, setDayEndBlockedMessage] = useState("");

  const [manufacturingRecipeRegisterOpen, setManufacturingRecipeRegisterOpen] =
    useState(false);
  const [manufacturingRecipeFormOpen, setManufacturingRecipeFormOpen] =
    useState(false);

  const [manufacturingPlanRegisterOpen, setManufacturingPlanRegisterOpen] =
    useState(false);
  const [manufacturingPlanFormOpen, setManufacturingPlanFormOpen] =
    useState(false);

  const [
    manufacturingMaterialRequirementsOpen,
    setManufacturingMaterialRequirementsOpen,
  ] = useState(false);

  const [manufacturingOrderRegisterOpen, setManufacturingOrderRegisterOpen] =
    useState(false);
  const [manufacturingOrderFormOpen, setManufacturingOrderFormOpen] =
    useState(false);
  const [manufacturingOrderWorkflowOpen, setManufacturingOrderWorkflowOpen] =
    useState(false);

  const [
    manufacturingProductionReportOpen,
    setManufacturingProductionReportOpen,
  ] = useState(false);
  const [manufacturingCostReportOpen, setManufacturingCostReportOpen] =
    useState(false);
  const [
    manufacturingMaterialUsageReportOpen,
    setManufacturingMaterialUsageReportOpen,
  ] = useState(false);
  const [manufacturingWastageReportOpen, setManufacturingWastageReportOpen] =
    useState(false);
  const [manufacturingVarianceReportOpen, setManufacturingVarianceReportOpen] =
    useState(false);
  const [frontStockManagementOpen, setFrontManagementStockOpen] =
    useState(false);
  const [backStockManagementOpen, setBackManagementStockOpen] = useState(false);
  const [issueStockToFrontOpen, setIssueStockToFrontOpen] = useState(false);
  const [dailyStockReconciliationOpen, setDailyStockReconciliationOpen] =
    useState(false);
  const [closingStockMovementReportOpen, setClosingStockMovementReportOpen] =
    useState(false);

  const [creditCustomerOpen, setCreditCustomerOpen] = useState(false);
  const [foodRecipeBomOpen, setFoodRecipeBomOpen] = useState(false);
  const [foodManufacturingBatchOpen, setFoodManufacturingBatchOpen] =
    useState(false);
  const [foodManufacturingReportsOpen, setFoodManufacturingReportsOpen] =
    useState(false);

  const [menuItemManagementOpen, setMenuItemManagementOpen] = useState(false);
  const [recipeMenuItem, setRecipeMenuItem] = useState(null);
  const [menuItemRecipeCostReportOpen, setMenuItemRecipeCostReportOpen] =
    useState(false);
  const [prepMessageConfigurationOpen, setPrepMessageConfigurationOpen] =
    useState(false);
  const [modifierConfigurationOpen, setModifierConfigurationOpen] =
    useState(false);

  const [stockWastageOpen, setStockWastageOpen] = useState(false);
  const [stockWastageReportOpen, setStockWastageReportOpen] = useState(false);

  const [stockIssuedToFrontReportOpen, setStockIssuedToFrontReportOpen] =
    useState(false);

  const [
    manufacturingRecipeManagementOpen,
    setManufacturingRecipeManagementOpen,
  ] = useState(false);
  const [manufacturingPlanManagementOpen, setManufacturingPlanManagementOpen] =
    useState(false);
  // const [manufacturingOrderWorkflowOpen, setManufacturingOrderWorkflowOpen] = useState(false);
  const [manufacturingReportsOpen, setManufacturingReportsOpen] =
    useState(false);

    const [foodManufacturingPlanningOpen, setFoodManufacturingPlanningOpen] =
  useState(false);

  const [purchaseManagementOpen, setPurchaseManagementOpen] = useState(false);
  const [purchaseInitialTab, setPurchaseInitialTab] = useState("orders");
  const [foodIndustrySalesReportOpen, setFoodIndustrySalesReportOpen] =
    useState(false);
  const [monthEndStockTakeOpen, setMonthEndStockTakeOpen] = useState(false);
  const [hospitalityMonthEndOpen, setHospitalityMonthEndOpen] = useState(false);

  const { user } = useAdminAuth();
  const { pathname } = useLocation();

  const loadCurrentTradingDay = async () => {
    try {
      const payload = await getCurrentTradingDay();
      setCurrentTradingDay(resolveTradingDayDate(payload));
      setTradingDayStatus(resolveTradingDayStatus(payload));
    } catch (error) {
      console.error("Failed to load current trading day:", error);
    }
  };

  const showEnterpriseNotice = ({
    title = "Notice",
    message = "",
    tone = "info",
  } = {}) => {
    setEnterpriseNotice({ title, message, tone });
  };

  useEffect(() => {
    loadCurrentTradingDay();
  }, []);

  useEffect(() => {
    if (!dayEndProcessing) return undefined;

    setDayEndProgress(8);

    const timer = window.setInterval(() => {
      setDayEndProgress((prev) => {
        if (prev >= 92) return prev;
        return prev + 4;
      });
    }, 450);

    return () => window.clearInterval(timer);
  }, [dayEndProcessing]);

  const handleRunDayEnd = async () => {
    setDayEndConfirmOpen(false);
    setDayEndProcessing(true);
    setDayEndProgress(10);
    setDayEndBlockedOpen(false);
    setDayEndBlockedPayload(null);
    setDayEndBlockedMessage("");

    try {
      const result = await runTradingDayEnd({
        expected_trading_date: currentTradingDay,
      });
      setDayEndProgress(100);
      await loadCurrentTradingDay();
      showEnterpriseNotice({
        title: "Day End Completed",
        message:
          result?.message ||
          "Day End completed successfully and the next trading day was opened.",
        tone: "success",
      });
      window.setTimeout(() => setDayEndProcessing(false), 650);
    } catch (error) {
      setDayEndProcessing(false);
      setDayEndProgress(0);

      const message =
        error?.response?.data?.message || error?.message || "Day End failed.";

      const looksLikeMissingClosing =
        error?.response?.status === 422 &&
        String(message).toLowerCase().includes("front stock closing");

      if (looksLikeMissingClosing) {
        let preflightPayload = error?.response?.data?.data || null;

        try {
          preflightPayload = await getDayEndPreflight({ date_to: new Date().toISOString().slice(0, 10) });
        } catch {
          // Keep the original Day End error visible even if the preflight lookup fails.
        }

        setDayEndBlockedMessage(message);
        setDayEndBlockedPayload(preflightPayload);
        setDayEndBlockedOpen(true);
        return;
      }

      showEnterpriseNotice({
        title: "Day End Failed",
        message,
        tone: "danger",
      });
    }
  };

  const handleReleaseLock = async () => {
    setReleaseLockConfirmOpen(false);
    setDayEndProcessing(true);
    setDayEndProgress(20);

    try {
      const { rollbackTradingDayEndLock } = await import(
        "../services/TradingDayEndEnterpriseService"
      );
      await rollbackTradingDayEndLock({});
      setDayEndProgress(100);
      await loadCurrentTradingDay();
      showEnterpriseNotice({
        title: "Day End Lock Released",
        message: "The Day End lock has been released and the trading day was refreshed.",
        tone: "success",
      });
      window.setTimeout(() => setDayEndProcessing(false), 650);
    } catch (error) {
      setDayEndProcessing(false);
      setDayEndProgress(0);
      showEnterpriseNotice({
        title: "Release Lock Failed",
        message:
          error?.response?.data?.message ||
          error?.message ||
          "Release lock failed.",
        tone: "danger",
      });
    }
  };

  const navigation = [
        {
      name: "General Procedure",
      children: [
        {
          name: "Monthend Stock take",
          modal: "monthend-stock-take",
          permission: "general_procedure",
        },
        {
          name: "Monthend",
          modal: "monthend-management",
          permission: "general_procedure",
        },
 
      ],
    },
    
    {
      name: "Products",
      children: [
        {
          name: "Product Register",
          modal: "product-management",
          permission: ["product_management", "inventory_management"],
        },
        {
          name: "Pack Relationships",
          modal: "product-pack-relationships",
          permission: ["product_management", "inventory_management"],
        },
        {
          name: "Department Management",
          modal: "category-management",
          permission: "view_product_category",
        },
        {
          name: "Barcode Printing",
          modal: "product-barcode-printing",
          permission: "product_barcode_printing",
        },
        {
          name: "Import Products",
          modal: "product-import",
          permission: "import_products",
        },
        {
          name: "Price",
          children: [
            { name: "Pricing Levels", modal: "price-lavel", permission: "price_management" },
            { name: "Price List", modal: "price-list", permission: "view_price_list" },
            { name: "Adjust Product Price", modal: "adjust-price", permission: "add_price" },
            { name: "Assign Role Price", modal: "role-price", permission: "assign_role_price" },
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

          children: [
            {
              name: "Front Stock Management",
              modal: "front-stock-management",
              permission: "inventory_management",
            },
            {
              name: "Daily Front Stock Closing",
              modal: "daily-front-stock-closing",
              permission: "inventory_management",
            },
          ],
        },
        {
          name: "Issue Stock",
          modal: "issue-stock-to-front",
          permission: "inventory_management",
        },
        {
          name: "Stock Wastage",
          modal: "stock-wastage",
          permission: "inventory_management",
        },

        {
          name: "Stock",
          children: [
            {
              name: "Stock Take",
              modal: "stock-take",
              permission: "stock_take",
            },
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
          name: "Menu Item Management",
          modal: "menu-item-management",
          permission: "inventory_management",
        },
        {
          name: "Menu Recipe Cost Report",
          modal: "menu-item-recipe-cost-report",
          permission: "inventory_management",
        },
        
        {
          name: "Extras",

          children: [
            {
              name: "Prep Message Configuration",
              modal: "prep-message-configuration",
              permission: "inventory_management",
            },
            {
              name: "Modifier Configuration",
              modal: "modifier-configuration",
              permission: "inventory_management",
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
            {
              name: "Profit & Loss",
              modal: "profit-loss",
              permission: "view_profit_loss",
            },
            {
              name: "Tax Reports",
              modal: "tax-report",
              permission: "tax_management",
            },
            {
              name: "Discount Report",
              modal: "discount-report",
              permission: "view_discount_reports",
            },
            {
              name: "Price Adjustment History",
              modal: "price-adjustment-history",
              permission: "view_price_adjustment_history",
            },
          ],
        },
        {
          name: "Sales Reports",
          children: [
            {
              name: "Daily Sales Report",
              modal: "daily-food-sales-report",
              permission: "view_sales_reports",
            },
            {
              name: "Detailed Sales Report",
              modal: "food-industry-sales-report",
              permission: "view_sales_reports",
            },
            {
              name: "Payments",
              modal: "sales-report-payments",
              permission: "view_sales_reports",
            },
            {
              name: "Cashiers",
              modal: "sales-report-cashiers",
              permission: "view_sales_reports",
            },
            {
              name: "Shift Report",
              modal: "shift-report",
              permission: "cash_up_reports",
            },
          ],
        },
        {
          name: "Cash Management Reports",
          children: [
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
          ],
        },
        {
          name: "Purchase Reports",
          children: [
            {
              name: "Purchase Management",
              modal: "purchase-management",
              permission: "purchase_orders",
            },
            {
              name: "Stock Received",
              modal: "stock-report-received",
              permission: "view_stock_reports",
            },
          ],
        },
        {
          name: "Inventory Reports",
          children: [
            {
              name: "Branch Balance",
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
              name: "Stock Wastage Report",
              modal: "stock-wastage-report",
              permission: "view_stock_reports",
            },
            {
              name: "Stock Issues",
              modal: "stock-issued-to-front-report",
              permission: "view_stock_reports",
            },
            {
              name: "Closing Stock Movement Report",
              modal: "closing-stock-movement-report",
              permission: "view_stock_reports",
            },
            {
              name: "Menu Recipe Daily Stock Reconciliation",
              modal: "menu-recipe-dsr",
              permission: "inventory_management",
            },
          ],
        },
        {
          name: "Product Reports",
          children: [
            {
              name: "Product Price List",
              modal: "price-list",
              permission: "view_price_list",
            },
            {
              name: "Menu Recipe Cost Report",
              modal: "menu-item-recipe-cost-report",
              permission: "inventory_management",
            },
            {
              name: "Product Barcode Printing",
              modal: "product-barcode-printing",
              permission: "product_barcode_printing",
            },
          ],
        },
        {
          name: "Employee Reports",
          children: [
            {
              name: "Shift Report",
              modal: "shift-report",
              permission: "cash_up_reports",
            },
            {
              name: "Cashup History",
              modal: "sales-report-cashups",
              permission: "cash_up_reports",
            },
          ],
        },
        {
          name: "Loyalty Reports",
          children: [
            {
              name: "Credit Customers",
              modal: "credit-customers",
              permission: "view_sales_reports",
            },
          ],
        },
      ],
    },

{
  name: "Food Manufacturing",
  children: [
    {
      name: "Food Recipes / BOM",
      modal: "food-recipes-bom",
      permission: "food_manufacturing_view",
    },
    {
      name: "Production Planning",
      modal: "food-manufacturing-planning",
      permission: "food_manufacturing_batches",
    },
    {
      name: "Record Production",
      modal: "food-manufacturing-batches",
      permission: "food_manufacturing_batches",
    },
    {
      name: "Production Reports",
      modal: "food-manufacturing-reports",
      permission: "food_manufacturing_reports",
    },
  ],
},
  ];

  const permittedNavigation = filterNavigationByPermission(navigation, user);

  const denyPermission = (name = "this page") => {
    setPermissionDeniedMessage(
      `Access denied. Your role does not have permission to open ${name}.`,
    );
    window.setTimeout(() => setPermissionDeniedMessage(""), 4500);
  };

  const canOpenChild = (child) => hasMenuPermission(user, child?.permission);

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

  const navigateHospitalityReport = (reportKey) => {
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

  const modalHandlers = {
    "user-management": () => setUserManagementOpen(true),
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
    "product-management": () => { setInventoryInitialTab("register"); setProductManagementOpen(true); },
    "product-pack-relationships": () => { setInventoryInitialTab("break"); setProductManagementOpen(true); },
    "inventory-overview": () => { setInventoryInitialTab("overview"); setProductManagementOpen(true); },
    "warehouse-management": () => { setInventoryInitialTab("warehouses"); setProductManagementOpen(true); },
    "batch-expiry": () => { setInventoryInitialTab("batches"); setProductManagementOpen(true); },
    "stock-movements": () => { setInventoryInitialTab("movements"); setProductManagementOpen(true); },
    "inventory-recalls": () => { setInventoryInitialTab("recalls"); setProductManagementOpen(true); },
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
    "printer-management": () => setPrinterOpen(true),
    "pos-more-button": () => setShowReceiptActions(true),
    "receipt-register": () => setReceiptRegisterOpen(true),
    "product-import": () => setProductImportOpen(true),
    "stock-take": () => setStockTakeOpen(true),
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
    "purchase-management": () => { setPurchaseInitialTab("orders"); setPurchaseManagementOpen(true); },
    "credit-customers": () => setCreditCustomerOpen(true),
    "back-stock-management": () => setBackManagementStockOpen(true),
    "front-stock-management": () => setFrontManagementStockOpen(true),
    "issue-stock-to-front": () => setIssueStockToFrontOpen(true),
    "daily-front-stock-closing": () => setDailyFrontStockClosingOpen(true),
    "daily-stock-reconciliation": () => setDailyStockReconciliationOpen(true),
    "menu-recipe-dsr": () => setMenuRecipeDsrOpen(true),
    "closing-stock-movement-report": () =>
      setClosingStockMovementReportOpen(true),
    "stock-issued-to-front-report": () => setStockIssuedToFrontReportOpen(true),
    "food-industry-sales-report": () => setFoodIndustrySalesReportOpen(true),
    "daily-food-sales-report": () => setDailyFoodSalesReportOpen(true),
    "monthend-stock-take": () => setMonthEndStockTakeOpen(true),
    "monthend-management": () => setHospitalityMonthEndOpen(true),

    "food-recipes-bom": () => setFoodRecipeBomOpen(true),
    "food-manufacturing-batches": () => setFoodManufacturingBatchOpen(true),
    "food-manufacturing-reports": () => setFoodManufacturingReportsOpen(true),
    "food-manufacturing-planning": () => setFoodManufacturingPlanningOpen(true),

    "menu-item-management": () => setMenuItemManagementOpen(true),
    "prep-message-configuration": () => setPrepMessageConfigurationOpen(true),
    "modifier-configuration": () => setModifierConfigurationOpen(true),

    "manufacturing-recipes": () => setManufacturingRecipeManagementOpen(true),
    "manufacturing-planning": () => setManufacturingPlanManagementOpen(true),
    "manufacturing-workflow": () => setManufacturingOrderWorkflowOpen(true),
    "manufacturing-reports": () => setManufacturingReportsOpen(true),
    "menu-item-recipe-cost-report": () => setMenuItemRecipeCostReportOpen(true),
    "stock-wastage": () => setStockWastageOpen(true),
    "stock-wastage-report": () => setStockWastageReportOpen(true),

    "sales-report-dashboard": () => openPosReport("dashboard"),
    "sales-report-daily-sales": () => openPosReport("daily-sales"),
    "sales-report-sales-list": () => openPosReport("sales-list"),
    "sales-report-payments": () => openPosReport("payments"),
    "sales-report-products": () => openPosReport("products"),
    "sales-report-cashiers": () => openPosReport("cashiers"),
    "sales-report-cashups": () => openPosReport("cashups"),
    "cashout-report": () => openPosReport("cashouts"),
    "shift-report": () => setShiftReportOpen(true),
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

    if (!hasMenuPermission(user, child.permission)) {
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
          className="relative overflow-visible group"
          onMouseEnter={() => openNestedMenuOnly(key, level)}
        >
          <button
            type="button"
            onClick={() => toggleNestedMenu(key, level)}
            className={`w-full h-10 px-4 text-left text-[12px] font-black border-b border-[#EFF2F2] flex items-center justify-between ${
              isActive
                ? "bg-[#EBF6F6] text-[#008989]"
                : "text-[#003636] hover:bg-[#EBF6F6] hover:text-[#008989]"
            }`}
          >
            <span>{child.name}</span>
          </button>

          {isOpen && (
            <div className="absolute top-0 left-full w-72 bg-white border border-[#BAC8C8] shadow-[0_18px_45px_rgba(0,54,54,0.24)] z-[100001]">
              <div className="h-[3px] bg-[#15D7D7]" />
              {child.children.map((nestedChild) =>
                renderDesktopChild(nestedChild, level + 1, key),
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
          className="block w-full h-10 text-left px-4 text-[12px] font-bold border-b border-[#EFF2F2] text-[#003636] hover:bg-[#EBF6F6] hover:text-[#008989]"
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
        className={`block h-10 px-4 leading-10 text-[12px] font-bold border-b border-[#EFF2F2] ${
          isLinkActive(child.href)
            ? "bg-[#EBF6F6] text-[#008989]"
            : "text-[#003636] hover:bg-[#EBF6F6] hover:text-[#008989]"
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

    const paddingLeft = level === 0 ? "pl-6" : level === 1 ? "pl-10" : "pl-14";

    if (hasChildren) {
      return (
        <div key={key} className="border-b border-[#EFF2F2]">
          <button
            type="button"
            onClick={() => toggleNestedMenu(key, level)}
            className={`w-full h-11 pr-4 ${paddingLeft} text-left text-[12px] font-bold flex items-center justify-between ${
              isActive
                ? "bg-[#EBF6F6] text-[#008989]"
                : "text-[#003636] hover:bg-[#EBF6F6] hover:text-[#008989]"
            }`}
          >
            <span>{child.name}</span>
          </button>

          {isOpen && (
            <div className="bg-white">
              {child.children.map((nestedChild) =>
                renderMobileChild(nestedChild, level + 1, key),
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
          className={`block w-full h-11 text-left pr-4 ${paddingLeft} text-[12px] font-semibold border-b border-[#EFF2F2] text-[#003636] hover:bg-[#EBF6F6] hover:text-[#008989]`}
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
            ? "bg-[#EBF6F6] text-[#008989]"
            : "text-[#003636] hover:bg-[#EBF6F6] hover:text-[#008989]"
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
      await logout();
      localStorage.removeItem("back_authToken");
      localStorage.removeItem("back_user");
      window.location.href = "/";
    } catch (error) {
      showEnterpriseNotice({
        title: "Logout Failed",
        message: error?.response?.data?.message || "Failed to log out.",
        tone: "danger",
      });
    } finally {
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

  return (
    <BackOfficeThemeProvider>
    <div className="sx-backoffice-v4 h-screen flex flex-col bg-white text-[#003636] overflow-hidden font-sans">
      <header className="pos-menu-area shrink-0 bg-[#004142] text-white border-b-2 border-[#00BCBC] shadow-[0_8px_20px_rgba(0,65,65,0.22)] z-[9000] relative overflow-visible">
        <div className="h-14 flex items-stretch overflow-visible">
          <Link
            to="/admin"
            onClick={closeMenus}
            className="min-w-[108px] px-4 border-r border-white/15 flex flex-col items-center justify-center text-center hover:bg-white/10"
            title="Back to POS"
          >
            <span className="text-[15px] font-black leading-none tracking-wide text-white">
              SERVENEST
            </span>
            <span className="mt-1 text-[9px] font-black uppercase tracking-[0.28em] text-[#00BCBC]">
              BOH
            </span>
          </Link>

          <nav className="hidden lg:flex h-full flex-1 items-stretch overflow-visible">
            {permittedNavigation.map((item) => {
              const hasChildren =
                Array.isArray(item.children) && item.children.length > 0;
              const isOpen = openMenu === `toolbar-${item.name}`;
              const isActive = hasChildren
                ? hasActiveChild(item.children)
                : isLinkActive(item.href);

              if (!hasChildren && item.href) {
                return (
                  <Link
                    key={`toolbar-${item.name}`}
                    to={item.href}
                    onClick={closeMenus}
                    className={`min-w-[118px] px-4 border-r border-white/10 flex items-center justify-center text-center text-[12px] font-black uppercase tracking-wide transition-colors ${
                      isActive
                        ? "bg-[#00BCBC] text-[#004141]"
                        : "text-white hover:bg-white/10 hover:text-[#00BCBC]"
                    }`}
                  >
                    {item.name}
                  </Link>
                );
              }

              return (
                <div
                  key={`toolbar-${item.name}`}
                  className="relative min-w-[118px] border-r border-white/10 overflow-visible"
                >
                  <button
                    type="button"
                    onClick={() => openTopMenu(`toolbar-${item.name}`, isOpen)}
                    className={`h-full w-full px-4 text-center text-[12px] font-black uppercase tracking-wide transition-colors ${
                      isOpen || isActive
                        ? "bg-[#00BCBC] text-[#004141]"
                        : "text-white hover:bg-white/10 hover:text-[#00BCBC]"
                    }`}
                  >
                    {item.name}
                  </button>

                  {isOpen && hasChildren && (
                    <div className="absolute left-0 top-full w-[310px] bg-white border border-[#BAC8C8] shadow-[0_20px_50px_rgba(0,54,54,0.28)] z-[100000] text-[#003636]">
                      <div className="h-1 bg-[#00BCBC]" />
                      <div className="px-4 py-2 border-b border-[#E2E9E9] bg-[#F8FAFC] text-[11px] font-black uppercase tracking-wide text-[#004141]">
                        {item.name}
                      </div>
                      {item.children.map((child) =>
                        renderDesktopChild(child, 0, `toolbar-${item.name}`),
                      )}
                    </div>
                  )}
                </div>
              );
            })}
          </nav>

          <button
            type="button"
            onClick={() => {
              setMobileMenuOpen(true);
              setOpenMenu(null);
              setOpenNestedMenus({});
            }}
            className="lg:hidden ml-auto h-full px-4 border-l border-white/15 text-[12px] font-black uppercase tracking-wide text-white hover:bg-white/10"
          >
            Menu
          </button>

          <button
            type="button"
            onClick={toggleFullscreen}
            className="hidden sm:flex h-full px-4 bg-[#003838] border-l border-white/15 text-[11px] font-black uppercase tracking-wide hover:bg-white/10 hover:text-[#00BCBC] items-center"
            title={isFullscreen ? "Exit fullscreen" : "Enter fullscreen"}
          >
            {isFullscreen ? "Exit Full" : "Fullscreen"}
          </button>

          <button
            type="button"
            onClick={handleLogout}
            disabled={isProcessingLogout}
            className="hidden sm:flex h-full px-4 bg-[#003838] border-l border-white/15 text-[11px] font-black uppercase tracking-wide hover:bg-red-700 items-center disabled:opacity-60"
          >
            {isProcessingLogout ? "Logging out" : "Logout"}
          </button>
        </div>
      </header>

      <section className="shrink-0 border-b border-[#BAC8C8] bg-[#EFF3F3] px-2 py-2">
        <div className="flex items-center justify-between gap-3">
          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={() => setDayEndConfirmOpen(true)}
              disabled={dayEndProcessing}
              className="h-10 w-[128px] border border-[#B38A18] bg-[#F4C542] text-[#3B2A00] text-[11px] font-black uppercase tracking-wide shadow-[0_3px_0_#B38A18] disabled:opacity-60"
            >
              Run Day End
            </button>

            <button
              type="button"
              onClick={() => setReleaseLockConfirmOpen(true)}
              disabled={dayEndProcessing}
              className="h-10 w-[128px] border border-[#ACB8B8] bg-white text-[#004141] text-[11px] font-black uppercase tracking-wide shadow-[0_3px_0_#ACB8B8] disabled:opacity-60"
            >
              Release Lock
            </button>

            <button
              type="button"
              onClick={() => setMenuRecipeDsrOpen(true)}
              className="h-10 w-[128px] border border-[#008A8A] bg-[#00BCBC] text-[#004141] text-[11px] font-black uppercase tracking-wide shadow-[0_3px_0_#008A8A]"
            >
              D.S.R
            </button>
          </div>

          <div className="min-w-[230px] border border-[#ACB8B8] bg-white px-3 py-1.5 text-right">
            <div className="text-[9px] font-black uppercase tracking-[0.22em] text-[#5F6C6C]">
              Trading Date
            </div>
            <div className="text-[14px] font-black text-[#004141]">
              {formatTradingDate(currentTradingDay)}
            </div>
            <div className="text-[9px] font-bold uppercase tracking-wide text-[#057B7C]">
              {tradingDayStatus}
            </div>
          </div>
        </div>
      </section>

      {permissionDeniedMessage && (
        <div className="fixed right-4 top-24 z-[100000] max-w-[420px] border border-[#FDA29B] bg-[#FEF3F2] px-4 py-3 text-[#B42318] shadow-xl">
          <div className="text-[12px] font-black uppercase tracking-wide">
            Permission Required
          </div>
          <div className="mt-1 text-[12px] font-semibold">
            {permissionDeniedMessage}
          </div>
        </div>
      )}

      {enterpriseNotice && (
        <EnterpriseNoticeDialog
          title={enterpriseNotice.title}
          message={enterpriseNotice.message}
          tone={enterpriseNotice.tone}
          onClose={() => setEnterpriseNotice(null)}
        />
      )}

      <DayEndBlockedDialog
        open={dayEndBlockedOpen}
        payload={dayEndBlockedPayload}
        message={dayEndBlockedMessage}
        onClose={() => setDayEndBlockedOpen(false)}
        onOpenGuard={() => {
          setDayEndBlockedOpen(false);
          setDayEndGuardOpen(true);
        }}
      />

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

          <aside className="absolute top-0 left-0 right-0 max-h-[92vh] bg-white text-[#003636] border-b border-[#CAD5D5] overflow-y-auto shadow-2xl">
            <div className="h-12 flex items-center justify-between px-4 border-b border-[#D8E1E0] bg-[#F8FAFC]">
              <div className="leading-tight">
                <div className="text-[15px] font-black tracking-wide">
                  SERVENEST POS
                </div>
                <div className="text-[9px] tracking-[0.24em] font-bold text-[#5F6C6C]">
                  BACK OFFICE
                </div>
              </div>

              <button
                type="button"
                onClick={() => {
                  setMobileMenuOpen(false);
                  setOpenMenu(null);
                  setOpenNestedMenus({});
                }}
                className="h-9 px-3 border border-[#CAD5D5] text-[11px] font-semibold hover:bg-[#F0F5F5]"
              >
                Close
              </button>
            </div>

            <div className="grid grid-cols-2 text-[11px] font-bold border-b border-[#D8E1E0] bg-[#F4F7F7]">
              <Link
                to="/admin"
                onClick={closeMenus}
                className="h-11 px-4 border-r border-[#D8E1E0] flex items-center uppercase tracking-wide hover:bg-white"
              >
                POS
              </Link>

              <button
                type="button"
                onClick={toggleFullscreen}
                className="h-11 px-4 flex items-center uppercase tracking-wide hover:bg-white"
              >
                {isFullscreen ? "Exit Full" : "Fullscreen"}
              </button>
            </div>

            <nav>
              {permittedNavigation.map((item) => {
                const hasChildren =
                  Array.isArray(item.children) && item.children.length > 0;
                const isOpen = openMenu === item.name;
                const isActive = hasChildren
                  ? hasActiveChild(item.children)
                  : isLinkActive(item.href);

                if (!hasChildren && item.href) {
                  return (
                    <Link
                      key={item.name}
                      to={item.href}
                      onClick={closeMenus}
                      className={`w-full h-12 px-4 text-left text-[13px] font-black uppercase tracking-wide flex items-center ${
                        isActive
                          ? "bg-[#F0F5F5] text-[#057B7C]"
                          : "text-[#003636] hover:bg-[#F0F5F5]"
                      }`}
                    >
                      {item.name}
                    </Link>
                  );
                }

                return (
                  <div key={item.name} className="border-b border-[#D8E1E0]">
                    <button
                      type="button"
                      onClick={() => openTopMenu(item.name, isOpen)}
                      className={`w-full h-12 px-4 text-left text-[13px] font-black uppercase tracking-wide flex items-center ${
                        isOpen || isActive
                          ? "bg-[#F0F5F5] text-[#057B7C]"
                          : "text-[#003636] hover:bg-[#F0F5F5]"
                      }`}
                    >
                      <span>{item.name}</span>
                    </button>

                    {isOpen && hasChildren && (
                      <div className="bg-white">
                        {item.children.map((child) =>
                          renderMobileChild(child, 0, item.name),
                        )}
                      </div>
                    )}
                  </div>
                );
              })}
            </nav>

            <button
              type="button"
              onClick={handleLogout}
              disabled={isProcessingLogout}
              className="w-full h-12 px-4 bg-[#FEE2E2] text-[#991B1B] text-left text-[13px] font-black uppercase tracking-wide disabled:opacity-60"
            >
              {isProcessingLogout ? "Logging out" : "Logout"}
            </button>
          </aside>
        </div>
      )}

      <main className="relative flex-1 bg-white overflow-y-auto z-10 border-t border-[#EFF2F2]">
        <div className="absolute inset-0 pointer-events-none">
          <div className="absolute right-8 bottom-8 text-right opacity-30">
            <div className="text-[42px] sm:text-[56px] font-black tracking-tight text-[#003636] leading-none">
              BOH
            </div>
            <div className="text-[18px] sm:text-[24px] font-black tracking-[0.5em] text-[#057B7C]">
              STOCK
            </div>
          </div>
        </div>

        <div className="relative z-10 min-h-full">
          <Outlet />
        </div>
      </main>

      {dayEndProcessing && (
        <div className="shrink-0 border-t border-[#ACB8B8] bg-[#F8FAFC]">
          <div className="h-2 bg-[#D8E1E0]">
            <div
              className="h-full bg-[#00BCBC] transition-all duration-300"
              style={{ width: `${dayEndProgress}%` }}
            />
          </div>
          <div className="px-3 py-1 text-[10px] font-black uppercase tracking-wide text-[#004141]">
            Day End Processing {Math.round(dayEndProgress)}%
          </div>
        </div>
      )}

      {dayEndConfirmOpen && (
        <div className="fixed inset-0 z-[100000] flex items-center justify-center bg-black/55 px-4">
          <div className="w-full max-w-[520px] border border-[#ACB8B8] bg-white shadow-2xl">
            <div className="border-b border-[#CAD5D5] bg-[#004142] px-5 py-4 text-white">
              <div className="text-[15px] font-black uppercase tracking-wide">
                Confirm Day End
              </div>
              <div className="mt-1 text-[11px] font-semibold text-white/75">
                Trading date: {formatTradingDate(currentTradingDay)}
              </div>
            </div>
            <div className="px-5 py-5 text-[13px] font-semibold text-[#004141]">
              Are you sure you want to run Day End for this trading date? POS
              will be locked while the process is running.
            </div>
            <div className="flex justify-end gap-2 border-t border-[#E2E9E9] bg-[#F8FAFC] px-5 py-4">
              <button
                type="button"
                onClick={() => setDayEndConfirmOpen(false)}
                className="h-10 w-[110px] border border-[#ACB8B8] bg-white text-[11px] font-black uppercase tracking-wide text-[#004141]"
              >
                Cancel
              </button>
              <button
                type="button"
                onClick={handleRunDayEnd}
                className="h-10 w-[130px] border border-[#B38A18] bg-[#F4C542] text-[11px] font-black uppercase tracking-wide text-[#3B2A00]"
              >
                Run Day End
              </button>
            </div>
          </div>
        </div>
      )}

      {releaseLockConfirmOpen && (
        <div className="fixed inset-0 z-[100000] flex items-center justify-center bg-black/55 px-4">
          <div className="w-full max-w-[520px] border border-[#ACB8B8] bg-white shadow-2xl">
            <div className="border-b border-[#CAD5D5] bg-[#004142] px-5 py-4 text-white">
              <div className="text-[15px] font-black uppercase tracking-wide">
                Release Day End Lock
              </div>
              <div className="mt-1 text-[11px] font-semibold text-white/75">
                Trading date: {formatTradingDate(currentTradingDay)}
              </div>
            </div>
            <div className="px-5 py-5 text-[13px] font-semibold text-[#004141]">
              Are you sure you want to release the running Day End lock? Use
              this only if Day End was interrupted.
            </div>
            <div className="flex justify-end gap-2 border-t border-[#E2E9E9] bg-[#F8FAFC] px-5 py-4">
              <button
                type="button"
                onClick={() => setReleaseLockConfirmOpen(false)}
                className="h-10 w-[110px] border border-[#ACB8B8] bg-white text-[11px] font-black uppercase tracking-wide text-[#004141]"
              >
                Cancel
              </button>
              <button
                type="button"
                onClick={handleReleaseLock}
                className="h-10 w-[130px] border border-[#ACB8B8] bg-white text-[11px] font-black uppercase tracking-wide text-[#004141]"
              >
                Release Lock
              </button>
            </div>
          </div>
        </div>
      )}

      <DayEndEnterpriseGuardModal
        open={dayEndGuardOpen}
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
        onCompleted={() => {
          loadCurrentTradingDay();
        }}
      />

      <UserManagementFullModal
        open={userManagementOpen}
        onClose={() => setUserManagementOpen(false)}
      />

      <Roles open={roleOpen} onClose={() => setRoleOpen(false)} />

      <GeneralSettings
        open={generalSettingsOpen}
        onClose={() => setSetGeneralSettingsOpen(false)}
      />

      <ShiftConfiguration
        open={shiftSettingsOpen}
        onClose={() => setShiftSettingsOpen(false)}
      />

      <CreateSaleModal
        open={createSaleOpen}
        onClose={() => setCreateSaleOpen(false)}
      />

      <CompanyLicenseModal
        open={licenseOpen}
        onClose={() => setLicenseOpen(false)}
        companyId={user?.company?.id}
      />

      <BranchManagementModal
        open={openBranchSettings}
        onClose={() => setBranchSettings(false)}
        adminMode
        companyId={user?.company?.id}
      />

      <ProductCategoryModal
        open={openCategoryManagement}
        onClose={() => setCategoryManagementOpen(false)}
      />

      <SupplierModal
        open={openSupplierManagement}
        onClose={() => setSupplierManagementOpen(false)}
      />

      <InventoryManagementModal
        open={openProductManagement}
        onClose={() => setProductManagementOpen(false)}
        currentUser={user}
        initialTab={inventoryInitialTab}
        isHospitality
      />

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
      />

      <ReceiveStockModal
        open={receiveStockOpen}
        onClose={() => setReceiveStockOpen(false)}
        currentBranchId={user?.branch_id || ""}
        currentBranchName={
          user?.branch?.branch_name || user?.branch?.name || ""
        }
        onSaved={() => {}}
      />

      <BulkStockAdjustmentModal
        open={bulkStockAdjustmentOpen}
        onClose={() => setBulkStockAdjustmentOpen(false)}
        currentBranchId={user?.branch_id || ""}
        currentBranchName={
          user?.branch?.branch_name || user?.branch?.name || ""
        }
        onSaved={() => {}}
      />

      <StockAdjustmentHistoryModal
        open={stockAdjustmentHistoryOpen}
        onClose={() => setStockAdjustmentHistoryOpen(false)}
        currentBranchId={user?.branch_id || ""}
        currentBranchName={
          user?.branch?.branch_name || user?.branch?.name || ""
        }
      />

      <PosReportsModal
        open={posReportOpen}
        onClose={() => {
          setPosReportOpen(false);
          setSelectedPosReport(null);
        }}
        initialReport={selectedPosReport || "payments"}
        title="BOH REPORTS"
        businessMode="fast_food_boh"
        useTradingDate
        tradingDate={currentTradingDay || new Date().toISOString().slice(0, 10)}
        currentUser={user}
        allowedReports={["payments", "cashiers", "cashups", "cashouts"]}
      />

      <StockReportsModal
        open={stockReportOpen}
        onClose={() => {
          setStockReportOpen(false);
          setSelectedStockReport(null);
        }}
        initialReport={selectedStockReport}
      />

      <CurrencyManagementModal
        open={currencyOpen}
        onClose={() => setCurrencyOpen(false)}
      />

      <PaymentTypeManagementModal
        open={paymentTypeOpen}
        onClose={() => setPaymentTypeOpen(false)}
      />

      <TillManagementModal
        open={tillManagementOpen}
        onClose={() => setTillManagementOpen(false)}
        currentUser={user}
      />

      <PrinterManagementModal
        open={printerOpen}
        onClose={() => setPrinterOpen(false)}
        currentUser={user}
      />

      <ReceiptActionsModal
        open={showReceiptActions}
        onClose={() => setShowReceiptActions(false)}
      />

      <ReceiptRegisterModal
        open={receiptRegisterOpen}
        onClose={() => setReceiptRegisterOpen(false)}
      />

      <ProductImportModal
        open={productImportOpen}
        onClose={() => setProductImportOpen(false)}
        isHospitality
        onImported={() => {
          window.dispatchEvent(new Event("salexa_products_imported"));
        }}
      />

      <StockTakeModal
        open={stockTakeOpen}
        onClose={() => setStockTakeOpen(false)}
      />

      <CancelledReceiptsModal
        open={cancelledReceiptOpen}
        onClose={() => setCancelledReceiptOpen(false)}
      />

      <StockTransferModal
        open={stockTransferOpen}
        onClose={() => setStockTransferOpen(false)}
      />

      <EnterpriseCashupModal
        open={enterpriseCashupOpen}
        onClose={() => setEnterpriseCashupOpen(false)}
        closeShiftDefault
      />

      <ProductBarcodePrintModal
        open={productBarcodePrintOpen}
        onClose={() => setProductBarcodePrintOpen(false)}
      />

      <DiscountManagementModal
        open={discountManagementOpen}
        onClose={() => setDiscountManagementOpen(false)}
      />

      <DiscountReportsModal
        open={discountReportOpen}
        onClose={() => setDiscountReportOpen(false)}
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
      />

      <ProfitAndLossReport
        open={profitReportOpen}
        onClose={() => setProfitReportOpen(false)}
      />

      <PurchaseManagementModal
        open={purchaseManagementOpen}
        onClose={() => setPurchaseManagementOpen(false)}
        currentUser={user}
        initialTab={purchaseInitialTab}
      />

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
      />

      <FrontStockRegisterModal
        open={frontStockManagementOpen}
        onClose={() => setFrontManagementStockOpen(false)}
        currentUser={user}
      />

      <BackStockRegisterModal
        open={backStockManagementOpen}
        onClose={() => setBackManagementStockOpen(false)}
        currentUser={user}
      />

      <IssueStockToFrontModal
        open={issueStockToFrontOpen}
        onClose={() => setIssueStockToFrontOpen(false)}
        currentUser={user}
      />
      {/* 
<FoodRecipeBomModal
  open={foodRecipeBomOpen}
  onClose={() => setFoodRecipeBomOpen(false)}
  currentUser={user}
/> */}

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

      {/* <MenuItemManagementModal
  open={menuItemManagementOpen}
  onClose={() => setMenuItemManagementOpen(false)}
  currentUser={user}
/> */}

      <MenuItemManagementModal
        open={menuItemManagementOpen}
        onClose={() => setMenuItemManagementOpen(false)}
        currentUser={user}
        onCreateRecipeForMenuItem={(product) => {
          setRecipeMenuItem(product);
          setFoodRecipeBomOpen(true);
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

      <DailyFrontStockClosingModal
        open={dailyFrontStockClosingOpen}
        onClose={() => setDailyFrontStockClosingOpen(false)}
        onOpenIssueStock={() => {
          setDailyFrontStockClosingOpen(false);
          setIssueStockToFrontOpen(true);
        }}
        defaultCurrency="USD"
      />

      <DailyStockReconciliationReportModal
        open={dailyStockReconciliationOpen}
        onClose={() => setDailyStockReconciliationOpen(false)}
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

      <StockWastageModal
        open={stockWastageOpen}
        onClose={() => setStockWastageOpen(false)}
        currentUser={user}
      />

      <StockWastageReportModal
        open={stockWastageReportOpen}
        onClose={() => setStockWastageReportOpen(false)}
        currentUser={user}
        onNavigateReport={navigateHospitalityReport}
      />

      <CashupHistoryReportModal
        open={cashupHistoryReportOpen}
        onClose={() => setCashupHistoryReportOpen(false)}
        currentUser={user}
      />

      <CashoutReportModal
        open={cashoutReportOpen}
        onClose={() => setCashoutReportOpen(false)}
        currentUser={user}
      />

      <ShiftReportModal
        open={shiftReportOpen}
        onClose={() => setShiftReportOpen(false)}
        currentUser={user}
        businessMode="fast_food_boh"
        tradingDate={currentTradingDay || ""}
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

      <FoodManufacturingPlanningModal
  open={foodManufacturingPlanningOpen}
  onClose={() => setFoodManufacturingPlanningOpen(false)}
  currentUser={user}
  onRecordProduction={() => {
    setFoodManufacturingPlanningOpen(false);
    setFoodManufacturingBatchOpen(true);
  }}
/>
    </div>
    </BackOfficeThemeProvider>
  );
}

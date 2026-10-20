import React, { useCallback, useEffect, useMemo, useState } from "react";
import { ArrowRight, BrainCircuit, RefreshCw } from "lucide-react";

import FullScreenModal from "../../../components/FullScreenModal";
import { requestEnterpriseConfirmation } from "../../../components/EnterpriseConfirmHost";
import EnterpriseFormModal from "../../../components/EnterpriseFormModal";
import ReusableMessageModal from "../../../components/ReusableMessageModal";
import InventoryVarianceAgentModal from "./InventoryVarianceAgentModal";

import { getAuthorisedCompany } from "../../../services/AuthenticationService";
import { getBaseCurrencies } from "../../../services/CurrencyService";
import {
  getEnterpriseInventoryDashboard, refreshInventoryAlerts, getInventoryWarehouses,
  createInventoryWarehouse, updateInventoryWarehouse, deleteInventoryWarehouse, getWarehouseProducts,
  createInventoryLocation, getInventoryBatches, getInventoryBatch, updateInventoryBatch, reconcileInventoryBatches, getInventoryMovements,
  getInventoryAlerts, getInventoryRecalls, createInventoryRecall, closeInventoryRecall, getInventoryVarianceOverview,
} from "../../../services/EnterpriseInventoryService";

import {
  getProducts,
  exportProducts,
  createProduct,
  updateProduct,
  deleteProduct,
  generateProductIdentity,
  breakProduct,
  getProductCategories,
  getProductSubCategories,
  getUoms,
} from "../../../services/ProductService";

const PRODUCT_TYPE_OPTIONS = [
  ["raw_material", "Raw Material"],
  ["semi_finished", "Semi Finished"],
  ["packaging", "Packaging"],
  ["consumable", "Consumable"],
];

const PRODUCT_TYPE_VALUES = PRODUCT_TYPE_OPTIONS.map(([value]) => value);

const STOCK_USAGE_OPTIONS = [
  ["turnover", "Turnover Stock"],
  ["non_turnover", "Non-Turnover Stock"],
];

const ISSUE_DESTINATION_OPTIONS = [
  ["front", "Front / Selling Stock"],
  ["department", "Department Use"],
  ["expense", "Operating Expense"],
  ["production", "Production Use"],
  ["wastage", "Wastage / Spoilage"],
];

const TURNOVER_DESTINATIONS = new Set(["front", "production", "department", "expense", "wastage"]);
const NON_TURNOVER_DESTINATIONS = new Set(["expense", "department", "production", "wastage"]);
const formatInventoryQuantity = (amount) => Number(amount || 0).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 3 });
const formatInventoryMoney = (amount, currency = "") => `${currency ? `${currency} ` : ""}${Number(amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const STOCK_ITEM_ALLOWED_TYPES = new Set([
  "raw_material",
  "raw material",
  "semi_finished",
  "semi finished",
  "semi_finished_good",
  "semi finished good",
  "semi-finished good",
  "packaging",
  "consumable",
  "consumables",
]);

const normalizeProductType = (value) =>
  String(value || "")
    .trim()
    .toLowerCase()
    .replace(/[-\s]+/g, "_");

const isStockItemType = (value) => {
  const raw = String(value || "").trim().toLowerCase();
  const normalized = normalizeProductType(value);

  // Explicitly block finished goods from Stock Item Management.
  if (["finished_good", "finished good", "finished-good"].includes(raw) || normalized === "finished_good") {
    return false;
  }

  return STOCK_ITEM_ALLOWED_TYPES.has(raw) || STOCK_ITEM_ALLOWED_TYPES.has(normalized);
};

const PARENT_ALLOWED_TYPES = PRODUCT_TYPE_VALUES;
const emptyFormData = {
  id: null,
  is_global: true,
  branch_id: "",

  category_id: "",
  sub_category_id: "",
  parent_product_id: "",
  base_uom_id: "",
  tax_id: "",

  name: "",
  code: "",
  sku: "",
  barcode: "",
  description: "",
  product_type: "raw_material",
  stock_usage_type: "turnover",
  default_issue_destination: "front",
  expense_account_code: "",

  stock_tracking_enabled: false,
  batch_tracking_enabled: false,
  expiry_tracking_enabled: false,
  default_shelf_life_days: "",
  allow_negative_stock: false,
  allow_discount: true,
  taxable: false,
  is_active: true,

  reorder_level: "0",
  minimum_stock: "0",
  maximum_stock: "0",

  barcodes: [],

  create_price_enabled: false,
  price_rows: [],

  opening_stock_enabled: false,
  opening_stock_branch_id: "",
  opening_stock_quantity: "",
  opening_stock_unit_cost: "",
  opening_stock_currency: "",

  break_rule_enabled: false,
  break_parent_quantity: "1",
  break_units_per_parent: "1",
  break_is_auto_break: true,
};

const emptyBreakForm = {
  child_product_id: "",
  branch_id: "",
  break_times: "1",
};

const InventoryManagementModal = ({ open, onClose, currentUser, initialTab = "overview", isHospitality = false }) => {
  const [products, setProducts] = useState([]);
  const [branches, setBranches] = useState([]);
  const [categories, setCategories] = useState([]);
  const [subCategories, setSubCategories] = useState([]);
  const [uoms, setUoms] = useState([]);
  const [baseCurrencies, setBaseCurrencies] = useState([]);

  const [loading, setLoading] = useState(false);
  const [savingProduct, setSavingProduct] = useState(false);
  const [savingBreak, setSavingBreak] = useState(false);

  const [deletingProductId, setDeletingProductId] = useState(null);
  const [generatingType, setGeneratingType] = useState("");
  const [exportingFormat, setExportingFormat] = useState("");

  const [productModalOpen, setProductModalOpen] = useState(false);
  const [breakModalOpen, setBreakModalOpen] = useState(false);

  const [search, setSearch] = useState("");
  const [typeFilter, setTypeFilter] = useState("");
  const [categoryFilter, setCategoryFilter] = useState("");
  const [subCategoryFilter, setSubCategoryFilter] = useState("");
  const [statusFilter, setStatusFilter] = useState("");

  const [activeTab, setActiveTab] = useState("overview");
  const [enterpriseDashboard, setEnterpriseDashboard] = useState({});
  const [varianceAgentOpen, setVarianceAgentOpen] = useState(false);
  const [varianceOverview, setVarianceOverview] = useState(null);
  const [varianceOverviewLoading, setVarianceOverviewLoading] = useState(false);
  const [varianceOverviewError, setVarianceOverviewError] = useState("");
  const [refreshingAlerts, setRefreshingAlerts] = useState(false);
  const [warehouses, setWarehouses] = useState([]);
  const [batches, setBatches] = useState([]);
  const [movements, setMovements] = useState([]);
  const [inventoryAlerts, setInventoryAlerts] = useState([]);
  const [recalls, setRecalls] = useState([]);
  const [warehouseForm, setWarehouseForm] = useState({ branch_id: "", code: "", name: "", warehouse_type: "general", address: "" });
  const [editingWarehouseId, setEditingWarehouseId] = useState("");
  const [warehouseFormOpen, setWarehouseFormOpen] = useState(false);
  const [selectedWarehouseId, setSelectedWarehouseId] = useState("");
  const [warehouseDetail, setWarehouseDetail] = useState(null);
  const [warehouseMovements, setWarehouseMovements] = useState([]);
  const [warehouseSearch, setWarehouseSearch] = useState("");
  const [loadingWarehouseDetail, setLoadingWarehouseDetail] = useState(false);
  const [reconcilingBatches, setReconcilingBatches] = useState(false);
  const [editingBatchId, setEditingBatchId] = useState("");
  const [batchForm, setBatchForm] = useState({ lot_number: "", manufactured_at: "", expires_at: "", notes: "" });
  const [batchFormOpen, setBatchFormOpen] = useState(false);
  const [selectedBatchDetail, setSelectedBatchDetail] = useState(null);
  const [loadingBatchDetail, setLoadingBatchDetail] = useState(false);
  const [locationForm, setLocationForm] = useState({ warehouse_id: "", code: "", name: "", location_type: "storage", pick_sequence: 0 });
  const [locationFormOpen, setLocationFormOpen] = useState(false);
  const [recallForm, setRecallForm] = useState({ title: "", reason: "", severity: "high", batch_ids: [] });
  const [recallFormOpen, setRecallFormOpen] = useState(false);
  const [recallResolutionForm, setRecallResolutionForm] = useState({ recall: null, resolution: "release", notes: "" });
  const [operationalFormBusy, setOperationalFormBusy] = useState(false);
  const [selectedProductId, setSelectedProductId] = useState(null);

  const [formData, setFormData] = useState(emptyFormData);
  const [breakForm, setBreakForm] = useState(emptyBreakForm);

  const [messageModal, setMessageModal] = useState({
    open: false,
    type: "success",
    title: "",
    message: "",
  });

  const isEditing = !!formData.id;
  const normalizedRole = String(currentUser?.role?.name || currentUser?.role_name || "").trim().toLowerCase().replace(/[ -]+/g, "_");
  const normalizedUserType = String(currentUser?.user_type || "").trim().toLowerCase().replace(/[ -]+/g, "_");
  const isSuperAdmin = Boolean(currentUser?.is_super_admin || normalizedRole === "super_admin" || normalizedRole === "super_administrator" || normalizedUserType === "super_admin");
  const assignedBranchIds = new Set((currentUser?.branches || currentUser?.assigned_branches || []).map((branch) => String(branch?.id || branch?.branch_id || branch)).filter(Boolean));
  const permissionNames = new Set((currentUser?.role?.permissions || currentUser?.permissions || []).map((row) => String(row?.name || row?.permission?.name || row || "").trim().toLowerCase()));
  const canAny = (...names) => !currentUser || isSuperAdmin || names.some((name) => permissionNames.has(String(name).toLowerCase()));
  const canViewVarianceAgent = canAny("view_inventory_variance_agent", "manage_inventory_variance_agent", "inventory_dashboard", "inventory_management");
  const availableTabs = [
    canAny("inventory_dashboard", "inventory_management", "view_stock_reports") && "overview",
    canAny("manage_warehouses", "inventory_management") && "warehouses",
    canAny("view_inventory_batches", "manage_inventory_batches", "inventory_management", "view_stock_reports") && "batches",
    canAny("view_inventory_movements", "inventory_management", "view_stock_reports") && "movements",
    canAny("view_inventory_recalls", "manage_inventory_recalls", "view_inventory_alerts", "inventory_management") && "recalls",
    isHospitality && canAny("product_management", "inventory_management") && "register",
    isHospitality && canAny("product_management", "inventory_management") && "break",
  ].filter(Boolean);

  const showMessage = ({ type = "success", title, message }) => {
    setMessageModal({ open: true, type, title, message });
  };

  const closeMessageModal = () => {
    setMessageModal((prev) => ({ ...prev, open: false }));
  };

  const getApiMessage = (source, fallback = "Request failed.") => {
    const responseData = source?.response?.data;

    if (responseData?.errors && typeof responseData.errors === "object") {
      return Object.values(responseData.errors).flat().join("\n");
    }

    if (responseData?.data && typeof responseData.data === "object") {
      const values = Object.values(responseData.data).flat();
      if (values.length > 0) return values.join("\n");
    }

    if (responseData?.error) return responseData.error;
    if (responseData?.message) return responseData.message;
    if (source?.data?.message) return source.data.message;
    if (source?.message && !source?.isAxiosError) return source.message;

    return fallback;
  };

  const normalizeListResponse = (response) => {
    const payload = response?.data;

    if (Array.isArray(payload)) return payload;
    if (Array.isArray(payload?.data)) return payload.data;
    if (Array.isArray(response?.data?.data)) return response.data.data;

    return [];
  };

  const normalizeCurrencyResponse = (response) => {
    const payload = response?.data;

    if (Array.isArray(payload)) return payload;
    if (Array.isArray(payload?.data)) return payload.data;
    if (Array.isArray(response?.data?.data)) return response.data.data;

    return [];
  };

  const getDefaultCurrencyCode = () => baseCurrencies?.[0]?.code || "";

  const money = (amount, currency = "") => {
    if (amount === null || amount === undefined || amount === "") return "-";
    const code = currency || getDefaultCurrencyCode() || "";
    return `${code} ${Number(amount || 0).toFixed(4)}`;
  };

  const quantity = (amount) => {
    if (amount === null || amount === undefined || amount === "") return "0.0000";
    return Number(amount || 0).toFixed(4);
  };

  const getBranchName = (branch) => branch?.branch_name || branch?.name || "Unnamed Branch";

  const getLoggedInBranchId = () => {
    return currentUser?.branch?.id || currentUser?.branch_id || currentUser?.branchId || "";
  };

  const getLoggedInBranch = () => {
    const loggedBranchId = getLoggedInBranchId();
    const matchedBranch = branches.find((branch) => String(branch.id) === String(loggedBranchId));
    if (matchedBranch) return matchedBranch;
    if (currentUser?.branch) return currentUser.branch;
    return null;
  };

  const getBranchOptions = () => {
    if (isSuperAdmin) return branches;
    if (assignedBranchIds.size > 0) return branches.filter((branch) => assignedBranchIds.has(String(branch.id)));
    const loggedBranch = getLoggedInBranch();
    return loggedBranch?.id ? [loggedBranch] : branches.slice(0, 1);
  };

  const canChooseProductScope = isSuperAdmin || getBranchOptions().length > 1;

  const getDefaultBranchId = () => getLoggedInBranch()?.id || branches?.[0]?.id || "";

  const loadBranches = async () => {
    const response = await getAuthorisedCompany();

    const company = response?.company || response?.data?.company || null;

    const companyBranches =
      response?.company?.branches ||
      response?.data?.branches ||
      response?.data?.company?.branches ||
      company?.branches ||
      [];

    setBranches(companyBranches);
    return companyBranches;
  };

  const getProductFilterParams = (scopeOverride = canChooseProductScope) => {
    const params = { per_page: 100, include_all_branches: scopeOverride ? 1 : 0 };

    if (search.trim()) params.q = search.trim();
    if (typeFilter) params.product_type = typeFilter;
    else params.product_types = PRODUCT_TYPE_VALUES.join(",");
    if (categoryFilter) params.category_id = categoryFilter;
    if (subCategoryFilter) params.sub_category_id = subCategoryFilter;
    if (statusFilter !== "") params.is_active = statusFilter;

    return params;
  };

  const loadProducts = async (scopeOverride = canChooseProductScope) => {
    const response = await getProducts(getProductFilterParams(scopeOverride));
    const rows = normalizeListResponse(response).filter((product) => isStockItemType(product?.product_type));
    setProducts(rows);
  };

  const loadCategories = async () => {
    const response = await getProductCategories({ per_page: 500 });
    setCategories(normalizeListResponse(response));
  };

  const loadSubCategories = async () => {
    const response = await getProductSubCategories({ per_page: 500 });
    setSubCategories(normalizeListResponse(response));
  };


  const loadUoms = async () => {
    const response = await getUoms();
    setUoms(normalizeListResponse(response));
  };


  const loadBaseCurrencyRows = async () => {
    const response = await getBaseCurrencies();
    const rows = normalizeCurrencyResponse(response);
    setBaseCurrencies(rows);
  };

  const loadData = async () => {
    try {
      setLoading(true);

      const loadedBranches = await loadBranches();
      const canChooseLoadedScope = isSuperAdmin || (
        assignedBranchIds.size > 0 && loadedBranches.filter((branch) => assignedBranchIds.has(String(branch.id))).length > 1
      );
      const results = await Promise.all([
        loadProducts(canChooseLoadedScope),
        loadCategories(),
        loadSubCategories(),
        loadUoms(),
        loadBaseCurrencyRows(),
        canAny("inventory_dashboard", "inventory_management", "view_stock_reports") ? getEnterpriseInventoryDashboard() : Promise.resolve({}),
        canAny("manage_warehouses", "inventory_management", "view_stock_reports") ? getInventoryWarehouses() : Promise.resolve([]),
        canAny("view_inventory_batches", "manage_inventory_batches", "manage_inventory_recalls", "inventory_management", "view_stock_reports") ? getInventoryBatches({ per_page: 200 }) : Promise.resolve({ data: [] }),
        canAny("view_inventory_movements", "inventory_management", "view_stock_reports") ? getInventoryMovements({ per_page: 200 }) : Promise.resolve({ data: [] }),
        canAny("view_inventory_alerts", "manage_inventory_alerts", "inventory_management", "view_stock_reports") ? getInventoryAlerts({ per_page: 200 }) : Promise.resolve({ data: [] }),
        canAny("view_inventory_recalls", "manage_inventory_recalls", "inventory_management") ? getInventoryRecalls({ per_page: 200 }) : Promise.resolve({ data: [] }),
      ]);
      setEnterpriseDashboard(results[5] || {});
      setWarehouses(Array.isArray(results[6]) ? results[6] : []);
      setBatches(results[7]?.data || []);
      setMovements(results[8]?.data || []);
      setInventoryAlerts(results[9]?.data || []);
      setRecalls(results[10]?.data || []);
    } catch (error) {
      showMessage({ type: "error", title: "Load Failed", message: getApiMessage(error) });
    } finally {
      setLoading(false);
    }
  };

  const reloadOperationalData = async () => {
    const [warehouseRows, batchPage, movementPage, alertPage, recallPage, dashboard] = await Promise.all([
      canAny("manage_warehouses", "inventory_management", "view_stock_reports") ? getInventoryWarehouses() : Promise.resolve([]),
      canAny("view_inventory_batches", "manage_inventory_batches", "manage_inventory_recalls", "inventory_management", "view_stock_reports") ? getInventoryBatches({ per_page: 200 }) : Promise.resolve({ data: [] }),
      canAny("view_inventory_movements", "inventory_management", "view_stock_reports") ? getInventoryMovements({ per_page: 200 }) : Promise.resolve({ data: [] }),
      canAny("view_inventory_alerts", "manage_inventory_alerts", "inventory_management", "view_stock_reports") ? getInventoryAlerts({ per_page: 200 }) : Promise.resolve({ data: [] }),
      canAny("view_inventory_recalls", "manage_inventory_recalls", "inventory_management") ? getInventoryRecalls({ per_page: 200 }) : Promise.resolve({ data: [] }),
      canAny("inventory_dashboard", "inventory_management", "view_stock_reports") ? getEnterpriseInventoryDashboard() : Promise.resolve({}),
    ]);
    setWarehouses(Array.isArray(warehouseRows) ? warehouseRows : []);
    setBatches(batchPage?.data || []);
    setMovements(movementPage?.data || []);
    setInventoryAlerts(alertPage?.data || []);
    setRecalls(recallPage?.data || []);
    setEnterpriseDashboard(dashboard || {});
  };

  const saveWarehouse = async () => {
    const wasEditing = Boolean(editingWarehouseId);
    try {
      setOperationalFormBusy(true);
      if (editingWarehouseId) await updateInventoryWarehouse(editingWarehouseId, {
        name: warehouseForm.name,
        warehouse_type: warehouseForm.warehouse_type,
        address: warehouseForm.address,
        is_active: true,
      });
      else await createInventoryWarehouse(warehouseForm);
      setWarehouseForm({ branch_id: "", code: "", name: "", warehouse_type: "general", address: "" });
      setEditingWarehouseId("");
      setWarehouseFormOpen(false);
      await reloadOperationalData();
      showMessage({ title: wasEditing ? "Warehouse Updated" : "Warehouse Created", message: "The warehouse is now available to the selected branch." });
    } catch (error) { showMessage({ type: "error", title: "Warehouse Failed", message: getApiMessage(error) }); }
    finally { setOperationalFormBusy(false); }
  };

  const openCreateWarehouse = () => {
    setEditingWarehouseId("");
    setWarehouseForm({ branch_id: "", code: "", name: "", warehouse_type: "general", address: "" });
    setWarehouseFormOpen(true);
  };

  const editWarehouse = (warehouse) => {
    setEditingWarehouseId(warehouse.id);
    setWarehouseForm({
      branch_id: warehouse.branch_id || "",
      code: warehouse.code || "",
      name: warehouse.name || "",
      warehouse_type: warehouse.warehouse_type || "general",
      address: warehouse.address || "",
    });
    setWarehouseFormOpen(true);
  };

  const cancelWarehouseEdit = () => {
    setEditingWarehouseId("");
    setWarehouseForm({ branch_id: "", code: "", name: "", warehouse_type: "general", address: "" });
    setWarehouseFormOpen(false);
  };

  const removeWarehouse = async (warehouse) => {
    if (!await requestEnterpriseConfirmation({
      title: "Delete Warehouse",
      message: `Delete warehouse ${warehouse.name}? Warehouses with stock or open transfers cannot be deleted.`,
      confirmLabel: "Delete warehouse",
    })) return;
    try {
      await deleteInventoryWarehouse(warehouse.id);
      if (selectedWarehouseId === warehouse.id) {
        setSelectedWarehouseId("");
        setWarehouseDetail(null);
        setWarehouseMovements([]);
      }
      await reloadOperationalData();
      showMessage({ title: "Warehouse Deleted", message: "The empty warehouse was removed safely." });
    } catch (error) { showMessage({ type: "error", title: "Warehouse Delete Failed", message: getApiMessage(error) }); }
  };

  const openWarehouse = async (warehouse) => {
    if (!warehouse?.id) return;
    try {
      setLoadingWarehouseDetail(true);
      if (selectedWarehouseId !== warehouse.id) {
        setWarehouseDetail(null);
        setWarehouseMovements([]);
        setWarehouseSearch("");
      }
      setSelectedWarehouseId(warehouse.id);
      const [detail, movementPage] = await Promise.all([
        getWarehouseProducts(warehouse.id, { per_page: 500 }),
        getInventoryMovements({ warehouse_id: warehouse.id, per_page: 100 }),
      ]);
      setWarehouseDetail(detail || null);
      setWarehouseMovements(movementPage?.data || []);
    } catch (error) { showMessage({ type: "error", title: "Warehouse Load Failed", message: getApiMessage(error) }); }
    finally { setLoadingWarehouseDetail(false); }
  };

  const reconcileBatches = async () => {
    try {
      setReconcilingBatches(true);
      await reconcileInventoryBatches();
      await reloadOperationalData();
      showMessage({ title: "Batch Stock Reconciled", message: "Existing tracked stock is now represented by released system opening batches. Future receipts retain their supplied lot and expiry details." });
    } catch (error) { showMessage({ type: "error", title: "Batch Reconciliation Failed", message: getApiMessage(error) }); }
    finally { setReconcilingBatches(false); }
  };

  const editBatch = (batch) => {
    setEditingBatchId(batch.id);
    setBatchForm({ lot_number: batch.lot_number || "", manufactured_at: batch.manufactured_at || "", expires_at: batch.expires_at || "", notes: batch.notes || "" });
    setBatchFormOpen(true);
  };

  const saveBatch = async () => {
    try {
      setOperationalFormBusy(true);
      await updateInventoryBatch(editingBatchId, { ...batchForm, manufactured_at: batchForm.manufactured_at || null, expires_at: batchForm.expires_at || null });
      setEditingBatchId("");
      setBatchForm({ lot_number: "", manufactured_at: "", expires_at: "", notes: "" });
      setBatchFormOpen(false);
      await reloadOperationalData();
      showMessage({ title: "Batch Updated", message: "The lot and expiry details were saved without changing its stock quantity." });
    } catch (error) { showMessage({ type: "error", title: "Batch Update Failed", message: getApiMessage(error) }); }
    finally { setOperationalFormBusy(false); }
  };

  const openBatchDetail = async (batch) => {
    try {
      setLoadingBatchDetail(true);
      setSelectedBatchDetail(await getInventoryBatch(batch.id));
    } catch (error) { showMessage({ type: "error", title: "Batch Load Failed", message: getApiMessage(error) }); }
    finally { setLoadingBatchDetail(false); }
  };

  const saveLocation = async () => {
    try {
      setOperationalFormBusy(true);
      const { warehouse_id, ...payload } = locationForm;
      await createInventoryLocation(warehouse_id, payload);
      setLocationForm({ warehouse_id: "", code: "", name: "", location_type: "storage", pick_sequence: 0 });
      setLocationFormOpen(false);
      await reloadOperationalData();
      showMessage({ title: "Location Created", message: "The warehouse location is ready for stock." });
    } catch (error) { showMessage({ type: "error", title: "Location Failed", message: getApiMessage(error) }); }
    finally { setOperationalFormBusy(false); }
  };

  const toggleRecallBatch = (batchId) => setRecallForm((previous) => ({ ...previous, batch_ids: previous.batch_ids.includes(batchId) ? previous.batch_ids.filter((id) => id !== batchId) : [...previous.batch_ids, batchId] }));

  const activateRecall = async () => {
    try {
      setOperationalFormBusy(true);
      await createInventoryRecall(recallForm);
      setRecallForm({ title: "", reason: "", severity: "high", batch_ids: [] });
      setRecallFormOpen(false);
      await reloadOperationalData();
      showMessage({ title: "Recall Activated", message: "Selected lots are quarantined and blocked from sale." });
    } catch (error) { showMessage({ type: "error", title: "Recall Failed", message: getApiMessage(error) }); }
    finally { setOperationalFormBusy(false); }
  };

  const openRecallResolution = (recall, resolution) => {
    setRecallResolutionForm({ recall, resolution, notes: "" });
  };

  const closeRecallResolution = () => {
    if (operationalFormBusy) return;
    setRecallResolutionForm({ recall: null, resolution: "release", notes: "" });
  };

  const resolveRecall = async () => {
    const { recall, resolution, notes } = recallResolutionForm;
    if (!recall || !notes.trim()) return;

    try {
      setOperationalFormBusy(true);
      await closeInventoryRecall(recall.id, { resolution, resolution_notes: notes.trim() });
      setRecallResolutionForm({ recall: null, resolution: "release", notes: "" });
      await reloadOperationalData();
      showMessage({ title: "Recall Closed", message: `The recalled stock was ${resolution === "release" ? "released" : "destroyed"}.` });
    } catch (error) { showMessage({ type: "error", title: "Recall Close Failed", message: getApiMessage(error) }); }
    finally { setOperationalFormBusy(false); }
  };

  const refreshEnterpriseAlerts = async () => {
    try {
      setRefreshingAlerts(true);
      await refreshInventoryAlerts();
      setEnterpriseDashboard(await getEnterpriseInventoryDashboard());
      showMessage({ type: "success", title: "Inventory Alerts Updated", message: "Stock, expiry and replenishment risks were recalculated from current company data." });
    } catch (error) {
      showMessage({ type: "error", title: "Alert Refresh Failed", message: getApiMessage(error) });
    } finally {
      setRefreshingAlerts(false);
    }
  };

  const refreshVarianceOverview = useCallback(async () => {
    if (!canViewVarianceAgent) return;
    try {
      setVarianceOverviewLoading(true);
      setVarianceOverviewError("");
      setVarianceOverview(await getInventoryVarianceOverview());
    } catch (error) {
      setVarianceOverviewError(error?.response?.data?.message || error?.message || "Unable to load the variance monitor.");
    } finally {
      setVarianceOverviewLoading(false);
    }
  }, [canViewVarianceAgent]);

  useEffect(() => {
    if (open) loadData();
  }, [open]);

  useEffect(() => {
    if (open && activeTab === "overview" && canViewVarianceAgent) refreshVarianceOverview();
  }, [open, activeTab, currentUser?.id, canViewVarianceAgent, refreshVarianceOverview]);

  useEffect(() => {
    if (open && availableTabs.includes(initialTab)) setActiveTab(initialTab);
  }, [open, initialTab]);

  useEffect(() => {
    if (open && !availableTabs.includes(activeTab) && availableTabs.length) setActiveTab(availableTabs[0]);
  }, [open, activeTab, availableTabs.join("|")]);

  useEffect(() => {
    if (!open) return;

    const timer = setTimeout(() => {
      loadProducts().catch((error) => {
        showMessage({ type: "error", title: "Filter Failed", message: getApiMessage(error) });
      });
    }, 350);

    return () => clearTimeout(timer);
  }, [search, typeFilter, categoryFilter, subCategoryFilter, statusFilter]);

  const parentProducts = useMemo(() => {
    return products.filter((product) => {
      if (product.id === formData.id) return false;
      return PARENT_ALLOWED_TYPES.includes(normalizeProductType(product.product_type));
    });
  }, [products, formData.id]);

  const filteredProducts = useMemo(() => {
    const q = search.toLowerCase();

    return products.filter((product) => {
      if (!isStockItemType(product?.product_type)) return false;

      const categoryName = product?.category?.name || "";
      const subCategoryName =
        product?.subCategory?.name ||
        product?.sub_category?.name ||
        "";
      const uomName = product?.uom?.name || "";
      const parentName = product?.parent?.name || "";

      return (
        product?.name?.toLowerCase().includes(q) ||
        product?.code?.toLowerCase().includes(q) ||
        product?.sku?.toLowerCase().includes(q) ||
        product?.barcode?.toLowerCase().includes(q) ||
        product?.product_type?.toLowerCase().includes(q) ||
        categoryName.toLowerCase().includes(q) ||
        subCategoryName.toLowerCase().includes(q) ||
        uomName.toLowerCase().includes(q) ||
        parentName.toLowerCase().includes(q)
      );
    });
  }, [products, search]);

  const selectedProduct = useMemo(() => {
    return products.find((product) => product.id === selectedProductId) || null;
  }, [products, selectedProductId]);

  const registerCounts = useMemo(() => {
    return {
      total: products.length,
      active: products.filter((product) => product.is_active).length,
      inactive: products.filter((product) => !product.is_active).length,
      rawMaterials: products.filter((product) => normalizeProductType(product.product_type) === "raw_material").length,
      semiFinished: products.filter((product) => ["semi_finished_good", "semi_finished"].includes(normalizeProductType(product.product_type))).length,
      packaging: products.filter((product) => normalizeProductType(product.product_type) === "packaging").length,
      consumables: products.filter((product) => ["consumable", "consumables"].includes(normalizeProductType(product.product_type))).length,
      turnover: products.filter((product) => getStockUsageType(product) === "turnover").length,
      nonTurnover: products.filter((product) => getStockUsageType(product) === "non_turnover").length,
    };
  }, [products]);

  const getBarcodeSummary = (product) => {
    const barcodes = Array.isArray(product?.barcodes) ? product.barcodes : [];
    const primary = barcodes.find((barcode) => barcode.type === "primary") || barcodes[0];

    return {
      primary: product?.barcode || primary?.barcode || "-",
      count: barcodes.length,
    };
  };

  const getBreakSummary = (product) => {
    const rule = product?.child_break_rule || product?.childBreakRule;
    if (!rule) return null;

    return {
      parentQuantity: rule.parent_quantity,
      unitsPerParent: rule.units_per_parent,
      isAutoBreak: rule.is_auto_break,
    };
  };

  const getChildrenSummary = (product) => {
    const children = Array.isArray(product?.children) ? product.children : [];
    return { count: children.length, list: children };
  };

  const getCategoryDisplay = (product) => {
    if (!product?.category) return "-";
    return product.category.code ? `${product.category.name} (${product.category.code})` : product.category.name;
  };

  const getSubCategoryDisplay = (product) => {
    const subCategory =
      product?.subCategory ||
      product?.sub_category ||
      product?.sub_category_details ||
      null;

    if (!subCategory) return "-";

    return subCategory.code
      ? `${subCategory.name} (${subCategory.code})`
      : subCategory.name;
  };

  const getFilteredSubCategoriesForCategory = (categoryId) => {
    if (!categoryId) return subCategories;

    return subCategories.filter(
      (subCategory) => String(subCategory.category_id) === String(categoryId)
    );
  };

  const getFormSubCategoryOptions = () => {
    return getFilteredSubCategoriesForCategory(formData.category_id);
  };

  const getFilterSubCategoryOptions = () => {
    return getFilteredSubCategoriesForCategory(categoryFilter);
  };

  const getUomDisplay = (product) => {
    if (!product?.uom) return "-";
    return `${product.uom.name}${product.uom.code ? ` (${product.uom.code})` : ""}`;
  };

  const getProductTypeLabel = (type) => {
    const normalized = normalizeProductType(type);
    return PRODUCT_TYPE_OPTIONS.find(([value]) => value === normalized)?.[1] || String(type || "Stock Item").replace(/[_-]+/g, " ");
  };

  function getStockUsageType(product) {
    const saved = normalizeProductType(product?.stock_usage_type);
    if (saved === "non_turnover") return "non_turnover";
    if (saved === "turnover") return "turnover";

    const type = normalizeProductType(product?.product_type);
    return ["consumable", "consumables"].includes(type) ? "non_turnover" : "turnover";
  }

  function getStockUsageLabel(value) {
    const normalized = normalizeProductType(value);
    return STOCK_USAGE_OPTIONS.find(([option]) => option === normalized)?.[1] || "Turnover Stock";
  }

  function getIssueDestinationLabel(value) {
    const normalized = normalizeProductType(value);
    return ISSUE_DESTINATION_OPTIONS.find(([option]) => option === normalized)?.[1] || "Front / Selling Stock";
  }

  function getDefaultDestinationForUsage(usageType, productType = "") {
    const usage = normalizeProductType(usageType);
    const type = normalizeProductType(productType);

    if (usage === "non_turnover" || ["consumable", "consumables"].includes(type)) {
      return "expense";
    }

    return "front";
  }

  function getDestinationOptionsForUsage(usageType) {
    const allowed = normalizeProductType(usageType) === "non_turnover"
      ? NON_TURNOVER_DESTINATIONS
      : TURNOVER_DESTINATIONS;

    return ISSUE_DESTINATION_OPTIONS.filter(([value]) => allowed.has(value));
  }

  const getProductFlags = (product) => {
    const usageType = getStockUsageType(product);
    const destination = product?.default_issue_destination || getDefaultDestinationForUsage(usageType, product?.product_type);

    return [
      usageType === "non_turnover" ? "Non-Turnover" : "Turnover",
      getIssueDestinationLabel(destination),
    ];
  };

  const downloadProductReport = async (format) => {
    try {
      setExportingFormat(format);
      const response = await exportProducts(format, getProductFilterParams());
      const disposition = response.headers?.["content-disposition"] || "";
      const encodedName = disposition.match(/filename\*=UTF-8''([^;]+)/i)?.[1];
      const plainName = disposition.match(/filename="?([^";]+)"?/i)?.[1];
      const filename = encodedName ? decodeURIComponent(encodedName) : plainName || `inventory-management.${format}`;
      const url = window.URL.createObjectURL(response.data);
      const link = document.createElement("a");
      link.href = url;
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.URL.revokeObjectURL(url);
    } catch (error) {
      showMessage({ type: "error", title: "Export Failed", message: getApiMessage(error, "The backend could not generate this report.") });
    } finally {
      setExportingFormat("");
    }
  };

  const downloadProductsExcel = () => downloadProductReport("xlsx");
  const downloadProductsPdf = () => downloadProductReport("pdf");

  const handleOpenCreate = () => {
    setActiveTab("register");
    setSelectedProductId(null);

    const defaultBranchId = getDefaultBranchId();
    const defaultCurrency = getDefaultCurrencyCode();

    setFormData({
      ...emptyFormData,
      is_global: canChooseProductScope,
      product_type: "raw_material",
      stock_usage_type: "turnover",
      default_issue_destination: "front",
      expense_account_code: "",
      branch_id: defaultBranchId,
      create_price_enabled: false,
      opening_stock_enabled: false,
      price_rows: [],
      barcodes: [],
    });

    setProductModalOpen(true);
  };

  const handleCloseProductModal = () => {
    if (savingProduct) return;
    setProductModalOpen(false);
    setFormData(emptyFormData);
    setGeneratingType("");
  };

  const handleChange = (e) => {
    const { name, value, checked, type } = e.target;

    setFormData((prev) => {
      const next = { ...prev, [name]: type === "checkbox" ? checked : value };

      if (name === "is_global") {
        next.is_global = value === "global";
        if (!next.is_global) next.branch_id = prev.branch_id || getDefaultBranchId();
      }

      if (name === "category_id") {
        next.sub_category_id = "";
      }

      if (name === "product_type") {
        const normalizedType = normalizeProductType(value);
        if (["consumable", "consumables"].includes(normalizedType)) {
          next.stock_usage_type = "non_turnover";
          next.default_issue_destination = "expense";
        } else if (!next.stock_usage_type || next.stock_usage_type === "non_turnover") {
          next.stock_usage_type = "turnover";
          next.default_issue_destination = "front";
        }

        if (value !== "child") {
          next.parent_product_id = "";
          next.break_rule_enabled = false;
          next.break_parent_quantity = "1";
          next.break_units_per_parent = "1";
          next.break_is_auto_break = true;
        }

        if (value === "service") {
          next.stock_tracking_enabled = false;
          next.opening_stock_enabled = false;
          next.allow_negative_stock = false;
        }

        // Inventory tracking is opt-in, including for physical products.
      }

      if (name === "stock_usage_type") {
        next.default_issue_destination = getDefaultDestinationForUsage(value, next.product_type);
      }

      if (name === "default_issue_destination") {
        const usage = normalizeProductType(next.stock_usage_type);
        if (usage === "non_turnover" && value === "front") {
          next.default_issue_destination = "expense";
        }
      }

      if (name === "stock_tracking_enabled" && !checked) {
        next.opening_stock_enabled = false;
        next.allow_negative_stock = false;
        next.batch_tracking_enabled = false;
        next.expiry_tracking_enabled = false;
        next.default_shelf_life_days = "";
      }

      if (name === "expiry_tracking_enabled") {
        next.batch_tracking_enabled = checked ? true : next.batch_tracking_enabled;
        if (!checked) next.default_shelf_life_days = "";
      }

      if (name === "create_price_enabled" && !checked) {
        next.create_price_enabled = false;
      }

      if (name === "opening_stock_enabled" && !checked) {
        next.opening_stock_enabled = false;
      }

      return next;
    });
  };

  const handleBreakChange = (e) => {
    const { name, value } = e.target;
    setBreakForm((prev) => ({ ...prev, [name]: value }));
  };

  const generateLocalBarcode = () => {
    const prefix = "27";
    const timestamp = Date.now().toString().slice(-9);
    const random = Math.floor(Math.random() * 90 + 10).toString();
    return `${prefix}${timestamp}${random}`;
  };

  const handleGenerateBarcode = () => {
    const barcode = generateLocalBarcode();

    setFormData((prev) => ({
      ...prev,
      barcode,
      barcodes: [{ barcode, type: "primary" }, ...prev.barcodes.filter((item) => item.type !== "primary")],
    }));
  };

  const handleGenerateIdentity = async (type) => {
    try {
      setGeneratingType(type);
      const response = await generateProductIdentity({ branch_id: formData.branch_id || null, type });
      const value = response?.data?.value || response?.value || "";

      if (!value) {
        showMessage({ type: "error", title: "Generate Failed", message: "The server did not return a generated value." });
        return;
      }

      setFormData((prev) => ({
        ...prev,
        ...(type === "product_code" ? { code: value } : {}),
        ...(type === "sku" ? { sku: value } : {}),
      }));
    } catch (error) {
      showMessage({ type: "error", title: "Generate Failed", message: getApiMessage(error) });
    } finally {
      setGeneratingType("");
    }
  };

  const handleAddAlternativeBarcode = () => {
    setFormData((prev) => ({ ...prev, barcodes: [...prev.barcodes, { barcode: "", type: "alternative" }] }));
  };

  const handleBarcodeChange = (index, field, value) => {
    setFormData((prev) => ({
      ...prev,
      barcodes: prev.barcodes.map((item, itemIndex) => (itemIndex === index ? { ...item, [field]: value } : item)),
    }));
  };

  const handleRemoveBarcode = (index) => {
    setFormData((prev) => ({ ...prev, barcodes: prev.barcodes.filter((_, itemIndex) => itemIndex !== index) }));
  };

  const validateProductForm = () => {
    if (!baseCurrencies.length) {
      showMessage({ type: "error", title: "Currency Missing", message: "No active base currency was found. Configure currency first." });
      return false;
    }

    if (!formData.name.trim()) {
      showMessage({ type: "error", title: "Validation Error", message: "Product name is required." });
      return false;
    }

    if (!formData.base_uom_id) {
      showMessage({ type: "error", title: "Validation Error", message: "Base UOM is required." });
      return false;
    }

    if (!PRODUCT_TYPE_VALUES.includes(normalizeProductType(formData.product_type))) {
      showMessage({ type: "error", title: "Validation Error", message: "Invalid product type selected." });
      return false;
    }

    if (formData.product_type === "child") {
      if (!formData.parent_product_id) {
        showMessage({ type: "error", title: "Validation Error", message: "Child product must have a parent product." });
        return false;
      }

      if (formData.break_rule_enabled) {
        if (!formData.break_parent_quantity || Number(formData.break_parent_quantity) <= 0 || !formData.break_units_per_parent || Number(formData.break_units_per_parent) <= 0) {
          showMessage({ type: "error", title: "Validation Error", message: "Parent quantity and units inside parent must be greater than zero." });
          return false;
        }
      }
    }

    if (normalizeProductType(formData.stock_usage_type) === "non_turnover" && formData.default_issue_destination === "front") {
      showMessage({ type: "error", title: "Validation Error", message: "Non-turnover stock cannot default to Front / Selling Stock. Select Expense, Department, Production or Wastage." });
      return false;
    }

    if (formData.expiry_tracking_enabled && Number(formData.default_shelf_life_days || 0) < 1) {
      showMessage({ type: "error", title: "Validation Error", message: "Enter the default shelf life in days for an expiry-tracked item." });
      return false;
    }

    if (!formData.is_global && !formData.branch_id) {
      showMessage({ type: "error", title: "Validation Error", message: "Select the branch where this stock item will be available." });
      return false;
    }

    return true;
  };

  const buildProductPayload = () => {
    const primaryBarcode = formData.barcode ? [{ barcode: formData.barcode, type: "primary" }] : [];
    const cleanBarcodes = formData.barcodes.filter((item) => String(item.barcode || "").trim());
    const mergedBarcodes = [...primaryBarcode, ...cleanBarcodes].filter((item, index, self) => self.findIndex((barcodeItem) => barcodeItem.barcode === item.barcode) === index);


    const payload = {
      is_global: !!formData.is_global,
      branch_id: formData.is_global ? null : formData.branch_id,
      category_id: formData.category_id || null,
      sub_category_id: formData.sub_category_id || null,
      parent_product_id: formData.product_type === "child" ? formData.parent_product_id || null : null,
      base_uom_id: formData.base_uom_id,
      tax_id: formData.tax_id || null,

      name: formData.name.trim(),
      code: formData.code || null,
      sku: formData.sku || null,
      barcode: formData.barcode || null,
      description: formData.description || null,
      product_type: normalizeProductType(formData.product_type) === "semi_finished_good" ? "semi_finished" : formData.product_type,
      stock_usage_type: normalizeProductType(formData.stock_usage_type) === "non_turnover" ? "non_turnover" : "turnover",
      default_issue_destination: formData.default_issue_destination || getDefaultDestinationForUsage(formData.stock_usage_type, formData.product_type),
      expense_account_code: formData.expense_account_code || null,

      stock_tracking_enabled: !!formData.stock_tracking_enabled,
      batch_tracking_enabled: !!formData.batch_tracking_enabled,
      expiry_tracking_enabled: !!formData.expiry_tracking_enabled,
      default_shelf_life_days: formData.expiry_tracking_enabled ? Number(formData.default_shelf_life_days || 0) : null,
      allow_negative_stock: !!formData.allow_negative_stock,
      allow_discount: !!formData.allow_discount,
      taxable: !!formData.taxable,
      is_active: !!formData.is_active,

      reorder_level: Number(formData.reorder_level || 0),
      minimum_stock: Number(formData.minimum_stock || 0),
      maximum_stock: Number(formData.maximum_stock || 0),
    };

    if (!isEditing) {
      payload.barcodes = mergedBarcodes;

      if (formData.product_type === "child" && formData.break_rule_enabled) {
        payload.break_rule = {
          parent_product_id: formData.parent_product_id,
          parent_quantity: Number(formData.break_parent_quantity || 1),
          units_per_parent: Number(formData.break_units_per_parent || 1),
          is_auto_break: !!formData.break_is_auto_break,
        };
      }
    }

    return payload;
  };

  const handleSubmitProduct = async (e) => {
    if (e?.preventDefault) e.preventDefault();
    if (!validateProductForm()) return;

    try {
      setSavingProduct(true);
      const payload = buildProductPayload();
      const response = isEditing ? await updateProduct(formData.id, payload) : await createProduct(payload);

      showMessage({
        type: "success",
        title: isEditing ? "Stock Item Updated" : "Stock Item Created",
        message: getApiMessage(response, isEditing ? "Stock item updated successfully." : "Stock item created successfully."),
      });

      handleCloseProductModal();
      await loadProducts();
    } catch (error) {
      showMessage({ type: "error", title: isEditing ? "Update Failed" : "Create Failed", message: getApiMessage(error) });
    } finally {
      setSavingProduct(false);
    }
  };

  const handleEdit = (product) => {
    setSelectedProductId(product.id);

    const rule = product.childBreakRule || product.child_break_rule;

    setFormData({
      ...emptyFormData,
      id: product.id,
      is_global: product.is_global ?? !product.branch_id,
      branch_id: product.branch_id || getDefaultBranchId(),

      category_id: product.category_id || product.category?.id || "",
      sub_category_id:
        product.sub_category_id ||
        product.subCategory?.id ||
        product.sub_category?.id ||
        "",
      parent_product_id: product.parent_product_id || product.parent?.id || "",
      base_uom_id: product.base_uom_id || product.uom?.id || "",
      tax_id: product.tax_id || product.tax?.id || "",

      name: product.name || "",
      code: product.code || "",
      sku: product.sku || "",
      barcode: product.barcode || "",
      description: product.description || "",
      product_type: normalizeProductType(product.product_type) === "semi_finished_good" ? "semi_finished" : product.product_type || "raw_material",
      stock_usage_type: getStockUsageType(product),
      default_issue_destination: product.default_issue_destination || getDefaultDestinationForUsage(getStockUsageType(product), product.product_type),
      expense_account_code: product.expense_account_code || "",

      stock_tracking_enabled: product.stock_tracking_enabled ?? false,
      batch_tracking_enabled: product.batch_tracking_enabled ?? false,
      expiry_tracking_enabled: product.expiry_tracking_enabled ?? false,
      default_shelf_life_days: product.default_shelf_life_days ?? "",
      allow_negative_stock: product.allow_negative_stock ?? false,
      allow_discount: product.allow_discount ?? true,
      taxable: product.taxable ?? false,
      is_active: product.is_active ?? true,

      reorder_level: product.reorder_level ?? "0",
      minimum_stock: product.minimum_stock ?? "0",
      maximum_stock: product.maximum_stock ?? "0",

      barcodes: product.barcodes || [],

      break_rule_enabled: !!rule,
      break_parent_quantity: rule?.parent_quantity || "1",
      break_units_per_parent: rule?.units_per_parent || "1",
      break_is_auto_break: rule?.is_auto_break ?? true,
    });

    setProductModalOpen(true);
  };

  const handleDelete = async (productId) => {
    const ok = await requestEnterpriseConfirmation({
      title: "Delete Stock Item",
      message: "Delete this stock item?",
      confirmLabel: "Delete item",
    });
    if (!ok) return;

    try {
      setDeletingProductId(productId);
      const response = await deleteProduct(productId);
      showMessage({ type: "success", title: "Stock Item Deleted", message: getApiMessage(response, "Stock item deleted successfully.") });
      await loadProducts();
    } catch (error) {
      showMessage({ type: "error", title: "Delete Failed", message: getApiMessage(error) });
    } finally {
      setDeletingProductId(null);
    }
  };

  const handleOpenBreakModal = (childProduct) => {
    if (childProduct.product_type !== "child") {
      showMessage({ type: "error", title: "Invalid Product", message: "Only child products can be broken from parent stock." });
      return;
    }

    setBreakForm({ child_product_id: childProduct.id, branch_id: getDefaultBranchId(), break_times: "1" });
    setBreakModalOpen(true);
  };

  const handleCloseBreakModal = () => {
    setBreakModalOpen(false);
    setBreakForm(emptyBreakForm);
  };

  const validateBreakForm = () => {
    if (!breakForm.child_product_id) {
      showMessage({ type: "error", title: "Validation Error", message: "Child product is required." });
      return false;
    }

    if (!breakForm.branch_id) {
      showMessage({ type: "error", title: "Validation Error", message: "Branch is required." });
      return false;
    }

    if (!breakForm.break_times || Number(breakForm.break_times) < 1) {
      showMessage({ type: "error", title: "Validation Error", message: "Break times must be at least 1." });
      return false;
    }

    return true;
  };

  const handleSubmitBreak = async (e) => {
    if (e?.preventDefault) e.preventDefault();
    if (!validateBreakForm()) return;

    try {
      setSavingBreak(true);
      const response = await breakProduct(breakForm.child_product_id, { branch_id: breakForm.branch_id, break_times: Number(breakForm.break_times || 1) });
      showMessage({ type: "success", title: "Stock Item Broken", message: getApiMessage(response, "Product broken successfully.") });
      handleCloseBreakModal();
      await loadProducts();
    } catch (error) {
      showMessage({ type: "error", title: "Break Failed", message: getApiMessage(error) });
    } finally {
      setSavingBreak(false);
    }
  };

  const selectedBreakProduct = useMemo(() => products.find((item) => item.id === breakForm.child_product_id), [products, breakForm.child_product_id]);

  const inventoryView = {
    overview: { title: "Inventory Overview", count: enterpriseDashboard?.branches?.length || 0, unit: "branches" },
    warehouses: { title: "Warehouses & Locations", count: warehouses.length, unit: "warehouses" },
    batches: { title: "Batches & Expiry", count: batches.length, unit: "batches" },
    movements: { title: "Stock Movements", count: movements.length, unit: "movements" },
    recalls: { title: "Alerts & Recalls", count: recalls.length, unit: "recalls" },
    register: { title: "Inventory Products", count: filteredProducts.length, unit: "products" },
    break: { title: "Pack Break", count: filteredProducts.length, unit: "products" },
  }[activeTab] || { title: "Inventory", count: 0, unit: "records" };

  return (
    <>
      <FullScreenModal open={open} onClose={onClose} title={inventoryView.title} subtitle="Inventory management">
        <div className="h-[calc(100vh-86px)] min-h-0 overflow-hidden bg-white text-[#263636]">
          <div className="flex h-full min-h-0 flex-col">
            <div className="flex min-h-[68px] flex-wrap items-center justify-between gap-3 border-b border-[#C3D0D0] bg-[#F7FAFC] px-4 py-2">
              <div>
                <h2 className="text-[15px] font-bold text-[#003636]">{inventoryView.title}</h2>
                <p className="mt-1 text-[10px] font-medium text-[#627474]">{inventoryView.count} {inventoryView.unit}</p>
              </div>
              <div className="flex flex-wrap items-center gap-2">
                {(activeTab === "register" || activeTab === "break") && <>
                  {canAny("product_management", "inventory_management") && <ProductDeskCompactTool onClick={handleOpenCreate} green>Add Stock Item</ProductDeskCompactTool>}
                  <ProductDeskCompactTool onClick={downloadProductsExcel} disabled={!!exportingFormat}>{exportingFormat === "xlsx" ? "Preparing…" : "Excel"}</ProductDeskCompactTool>
                  <ProductDeskCompactTool onClick={downloadProductsPdf} disabled={!!exportingFormat}>{exportingFormat === "pdf" ? "Preparing…" : "PDF"}</ProductDeskCompactTool>
                  <ProductDeskCompactTool onClick={() => selectedProduct && handleEdit(selectedProduct)} disabled={!selectedProduct}>Edit</ProductDeskCompactTool>
                  <ProductDeskCompactTool onClick={() => selectedProduct && handleOpenBreakModal(selectedProduct)} disabled={!selectedProduct || selectedProduct.product_type !== "child"}>Break</ProductDeskCompactTool>
                </>}
                <ProductDeskCompactTool onClick={loadData} disabled={loading}>{loading ? "Loading..." : "Refresh"}</ProductDeskCompactTool>
                {(activeTab === "register" || activeTab === "break") && <>
                  <ProductDeskCompactTool onClick={() => { setSearch(""); setTypeFilter(""); setCategoryFilter(""); setSubCategoryFilter(""); setStatusFilter(""); }}>Clear</ProductDeskCompactTool>
                  <ProductDeskCompactTool onClick={() => selectedProduct && handleDelete(selectedProduct.id)} disabled={!selectedProduct} danger>Delete</ProductDeskCompactTool>
                </>}
              </div>
            </div>

            {(activeTab === "register" || activeTab === "break") && <div className="grid shrink-0 gap-2 border-b border-[#DAE2E2] bg-white px-4 py-2 md:grid-cols-[minmax(260px,1fr)_180px_180px_150px]">
              <input type="text" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search product, code, SKU or barcode" className="h-8 border border-[#B9C3C3] bg-white px-2 text-[11px] outline-none focus:border-[#069494]" />
              <select value={categoryFilter} onChange={(e) => { setCategoryFilter(e.target.value); setSubCategoryFilter(""); }} className="h-8 border border-[#B9C3C3] bg-white px-2 text-[11px]"><option value="">All categories</option>{categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}</select>
              <select value={typeFilter} onChange={(e) => setTypeFilter(e.target.value)} className="h-8 border border-[#B9C3C3] bg-white px-2 text-[11px]"><option value="">All types</option>{PRODUCT_TYPE_OPTIONS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select>
              <select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)} className="h-8 border border-[#B9C3C3] bg-white px-2 text-[11px]"><option value="">All statuses</option><option value="true">Active</option><option value="false">Inactive</option></select>
            </div>}

            <div className="min-h-0 flex-1 bg-white">
              <div className="flex h-full min-h-0 flex-col">
                {activeTab === "overview" && (
                  <div className="h-full overflow-auto bg-white">
                    {canViewVarianceAgent && (
                      <section className="border-b border-[#C3D0D0] bg-[#F4F8F8] px-4 py-4" aria-label="Inventory variance agent">
                        {varianceOverviewLoading && !varianceOverview ? (
                          <div className="flex items-center gap-2 text-[11px] text-[#465757]"><RefreshCw className="h-4 w-4 animate-spin" />Loading variance monitor...</div>
                        ) : varianceOverviewError && !varianceOverview ? (
                          <div className="flex flex-wrap items-center justify-between gap-2 border border-[#FEC84B] bg-[#FFFAEB] p-3 text-[11px] text-[#B54708]"><span>{varianceOverviewError}</span><button type="button" onClick={refreshVarianceOverview} className="border border-[#B54708] px-3 py-1 font-bold">Retry</button></div>
                        ) : varianceOverview?.enabled ? (
                          <div className="border border-[#83C6C6] bg-white shadow-sm">
                            <div className="flex flex-wrap items-start justify-between gap-3 border-b border-[#DBE8E7] bg-[#004142] px-4 py-3 text-white">
                              <div className="flex items-start gap-3"><span className="relative flex h-10 w-10 shrink-0 items-center justify-center border border-[#6DDBDB]/50 bg-[#005454]"><BrainCircuit className="h-5 w-5 text-[#6DDBDB]" /><span className="absolute -right-1 -top-1 h-3 w-3 rounded-full border-2 border-[#004141] bg-[#12B76A]" /></span><div><h3 className="text-[13px] font-bold">Variance Intelligence Agent</h3><p className="mt-0.5 text-[10px] text-white/70">Monitoring {currentUser?.company?.company_name || currentUser?.company?.name || "this company"} across authorised branches</p></div></div>
                              <button type="button" onClick={() => setVarianceAgentOpen(true)} className="inline-flex h-9 items-center gap-2 border border-[#6DDBDB] bg-[#008A8A] px-3 text-[11px] font-bold text-white hover:bg-[#057B7B]">Open agent <ArrowRight className="h-4 w-4" /></button>
                            </div>
                            <div className="grid gap-2 p-3 sm:grid-cols-2 xl:grid-cols-4">
                              <VarianceOverviewMetric label="Open cases" value={varianceOverview.summary?.open || 0} tone="red" />
                              <VarianceOverviewMetric label="Shortages" value={varianceOverview.summary?.shortages || 0} tone="amber" />
                              <VarianceOverviewMetric label="Surpluses" value={varianceOverview.summary?.surpluses || 0} tone="blue" />
                              <VarianceOverviewMetric label="High priority" value={varianceOverview.summary?.high || 0} tone="red" />
                            </div>
                            <div className="flex flex-wrap items-center justify-between gap-2 border-t border-[#E9EDED] px-3 py-2 text-[10px] text-[#627474]"><span>{varianceOverview.summary?.open ? `First open case: ${varianceOverview.summary.oldest_started_at ? new Date(varianceOverview.summary.oldest_started_at).toLocaleString() : "Time unavailable"}` : "No open product variances in this company scope."}</span><span>Last scan: {varianceOverview.last_scan?.completed_at ? new Date(varianceOverview.last_scan.completed_at).toLocaleString() : "Awaiting first scan"}</span></div>
                            {(varianceOverview.summary?.exposure_by_currency || []).length > 0 && <div className="border-t border-[#E9EDED] px-3 py-2 text-[10px] text-[#465757]"><strong className="mr-2 uppercase text-[#324443]">Cost exposure</strong>{varianceOverview.summary.exposure_by_currency.map((group) => `${group.currency_code === "UNSPECIFIED" ? "Unspecified" : group.currency_code} ${Number(group.exposure_value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`).join(" · ")}</div>}
                          </div>
                        ) : (
                          <div className="flex flex-wrap items-center justify-between gap-3 border border-[#CFD6D6] bg-white px-4 py-3"><div className="flex items-center gap-3"><BrainCircuit className="h-5 w-5 text-[#627474]" /><div><div className="text-[11px] font-bold text-[#324443]">Variance Intelligence Agent is off</div><div className="text-[10px] text-[#627474]">Enable product and branch variance monitoring when you are ready.</div></div></div><button type="button" onClick={() => setVarianceAgentOpen(true)} className="border border-[#96A5A4] bg-white px-3 py-2 text-[10px] font-bold text-[#324443]">Configure agent</button></div>
                        )}
                      </section>
                    )}
                    <InventoryOverviewVisuals dashboard={enterpriseDashboard} currency={getDefaultCurrencyCode()} />
                    {isHospitality && <HospitalityStockAreaOverview dashboard={enterpriseDashboard} currency={getDefaultCurrencyCode()} fallbackNonTurnoverCount={registerCounts.nonTurnover} />}
                    <div className="flex items-center justify-between border-b border-[#C3D0D0] px-4 py-2"><strong className="text-[11px] text-[#003636]">Inventory by Branch</strong>{canAny("manage_inventory_alerts", "inventory_management") && <button type="button" onClick={refreshEnterpriseAlerts} disabled={refreshingAlerts} className="border border-[#069494] bg-white px-3 py-1.5 text-[10px] font-bold text-[#008585] disabled:opacity-50">{refreshingAlerts ? "Refreshing..." : "Refresh"}</button>}</div>
                    <table className="sx-enterprise-table"><thead><tr><th>Branch</th><th className="text-right">Products</th><th className="text-right">On Hand</th><th className="text-right">Reserved</th><th className="text-right">Available</th><th className="text-right">Stock Value</th></tr></thead><tbody>
                      {(enterpriseDashboard?.branches || []).map((row) => <tr key={row.branch_id}><td><strong>{row.branch_name || "Unassigned"}</strong></td><td className="text-right">{row.product_count}</td><td className="text-right">{quantity(row.quantity_on_hand)}</td><td className="text-right">{quantity(row.reserved_quantity)}</td><td className="text-right">{quantity(row.available_quantity)}</td><td className="text-right">{getDefaultCurrencyCode()} {Number(row.stock_value || 0).toFixed(2)}</td></tr>)}
                      {!(enterpriseDashboard?.branches || []).length && <tr><td colSpan="6" className="py-8 text-center text-[#627474]">No stock balances have been recorded.</td></tr>}
                    </tbody></table>
                    <div className="border-b border-t border-[#C3D0D0] px-4 py-2 text-[11px] font-bold text-[#003636]">Inventory Intelligence</div>
                    <table className="sx-enterprise-table"><thead><tr><th>Product</th><th>Branch</th><th className="text-right">Available</th><th className="text-right">Daily Use</th><th className="text-right">Days Cover</th><th className="text-right">Risk</th></tr></thead><tbody>
                      {(enterpriseDashboard?.intelligence || []).map((row) => <tr key={`${row.branch_id}-${row.product_id}`}><td><strong>{row.product_name}</strong></td><td>{row.branch_name || "-"}</td><td className="text-right">{quantity(row.available_quantity)}</td><td className="text-right">{quantity(row.average_daily_usage_30d)}</td><td className="text-right">{row.estimated_days_cover == null ? "No history" : row.estimated_days_cover}</td><td className="text-right uppercase">{row.risk}</td></tr>)}
                      {!(enterpriseDashboard?.intelligence || []).length && <tr><td colSpan="6" className="py-8 text-center text-[#627474]">No movement history available.</td></tr>}
                    </tbody></table>
                  </div>
                )}
                {activeTab === "warehouses" && (
                  <div className="h-full overflow-auto bg-[#F4F7F7] p-3">
                    <div className="mb-3 flex items-center justify-between border border-[#CCD7D6] bg-white p-2">
                      <div><strong className="text-[11px] text-[#003636]">Warehouse Register</strong><small className="ml-2 text-[#627474]">Create and edit records in modal forms.</small></div>
                      <div className="flex gap-2">
                        <ProductDeskCompactTool onClick={openCreateWarehouse} green>New Warehouse</ProductDeskCompactTool>
                        <ProductDeskCompactTool onClick={() => { setLocationForm({ warehouse_id: "", code: "", name: "", location_type: "storage", pick_sequence: 0 }); setLocationFormOpen(true); }}>New Location</ProductDeskCompactTool>
                      </div>
                    </div>
                    <div className="overflow-auto bg-white">
                      <table className="sx-enterprise-table">
                        <thead><tr><th>Code</th><th>Warehouse</th><th>Address</th><th className="text-right">Locations</th><th>Branch</th><th>Type</th><th className="text-right">Products</th><th className="text-right">On Hand</th><th className="text-right">Available</th><th className="text-right">Value</th><th className="w-12 text-center">Actions</th></tr></thead>
                        <tbody>
                          {warehouses.map((warehouse) => (
                            <tr key={warehouse.id} className={selectedWarehouseId === warehouse.id ? "sx-row-selected" : ""}>
                              <td><strong>{warehouse.code}</strong></td>
                              <td>{warehouse.name}</td>
                              <td>{warehouse.address || "-"}</td>
                              <td className="text-right">{warehouse.location_count ?? warehouse.locations?.length ?? 0}</td>
                              <td>{warehouse.branch_name || "Company-wide"}</td>
                              <td>{String(warehouse.warehouse_type || "general").replaceAll("_", " ")}</td>
                              <td className="text-right">{warehouse.product_count || 0}</td>
                              <td className="text-right">{quantity(warehouse.quantity_on_hand)}</td>
                              <td className="text-right">{quantity(warehouse.available_quantity)}</td>
                              <td className="text-right">{money(warehouse.stock_value)}</td>
                              <td className="text-center"><InventoryRowActionMenu label={`Actions for ${warehouse.name}`} actions={[{ label: "Manage", onClick: () => openWarehouse(warehouse) }, { label: "Edit", onClick: () => editWarehouse(warehouse) }, { label: "Delete", danger: true, onClick: () => removeWarehouse(warehouse) }]} /></td>
                            </tr>
                          ))}
                          {!warehouses.length && <tr><td colSpan="11" className="py-8 text-center text-[#627474]">No warehouses found.</td></tr>}
                        </tbody>
                      </table>
                    </div>
                  </div>
                )}
                {activeTab === "batches" && (
                  <div className="h-full overflow-auto bg-white">
                    <div className="flex items-center justify-between border-b border-[#C3D0D0] px-4 py-2"><div><strong className="text-[11px] text-[#003636]">Batches & Expiry</strong><small className="ml-2 text-[#627474]">Lot and expiry details are created when stock is received. Legacy stock can be reconciled once.</small></div>{canAny("manage_inventory_batches", "inventory_management") && <button type="button" onClick={reconcileBatches} disabled={reconcilingBatches} className="border border-[#069494] bg-white px-3 py-1.5 text-[10px] font-bold text-[#008585] disabled:opacity-50">{reconcilingBatches ? "Reconciling..." : "Reconcile Existing Stock"}</button>}</div>
                    {selectedBatchDetail && <div className="border-b-2 border-[#069494] bg-[#F7FAFC] p-3">
                      <div className="mb-3 flex items-center justify-between"><div><strong className="text-[13px] text-[#003636]">Lot {selectedBatchDetail.batch?.lot_number}</strong><small className="ml-2 text-[#627474]">Complete batch trace</small></div><button type="button" onClick={() => setSelectedBatchDetail(null)} className="border border-[#ACB8B8] bg-white px-3 py-1 text-[10px] font-bold">Close details</button></div>
                      <div className="grid gap-2 md:grid-cols-4 xl:grid-cols-6">
                        {[['Product', selectedBatchDetail.batch?.product_name], ['Branch', selectedBatchDetail.batch?.branch_name], ['Warehouse', selectedBatchDetail.batch?.warehouse_name], ['Location', selectedBatchDetail.batch?.location_name], ['Received', quantity(selectedBatchDetail.batch?.quantity_received)], ['On hand', quantity(selectedBatchDetail.batch?.quantity_on_hand)], ['Reserved', quantity(selectedBatchDetail.batch?.reserved_quantity)], ['Quarantined', quantity(selectedBatchDetail.batch?.quarantined_quantity)], ['Manufactured', selectedBatchDetail.batch?.manufactured_at || 'Unknown'], ['Expires', selectedBatchDetail.batch?.expires_at || 'No expiry recorded'], ['Unit cost', money(selectedBatchDetail.batch?.unit_cost, selectedBatchDetail.batch?.currency_code)], ['Quality', selectedBatchDetail.batch?.quality_status || '-'], ['Status', selectedBatchDetail.batch?.status || '-']].map(([label, value]) => <div key={label} className="border border-[#DAE2E2] bg-white p-2"><small className="block text-[9px] font-bold uppercase text-[#627474]">{label}</small><strong className="text-[11px] text-[#0C1B1B]">{value || '-'}</strong></div>)}
                      </div>
                      <div className="mt-3 grid gap-3 xl:grid-cols-2">
                        <ProductDeskPanel title="Receiving Source"><div className="grid grid-cols-2 gap-2 text-[10px]"><div><small className="block text-[#627474]">GRN</small><b>{selectedBatchDetail.batch?.grn_number || 'Opening balance / no GRN'}</b></div><div><small className="block text-[#627474]">Received date</small><b>{selectedBatchDetail.batch?.received_date || '-'}</b></div><div><small className="block text-[#627474]">Supplier</small><b>{selectedBatchDetail.batch?.supplier_name || '-'}</b></div><div><small className="block text-[#627474]">Purchase order</small><b>{selectedBatchDetail.batch?.po_number || '-'}</b></div><div><small className="block text-[#627474]">Receipt quantity</small><b>{selectedBatchDetail.batch?.receipt_line_quantity == null ? '-' : quantity(selectedBatchDetail.batch?.receipt_line_quantity)}</b></div><div><small className="block text-[#627474]">Receipt line total</small><b>{selectedBatchDetail.batch?.receipt_line_total == null ? '-' : money(selectedBatchDetail.batch?.receipt_line_total, selectedBatchDetail.batch?.receipt_currency)}</b></div></div>{selectedBatchDetail.batch?.notes && <div className="mt-2 border-t pt-2 text-[10px] text-[#627474]">{selectedBatchDetail.batch.notes}</div>}</ProductDeskPanel>
                        <ProductDeskPanel title="Transfer & Recall Trace"><div className="max-h-36 overflow-auto text-[10px]">{(selectedBatchDetail.transfers || []).map((row) => <div key={row.id} className="border-b py-1"><b>{row.transfer_no}</b> · {row.source_warehouse_name || '-'} → {row.destination_warehouse_name || '-'} · dispatched {quantity(row.quantity_dispatched)} · received {quantity(row.quantity_received)}</div>)}{(selectedBatchDetail.recalls || []).map((row) => <div key={row.id} className="border-b py-1 text-[#B42318]"><b>{row.recall_number}</b> · {row.title} · {row.recall_status}</div>)}{!(selectedBatchDetail.transfers || []).length && !(selectedBatchDetail.recalls || []).length && <div className="text-[#627474]">No transfers or recalls for this lot.</div>}</div></ProductDeskPanel>
                      </div>
                      <div className="mt-3 overflow-auto bg-white"><table className="sx-enterprise-table"><thead><tr><th>Date</th><th>Movement</th><th>Warehouse</th><th>Location</th><th className="text-right">In</th><th className="text-right">Out</th><th className="text-right">Balance</th><th>Reference</th><th>Notes</th></tr></thead><tbody>{(selectedBatchDetail.movements || []).map((movement) => <tr key={movement.id}><td>{movement.created_at ? new Date(movement.created_at).toLocaleString() : '-'}</td><td>{String(movement.movement_type || '-').replaceAll('_', ' ')}</td><td>{movement.warehouse_name || '-'}</td><td>{movement.location_name || '-'}</td><td className="text-right text-[#067647]">{quantity(movement.quantity_in)}</td><td className="text-right text-[#B42318]">{quantity(movement.quantity_out)}</td><td className="text-right">{movement.balance_after == null ? '-' : quantity(movement.balance_after)}</td><td>{movement.reference_type || '-'}</td><td>{movement.notes || '-'}</td></tr>)}{!(selectedBatchDetail.movements || []).length && <tr><td colSpan="9" className="py-4 text-center text-[#627474]">No movements recorded for this lot yet.</td></tr>}</tbody></table></div>
                    </div>}
                    {loadingBatchDetail && <div className="border-b bg-[#F7FAFC] p-3 text-center text-[11px] font-bold text-[#627474]">Loading complete batch trace...</div>}
                    <table className="sx-enterprise-table">
                      <thead><tr><th>Lot</th><th>Product</th><th>Branch</th><th>Warehouse</th><th>Location</th><th>Manufactured</th><th>Expiry</th><th className="text-right">On Hand</th><th>Quality</th><th>Status</th><th className="w-12 text-center">Actions</th></tr></thead>
                      <tbody>
                        {batches.map((batch) => (
                          <tr key={batch.id}>
                            <td><strong>{batch.lot_number}</strong></td>
                            <td>{batch.product_name}</td>
                            <td>{batch.branch_name || "-"}</td>
                            <td>{batch.warehouse_name || "-"}</td>
                            <td>{batch.location_name || "-"}</td>
                            <td>{batch.manufactured_at || "-"}</td>
                            <td className={batch.expires_at && new Date(batch.expires_at) < new Date() ? "font-bold text-[#B42318]" : ""}>{batch.expires_at || "No expiry"}</td>
                            <td className="text-right">{quantity(batch.quantity_on_hand)}</td>
                            <td className="uppercase">{batch.quality_status || "-"}</td>
                            <td className="uppercase">{batch.status || "-"}</td>
                            <td className="text-center"><InventoryRowActionMenu label={`Actions for lot ${batch.lot_number}`} actions={[{ label: "View Trace", onClick: () => openBatchDetail(batch) }, canAny("manage_inventory_batches", "inventory_management") && { label: "Edit Lot / Expiry", onClick: () => editBatch(batch) }]} /></td>
                          </tr>
                        ))}
                        {!batches.length && <tr><td colSpan="11" className="py-8 text-center text-[#627474]">No tracked batches have been received yet.</td></tr>}
                      </tbody>
                    </table>
                  </div>
                )}
                {activeTab === "movements" && (
                  <div className="h-full overflow-auto bg-white">
                    <table className="sx-enterprise-table">
                      <thead><tr><th>Date</th><th>Product</th><th>Product Code</th><th>Branch</th><th>Warehouse</th><th>Location</th><th>Lot</th><th>Movement</th><th className="text-right">In</th><th className="text-right">Out</th><th className="text-right">Unit Cost</th><th>Reference</th></tr></thead>
                      <tbody>
                        {movements.map((movement) => <tr key={movement.id}><td>{movement.created_at ? new Date(movement.created_at).toLocaleString() : "-"}</td><td><strong>{movement.product_name}</strong></td><td>{movement.product_code || "-"}</td><td>{movement.branch_name || "-"}</td><td>{movement.warehouse_name || "-"}</td><td>{movement.location_name || "-"}</td><td>{movement.lot_number || "-"}</td><td>{String(movement.movement_type || "-").replaceAll("_", " ")}</td><td className="text-right text-[#067647]">{quantity(movement.quantity_in)}</td><td className="text-right text-[#B42318]">{quantity(movement.quantity_out)}</td><td className="text-right">{Number(movement.unit_cost || 0).toFixed(4)}</td><td>{movement.reference_type || "-"}</td></tr>)}
                        {!movements.length && <tr><td colSpan="12" className="py-8 text-center text-[#627474]">No stock movements recorded.</td></tr>}
                      </tbody>
                    </table>
                  </div>
                )}
                {activeTab === "recalls" && (
                  <div className="h-full overflow-auto bg-[#F4F7F7] p-3">
                    {canAny("manage_inventory_recalls", "inventory_management") && <div className="mb-3 flex justify-end"><ProductDeskCompactTool danger onClick={() => { setRecallForm({ title: "", reason: "", severity: "high", batch_ids: [] }); setRecallFormOpen(true); }}>Start Product Recall</ProductDeskCompactTool></div>}
                    <div className="space-y-3">
                      <div className="space-y-3">
                        <ProductDeskPanel title="Recall Register">
                          <div className="overflow-auto">
                            <table className="sx-enterprise-table min-w-[900px]">
                              <thead><tr><th>Recall No.</th><th>Title</th><th>Reason</th><th className="text-right">Lots</th><th className="text-right">Quarantined</th><th>Status</th><th className="w-12 text-center">Actions</th></tr></thead>
                              <tbody>
                                {recalls.map((recall) => (
                                  <tr key={recall.id}>
                                    <td><strong>{recall.recall_number || "-"}</strong></td>
                                    <td>{recall.title || "-"}</td>
                                    <td>{recall.reason || "-"}</td>
                                    <td className="text-right">{recall.batch_count || 0}</td>
                                    <td className="text-right">{quantity(recall.quantity_quarantined)}</td>
                                    <td className={recall.status === "active" ? "font-semibold uppercase text-[#B42318]" : "font-semibold uppercase text-[#067647]"}>{recall.status || "-"}</td>
                                    <td className="text-center"><InventoryRowActionMenu label={`Actions for recall ${recall.recall_number || recall.title}`} actions={[recall.status === "active" && canAny("manage_inventory_recalls", "inventory_management") && { label: "Release after clearance", onClick: () => openRecallResolution(recall, "release") }, recall.status === "active" && canAny("manage_inventory_recalls", "inventory_management") && { label: "Destroy recalled stock", danger: true, onClick: () => openRecallResolution(recall, "destroy") }]} /></td>
                                  </tr>
                                ))}
                                {!recalls.length && <tr><td colSpan="7" className="py-8 text-center text-[#627474]">No recalls recorded.</td></tr>}
                              </tbody>
                            </table>
                          </div>
                        </ProductDeskPanel>
                        <ProductDeskPanel title="Open Inventory Alerts">
                          <div className="overflow-auto">
                            <table className="sx-enterprise-table min-w-[760px]">
                              <thead><tr><th>Title</th><th>Message</th><th>Severity</th><th>Status</th></tr></thead>
                              <tbody>
                                {inventoryAlerts.filter((alert) => alert.status === "open").slice(0, 20).map((alert) => <tr key={alert.id}><td><strong>{alert.title || "-"}</strong></td><td>{alert.message || "-"}</td><td className="uppercase">{alert.severity || "-"}</td><td className="uppercase">{alert.status || "-"}</td></tr>)}
                                {!inventoryAlerts.filter((alert) => alert.status === "open").length && <tr><td colSpan="4" className="py-8 text-center text-[#627474]">No open inventory alerts.</td></tr>}
                              </tbody>
                            </table>
                          </div>
                        </ProductDeskPanel>
                      </div>
                    </div>
                  </div>
                )}
                {activeTab === "register" && (
                  <div className="h-full overflow-auto bg-white">
                    <table className="sx-enterprise-table"><thead><tr><th>Product</th><th>Description</th><th>Code</th><th>SKU</th><th>Barcode</th><th>Category</th><th>Sub Category</th><th>UOM</th><th>Type</th><th>Status</th><th className="w-12 text-center">Actions</th></tr></thead><tbody>
                      {loading ? <tr><td colSpan="11" className="py-8 text-center text-[#627474]">Loading inventory products...</td></tr> : filteredProducts.length === 0 ? <tr><td colSpan="11" className="py-8 text-center text-[#627474]">No inventory products found.</td></tr> : (
                        filteredProducts.map((product) => {
                          const barcode = getBarcodeSummary(product);
                          const selected = selectedProductId === product.id;

                          return (
                            <tr key={product.id} onClick={() => setSelectedProductId(product.id)} className={selected ? "sx-row-selected" : ""}>
                              <td><strong>{product.name}</strong></td><td>{product.description || "-"}</td><td>{product.code || "-"}</td><td>{product.sku || "-"}</td><td>{barcode.primary}</td><td>{getCategoryDisplay(product)}</td><td>{getSubCategoryDisplay(product)}</td><td>{getUomDisplay(product)}</td><td>{getProductTypeLabel(product.product_type)}</td><td>{product.is_active ? "Active" : "Inactive"}</td>
                              <td className="text-center"><InventoryRowActionMenu label={`Actions for ${product.name}`} actions={[{ label: "Edit", onClick: () => handleEdit(product) }, product.product_type === "child" && { label: "Break Pack", onClick: () => handleOpenBreakModal(product) }, { label: deletingProductId === product.id ? "Deleting..." : "Delete", danger: true, disabled: deletingProductId === product.id, onClick: () => handleDelete(product.id) }]} /></td>
                            </tr>
                          );
                        })
                      )}
                    </tbody></table>
                  </div>
                )}
                {activeTab === "break" && (
                  <div className="h-full overflow-auto bg-white">
                    <table className="sx-enterprise-table min-w-[1040px]">
                      <thead><tr><th className="w-12 text-center">Line</th><th>Product</th><th>Type</th><th>Parent</th><th className="text-right">Child Count</th><th className="text-right">Parent Qty</th><th className="text-right">Units Inside</th><th className="w-12 text-center">Actions</th></tr></thead>
                      <tbody>
                        {filteredProducts.map((product, index) => {
                          const breakRule = getBreakSummary(product);
                          const children = getChildrenSummary(product);
                          const selected = selectedProductId === product.id;

                          return (
                            <tr key={product.id} onClick={() => setSelectedProductId(product.id)} className={`cursor-pointer ${selected ? "sx-row-selected" : ""}`}>
                              <td className="text-center text-[#627474]">{index + 1}</td>
                              <td><strong>{product.name}</strong></td>
                              <td>{getProductTypeLabel(product.product_type)}</td>
                              <td>{product.parent?.name || "-"}</td>
                              <td className="text-right">{children.count}</td>
                              <td className="text-right">{breakRule ? quantity(breakRule.parentQuantity) : "-"}</td>
                              <td className="text-right">{breakRule ? quantity(breakRule.unitsPerParent) : "-"}</td>
                              <td className="text-center"><InventoryRowActionMenu label={`Actions for ${product.name}`} actions={[product.product_type === "child" && { label: "Break Pack", onClick: () => handleOpenBreakModal(product) }]} /></td>
                            </tr>
                          );
                        })}
                        {!loading && filteredProducts.length === 0 && <tr><td colSpan="8" className="py-8 text-center text-[#627474]">No inventory items found.</td></tr>}
                      </tbody>
                    </table>
                  </div>
                )}
              </div>
            </div>
          </div>
        </div>
      </FullScreenModal>

      <EnterpriseFormModal
        open={warehouseFormOpen}
        title={editingWarehouseId ? "Edit Warehouse" : "New Warehouse"}
        subtitle="Warehouse identity and operating type"
        onClose={() => !operationalFormBusy && cancelWarehouseEdit()}
        busy={operationalFormBusy}
        width="680px"
      >
        <form onSubmit={(event) => { event.preventDefault(); saveWarehouse(); }} className="space-y-3 p-4">
          <div className="grid grid-cols-2 gap-3">
            <Field label="Branch"><select disabled={!!editingWarehouseId} value={warehouseForm.branch_id} onChange={(e) => setWarehouseForm({ ...warehouseForm, branch_id: e.target.value })} className="input-small disabled:bg-[#F2F4F7]"><option value="">Company-wide / Select Branch</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{getBranchName(branch)}</option>)}</select></Field>
            <Field label="Warehouse Code"><input disabled={!!editingWarehouseId} value={warehouseForm.code} onChange={(e) => setWarehouseForm({ ...warehouseForm, code: e.target.value })} placeholder="Code e.g. WH-HRE" className="input-small disabled:bg-[#F2F4F7]" /></Field>
            <Field label="Warehouse Name"><input value={warehouseForm.name} onChange={(e) => setWarehouseForm({ ...warehouseForm, name: e.target.value })} placeholder="Warehouse name" className="input-small" autoFocus /></Field>
            <Field label="Warehouse Type"><select value={warehouseForm.warehouse_type} onChange={(e) => setWarehouseForm({ ...warehouseForm, warehouse_type: e.target.value })} className="input-small"><option value="general">General</option><option value="receiving">Receiving</option><option value="dispatch">Dispatch</option><option value="returns">Returns</option><option value="production">Production</option><option value="cold_storage">Cold storage</option></select></Field>
          </div>
          <Field label="Address"><input value={warehouseForm.address} onChange={(e) => setWarehouseForm({ ...warehouseForm, address: e.target.value })} placeholder="Address" className="input-small" /></Field>
          <div className="flex justify-end gap-2 border-t border-[#DAE2E2] pt-3">
            <button type="button" onClick={cancelWarehouseEdit} disabled={operationalFormBusy} className="h-8 border border-[#ACB8B8] bg-white px-4 text-[11px] font-bold">Cancel</button>
            <button type="submit" disabled={operationalFormBusy || !warehouseForm.code || !warehouseForm.name} className="h-8 bg-[#007474] px-4 text-[11px] font-bold text-white disabled:opacity-50">{operationalFormBusy ? "Saving..." : editingWarehouseId ? "Save Changes" : "Create Warehouse"}</button>
          </div>
        </form>
      </EnterpriseFormModal>

      <EnterpriseFormModal
        open={locationFormOpen}
        title="New Warehouse Location"
        subtitle="Create a storage, receiving, picking, or control location"
        onClose={() => !operationalFormBusy && setLocationFormOpen(false)}
        busy={operationalFormBusy}
        width="620px"
      >
        <form onSubmit={(event) => { event.preventDefault(); saveLocation(); }} className="space-y-3 p-4">
          <Field label="Warehouse"><select value={locationForm.warehouse_id} onChange={(e) => setLocationForm({ ...locationForm, warehouse_id: e.target.value })} className="input-small"><option value="">Select Warehouse</option>{warehouses.map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.name} · {warehouse.branch_name || "Company"}</option>)}</select></Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Location Code"><input value={locationForm.code} onChange={(e) => setLocationForm({ ...locationForm, code: e.target.value })} placeholder="Location code" className="input-small" autoFocus /></Field>
            <Field label="Location Name"><input value={locationForm.name} onChange={(e) => setLocationForm({ ...locationForm, name: e.target.value })} placeholder="Location name" className="input-small" /></Field>
            <Field label="Location Type"><select value={locationForm.location_type} onChange={(e) => setLocationForm({ ...locationForm, location_type: e.target.value })} className="input-small"><option value="storage">Storage</option><option value="receiving">Receiving</option><option value="picking">Picking</option><option value="dispatch">Dispatch</option><option value="returns">Returns</option><option value="quarantine">Quarantine</option><option value="damaged">Damaged</option></select></Field>
            <Field label="Pick Sequence"><input type="number" min="0" value={locationForm.pick_sequence} onChange={(e) => setLocationForm({ ...locationForm, pick_sequence: Number(e.target.value) })} className="input-small" /></Field>
          </div>
          <div className="flex justify-end gap-2 border-t border-[#DAE2E2] pt-3">
            <button type="button" onClick={() => setLocationFormOpen(false)} disabled={operationalFormBusy} className="h-8 border border-[#ACB8B8] bg-white px-4 text-[11px] font-bold">Cancel</button>
            <button type="submit" disabled={operationalFormBusy || !locationForm.warehouse_id || !locationForm.code || !locationForm.name} className="h-8 bg-[#027A48] px-4 text-[11px] font-bold text-white disabled:opacity-50">{operationalFormBusy ? "Saving..." : "Create Location"}</button>
          </div>
        </form>
      </EnterpriseFormModal>

      <EnterpriseFormModal
        open={batchFormOpen}
        title="Edit Batch Details"
        subtitle="Update lot and expiry details without changing stock quantity"
        onClose={() => { if (!operationalFormBusy) { setBatchFormOpen(false); setEditingBatchId(""); } }}
        busy={operationalFormBusy}
        width="700px"
      >
        <form onSubmit={(event) => { event.preventDefault(); saveBatch(); }} className="space-y-3 p-4">
          <div className="grid grid-cols-3 gap-3">
            <Field label="Lot Number"><input value={batchForm.lot_number} onChange={(e) => setBatchForm({ ...batchForm, lot_number: e.target.value })} placeholder="Lot number" className="input-small" autoFocus /></Field>
            <Field label="Manufactured Date"><input type="date" value={batchForm.manufactured_at} onChange={(e) => setBatchForm({ ...batchForm, manufactured_at: e.target.value })} className="input-small" /></Field>
            <Field label="Expiry Date"><input type="date" value={batchForm.expires_at} onChange={(e) => setBatchForm({ ...batchForm, expires_at: e.target.value })} className="input-small" /></Field>
          </div>
          <Field label="Batch Notes"><textarea value={batchForm.notes} onChange={(e) => setBatchForm({ ...batchForm, notes: e.target.value })} placeholder="Batch notes" className="min-h-24 w-full border border-[#ACB8B8] p-2 text-[11px]" /></Field>
          <div className="flex justify-end gap-2 border-t border-[#DAE2E2] pt-3">
            <button type="button" onClick={() => { setBatchFormOpen(false); setEditingBatchId(""); }} disabled={operationalFormBusy} className="h-8 border border-[#ACB8B8] bg-white px-4 text-[11px] font-bold">Cancel</button>
            <button type="submit" disabled={operationalFormBusy || !batchForm.lot_number} className="h-8 bg-[#007474] px-4 text-[11px] font-bold text-white disabled:opacity-50">{operationalFormBusy ? "Saving..." : "Save Batch"}</button>
          </div>
        </form>
      </EnterpriseFormModal>

      <EnterpriseFormModal
        open={recallFormOpen}
        title="Start Product Recall"
        subtitle="Quarantine selected released lots"
        onClose={() => !operationalFormBusy && setRecallFormOpen(false)}
        busy={operationalFormBusy}
        width="760px"
      >
        <form onSubmit={(event) => { event.preventDefault(); activateRecall(); }} className="space-y-3 p-4">
          <Field label="Recall Title"><input value={recallForm.title} onChange={(e) => setRecallForm({ ...recallForm, title: e.target.value })} placeholder="Recall title" className="input-small" autoFocus /></Field>
          <Field label="Reason and Safety Instructions"><textarea value={recallForm.reason} onChange={(e) => setRecallForm({ ...recallForm, reason: e.target.value })} className="min-h-24 w-full border border-[#ACB8B8] p-2 text-[11px]" /></Field>
          <Field label="Severity"><select value={recallForm.severity} onChange={(e) => setRecallForm({ ...recallForm, severity: e.target.value })} className="input-small"><option value="medium">Medium</option><option value="high">High</option><option value="critical">Critical</option></select></Field>
          <div>
            <div className="mb-1 text-[10px] font-bold uppercase text-[#465757]">Released Lots</div>
            <div className="max-h-64 overflow-auto border border-[#ACB8B8]">{batches.filter((batch) => Number(batch.quantity_on_hand) > 0 && batch.quality_status === "released").map((batch) => <label key={batch.id} className="flex gap-2 border-b p-2 text-[10px]"><input type="checkbox" checked={recallForm.batch_ids.includes(batch.id)} onChange={() => toggleRecallBatch(batch.id)} /><span><b>{batch.lot_number}</b> · {batch.product_name}<small className="block text-[#627474]">{batch.branch_name} · Qty {quantity(batch.quantity_on_hand)} · Exp {batch.expires_at || "none"}</small></span></label>)}</div>
          </div>
          <div className="flex justify-end gap-2 border-t border-[#DAE2E2] pt-3">
            <button type="button" onClick={() => setRecallFormOpen(false)} disabled={operationalFormBusy} className="h-8 border border-[#ACB8B8] bg-white px-4 text-[11px] font-bold">Cancel</button>
            <button type="submit" disabled={operationalFormBusy || !recallForm.title || !recallForm.reason || !recallForm.batch_ids.length} className="h-8 bg-[#B42318] px-4 text-[11px] font-bold text-white disabled:opacity-50">{operationalFormBusy ? "Starting..." : "Quarantine Selected Lots"}</button>
          </div>
        </form>
      </EnterpriseFormModal>

      <EnterpriseFormModal
        open={Boolean(recallResolutionForm.recall)}
        title={recallResolutionForm.resolution === "release" ? "Release Recalled Stock" : "Destroy Recalled Stock"}
        subtitle={recallResolutionForm.recall?.recall_number || "Recall resolution"}
        onClose={closeRecallResolution}
        busy={operationalFormBusy}
        width="620px"
      >
        <form onSubmit={(event) => { event.preventDefault(); resolveRecall(); }} className="space-y-3 p-4">
          <div className={`border p-3 text-[11px] ${recallResolutionForm.resolution === "release" ? "border-[#ABEFC6] bg-[#ECFDF3] text-[#067647]" : "border-[#FECDCA] bg-[#FEF3F2] text-[#B42318]"}`}>
            <strong className="block text-[12px]">{recallResolutionForm.recall?.title || "Product recall"}</strong>
            <span className="mt-1 block">{recallResolutionForm.resolution === "release" ? "The quarantined lots will return to released stock after clearance." : "The quarantined lots will be marked for destruction and will not return to available stock."}</span>
          </div>
          <Field label="Resolution Notes">
            <textarea
              value={recallResolutionForm.notes}
              onChange={(event) => setRecallResolutionForm((previous) => ({ ...previous, notes: event.target.value }))}
              placeholder="Record the inspection, approval, reason, or destruction evidence..."
              className="min-h-28 w-full border border-[#ACB8B8] p-2 text-[11px] outline-none focus:border-[#069494]"
              autoFocus
            />
          </Field>
          <div className="flex justify-end gap-2 border-t border-[#DAE2E2] pt-3">
            <button type="button" onClick={closeRecallResolution} disabled={operationalFormBusy} className="h-8 border border-[#ACB8B8] bg-white px-4 text-[11px] font-bold">Cancel</button>
            <button type="submit" disabled={operationalFormBusy || !recallResolutionForm.notes.trim()} className={`h-8 px-4 text-[11px] font-bold text-white disabled:opacity-50 ${recallResolutionForm.resolution === "release" ? "bg-[#027A48]" : "bg-[#B42318]"}`}>{operationalFormBusy ? "Saving..." : recallResolutionForm.resolution === "release" ? "Release Stock" : "Confirm Destruction"}</button>
          </div>
        </form>
      </EnterpriseFormModal>

      {selectedWarehouseId && <WarehouseManagementWorkspace
        warehouse={warehouses.find((row) => row.id === selectedWarehouseId) || warehouseDetail?.warehouse}
        detail={warehouseDetail}
        movements={warehouseMovements}
        loading={loadingWarehouseDetail}
        search={warehouseSearch}
        onSearch={setWarehouseSearch}
        onRefresh={() => openWarehouse(warehouses.find((row) => row.id === selectedWarehouseId) || warehouseDetail?.warehouse)}
        onClose={() => { setSelectedWarehouseId(""); setWarehouseSearch(""); }}
      />}

      {productModalOpen && (
        <div className="clean-modal-backdrop">
          <div className="clean-product-modal compact-product-modal">
            <div className="clean-modal-header compact-modal-header">
              <div>
                <h2>{isEditing ? "Edit Stock Item" : "Add Stock Item"}</h2>
                <p>{isEditing ? "Update inventory item identity and controls." : "Create a compact inventory item. Pricing and opening stock are handled separately."}</p>
              </div>
              <button type="button" onClick={handleCloseProductModal} disabled={savingProduct} className="clean-close-button">x</button>
            </div>

            <form onSubmit={handleSubmitProduct} className="clean-modal-body compact-modal-body appetising-stock-form">
              <div className="clean-form-hero">
                <div className="clean-hero-title">
                  <span className="clean-hero-kicker">Stock item setup</span>
                  <strong>{formData.name || "New inventory item"}</strong>
                  <small>{getProductTypeLabel(formData.product_type)} · {formData.is_active ? "Active" : "Inactive"}</small>
                </div>

                <div className="clean-hero-card">
                  <span>Tracking</span>
                  <strong>{formData.stock_tracking_enabled ? "Enabled" : "Off"}</strong>
                </div>

                <div className="clean-hero-card">
                  <span>Negative Stock</span>
                  <strong>{formData.allow_negative_stock ? "Allowed" : "Blocked"}</strong>
                </div>

                <div className="clean-hero-card">
                  <span>Usage</span>
                  <strong>{getStockUsageLabel(formData.stock_usage_type)}</strong>
                </div>
              </div>

              <CleanSection title="Stock Item Identity">
                <CleanGrid>
                  <CleanField label="Item Type" span="2">
                    <select name="product_type" value={formData.product_type} onChange={handleChange} className="clean-input">
                      {!PRODUCT_TYPE_OPTIONS.some(([value]) => value === formData.product_type) && formData.product_type && (
                        <option value={formData.product_type}>{getProductTypeLabel(formData.product_type)}</option>
                      )}
                      {PRODUCT_TYPE_OPTIONS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                    </select>
                  </CleanField>

                  <CleanField label="Item Name" span="4">
                    <input type="text" name="name" value={formData.name} onChange={handleChange} className="clean-input" autoFocus />
                  </CleanField>

                  {canChooseProductScope && <CleanField label="Item Availability" span="3">
                    <select name="is_global" value={formData.is_global ? "global" : "branch"} onChange={handleChange} className="clean-input">
                      <option value="global">Global — every branch</option>
                      <option value="branch">Selected branch only</option>
                    </select>
                  </CleanField>}

                  <CleanField label={formData.is_global ? "Identity Branch" : "Inventory Branch"} span="3">
                    <select name="branch_id" value={formData.branch_id} onChange={handleChange} disabled={!canChooseProductScope && getBranchOptions().length === 1} className="clean-input">
                      <option value="">Select Branch</option>
                      {getBranchOptions().map((branch) => <option key={branch.id} value={branch.id}>{getBranchName(branch)}</option>)}
                    </select>
                  </CleanField>

                  <CleanField label="Barcode" span="3">
                    <div className="clean-input-group">
                      <input type="text" name="barcode" value={formData.barcode} onChange={handleChange} className="clean-input" />
                      <button type="button" onClick={handleGenerateBarcode} className="clean-small-button">Gen</button>
                    </div>
                  </CleanField>

                  <CleanField label="Item Code" span="3">
                    <div className="clean-input-group">
                      <input type="text" name="code" value={formData.code} onChange={handleChange} className="clean-input" />
                      <button type="button" onClick={() => handleGenerateIdentity("product_code")} disabled={generatingType === "product_code"} className="clean-small-button">{generatingType === "product_code" ? "..." : "Gen"}</button>
                    </div>
                  </CleanField>

                  <CleanField label="SKU" span="3">
                    <div className="clean-input-group">
                      <input type="text" name="sku" value={formData.sku} onChange={handleChange} className="clean-input" />
                      <button type="button" onClick={() => handleGenerateIdentity("sku")} disabled={generatingType === "sku"} className="clean-small-button">{generatingType === "sku" ? "..." : "Gen"}</button>
                    </div>
                  </CleanField>

                  <CleanField label="Category" span="3">
                    <select name="category_id" value={formData.category_id} onChange={handleChange} className="clean-input">
                      <option value="">Select Category</option>
                      {categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}
                    </select>
                  </CleanField>

                  <CleanField label="Sub Category" span="3">
                    <select name="sub_category_id" value={formData.sub_category_id} onChange={handleChange} disabled={!formData.category_id} className="clean-input">
                      <option value="">{formData.category_id ? "Select Sub Category" : "Select Category First"}</option>
                      {getFormSubCategoryOptions().map((subCategory) => <option key={subCategory.id} value={subCategory.id}>{subCategory.name}</option>)}
                    </select>
                  </CleanField>

                  <CleanField label="Base UOM" span="3">
                    <select name="base_uom_id" value={formData.base_uom_id} onChange={handleChange} className="clean-input">
                      <option value="">Select UOM</option>
                      {uoms.map((uom) => <option key={uom.id} value={uom.id}>{uom.name} {uom.code ? `(${uom.code})` : ""}</option>)}
                    </select>
                  </CleanField>

                  <CleanField label="Tax ID" span="3">
                    <input type="text" name="tax_id" value={formData.tax_id} onChange={handleChange} placeholder="Optional" className="clean-input" />
                  </CleanField>
                </CleanGrid>
              </CleanSection>

              <CleanSection title="Turnover Classification">
                <CleanGrid>
                  <CleanField label="Stock Usage" span="3">
                    <select name="stock_usage_type" value={formData.stock_usage_type} onChange={handleChange} className="clean-input">
                      {STOCK_USAGE_OPTIONS.map(([value, label]) => (
                        <option key={value} value={value}>{label}</option>
                      ))}
                    </select>
                  </CleanField>

                  <CleanField label="Default Issue Destination" span="3">
                    <select name="default_issue_destination" value={formData.default_issue_destination} onChange={handleChange} className="clean-input">
                      {getDestinationOptionsForUsage(formData.stock_usage_type).map(([value, label]) => (
                        <option key={value} value={value}>{label}</option>
                      ))}
                    </select>
                  </CleanField>

                  <CleanField label="Expense / Cost Account" span="3">
                    <input type="text" name="expense_account_code" value={formData.expense_account_code} onChange={handleChange} placeholder="Optional account code" className="clean-input" />
                  </CleanField>

                  <CleanField label="Report Treatment" span="3">
                    <input
                      value={normalizeProductType(formData.stock_usage_type) === "non_turnover" ? "P&L / Internal Consumption" : "Turnover / Cost of Sales"}
                      readOnly
                      disabled
                      className="clean-input"
                    />
                  </CleanField>

                  <div className="clean-note clean-span-12">
                    Turnover stock is stock that can move to Front / Selling Stock and later appears in turnover or cost-of-sales reporting.
                    Non-turnover stock, such as cleaning material and office consumables, is received into Back Stock but is issued directly to expense, department, production use or wastage. It does not move to Front Stock.
                  </div>
                </CleanGrid>
              </CleanSection>

              {formData.product_type === "child" && (
                <CleanSection title="Parent / Child Break Setup">
                  <CleanGrid>
                    <CleanField label="Parent Product" span="4">
                      <select name="parent_product_id" value={formData.parent_product_id} onChange={handleChange} className="clean-input">
                        <option value="">Select Parent Product</option>
                        {parentProducts.map((product) => <option key={product.id} value={product.id}>{product.name}</option>)}
                      </select>
                    </CleanField>

                    {!isEditing && (
                      <>
                        <CleanCheck span="2"><input type="checkbox" name="break_rule_enabled" checked={formData.break_rule_enabled} onChange={handleChange} /> Add Break Rule</CleanCheck>
                        {formData.break_rule_enabled && (
                          <>
                            <CleanField label="Parent Qty" span="2"><input type="number" step="0.0001" name="break_parent_quantity" value={formData.break_parent_quantity} onChange={handleChange} className="clean-input" /></CleanField>
                            <CleanField label="Units Inside" span="2"><input type="number" step="0.0001" name="break_units_per_parent" value={formData.break_units_per_parent} onChange={handleChange} className="clean-input" /></CleanField>
                            <CleanCheck span="2"><input type="checkbox" name="break_is_auto_break" checked={formData.break_is_auto_break} onChange={handleChange} /> Auto Break</CleanCheck>
                            <div className="clean-note clean-span-12">Example: Parent Qty 1 and Units Inside 10 means 1 parent product creates 10 child products.</div>
                          </>
                        )}
                      </>
                    )}
                  </CleanGrid>
                </CleanSection>
              )}

              <div className="clean-section-grid inventory-rule-grid">
                <CleanSection title="Stock Planning Rules">
                  <CleanGrid>
                    <CleanField label="Reorder Level" span="4">
                      <input type="number" step="0.0001" name="reorder_level" value={formData.reorder_level} onChange={handleChange} className="clean-input" />
                    </CleanField>
                    <CleanField label="Minimum Stock" span="4">
                      <input type="number" step="0.0001" name="minimum_stock" value={formData.minimum_stock} onChange={handleChange} className="clean-input" />
                    </CleanField>
                    <CleanField label="Maximum Stock" span="4">
                      <input type="number" step="0.0001" name="maximum_stock" value={formData.maximum_stock} onChange={handleChange} className="clean-input" />
                    </CleanField>
                  </CleanGrid>
                  <div className="clean-note compact-note">These rules control alerts and stock planning for raw materials, packaging, consumables and semi-finished items.</div>
                </CleanSection>

                <CleanSection title="Product Rules">
                  <div className="clean-rules-grid inventory-rules-compact">
                    {[
                      ["stock_tracking_enabled", "Manage stock for this product"],
                      ["batch_tracking_enabled", "Batch / Lot Tracking"],
                      ["expiry_tracking_enabled", "Expiry Tracking"],
                      ["allow_negative_stock", "Negative Stock"],
                      ["allow_discount", "Allow Discount"],
                      ["taxable", "Taxable"],
                      ["is_active", "Active"],
                    ].map(([name, label]) => (
                      <label key={name} className="clean-check-card clean-rule-tile">
                        <input type="checkbox" name={name} checked={!!formData[name]} onChange={handleChange} disabled={["batch_tracking_enabled", "expiry_tracking_enabled"].includes(name) && !formData.stock_tracking_enabled} />
                        <span>{label}</span>
                      </label>
                    ))}
                  </div>
                  {!formData.stock_tracking_enabled && <div className="clean-note compact-note">Stock management is optional. Enable it only when this product needs quantities, batches, expiry dates, costing, or stock alerts.</div>}
                  {formData.expiry_tracking_enabled && <div className="mt-2"><CleanField label="Default Shelf Life (days)" span="12"><input type="number" min="1" name="default_shelf_life_days" value={formData.default_shelf_life_days} onChange={handleChange} className="clean-input" /></CleanField></div>}
                </CleanSection>
              </div>

              <CleanSection title="Extra Details">
                <CleanGrid>
                  <CleanField label="Description" span="12">
                    <textarea name="description" value={formData.description} onChange={handleChange} rows={2} className="clean-textarea" placeholder="Optional notes, pack size, storage guidance or kitchen usage..." />
                  </CleanField>
                </CleanGrid>
              </CleanSection>

              {!isEditing && (
                <CleanSection title="Additional Barcodes" right={<button type="button" onClick={handleAddAlternativeBarcode} className="clean-small-button">Add Barcode</button>}>
                  {formData.barcodes.length === 0 ? (
                    <div className="clean-empty-line">No additional barcodes added.</div>
                  ) : (
                    <div className="clean-list-stack">
                      {formData.barcodes.map((item, index) => (
                        <div key={index} className="clean-barcode-row">
                          <input type="text" value={item.barcode} onChange={(e) => handleBarcodeChange(index, "barcode", e.target.value)} placeholder="Barcode" className="clean-input" />
                          <select value={item.type} onChange={(e) => handleBarcodeChange(index, "type", e.target.value)} className="clean-input">
                            <option value="alternative">Alternative</option>
                            <option value="supplier">Supplier</option>
                            <option value="primary">Primary</option>
                          </select>
                          <button type="button" onClick={() => handleRemoveBarcode(index)} className="clean-danger-button">Remove</button>
                        </div>
                      ))}
                    </div>
                  )}
                </CleanSection>
              )}
            </form>

            <div className="clean-modal-footer compact-modal-footer">
              <div className="clean-modal-status">{formData.name ? formData.name : "New stock item"}</div>
              <div className="clean-footer-actions">
                <button type="button" onClick={handleCloseProductModal} disabled={savingProduct} className="clean-secondary-button">Cancel</button>
                <button type="button" disabled={savingProduct} onClick={handleSubmitProduct} className="clean-primary-button">{savingProduct ? "Saving..." : isEditing ? "Update Stock Item" : "Save Stock Item"}</button>
              </div>
            </div>
          </div>
        </div>
      )}

      {breakModalOpen && (
        <SmallModalShell title="Break Parent Into Child" subtitle={selectedBreakProduct ? `Child: ${selectedBreakProduct.name}` : ""} onClose={handleCloseBreakModal} maxWidth="max-w-xl" footer={<><button type="button" onClick={handleCloseBreakModal} className="h-8 px-4 bg-[#E4E8E8] border border-[#ACB8B8] text-[#003636] text-[11px] font-semibold uppercase">Cancel</button><button type="button" disabled={savingBreak} onClick={handleSubmitBreak} className="h-8 px-4 bg-[#069495] text-white text-[11px] font-semibold uppercase disabled:opacity-60">{savingBreak ? "Breaking..." : "Break Product"}</button></>}>
          <form onSubmit={handleSubmitBreak} className="space-y-3">
            <div className="border border-[#ACB8B8] bg-[#E8F7F5] p-2">
              <div className="text-[10px] font-semibold uppercase text-[#005A5A]">Break Flow</div>
              <div className="text-[11px] font-medium mt-1 text-[#003636]">This uses the saved child break rule: parent stock goes down, child stock goes up.</div>
            </div>
            <div className="border border-[#ACB8B8] bg-[#F8FAFC] p-2 space-y-2">
              <Field label="Branch"><select name="branch_id" value={breakForm.branch_id} onChange={handleBreakChange} className="input-small"><option value="">Select Branch</option>{getBranchOptions().map((branch) => <option key={branch.id} value={branch.id}>{getBranchName(branch)}</option>)}</select></Field>
              <Field label="Break Times"><input type="number" min="1" step="1" name="break_times" value={breakForm.break_times} onChange={handleBreakChange} className="input-small" /></Field>
            </div>
          </form>
        </SmallModalShell>
      )}

      <ReusableMessageModal open={messageModal.open} type={messageModal.type} title={messageModal.title} message={messageModal.message} onClose={closeMessageModal} />
      <InventoryVarianceAgentModal open={varianceAgentOpen} onClose={() => { setVarianceAgentOpen(false); refreshVarianceOverview(); }} currentUser={currentUser} initialBranchId={getLoggedInBranchId()} isHospitality={isHospitality} />
      <AppInlineStyles />
    </>
  );
};

const VarianceOverviewMetric = ({ label, value, tone = "blue" }) => {
  const toneClass = tone === "red" ? "border-[#FDA29B] bg-[#FEF3F2] text-[#B42318]" : tone === "amber" ? "border-[#FEDF89] bg-[#FFFAEB] text-[#B54708]" : "border-[#A4E5E4] bg-[#F1F8F8] text-[#057B7B]";
  return <div className={`flex items-center justify-between border px-3 py-2 ${toneClass}`}><span className="text-[10px] font-bold uppercase tracking-wide">{label}</span><strong className="ml-3 text-[19px] tabular-nums">{Number(value || 0).toLocaleString()}</strong></div>;
};

const WarehouseMovementLineGraph = ({ movements = [] }) => {
  const quantity = formatInventoryQuantity;
  const trend = useMemo(() => {
    const byDate = new Map();
    movements.forEach((movement) => {
      const rawDate = movement.created_at || movement.movement_date;
      if (!rawDate) return;
      const date = String(rawDate).slice(0, 10);
      const current = byDate.get(date) || { date, quantityIn: 0, quantityOut: 0 };
      current.quantityIn += Number(movement.quantity_in || 0);
      current.quantityOut += Number(movement.quantity_out || 0);
      byDate.set(date, current);
    });
    return [...byDate.values()].sort((a, b) => a.date.localeCompare(b.date)).slice(-30);
  }, [movements]);

  const width = 920;
  const height = 230;
  const left = 58;
  const right = 24;
  const top = 22;
  const bottom = 42;
  const chartWidth = width - left - right;
  const chartHeight = height - top - bottom;
  const maxValue = Math.max(...trend.flatMap((row) => [row.quantityIn, row.quantityOut]), 1);
  const point = (row, index, field) => {
    const x = left + (trend.length <= 1 ? chartWidth / 2 : (index / (trend.length - 1)) * chartWidth);
    const y = top + chartHeight - (Number(row[field] || 0) / maxValue) * chartHeight;
    return [x, y];
  };
  const path = (field) => trend.map((row, index) => `${index === 0 ? "M" : "L"} ${point(row, index, field).join(" ")}`).join(" ");
  const labelIndexes = [...new Set([0, Math.floor((trend.length - 1) / 2), trend.length - 1])].filter((index) => index >= 0);

  return <section className="border border-[#CCD7D6] bg-white p-3">
    <div className="flex items-start justify-between gap-3"><div><div className="text-[12px] font-black uppercase text-[#004141]">Warehouse movement trend</div><div className="text-[10px] text-[#627474]">Daily quantities received and issued · latest 30 movement days</div></div><div className="flex gap-3 text-[9px] font-bold uppercase text-[#627474]"><span><i className="mr-1 inline-block h-2 w-4 bg-[#12B76A]" />Received</span><span><i className="mr-1 inline-block h-2 w-4 bg-[#F04438]" />Issued</span></div></div>
    {trend.length === 0 ? <div className="flex h-[230px] items-center justify-center text-[11px] text-[#627474]">No warehouse movements are available yet.</div> : <svg viewBox={`0 0 ${width} ${height}`} className="mt-2 h-[230px] w-full" role="img" aria-label="Daily warehouse received and issued quantity line graph">
      {[0, .25, .5, .75, 1].map((ratio) => { const y = top + chartHeight - ratio * chartHeight; return <g key={ratio}><line x1={left} x2={width - right} y1={y} y2={y} stroke="#E4EAEA" strokeWidth="1" /><text x={left - 8} y={y + 3} textAnchor="end" fontSize="9" fill="#627474">{quantity(maxValue * ratio)}</text></g>; })}
      <line x1={left} x2={left} y1={top} y2={top + chartHeight} stroke="#96A5A4" /><line x1={left} x2={width - right} y1={top + chartHeight} y2={top + chartHeight} stroke="#96A5A4" />
      <path d={path("quantityIn")} fill="none" stroke="#12B76A" strokeWidth="3" /><path d={path("quantityOut")} fill="none" stroke="#F04438" strokeWidth="3" />
      {trend.map((row, index) => <g key={row.date}><circle cx={point(row, index, "quantityIn")[0]} cy={point(row, index, "quantityIn")[1]} r="3" fill="#12B76A"><title>{`${row.date}: received ${quantity(row.quantityIn)}`}</title></circle><circle cx={point(row, index, "quantityOut")[0]} cy={point(row, index, "quantityOut")[1]} r="3" fill="#F04438"><title>{`${row.date}: issued ${quantity(row.quantityOut)}`}</title></circle></g>)}
      {labelIndexes.map((index) => <text key={index} x={point(trend[index], index, "quantityIn")[0]} y={height - 14} textAnchor="middle" fontSize="9" fill="#627474">{trend[index].date}</text>)}
      <text x="12" y={top + chartHeight / 2} transform={`rotate(-90 12 ${top + chartHeight / 2})`} textAnchor="middle" fontSize="9" fill="#627474">Quantity</text>
    </svg>}
  </section>;
};

const WarehouseManagementWorkspace = ({ warehouse = {}, detail = {}, movements = [], loading = false, search = "", onSearch, onRefresh, onClose }) => {
  const quantity = formatInventoryQuantity;
  const money = formatInventoryMoney;
  const term = search.trim().toLowerCase();
  const products = (detail?.products || []).filter((product) => !term || [product.product_name, product.code, product.sku, product.barcode, product.category_name, product.product_type].some((value) => String(value || "").toLowerCase().includes(term)));
  const filteredMovements = movements.filter((movement) => !term || [movement.product_name, movement.product_code, movement.movement_type, movement.lot_number, movement.reference_type, movement.notes].some((value) => String(value || "").toLowerCase().includes(term)));
  const summary = detail?.summary || warehouse || {};
  const locations = detail?.warehouse?.locations || warehouse?.locations || [];

  return <div className="fixed inset-0 z-[100100] flex flex-col bg-[#F4F7F7] text-[#263636]" role="dialog" aria-modal="true" aria-label={`Manage ${warehouse?.name || "warehouse"}`}>
    <header className="flex min-h-[62px] shrink-0 items-center justify-between gap-4 border-b border-[#ACB8B8] bg-[#004142] px-5 text-white">
      <div><div className="text-[15px] font-black uppercase tracking-[0.08em]">{warehouse?.name || detail?.warehouse?.name || "Warehouse Management"}</div><div className="mt-1 text-[10px] text-white/75">{warehouse?.code || detail?.warehouse?.code || "-"} · {warehouse?.branch_name || detail?.warehouse?.branch_name || "Company-wide"} · {String(warehouse?.warehouse_type || detail?.warehouse?.warehouse_type || "general").replaceAll("_", " ")}</div></div>
      <div className="flex items-center gap-2"><button type="button" onClick={onRefresh} disabled={loading} className="h-8 border border-white/40 bg-white/10 px-4 text-[11px] font-bold uppercase hover:bg-white/20 disabled:opacity-50">{loading ? "Loading…" : "Refresh"}</button><button type="button" onClick={onClose} className="h-8 border border-white/40 bg-white px-4 text-[11px] font-bold uppercase text-[#004141]">Close</button></div>
    </header>
    <div className="grid shrink-0 gap-2 border-b border-[#CCD7D6] bg-white p-3 sm:grid-cols-2 xl:grid-cols-5">
      {[["Products", summary.product_count || detail?.products?.length || 0], ["On hand", quantity(summary.quantity_on_hand)], ["Available", quantity(summary.available_quantity)], ["Stock value", money(summary.stock_value)], ["Locations", locations.length || warehouse?.location_count || 0]].map(([label, value]) => <div key={label} className="border border-[#DAE2E2] bg-[#F8FAFC] p-3"><div className="text-[9px] font-black uppercase tracking-[0.12em] text-[#627474]">{label}</div><div className="mt-1 text-[20px] font-black text-[#004141]">{value}</div></div>)}
    </div>
    <div className="grid shrink-0 gap-2 border-b border-[#CCD7D6] bg-white p-3 lg:grid-cols-[minmax(320px,1fr)_auto]">
      <input value={search} onChange={(event) => onSearch(event.target.value)} placeholder="Search products, SKU, barcode, movement, lot or reference" className="h-9 border border-[#96A5A4] px-3 text-[12px] outline-none focus:border-[#069494]" />
      <div className="flex items-center text-[10px] font-bold uppercase text-[#627474]">Showing {products.length} products · {filteredMovements.length} movements</div>
    </div>
    <main className="min-h-0 flex-1 overflow-y-auto p-3">
      {loading && !detail && <div className="mb-3 border border-[#A4E5E4] bg-[#F1F8F8] p-3 text-[11px] font-bold text-[#057B7B]">Loading complete warehouse information…</div>}
      <WarehouseMovementLineGraph movements={movements} />
      <section className="mt-3 border border-[#CCD7D6] bg-white">
        <div className="border-b border-[#CCD7D6] bg-[#EEF2F2] px-3 py-2 text-[11px] font-black uppercase text-[#004141]">Products in this warehouse</div>
        <div className="overflow-auto">
          <table className="sx-enterprise-table min-w-[1880px]">
            <thead><tr><th>Product</th><th>Code</th><th>SKU</th><th>Barcode</th><th>Category</th><th>Type</th><th>UOM</th><th>Batch Tracking</th><th>Expiry Tracking</th><th className="text-right">On Hand</th><th className="text-right">Available</th><th className="text-right">Unit Cost</th><th className="text-right">Value</th><th className="text-right">Batches</th><th>Nearest Expiry</th><th>Last Movement</th></tr></thead>
            <tbody>
              {products.map((product) => (
                <tr key={product.product_id}>
                  <td><strong>{product.product_name || "-"}</strong></td>
                  <td>{product.code || "-"}</td>
                  <td>{product.sku || "-"}</td>
                  <td>{product.barcode || "-"}</td>
                  <td>{product.category_name || "Uncategorised"}</td>
                  <td>{String(product.product_type || "-").replaceAll("_", " ")}</td>
                  <td>{product.uom || "-"}</td>
                  <td>{product.batch_tracking_enabled ? "Enabled" : "Disabled"}</td>
                  <td>{product.expiry_tracking_enabled ? "Enabled" : "Disabled"}</td>
                  <td className="text-right">{quantity(product.quantity_on_hand)}</td>
                  <td className="text-right">{quantity(product.available_quantity)}</td>
                  <td className="text-right">{money(product.unit_cost, product.currency_code)}</td>
                  <td className="text-right">{money(product.stock_value, product.currency_code)}</td>
                  <td className="text-right">{product.batch_count || 0}</td>
                  <td>{product.nearest_expiry || "No expiry"}</td>
                  <td>{product.last_movement_at ? new Date(product.last_movement_at).toLocaleString() : "Never"}</td>
                </tr>
              ))}
              {!loading && products.length === 0 && <tr><td colSpan="16" className="py-8 text-center text-[#627474]">No products match this warehouse search.</td></tr>}
            </tbody>
          </table>
        </div>
      </section>
      <section className="mt-3 border border-[#CCD7D6] bg-white">
        <div className="border-b border-[#CCD7D6] bg-[#EEF2F2] px-3 py-2 text-[11px] font-black uppercase text-[#004141]">Recent warehouse movements</div>
        <div className="overflow-auto">
          <table className="sx-enterprise-table min-w-[1160px]">
            <thead><tr><th>Date</th><th>Product</th><th>Product Code</th><th>Movement</th><th>Lot</th><th className="text-right">In</th><th className="text-right">Out</th><th className="text-right">Balance</th><th>Reference</th><th>Notes</th></tr></thead>
            <tbody>
              {filteredMovements.map((movement) => <tr key={movement.id}><td>{movement.created_at || movement.movement_date ? new Date(movement.created_at || movement.movement_date).toLocaleString() : "-"}</td><td>{movement.product_name || "-"}</td><td>{movement.product_code || "-"}</td><td>{String(movement.movement_type || "-").replaceAll("_", " ")}</td><td>{movement.lot_number || "-"}</td><td className="text-right text-[#067647]">{quantity(movement.quantity_in)}</td><td className="text-right text-[#B42318]">{quantity(movement.quantity_out)}</td><td className="text-right">{movement.balance_after == null ? "-" : quantity(movement.balance_after)}</td><td>{movement.reference_type || "-"}</td><td>{movement.notes || "-"}</td></tr>)}
              {!loading && filteredMovements.length === 0 && <tr><td colSpan="10" className="py-8 text-center text-[#627474]">No movements match this warehouse search.</td></tr>}
            </tbody>
          </table>
        </div>
      </section>
    </main>
  </div>;
};

const HospitalityStockAreaOverview = ({ dashboard = {}, currency = "USD", fallbackNonTurnoverCount = 0 }) => {
  const [activeArea, setActiveArea] = useState("back");
  const stockAreas = Array.isArray(dashboard?.stock_areas) ? dashboard.stock_areas : [];
  const nonTurnoverPayload = dashboard?.non_turnover_stock || {};
  const nonTurnoverSummary = nonTurnoverPayload?.summary || {};
  const nonTurnoverProducts = Array.isArray(nonTurnoverPayload?.products) ? nonTurnoverPayload.products : [];
  const findArea = (area) => stockAreas.find((row) => normalizeProductType(row?.stock_area) === area) || {};
  const backStock = findArea("back");
  const frontStock = findArea("front");
  const nonTurnoverProductCount = nonTurnoverSummary.product_count ?? backStock.non_turnover_product_count ?? fallbackNonTurnoverCount;
  const nonTurnoverOnHand = nonTurnoverSummary.quantity_on_hand ?? backStock.non_turnover_quantity_on_hand ?? 0;
  const nonTurnoverAvailable = nonTurnoverSummary.available_quantity ?? backStock.non_turnover_available_quantity ?? 0;
  const nonTurnoverValue = nonTurnoverSummary.stock_value ?? backStock.non_turnover_stock_value ?? 0;
  const cards = [
    {
      key: "back",
      label: "Back Stock",
      productCount: backStock.product_count || 0,
      onHand: backStock.quantity_on_hand || 0,
      available: backStock.available_quantity || 0,
      value: backStock.stock_value || 0,
      description: "Stores / BOH stock awaiting transfer to service, production, department use or expense.",
    },
    {
      key: "front",
      label: "Front of House",
      productCount: frontStock.product_count || 0,
      onHand: frontStock.quantity_on_hand || 0,
      available: frontStock.available_quantity || 0,
      value: frontStock.stock_value || 0,
      description: "Selling and service stock available to hospitality operations.",
    },
    {
      key: "non_turnover",
      label: "Non-Turnover Stock",
      productCount: nonTurnoverProductCount,
      onHand: nonTurnoverOnHand,
      available: nonTurnoverAvailable,
      value: nonTurnoverValue,
      description: "Operating consumables held in Back Stock and charged to internal use when issued.",
    },
  ];
  const selectedCard = cards.find((card) => card.key === activeArea) || cards[0];

  return (
    <section className="border-b border-[#C3D0D0] bg-[#EEF2F2] p-3">
      <div className="mb-2 flex flex-wrap items-end justify-between gap-2">
        <div>
          <h3 className="text-[12px] font-black uppercase tracking-[0.08em] text-[#004141]">Hospitality Stock Areas</h3>
          <p className="mt-1 text-[10px] text-[#627474]">Back Stock, Front of House, and non-turnover operating stock remain separate management classifications.</p>
        </div>
        <span className="border border-[#A4E5E4] bg-[#F1F8F8] px-2 py-1 text-[9px] font-bold uppercase text-[#057B7B]">Management view</span>
      </div>

      <div className="grid gap-2 lg:grid-cols-3">
        {cards.map((card) => (
          <button
            key={card.key}
            type="button"
            aria-pressed={activeArea === card.key}
            onClick={() => setActiveArea(card.key)}
            className={`border bg-white p-3 text-left ${activeArea === card.key ? "border-[#069495] shadow-[inset_0_-3px_0_#069495]" : "border-[#CCD7D6] hover:border-[#96A5A4]"}`}
          >
            <div className="flex items-center justify-between gap-2">
              <span className="text-[10px] font-black uppercase tracking-[0.08em] text-[#324443]">{card.label}</span>
              <strong className="text-[15px] text-[#004141]">{Number(card.productCount || 0).toLocaleString()} items</strong>
            </div>
            <div className="mt-2 grid grid-cols-3 gap-2 border-t border-[#E4E8E8] pt-2">
              <div><span className="block text-[8px] font-bold uppercase text-[#627474]">On Hand</span><b className="text-[11px] text-[#0C1B1B]">{formatInventoryQuantity(card.onHand)}</b></div>
              <div><span className="block text-[8px] font-bold uppercase text-[#627474]">Available</span><b className="text-[11px] text-[#0C1B1B]">{formatInventoryQuantity(card.available)}</b></div>
              <div><span className="block text-[8px] font-bold uppercase text-[#627474]">Value</span><b className="text-[11px] text-[#0C1B1B]">{formatInventoryMoney(card.value, currency)}</b></div>
            </div>
          </button>
        ))}
      </div>

      <div className="mt-2 border border-[#CCD7D6] bg-white">
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-[#CCD7D6] bg-[#F8FAFC] px-3 py-2">
          <div><strong className="text-[11px] text-[#004141]">{selectedCard.label} Register</strong><small className="ml-2 text-[10px] text-[#627474]">{selectedCard.description}</small></div>
          <span className="text-[9px] font-bold uppercase text-[#627474]">{activeArea === "non_turnover" ? `${nonTurnoverProducts.length} detailed rows` : "Area totals"}</span>
        </div>

        {activeArea === "non_turnover" ? (
          <div className="overflow-auto">
            <table className="sx-enterprise-table min-w-[1680px]">
              <thead><tr><th>Product</th><th>Type</th><th>Code</th><th>SKU</th><th>Branch</th><th className="text-right">Back On Hand</th><th className="text-right">Back Available</th><th className="text-right">Front On Hand</th><th className="text-right">Front Available</th><th className="text-right">Total Available</th><th className="text-right">Unit Cost</th><th className="text-right">Stock Value</th><th>Default Use</th><th>Expense Account</th></tr></thead>
              <tbody>
                {nonTurnoverProducts.map((row) => (
                  <tr key={`${row.branch_id || "company"}-${row.product_id}`}>
                    <td><strong>{row.product_name || "Unnamed stock item"}</strong></td>
                    <td>{String(row.product_type || "consumable").replaceAll("_", " ")}</td>
                    <td>{row.code || "-"}</td>
                    <td>{row.sku || "-"}</td>
                    <td>{row.branch_name || "Company-wide"}</td>
                    <td className="text-right">{formatInventoryQuantity(row.back_quantity_on_hand)}</td>
                    <td className="text-right">{formatInventoryQuantity(row.back_available_quantity)}</td>
                    <td className="text-right">{formatInventoryQuantity(row.front_quantity_on_hand)}</td>
                    <td className="text-right">{formatInventoryQuantity(row.front_available_quantity)}</td>
                    <td className="text-right"><strong>{formatInventoryQuantity(row.total_available_quantity)}</strong></td>
                    <td className="text-right">{formatInventoryMoney(row.unit_cost, row.currency || currency)}</td>
                    <td className="text-right">{formatInventoryMoney(row.stock_value, row.currency || currency)}</td>
                    <td>{String(row.default_issue_destination || "expense").replaceAll("_", " ")}</td>
                    <td>{row.expense_account_code || "No expense account"}</td>
                  </tr>
                ))}
                {!nonTurnoverProducts.length && <tr><td colSpan="14" className="py-8 text-center text-[#627474]">No non-turnover stock balances have been recorded.</td></tr>}
              </tbody>
            </table>
          </div>
        ) : (
          <div className="overflow-auto">
            <table className="sx-enterprise-table min-w-[900px]">
              <thead><tr><th>Stock Area</th><th>Purpose</th><th className="text-right">Products</th><th className="text-right">Turnover Products</th><th className="text-right">Non-Turnover Products</th><th className="text-right">On Hand</th><th className="text-right">Reserved</th><th className="text-right">Available</th><th className="text-right">Stock Value</th></tr></thead>
              <tbody>
                {[backStock, frontStock].filter((row) => row?.stock_area).map((row) => {
                  const area = normalizeProductType(row.stock_area);
                  return <tr key={area} className={activeArea === area ? "sx-row-selected" : ""}><td><strong>{area === "back" ? "Back Stock" : "Front of House"}</strong></td><td>{area === "back" ? "BOH / stores" : "Selling / service"}</td><td className="text-right">{row.product_count || 0}</td><td className="text-right">{row.turnover_product_count || 0}</td><td className="text-right">{row.non_turnover_product_count || 0}</td><td className="text-right">{formatInventoryQuantity(row.quantity_on_hand)}</td><td className="text-right">{formatInventoryQuantity(row.reserved_quantity)}</td><td className="text-right">{formatInventoryQuantity(row.available_quantity)}</td><td className="text-right">{formatInventoryMoney(row.stock_value, currency)}</td></tr>;
                })}
                {!stockAreas.length && <tr><td colSpan="9" className="py-8 text-center text-[#627474]">No Back Stock or Front of House balances have been recorded.</td></tr>}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </section>
  );
};

const InventoryOverviewVisuals = ({ dashboard = {}, currency = "USD" }) => {
  const summary = dashboard?.summary || {};
  const visuals = dashboard?.visuals || {};
  const health = Array.isArray(visuals.stock_health) ? visuals.stock_health : [];
  const categories = Array.isArray(visuals.stock_value_by_category) ? visuals.stock_value_by_category : [];
  const movements = Array.isArray(visuals.movement_trend_14d) ? visuals.movement_trend_14d : [];
  const healthTotal = Math.max(health.reduce((sum, row) => sum + Number(row.product_count || 0), 0), 1);
  const categoryMax = Math.max(...categories.map((row) => Number(row.stock_value || 0)), 1);
  const movementMax = Math.max(...movements.map((row) => Math.max(Number(row.quantity_in || 0), Number(row.quantity_out || 0))), 1);
  const statusColor = { healthy: "#12b76a", low: "#f79009", negative: "#d92d20", overstock: "#069494" };
  return <div className="border-b border-[#C3D0D0] bg-[#F4F7F7] p-3">
    <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
      {[["Stock value", `${currency} ${Number(summary.stock_value || 0).toLocaleString(undefined, { maximumFractionDigits: 2 })}`], ["Available units", Number(summary.available_quantity || 0).toLocaleString()], ["Low / negative", `${Number(summary.low_stock_products || 0)} / ${Number(summary.negative_stock_products || 0)}`], ["Expiry risk", Number(summary.expired_batches || 0) + Number(summary.expiring_batches || 0)]].map(([label, value]) => <div key={label} className="border border-[#CCD7D6] bg-white p-3"><div className="text-[9px] font-black uppercase tracking-[0.12em] text-[#627474]">{label}</div><div className="mt-1 text-[20px] font-black text-[#004141]">{value}</div></div>)}
    </div>
    <div className="mt-2 grid gap-2 xl:grid-cols-3">
      <div className="border border-[#CCD7D6] bg-white p-3"><div className="mb-3 text-[10px] font-black uppercase text-[#324443]">Stock health</div>{health.map((row) => <div key={row.stock_status} className="mb-2"><div className="flex justify-between text-[9px] font-bold uppercase"><span>{row.stock_status}</span><span>{row.product_count}</span></div><div className="mt-1 h-2 bg-[#EEF2F2]"><div className="h-full" style={{ width: `${Math.max(3, Number(row.product_count || 0) / healthTotal * 100)}%`, background: statusColor[row.stock_status] || "#627474" }} /></div></div>)}</div>
      <div className="border border-[#CCD7D6] bg-white p-3"><div className="mb-3 text-[10px] font-black uppercase text-[#324443]">Value by category</div>{categories.slice(0, 6).map((row) => <div key={row.category_name} className="mb-2 grid grid-cols-[90px_1fr_72px] items-center gap-2 text-[9px]"><span className="truncate font-bold">{row.category_name}</span><div className="h-2 bg-[#EEF2F2]"><div className="h-full bg-[#069494]" style={{ width: `${Number(row.stock_value || 0) / categoryMax * 100}%` }} /></div><span className="text-right">{Number(row.stock_value || 0).toFixed(0)}</span></div>)}</div>
      <div className="border border-[#CCD7D6] bg-white p-3"><div className="mb-3 text-[10px] font-black uppercase text-[#324443]">Movement · last 14 days</div><div className="flex h-[110px] items-end gap-1">{movements.map((row) => <div key={row.movement_date} className="flex h-full min-w-0 flex-1 items-end justify-center gap-[1px]" title={`${row.movement_date}: in ${row.quantity_in}, out ${row.quantity_out}`}><div className="w-1/2 bg-[#12B76A]" style={{ height: `${Math.max(2, Number(row.quantity_in || 0) / movementMax * 100)}%` }} /><div className="w-1/2 bg-[#F04438]" style={{ height: `${Math.max(2, Number(row.quantity_out || 0) / movementMax * 100)}%` }} /></div>)}</div><div className="mt-2 flex gap-4 text-[8px] font-bold uppercase text-[#627474]"><span><b className="mr-1 inline-block h-2 w-2 bg-[#12B76A]" />Received</span><span><b className="mr-1 inline-block h-2 w-2 bg-[#F04438]" />Issued / sold</span></div></div>
    </div>
  </div>;
};

const ProductDeskPanel = ({ title, children }) => (
  <div className="border border-[#AFB9B9] bg-white"><div className="border-b border-[#D8DFDF] bg-[#EEF2F2] px-2 py-1 text-[11px] font-semibold text-[#324443]">{title}</div><div className="p-2">{children}</div></div>
);

const ProductDeskCompactTool = ({ children, onClick, disabled = false, green = false, danger = false }) => {
  let cls = "border-[#B9C3C3] bg-white text-[#324443]";
  if (green) cls = "border-[#027A48] bg-[#027A48] text-white";
  if (danger) cls = "border-[#FECDCA] bg-white text-[#B42318]";
  return <button type="button" disabled={disabled} onClick={onClick} className={`h-7 border px-3 text-[11px] font-semibold disabled:opacity-50 ${cls}`}>{children}</button>;
};

const InventoryRowActionMenu = ({ label = "Row actions", actions = [] }) => {
  const [open, setOpen] = useState(false);
  const availableActions = actions.filter(Boolean);

  if (!availableActions.length) return <span className="text-[#96A5A4]">-</span>;

  return (
    <div className="action-menu-wrap relative inline-flex" onClick={(event) => event.stopPropagation()} onBlur={(event) => { if (!event.currentTarget.contains(event.relatedTarget)) setOpen(false); }}>
      <button type="button" aria-label={label} aria-haspopup="menu" aria-expanded={open} onClick={() => setOpen((previous) => !previous)} className="flex h-7 w-7 items-center justify-center border border-[#B9C3C3] bg-white text-[18px] font-bold leading-none text-[#324443] hover:bg-[#F2F4F7]">⋮</button>
      {open && (
        <div role="menu" className="absolute right-0 top-8 z-[90] min-w-[150px] border border-[#96A5A4] bg-white p-1 text-left shadow-xl">
          {availableActions.map((action) => (
            <button
              key={action.label}
              type="button"
              role="menuitem"
              disabled={action.disabled}
              onClick={(event) => { event.stopPropagation(); setOpen(false); action.onClick?.(event); }}
              className={`block h-8 w-full px-2 text-left text-[10px] font-semibold disabled:opacity-50 ${action.danger ? "text-[#B42318] hover:bg-[#FEF3F2]" : "text-[#324443] hover:bg-[#F2F4F7]"}`}
            >
              {action.label}
            </button>
          ))}
        </div>
      )}
    </div>
  );
};

const Field = ({ label, children, className = "" }) => <div className={className}><label className="block text-[10px] font-medium mb-1 uppercase text-[#324444]">{label}</label>{children}</div>;
const CleanSection = ({ title, right, children }) => <section className="clean-section"><div className="clean-section-header"><h3>{title}</h3>{right}</div><div className="clean-section-body">{children}</div></section>;
const CleanGrid = ({ children }) => <div className="clean-grid">{children}</div>;
const CleanField = ({ label, children, span = "12" }) => <div className={`clean-field clean-span-${span}`}><label>{label}</label>{children}</div>;
const CleanCheck = ({ children, span = "12" }) => <label className={`clean-check-card clean-span-${span}`}>{children}</label>;

const SmallModalShell = ({ title, subtitle, color = "#004141", accent = "#00BCBC", onClose, children, footer, maxWidth = "max-w-lg" }) => (
  <div className="fixed inset-0 z-[100000] bg-black/50 flex items-center justify-center p-4">
    <div className={`sx-large-modal-window ${maxWidth} bg-white border border-[#ACB8B8] shadow-2xl`} role="dialog" aria-modal="true" aria-label={title}>
      <div className="h-10 px-3 flex items-center justify-between border-b" style={{ backgroundColor: color, borderColor: accent }}>
        <div><h2 className="text-[12px] font-semibold tracking-wide text-white uppercase">{title}</h2>{subtitle && <div className="text-[9px] text-white/80 font-medium uppercase">{subtitle}</div>}</div>
        <button type="button" onClick={onClose} className="text-white text-[18px] font-medium leading-none">x</button>
      </div>
      <div className="min-h-0 flex-1 bg-[#DFE5E5] p-2"><div className="h-full bg-white border border-[#CAD5D5] p-3 overflow-y-auto">{children}</div></div>
      {footer && <div className="h-11 border-t border-[#CAD5D5] bg-[#F8FAFC] px-3 flex items-center justify-end gap-2">{footer}</div>}
    </div>
  </div>
);

const AppInlineStyles = () => (
  <style>{`
    .input-small { width: 100%; height: 2rem; border: 1px solid #ACB8B8; padding: 0 0.5rem; font-size: 11px; outline: none; background: white; border-radius: 0; }
    .input-small:focus { border-color: #00BCBC; box-shadow: inset 0 0 0 1px #00BCBC; }
    button, input, select, textarea { border-radius: 0 !important; }

    .clean-modal-backdrop { position: fixed; inset: 0; z-index: 99999; background: rgba(0, 52, 52, 0.62); display: flex; align-items: center; justify-content: center; padding: 14px; }
    .clean-product-modal { width: min(1380px, 99vw); height: min(94vh, 900px); background: #ffffff; border: 1px solid #9BA5A5; box-shadow: 0 20px 50px rgba(0, 0, 0, 0.32); display: flex; flex-direction: column; color: #0D1B1B; }
    .compact-product-modal { height: min(93vh, 880px); }
    .clean-modal-header { min-height: 58px; flex-shrink: 0; background: linear-gradient(90deg, #F8FAFC 0%, #EEF7F5 100%); border-bottom: 1px solid #CBD6D6; padding: 10px 14px; display: flex; align-items: center; justify-content: space-between; gap: 14px; }
    .compact-modal-header { min-height: 50px; padding: 8px 12px; }
    .clean-modal-header h2 { margin: 0; font-size: 15px; font-weight: 500; color: #091B1B; }
    .clean-modal-header p { margin: 3px 0 0; font-size: 12px; color: #637877; }
    .clean-close-button { width: 34px; height: 34px; border: 1px solid #CBD6D6; background: #ffffff; font-size: 18px; line-height: 1; color: #324444; }
    .clean-close-button:hover:not(:disabled) { background: #FEE2E2; border-color: #EF4444; color: #991B1B; }
    .clean-modal-body { flex: 1; min-height: 0; overflow-y: auto; padding: 14px; background: linear-gradient(180deg, #F8FAFC 0%, #EFF3F3 100%); }
    .compact-modal-body { padding: 10px; }

    .clean-section { background: #ffffff; border: 1px solid #CBD6D6; margin-bottom: 10px; }
    .clean-section-header { min-height: 34px; border-bottom: 1px solid #E2E9E9; background: #F8FAFC; padding: 6px 9px; display: flex; align-items: center; justify-content: space-between; gap: 10px; }
    .clean-section-header h3 { margin: 0; font-size: 12px; font-weight: 500; color: #091B1B; }
    .clean-section-body { padding: 9px; }
    .clean-grid { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 8px; }
    .clean-field label { display: block; margin-bottom: 3px; font-size: 10.5px; font-weight: 400; color: #465858; }
    .clean-span-2 { grid-column: span 2 / span 2; }
    .clean-span-3 { grid-column: span 3 / span 3; }
    .clean-span-4 { grid-column: span 4 / span 4; }
    .clean-span-6 { grid-column: span 6 / span 6; }
    .clean-span-12 { grid-column: span 12 / span 12; }

    .clean-input, .clean-textarea { width: 100%; border: 1px solid #93A6A6; background: #ffffff; color: #091B1B; font-size: 12px; outline: none; }
    .clean-input { height: 31px; padding: 0 8px; }
    .clean-textarea { padding: 8px; resize: vertical; }
    .clean-input:focus, .clean-textarea:focus { border-color: #069495; box-shadow: inset 0 0 0 1px #93F5F4; }
    .clean-input-group { display: grid; grid-template-columns: 1fr auto; }
    .clean-small-button, .clean-secondary-button, .clean-primary-button, .clean-danger-button { height: 31px; border: 1px solid #93A6A6; padding: 0 10px; font-size: 11px; font-weight: 400; white-space: nowrap; }
    .clean-small-button { background: #F8FAFC; color: #091B1B; }
    .clean-small-button:hover:not(:disabled), .clean-secondary-button:hover:not(:disabled) { background: #E2E9E9; }
    .clean-primary-button { background: #069495; border-color: #057B7C; color: #ffffff; }
    .clean-primary-button:hover:not(:disabled) { background: #057B7C; }
    .clean-secondary-button { background: #ffffff; color: #091B1B; }
    .clean-danger-button { background: #ffffff; border-color: #DC2626; color: #B91C1C; }
    .clean-danger-button:hover:not(:disabled) { background: #FEE2E2; }

    .clean-section-check, .clean-check-card { min-height: 31px; border: 1px solid #CBD6D6; background: #F8FAFC; padding: 0 8px; display: flex; align-items: center; gap: 7px; font-size: 12px; font-weight: 400; color: #324444; }
    .clean-section-check { display: inline-flex; margin-bottom: 8px; }
    .clean-rules-grid { display: grid; grid-template-columns: repeat(5, minmax(120px, 1fr)); gap: 8px; }
    .clean-note { border: 1px solid #F59E0B; background: #FFFBEB; color: #92400E; padding: 8px 9px; font-size: 12px; }
    .clean-list-stack { display: flex; flex-direction: column; gap: 8px; }
    .clean-empty-line { color: #637877; font-size: 12px; }
    .clean-barcode-row { display: grid; grid-template-columns: 1fr 180px 90px; gap: 8px; }

    .clean-section-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 10px; align-items: start; }
    .clean-section-disabled { opacity: 0.45; pointer-events: none; }
    fieldset { border: 0; margin: 0; padding: 0; min-width: 0; }
    .compact-check { min-height: 29px; margin-bottom: 7px; }
    .compact-stack { gap: 6px; }
    .compact-note { margin-top: 8px; padding: 7px 8px; font-size: 11px; }
    .compact-price-row { border: 1px solid #CBD6D6; background: #F8FAFC; }
    .compact-price-title { min-height: 29px; border-bottom: 1px solid #E2E9E9; padding: 5px 7px; display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 11px; color: #324444; }
    .compact-price-grid { padding: 7px; display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 6px; }
    .compact-price-grid .clean-input { height: 29px; font-size: 11px; }
    .compact-check-card { min-height: 29px; border: 1px solid #CBD6D6; background: #FFFFFF; padding: 0 8px; display: flex; align-items: center; gap: 6px; font-size: 11px; color: #324444; }
    .compact-remove { height: 25px; padding: 0 8px; font-size: 10px; }

    .clean-modal-footer { min-height: 52px; flex-shrink: 0; border-top: 1px solid #CBD6D6; background: #F8FAFC; padding: 9px 12px; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
    .compact-modal-footer { min-height: 48px; }
    .clean-modal-status { font-size: 12px; color: #637877; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .clean-footer-actions { display: flex; align-items: center; justify-content: flex-end; gap: 8px; }
    button:disabled, input:disabled, select:disabled, textarea:disabled { opacity: 0.55; cursor: not-allowed; }

    .inventory-compact-summary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
    .inventory-compact-summary > div { border: 1px solid #CBD6D6; background: #F8FAFC; padding: 9px; min-height: 48px; }
    .inventory-compact-summary span { display: block; font-size: 10px; color: #637877; text-transform: uppercase; margin-bottom: 4px; }
    .inventory-compact-summary strong { display: block; font-size: 11px; color: #091B1B; font-weight: 500; }


    .clean-form-hero { display: grid; grid-template-columns: minmax(260px, 1fr) repeat(3, minmax(150px, 190px)); gap: 8px; margin-bottom: 10px; }
    .clean-hero-title { border: 1px solid #BAC8C8; background: linear-gradient(135deg, #FFFFFF 0%, #EAF7F4 100%); padding: 9px 10px; min-height: 64px; display: flex; flex-direction: column; justify-content: center; }
    .clean-hero-title strong { display: block; color: #004141; font-size: 15px; font-weight: 800; line-height: 1.15; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .clean-hero-title small { color: #637877; font-size: 10px; font-weight: 700; margin-top: 3px; text-transform: uppercase; letter-spacing: 0.12em; }
    .clean-hero-kicker { color: #057B7C; font-size: 9px; font-weight: 900; text-transform: uppercase; letter-spacing: 0.22em; margin-bottom: 4px; }
    .clean-hero-card { border: 1px solid #BAC8C8; background: #FFFFFF; min-height: 64px; padding: 9px 10px; display: flex; flex-direction: column; justify-content: center; box-shadow: inset 0 -3px 0 #E2E9E9; }
    .clean-hero-card span { color: #637877; font-size: 9px; font-weight: 900; text-transform: uppercase; letter-spacing: 0.16em; }
    .clean-hero-card strong { color: #004141; font-size: 13px; font-weight: 800; margin-top: 5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .appetising-stock-form .clean-section { border-color: #BAC8C8; box-shadow: inset 0 1px 0 rgba(255,255,255,0.75); }
    .appetising-stock-form .clean-section-header { background: #FFFFFF; border-left: 4px solid #00BCBC; }
    .appetising-stock-form .clean-section-header h3 { color: #004141; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; }
    .appetising-stock-form .clean-input, .appetising-stock-form .clean-textarea { border-color: #ACB8B8; background: #FFFFFF; }
    .appetising-stock-form .clean-input:focus, .appetising-stock-form .clean-textarea:focus { border-color: #0AA7A7; box-shadow: inset 0 0 0 1px #00BCBC; }
    .inventory-rule-grid { grid-template-columns: minmax(0, 1.15fr) minmax(360px, 0.85fr); }
    .inventory-rules-compact { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .clean-rule-tile { justify-content: flex-start; min-height: 34px; background: #FFFFFF; }
    .clean-rule-tile input { accent-color: #069495; }

    @media (max-width: 1100px) {
      .clean-span-2, .clean-span-3, .clean-span-4 { grid-column: span 6 / span 6; }
      .clean-rules-grid { grid-template-columns: repeat(2, minmax(120px, 1fr)); }
      .clean-section-grid, .inventory-rule-grid { grid-template-columns: 1fr; }
      .clean-form-hero { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .clean-hero-title { grid-column: span 2 / span 2; }
      .compact-price-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }

    @media (max-width: 700px) {
      .clean-product-modal { width: 99vw; height: 98vh; }
      .clean-grid { grid-template-columns: 1fr; }
      .clean-span-2, .clean-span-3, .clean-span-4, .clean-span-6, .clean-span-12 { grid-column: span 1 / span 1; }
      .clean-rules-grid, .inventory-rules-compact, .compact-price-grid, .clean-barcode-row, .inventory-compact-summary, .clean-form-hero { grid-template-columns: 1fr; }
      .clean-hero-title { grid-column: span 1 / span 1; }
      .clean-modal-footer { align-items: stretch; flex-direction: column; }
      .clean-footer-actions { width: 100%; }
      .clean-footer-actions button { flex: 1; }
    }
  `}</style>
);

export default InventoryManagementModal;

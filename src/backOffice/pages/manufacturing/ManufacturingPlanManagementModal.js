import React, { useEffect, useMemo, useState } from "react";
import {
  BarChart3,
  CheckCircle2,
  ClipboardList,
  Factory,
  FileText,
  Layers,
  PackageSearch,
  Plus,
  RefreshCw,
  Search,
  ShieldCheck,
  Trash2,
  Truck,
  XCircle,
} from "lucide-react";

import FullScreenModal from "../../../components/FullScreenModal";
import { requestEnterpriseConfirmation } from "../../../components/EnterpriseConfirmHost";
import { getProducts } from "../../../services/ProductService";
import { getBaseCurrencies } from "../../../services/CurrencyService";
import {
  approveProductionPlan,
  calculateProductionPlanMaterials,
  cancelProductionPlan,
  convertProductionPlanToOrders,
  getProductionPlan,
  getProductionPlans,
  getProductionRecipes,
  saveProductionPlan,
} from "../../../services/ManufacturingService";

import ManufacturingProductLookupModal from "./ManufacturingProductLookupModal";

import {
  ActionButton,
  CostBar,
  EmptyBlock,
  FormInput,
  FormSelect,
  GridTd,
  GridTh,
  IconButton,
  LoadingPill,
  MetricCard,
  MiniBar,
  MiniInput,
  Notice,
  Panel,
  ReadinessLine,
  SectionTitle,
  SmallButton,
  SmallMetric,
  StatusBadge,
  getBranchId,
  getErrorMessage,
  getPayloadData,
  getProductCode,
  getProductName,
  getProductStock,
  moneyNumber,
  normalizeRows,
  numberValue,
  qtyNumber,
  safeDate,
  statusLabel,
  today,
  todayPlusDays,
  useNotice,
} from "./ManufacturingShared";

const FINISHED_PRODUCT_TYPES = ["finished_good", "semi_finished"];

const emptyPlanForm = (branchId = "", currency = "") => ({
  branch_id: branchId,
  name: "",
  planning_basis: "manual",
  period_start: today(),
  period_end: todayPlusDays(7),
  currency: currency || "",
});

const normalizeProductType = (type) =>
  String(type || "")
    .trim()
    .toLowerCase()
    .replaceAll(" ", "_")
    .replaceAll("-", "_");

const productMatchesType = (product, allowedTypes) => {
  const type = normalizeProductType(
    product?.product_type ||
      product?.type ||
      product?.category?.product_type ||
      product?.metadata?.product_type
  );

  return allowedTypes.includes(type);
};

const getPlanItems = (plan) => {
  if (Array.isArray(plan?.items)) return plan.items;
  if (Array.isArray(plan?.production_plan_items)) return plan.production_plan_items;
  return [];
};

const getRequirements = (plan) => {
  if (Array.isArray(plan?.material_requirements)) return plan.material_requirements;
  if (Array.isArray(plan?.materialRequirements)) return plan.materialRequirements;
  return [];
};

const getOrders = (plan) => {
  if (Array.isArray(plan?.production_orders)) return plan.production_orders;
  if (Array.isArray(plan?.productionOrders)) return plan.productionOrders;
  return [];
};

const itemProductName = (row) =>
  row?.finished_product?.name ||
  row?.finishedProduct?.name ||
  row?.metadata?.product_name ||
  row?.finished_product_name ||
  "-";

const itemRecipeName = (row) =>
  row?.recipe?.name ||
  row?.production_recipe?.name ||
  row?.recipe_name ||
  "-";

const reqProductName = (row) =>
  row?.product?.name ||
  row?.product_name ||
  row?.metadata?.product_name ||
  "-";

const orderProductName = (row) =>
  row?.finished_product?.name ||
  row?.finishedProduct?.name ||
  row?.metadata?.product_name ||
  "-";

const getDefaultCurrency = (rows = []) =>
  rows?.[0]?.code || rows?.[0]?.currency || "USD";

const getRecipeCost = (recipe) =>
  numberValue(
    recipe?.metadata?.frontend_total_cost ||
      recipe?.metadata?.frontend_material_cost ||
      recipe?.metadata?.material_cost ||
      recipe?.metadata?.total_cost ||
      recipe?.standard_total_cost ||
      recipe?.planned_total_cost ||
      recipe?.total_cost ||
      0
  );

const getRecipeUnitCost = (recipe) => {
  const batch = Math.max(numberValue(recipe?.batch_output_quantity), 1);

  const direct = numberValue(
    recipe?.metadata?.frontend_unit_cost ||
      recipe?.metadata?.frontend_cost_per_unit ||
      recipe?.metadata?.cost_per_unit ||
      recipe?.standard_unit_cost ||
      recipe?.unit_cost ||
      0
  );

  return direct > 0 ? direct : getRecipeCost(recipe) / batch;
};

export default function ManufacturingPlanManagementModal({
  open,
  onClose,
  currentUser = null,
}) {
  const branchId = getBranchId(currentUser);

  const [plans, setPlans] = useState([]);
  const [selectedPlanId, setSelectedPlanId] = useState(null);
  const [selectedPlan, setSelectedPlan] = useState(null);

  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [basisFilter, setBasisFilter] = useState("");
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");

  const [activeTab, setActiveTab] = useState("lines");
  const [planFormOpen, setPlanFormOpen] = useState(false);

  const [loading, setLoading] = useState(false);

  const { notice, showNotice } = useNotice();

  const loadPlans = async (selectFirst = false) => {
    try {
      setLoading(true);

      const params = {
        branch_id: branchId || undefined,
        per_page: 100,
      };

      if (statusFilter) params.status = statusFilter;
      if (dateFrom) params.date_from = dateFrom;
      if (dateTo) params.date_to = dateTo;

      const res = await getProductionPlans(params);
      const rows = normalizeRows(res);

      setPlans(rows);

      if (selectFirst && rows.length && !selectedPlanId) {
        await loadPlanDetails(rows[0].id, false);
        return;
      }

      if (selectedPlanId && rows.some((row) => row.id === selectedPlanId)) {
        await loadPlanDetails(selectedPlanId, false);
      }
    } catch (error) {
      showNotice(getErrorMessage(error, "Failed to load production plans."), "error");
    } finally {
      setLoading(false);
    }
  };

  const loadPlanDetails = async (planId, notify = false) => {
    if (!planId) return;

    try {
      setLoading(true);

      const res = await getProductionPlan(planId);
      const data = getPayloadData(res);

      setSelectedPlan(data);
      setSelectedPlanId(planId);

      if (notify) showNotice("Plan details refreshed.", "success");
    } catch (error) {
      showNotice(
        getErrorMessage(error, "Failed to load production plan details."),
        "error"
      );
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (!open) return;
    loadPlans(true);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  useEffect(() => {
    if (!open) return;

    const timer = window.setTimeout(() => {
      loadPlans(false);
    }, 350);

    return () => window.clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusFilter, dateFrom, dateTo]);

  const filteredPlans = useMemo(() => {
    const q = search.trim().toLowerCase();

    return plans.filter((plan) => {
      const matchesSearch =
        !q ||
        String(plan.plan_number || "").toLowerCase().includes(q) ||
        String(plan.name || "").toLowerCase().includes(q) ||
        String(plan.status || "").toLowerCase().includes(q) ||
        String(plan.planning_basis || "").toLowerCase().includes(q);

      const matchesBasis = !basisFilter || plan.planning_basis === basisFilter;

      return matchesSearch && matchesBasis;
    });
  }, [plans, search, basisFilter]);

  const selectedItems = useMemo(() => getPlanItems(selectedPlan), [selectedPlan]);
  const requirements = useMemo(() => getRequirements(selectedPlan), [selectedPlan]);
  const orders = useMemo(() => getOrders(selectedPlan), [selectedPlan]);

  const maxQty = useMemo(
    () =>
      Math.max(
        ...selectedItems.map((item) => numberValue(item.planned_quantity)),
        0
      ),
    [selectedItems]
  );

  const maxReq = useMemo(
    () =>
      Math.max(
        ...requirements.map((item) => numberValue(item.required_quantity)),
        0
      ),
    [requirements]
  );

  const summary = useMemo(() => {
    const plannedQty = selectedItems.reduce(
      (sum, item) => sum + numberValue(item.planned_quantity),
      0
    );

    const batches = selectedItems.reduce(
      (sum, item) => sum + numberValue(item.planned_batches),
      0
    );

    const plannedCost = selectedItems.reduce(
      (sum, item) =>
        sum +
        numberValue(
          item.planned_total_cost ||
            item.metadata?.planned_total_cost ||
            item.metadata?.planned_material_cost
        ),
      0
    );

    const requirementQty = requirements.reduce(
      (sum, item) => sum + numberValue(item.required_quantity),
      0
    );

    const shortageQty = requirements.reduce(
      (sum, item) => sum + numberValue(item.shortage_quantity),
      0
    );

    return {
      plannedQty,
      batches,
      plannedCost,
      unitCost: plannedQty > 0 ? plannedCost / plannedQty : 0,
      requirementQty,
      shortageQty,
      coverage:
        requirementQty > 0
          ? ((requirementQty - shortageQty) / requirementQty) * 100
          : 0,
    };
  }, [selectedItems, requirements]);

  const runPlanAction = async (action) => {
    if (!selectedPlan?.id) {
      showNotice("Select a production plan first.", "error");
      return;
    }

    const map = {
      approve: () => approveProductionPlan(selectedPlan.id),
      calculate: () => calculateProductionPlanMaterials(selectedPlan.id),
      convert: () => convertProductionPlanToOrders(selectedPlan.id),
      cancel: () => cancelProductionPlan(selectedPlan.id),
    };

    if (action === "cancel" && !await requestEnterpriseConfirmation({
      title: "Cancel Production Plan",
      message: "Cancel this production plan?",
      confirmLabel: "Cancel plan",
    })) {
      return;
    }

    try {
      setLoading(true);

      const res = await map[action]();

      showNotice(res?.message || "Action completed.", "success");

      await loadPlans(false);
      await loadPlanDetails(selectedPlan.id, false);
    } catch (error) {
      showNotice(getErrorMessage(error, "Plan action failed."), "error");
    } finally {
      setLoading(false);
    }
  };

  const clearFilters = () => {
    setSearch("");
    setStatusFilter("");
    setBasisFilter("");
    setDateFrom("");
    setDateTo("");
  };

  const handlePlanSaved = async (savedPlan = null) => {
    setPlanFormOpen(false);

    await loadPlans(false);

    if (savedPlan?.id) {
      await loadPlanDetails(savedPlan.id, false);
    }
  };

  if (!open) return null;

  return (
    <FullScreenModal
      open={open}
      onClose={onClose}
      title="PRODUCTION PLANNING"
      subtitle="Plan demand, calculate materials, approve and convert to production orders"
    >
      <Notice notice={notice} />
      <LoadingPill loading={loading} />

      <div className="h-[calc(100vh-86px)] min-h-0 overflow-hidden bg-[#EEF1F1] p-2 text-[#1C2C2B]">
        <div className="flex h-full min-h-0 flex-col border border-[#AAB5B5] bg-white">
          <div className="border-b border-[#AAB5B5] bg-[#F5F7FA]">
            <div className="flex h-9 items-center justify-between border-b border-[#CFD9D8] px-2">
              <div className="flex items-center gap-2">
                <div className="text-[13px] font-semibold text-[#0C1B1B]">
                  Planning workbench
                </div>
                <div className="h-4 border-l border-[#AAB5B5]" />
                <div className="text-[10px] font-medium uppercase text-[#627474]">
                  New production plan opens as a modal
                </div>
              </div>

              <div className="flex gap-1">
                <ActionButton
                  label="New Plan"
                  icon={Plus}
                  onClick={() => setPlanFormOpen(true)}
                  primary
                />
                <ActionButton
                  label="Refresh"
                  icon={RefreshCw}
                  onClick={() => loadPlans(false)}
                />
              </div>
            </div>

            <div className="grid grid-cols-[minmax(250px,1fr)_150px_170px_140px_140px_auto] gap-2 px-2 py-2">
              <div>
                <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">
                  Search
                </div>
                <div className="relative">
                  <Search className="absolute left-2 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-[#627474]" />
                  <input
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder="Search plan..."
                    className="h-8 w-full border border-[#AAB5B5] bg-white pl-8 pr-2 text-[11px] outline-none"
                  />
                </div>
              </div>

              <FormSelect
                label="Status"
                value={statusFilter}
                onChange={setStatusFilter}
                options={[
                  ["", "All"],
                  ["draft", "Draft"],
                  ["approved", "Approved"],
                  ["converted_to_orders", "Converted"],
                  ["cancelled", "Cancelled"],
                ]}
              />

              <FormSelect
                label="Basis"
                value={basisFilter}
                onChange={setBasisFilter}
                options={[
                  ["", "All"],
                  ["manual", "Manual"],
                  ["sales_forecast", "Sales Forecast"],
                  ["sales_order", "Sales Order"],
                  ["minimum_stock", "Minimum Stock"],
                  ["reorder_level", "Reorder Level"],
                ]}
              />

              <FormInput
                label="From"
                type="date"
                value={dateFrom}
                onChange={setDateFrom}
              />

              <FormInput
                label="To"
                type="date"
                value={dateTo}
                onChange={setDateTo}
              />

              <div className="pt-5">
                <SmallButton onClick={clearFilters}>Clear</SmallButton>
              </div>
            </div>
          </div>

          <div className="grid min-h-[260px] grid-cols-[minmax(430px,50%)_minmax(500px,50%)] border-b border-[#AAB5B5]">
            <section className="min-w-0 border-r border-[#AAB5B5]">
              <SectionTitle title="Production Plans" subtitle="Register" />

              <div className="h-[230px] overflow-auto">
                <table className="w-full min-w-[780px] border-collapse text-left text-[11px]">
                  <thead className="sticky top-0 z-10 bg-[#0E4D4D] text-white">
                    <tr>
                      <GridTh>#</GridTh>
                      <GridTh>Plan No</GridTh>
                      <GridTh>Name</GridTh>
                      <GridTh>Status</GridTh>
                      <GridTh>Basis</GridTh>
                      <GridTh>Period</GridTh>
                    </tr>
                  </thead>

                  <tbody>
                    {filteredPlans.length === 0 ? (
                      <tr>
                        <td
                          colSpan={6}
                          className="px-3 py-10 text-center text-[#627474]"
                        >
                          No plans found.
                        </td>
                      </tr>
                    ) : (
                      filteredPlans.map((plan, index) => (
                        <tr
                          key={plan.id}
                          onClick={() => loadPlanDetails(plan.id)}
                          className={`cursor-pointer border-b border-[#CFD9D8] ${
                            selectedPlanId === plan.id
                              ? "bg-[#49A942] text-white"
                              : "bg-white hover:bg-[#F8FAFC]"
                          }`}
                        >
                          <GridTd>{index + 1}</GridTd>
                          <GridTd strong>{plan.plan_number || "-"}</GridTd>
                          <GridTd strong>{plan.name || "-"}</GridTd>
                          <GridTd>
                            <StatusBadge status={plan.status} />
                          </GridTd>
                          <GridTd>
                            {String(plan.planning_basis || "-").replaceAll(
                              "_",
                              " "
                            )}
                          </GridTd>
                          <GridTd>
                            {safeDate(plan.period_start)} to{" "}
                            {safeDate(plan.period_end)}
                          </GridTd>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            </section>

            <section className="min-w-0 bg-[#F7F8FA]">
              <SectionTitle title="Plan Control" subtitle="Selected plan" />

              {!selectedPlan ? (
                <div className="h-[230px]">
                  <EmptyBlock
                    text="Select a plan to view details."
                    icon={PackageSearch}
                  />
                </div>
              ) : (
                <div className="h-[230px] overflow-auto p-2">
                  <div className="grid grid-cols-3 gap-2">
                    <SmallMetric
                      label="Plan"
                      value={selectedPlan.plan_number || "-"}
                    />
                    <SmallMetric
                      label="Status"
                      value={statusLabel(selectedPlan.status)}
                    />
                    <SmallMetric label="Orders" value={orders.length} />
                    <SmallMetric
                      label="Planned Qty"
                      value={qtyNumber(summary.plannedQty)}
                    />
                    <SmallMetric
                      label="Batches"
                      value={qtyNumber(summary.batches)}
                    />
                    <SmallMetric
                      label="Shortage"
                      value={qtyNumber(summary.shortageQty)}
                      danger={summary.shortageQty > 0}
                    />
                  </div>

                  <div className="mt-2 grid grid-cols-4 gap-2">
                    <MetricCard
                      title="Items"
                      value={selectedItems.length}
                      icon={Factory}
                    />
                    <MetricCard
                      title="Materials"
                      value={requirements.length}
                      icon={Layers}
                    />
                    <MetricCard
                      title="Cost"
                      value={moneyNumber(summary.plannedCost)}
                      icon={FileText}
                    />
                    <MetricCard
                      title="Coverage"
                      value={`${summary.coverage.toFixed(1)}%`}
                      icon={BarChart3}
                      danger={summary.shortageQty > 0}
                    />
                  </div>

                  <div className="mt-2 flex flex-wrap gap-1 border border-[#CFD9D8] bg-white p-1.5">
                    <ActionButton
                      label="Reload"
                      icon={RefreshCw}
                      onClick={() => loadPlanDetails(selectedPlan.id, true)}
                    />
                    <ActionButton
                      label="Calculate"
                      icon={BarChart3}
                      onClick={() => runPlanAction("calculate")}
                      primary
                    />
                    <ActionButton
                      label="Approve"
                      icon={ShieldCheck}
                      onClick={() => runPlanAction("approve")}
                      primary
                    />
                    <ActionButton
                      label="Convert"
                      icon={Truck}
                      onClick={() => runPlanAction("convert")}
                      primary
                    />
                    <ActionButton
                      label="Cancel"
                      icon={XCircle}
                      onClick={() => runPlanAction("cancel")}
                      danger
                    />
                  </div>
                </div>
              )}
            </section>
          </div>

          <div className="flex h-8 items-end border-b border-[#AAB5B5] bg-[#F5F7FA] px-2">
            {[
              ["lines", "Plan Lines"],
              ["materials", "Materials"],
              ["orders", "Converted Orders"],
              ["visuals", "Visuals"],
            ].map(([key, label]) => (
              <button
                key={key}
                type="button"
                onClick={() => setActiveTab(key)}
                className={`h-7 border border-b-0 px-4 text-[11px] font-semibold ${
                  activeTab === key
                    ? "border-[#31B44B] bg-[#31B44B] text-white"
                    : "border-[#AAB5B5] bg-white text-[#324443] hover:bg-[#EEF2F2]"
                }`}
              >
                {label}
              </button>
            ))}
          </div>

          <div className="min-h-0 flex-1 overflow-hidden">
            {activeTab === "lines" && (
              <LinesTab selectedItems={selectedItems} maxQty={maxQty} />
            )}
            {activeTab === "materials" && (
              <MaterialsTab requirements={requirements} maxReq={maxReq} />
            )}
            {activeTab === "orders" && <OrdersTab orders={orders} />}
            {activeTab === "visuals" && (
              <VisualsTab
                selectedPlan={selectedPlan}
                selectedItems={selectedItems}
                requirements={requirements}
                summary={summary}
              />
            )}
          </div>
        </div>
      </div>

      <NewProductionPlanModal
        open={planFormOpen}
        onClose={() => setPlanFormOpen(false)}
        onSaved={handlePlanSaved}
        currentUser={currentUser}
      />

      <NoRadiusStyles />
    </FullScreenModal>
  );
}

function NewProductionPlanModal({
  open,
  onClose,
  onSaved,
  currentUser = null,
}) {
  const branchId = getBranchId(currentUser);

  const [baseCurrencies, setBaseCurrencies] = useState([]);
  const [form, setForm] = useState(emptyPlanForm(branchId, "USD"));

  const [items, setItems] = useState([]);
  const [recipes, setRecipes] = useState([]);
  const [products, setProducts] = useState([]);

  const [lookupOpen, setLookupOpen] = useState(false);
  const [productSearch, setProductSearch] = useState("");

  const [activeTab, setActiveTab] = useState("items");
  const [loading, setLoading] = useState(false);

  const { notice, showNotice } = useNotice();

  const defaultCurrency = useMemo(
    () => form.currency || getDefaultCurrency(baseCurrencies),
    [form.currency, baseCurrencies]
  );

  const totals = useMemo(() => {
    const plannedQty = items.reduce(
      (sum, line) => sum + numberValue(line.planned_quantity),
      0
    );

    const forecastQty = items.reduce(
      (sum, line) => sum + numberValue(line.forecast_quantity),
      0
    );

    const stockQty = items.reduce(
      (sum, line) => sum + numberValue(line.current_stock_quantity),
      0
    );

    const safetyQty = items.reduce(
      (sum, line) => sum + numberValue(line.safety_stock_quantity),
      0
    );

    const materialCost = items.reduce(
      (sum, line) =>
        sum + numberValue(line.planned_quantity) * numberValue(line.unit_cost),
      0
    );

    const labourCost = items.reduce(
      (sum, line) => sum + numberValue(line.planned_labour_cost),
      0
    );

    const overheadCost = items.reduce(
      (sum, line) => sum + numberValue(line.planned_overhead_cost),
      0
    );

    const totalCost = materialCost + labourCost + overheadCost;

    return {
      plannedQty,
      forecastQty,
      stockQty,
      safetyQty,
      materialCost,
      labourCost,
      overheadCost,
      totalCost,
      unitCost: plannedQty > 0 ? totalCost / plannedQty : 0,
    };
  }, [items]);

  useEffect(() => {
    if (!open) return;

    setItems([]);
    setProducts([]);
    setProductSearch("");
    setActiveTab("items");

    loadInitialData();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const loadInitialData = async () => {
    try {
      setLoading(true);

      const [currencyRes, recipeRes] = await Promise.all([
        getBaseCurrencies(),
        getProductionRecipes({
          branch_id: branchId || undefined,
          per_page: 100,
          is_active: true,
        }),
      ]);

      const currencyRows = normalizeRows(currencyRes);
      const currency = getDefaultCurrency(currencyRows);

      setBaseCurrencies(currencyRows);
      setRecipes(normalizeRows(recipeRes));
      setForm(emptyPlanForm(branchId, currency));
    } catch (error) {
      showNotice(getErrorMessage(error, "Failed to load plan setup."), "error");
    } finally {
      setLoading(false);
    }
  };

  const loadRecipes = async () => {
    try {
      const res = await getProductionRecipes({
        branch_id: branchId || undefined,
        per_page: 100,
        is_active: true,
      });

      setRecipes(normalizeRows(res));
    } catch (error) {
      showNotice(getErrorMessage(error, "Failed to load recipes."), "error");
    }
  };

  const searchProducts = async (q = "") => {
    try {
      const res = await getProducts({
        q,
        search: q,
        branch_id: branchId || undefined,
        per_page: 100,
        limit: 100,
        is_active: true,
      });

      const rows = normalizeRows(res);

      setProducts(
        rows.filter((product) =>
          productMatchesType(product, FINISHED_PRODUCT_TYPES)
        )
      );
    } catch (error) {
      showNotice(
        getErrorMessage(error, "Failed to search finished products."),
        "error"
      );
    }
  };

  const openProductLookup = () => {
    setProductSearch("");
    setProducts([]);
    setLookupOpen(true);

    window.setTimeout(() => {
      searchProducts("");
    }, 0);
  };

  const selectProduct = (product) => {
    const productRecipes = recipes.filter((recipe) => {
      const recipeProductId =
        recipe.finished_product_id ||
        recipe.finished_product?.id ||
        recipe.finishedProduct?.id;

      return String(recipeProductId) === String(product.id);
    });

    const recipe = productRecipes[0] || null;

    const stock = getProductStock(product);
    const forecast = 0;
    const safety = 0;
    const planned = Math.max(forecast + safety - stock, 0) || 1;
    const unitCost = recipe ? getRecipeUnitCost(recipe) : 0;

    setItems((prev) => [
      ...prev,
      {
        line_id: `${product.id}-${Date.now()}`,
        finished_product_id: product.id,
        finished_product_name: getProductName(product),
        finished_product_code: getProductCode(product),
        production_recipe_id: recipe?.id || "",
        forecast_quantity: forecast,
        current_stock_quantity: stock,
        safety_stock_quantity: safety,
        planned_quantity: planned,
        planned_labour_cost: 0,
        planned_overhead_cost: 0,
        unit_cost: unitCost,
        batch_size: recipe?.batch_output_quantity || 1,
        required_by: todayPlusDays(1),
      },
    ]);

    setLookupOpen(false);
  };

  const updateLine = (lineId, patch) => {
    setItems((prev) =>
      prev.map((line) =>
        line.line_id === lineId ? { ...line, ...patch } : line
      )
    );
  };

  const removeLine = (lineId) => {
    setItems((prev) => prev.filter((line) => line.line_id !== lineId));
  };

  const selectRecipeForLine = (line, recipeId) => {
    const recipe = recipes.find((item) => item.id === recipeId);

    updateLine(line.line_id, {
      production_recipe_id: recipeId,
      unit_cost: recipe ? getRecipeUnitCost(recipe) : 0,
      batch_size: recipe?.batch_output_quantity || line.batch_size || 1,
    });
  };

  const recalcLinePlannedQty = (line) => {
    const planned = Math.max(
      numberValue(line.forecast_quantity) +
        numberValue(line.safety_stock_quantity) -
        numberValue(line.current_stock_quantity),
      0
    );

    updateLine(line.line_id, {
      planned_quantity: planned,
    });
  };

  const savePlan = async () => {
    if (!form.name.trim()) {
      showNotice("Plan name is required.", "error");
      return;
    }

    if (!items.length) {
      showNotice("Add at least one finished good to plan.", "error");
      return;
    }

    if (items.some((line) => !line.production_recipe_id)) {
      showNotice("Each planned product must have a BOM / recipe selected.", "error");
      return;
    }

    if (!form.currency) {
      showNotice("Currency is required.", "error");
      return;
    }

    const payload = {
      branch_id: form.branch_id || branchId,
      name: form.name.trim(),
      planning_basis: form.planning_basis,
      period_start: form.period_start,
      period_end: form.period_end,
      metadata: {
        currency: form.currency,
        frontend_total_cost: totals.totalCost,
        frontend_unit_cost: totals.unitCost,
        frontend_material_cost: totals.materialCost,
        frontend_labour_cost: totals.labourCost,
        frontend_overhead_cost: totals.overheadCost,
      },
      items: items.map((line) => ({
        finished_product_id: line.finished_product_id,
        production_recipe_id: line.production_recipe_id,
        forecast_quantity: numberValue(line.forecast_quantity),
        current_stock_quantity: numberValue(line.current_stock_quantity),
        safety_stock_quantity: numberValue(line.safety_stock_quantity),
        planned_quantity: numberValue(line.planned_quantity),
        planned_labour_cost: numberValue(line.planned_labour_cost),
        planned_overhead_cost: numberValue(line.planned_overhead_cost),
        required_by: line.required_by || null,
        metadata: {
          currency: form.currency,
          product_code: line.finished_product_code,
          product_name: line.finished_product_name,
          frontend_recipe_unit_cost: numberValue(line.unit_cost),
          frontend_batch_size: numberValue(line.batch_size),
        },
      })),
    };

    try {
      setLoading(true);

      const res = await saveProductionPlan(payload);
      const saved = getPayloadData(res);

      showNotice(res?.message || "Production plan saved.", "success");

      setForm(emptyPlanForm(branchId, defaultCurrency));
      setItems([]);

      onSaved?.(saved);
    } catch (error) {
      showNotice(getErrorMessage(error, "Failed to save production plan."), "error");
    } finally {
      setLoading(false);
    }
  };

  if (!open) return null;

  return (
    <FullScreenModal
      open={open}
      onClose={onClose}
      title="NEW PRODUCTION PLAN"
      subtitle="Create a plan from finished goods, semi-finished goods and BOM recipes"
    >
      <Notice notice={notice} />
      <LoadingPill loading={loading} />

      <div className="h-[calc(100vh-86px)] min-h-0 overflow-hidden bg-[#EEF1F1] p-2 text-[#1C2C2B]">
        <div className="flex h-full min-h-0 flex-col border border-[#AAB5B5] bg-white">
          <div className="border-b border-[#AAB5B5] bg-[#F5F7FA]">
            <div className="flex h-9 items-center justify-between border-b border-[#CFD9D8] px-2">
              <div className="text-[13px] font-semibold text-[#0C1B1B]">
                Plan creation workbench
              </div>

              <div className="flex gap-1">
                <ActionButton
                  onClick={loadRecipes}
                  label="Refresh BOMs"
                  icon={RefreshCw}
                />
                <ActionButton
                  onClick={savePlan}
                  label="Save Plan"
                  icon={CheckCircle2}
                  primary
                />
              </div>
            </div>

            <div className="grid grid-cols-6 gap-2 px-2 py-2">
              <FormInput
                label="Plan Name"
                value={form.name}
                onChange={(value) =>
                  setForm((prev) => ({ ...prev, name: value }))
                }
              />

              <FormSelect
                label="Basis"
                value={form.planning_basis}
                onChange={(value) =>
                  setForm((prev) => ({ ...prev, planning_basis: value }))
                }
                options={[
                  ["manual", "Manual"],
                  ["sales_forecast", "Sales Forecast"],
                  ["sales_order", "Sales Order"],
                  ["minimum_stock", "Minimum Stock"],
                  ["reorder_level", "Reorder Level"],
                ]}
              />

              <FormInput
                label="Period Start"
                type="date"
                value={form.period_start}
                onChange={(value) =>
                  setForm((prev) => ({ ...prev, period_start: value }))
                }
              />

              <FormInput
                label="Period End"
                type="date"
                value={form.period_end}
                onChange={(value) =>
                  setForm((prev) => ({ ...prev, period_end: value }))
                }
              />

              <FormSelect
                label="Currency"
                value={form.currency}
                onChange={(value) =>
                  setForm((prev) => ({ ...prev, currency: value }))
                }
                options={[
                  ["", "Select"],
                  ...(baseCurrencies.length
                    ? baseCurrencies.map((currency) => [
                        currency.code || currency.currency,
                        `${currency.code || currency.currency}${
                          currency.name ? ` - ${currency.name}` : ""
                        }`,
                      ])
                    : [["USD", "USD"]]),
                ]}
              />

              <label className="block">
                <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">
                  Add Product
                </div>
                <button
                  type="button"
                  onClick={openProductLookup}
                  className="h-8 w-full border border-[#027A48] bg-[#027A48] px-2 text-[11px] font-semibold uppercase text-white"
                >
                  Add Finished Good
                </button>
              </label>
            </div>
          </div>

          <div className="grid min-h-[105px] grid-cols-5 gap-2 border-b border-[#AAB5B5] bg-[#F7F8FA] p-2">
            <MetricCard
              title="Forecast"
              value={qtyNumber(totals.forecastQty)}
              icon={Factory}
            />
            <MetricCard
              title="Current Stock"
              value={qtyNumber(totals.stockQty)}
              icon={Layers}
            />
            <MetricCard
              title="Safety"
              value={qtyNumber(totals.safetyQty)}
              icon={Layers}
            />
            <MetricCard
              title="Planned Qty"
              value={qtyNumber(totals.plannedQty)}
              icon={Factory}
            />
            <MetricCard
              title="Plan Cost"
              value={`${form.currency || ""} ${moneyNumber(totals.totalCost)}`}
              icon={FileText}
            />
          </div>

          <div className="border-b border-[#A4E5E4] bg-[#F1F8F8] px-2 py-1.5 text-[11px] font-medium text-[#057B7B]">
            Finished product lookup is filtered to finished goods and semi-finished goods only.
            Planned Qty = Forecast + Safety Stock - Current Stock.
          </div>

          <div className="flex h-8 items-end border-b border-[#AAB5B5] bg-[#F5F7FA] px-2">
            {[
              ["items", "Plan Items"],
              ["visuals", "Visuals / Costing"],
            ].map(([key, label]) => (
              <button
                key={key}
                type="button"
                onClick={() => setActiveTab(key)}
                className={`h-7 border border-b-0 px-4 text-[11px] font-semibold ${
                  activeTab === key
                    ? "border-[#31B44B] bg-[#31B44B] text-white"
                    : "border-[#AAB5B5] bg-white text-[#324443] hover:bg-[#EEF2F2]"
                }`}
              >
                {label}
              </button>
            ))}
          </div>

          <div className="min-h-0 flex-1 overflow-hidden">
            {activeTab === "items" ? (
              <PlanDraftItemsTable
                items={items}
                recipes={recipes}
                updateLine={updateLine}
                removeLine={removeLine}
                selectRecipeForLine={selectRecipeForLine}
                recalcLinePlannedQty={recalcLinePlannedQty}
                currency={form.currency}
              />
            ) : (
              <PlanDraftVisuals form={form} items={items} totals={totals} />
            )}
          </div>
        </div>
      </div>

      <ManufacturingProductLookupModal
        open={lookupOpen}
        mode="finished"
        title="Select Finished Good / Semi Finished"
        search={productSearch}
        setSearch={setProductSearch}
        products={products}
        searchProducts={searchProducts}
        onSelect={selectProduct}
        onClose={() => setLookupOpen(false)}
        defaultCurrency={form.currency || defaultCurrency}
      />
    </FullScreenModal>
  );
}

function PlanDraftItemsTable({
  items,
  recipes,
  updateLine,
  removeLine,
  selectRecipeForLine,
  recalcLinePlannedQty,
  currency,
}) {
  return (
    <div className="h-full overflow-auto">
      <table className="w-full min-w-[1450px] border-collapse text-left text-[11px]">
        <thead className="sticky top-0 z-10 bg-[#0E4D4D] text-white">
          <tr>
            <GridTh>Line</GridTh>
            <GridTh>Product</GridTh>
            <GridTh>BOM / Recipe</GridTh>
            <GridTh align="right">Forecast</GridTh>
            <GridTh align="right">Stock</GridTh>
            <GridTh align="right">Safety</GridTh>
            <GridTh align="right">Planned</GridTh>
            <GridTh align="right">Batch Size</GridTh>
            <GridTh align="right">Batches</GridTh>
            <GridTh align="right">BOM Unit Cost</GridTh>
            <GridTh align="right">Labour</GridTh>
            <GridTh align="right">Overhead</GridTh>
            <GridTh>Required By</GridTh>
            <GridTh>Action</GridTh>
          </tr>
        </thead>

        <tbody>
          {items.length === 0 ? (
            <tr>
              <td colSpan={14} className="px-3 py-10 text-center text-[#627474]">
                No plan items added.
              </td>
            </tr>
          ) : (
            items.map((line, index) => {
              const batchSize = Math.max(numberValue(line.batch_size), 1);
              const batches = Math.ceil(
                numberValue(line.planned_quantity) / batchSize
              );

              const lineRecipes = recipes.filter((recipe) => {
                const recipeProductId =
                  recipe.finished_product_id ||
                  recipe.finished_product?.id ||
                  recipe.finishedProduct?.id;

                return String(recipeProductId) === String(line.finished_product_id);
              });

              return (
                <tr
                  key={line.line_id}
                  className="border-b border-[#CFD9D8] bg-white hover:bg-[#F8FAFC]"
                >
                  <GridTd>{index + 1}</GridTd>
                  <GridTd strong>{line.finished_product_name}</GridTd>

                  <GridTd>
                    <select
                      value={line.production_recipe_id}
                      onChange={(event) =>
                        selectRecipeForLine(line, event.target.value)
                      }
                      className="h-8 w-full border border-[#B9C3C3] bg-white px-2 text-[11px]"
                    >
                      <option value="">Select BOM</option>
                      {lineRecipes.map((recipe) => (
                        <option key={recipe.id} value={recipe.id}>
                          {recipe.name}
                        </option>
                      ))}
                    </select>
                  </GridTd>

                  <GridTd right>
                    <MiniInput
                      type="number"
                      value={line.forecast_quantity}
                      onChange={(value) =>
                        updateLine(line.line_id, { forecast_quantity: value })
                      }
                    />
                  </GridTd>

                  <GridTd right>
                    <MiniInput
                      type="number"
                      value={line.current_stock_quantity}
                      onChange={(value) =>
                        updateLine(line.line_id, {
                          current_stock_quantity: value,
                        })
                      }
                    />
                  </GridTd>

                  <GridTd right>
                    <MiniInput
                      type="number"
                      value={line.safety_stock_quantity}
                      onChange={(value) =>
                        updateLine(line.line_id, {
                          safety_stock_quantity: value,
                        })
                      }
                    />
                  </GridTd>

                  <GridTd right>
                    <div className="grid grid-cols-[1fr_auto] gap-1">
                      <MiniInput
                        type="number"
                        value={line.planned_quantity}
                        onChange={(value) =>
                          updateLine(line.line_id, {
                            planned_quantity: value,
                          })
                        }
                      />
                      <SmallButton onClick={() => recalcLinePlannedQty(line)}>
                        Calc
                      </SmallButton>
                    </div>
                  </GridTd>

                  <GridTd right>{qtyNumber(line.batch_size)}</GridTd>
                  <GridTd right>{qtyNumber(batches)}</GridTd>

                  <GridTd right>
                    {currency} {moneyNumber(line.unit_cost)}
                  </GridTd>

                  <GridTd right>
                    <MiniInput
                      type="number"
                      value={line.planned_labour_cost}
                      onChange={(value) =>
                        updateLine(line.line_id, {
                          planned_labour_cost: value,
                        })
                      }
                    />
                  </GridTd>

                  <GridTd right>
                    <MiniInput
                      type="number"
                      value={line.planned_overhead_cost}
                      onChange={(value) =>
                        updateLine(line.line_id, {
                          planned_overhead_cost: value,
                        })
                      }
                    />
                  </GridTd>

                  <GridTd>
                    <MiniInput
                      type="date"
                      value={line.required_by}
                      onChange={(value) =>
                        updateLine(line.line_id, { required_by: value })
                      }
                    />
                  </GridTd>

                  <GridTd>
                    <IconButton
                      icon={Trash2}
                      danger
                      onClick={() => removeLine(line.line_id)}
                    />
                  </GridTd>
                </tr>
              );
            })
          )}
        </tbody>
      </table>
    </div>
  );
}

function PlanDraftVisuals({ form, items, totals }) {
  return (
    <div className="h-full overflow-auto bg-[#F7F8FA] p-2">
      <div className="grid gap-2 lg:grid-cols-4">
        <MetricCard title="Items" value={items.length} icon={Layers} />
        <MetricCard
          title="Planned Qty"
          value={qtyNumber(totals.plannedQty)}
          icon={Factory}
        />
        <MetricCard
          title="Total Cost"
          value={`${form.currency || ""} ${moneyNumber(totals.totalCost)}`}
          icon={FileText}
        />
        <MetricCard
          title="Unit Cost"
          value={`${form.currency || ""} ${moneyNumber(totals.unitCost)}`}
          icon={Factory}
        />
      </div>

      <div className="mt-2 grid gap-2 xl:grid-cols-2">
        <Panel title="Cost Composition" subtitle="Planned production cost">
          <ReadinessLine
            label="Material / BOM Cost"
            ok
            value={`${form.currency} ${moneyNumber(totals.materialCost)}`}
          />
          <ReadinessLine
            label="Direct Labour"
            ok
            value={`${form.currency} ${moneyNumber(totals.labourCost)}`}
          />
          <ReadinessLine
            label="Overhead"
            ok
            value={`${form.currency} ${moneyNumber(totals.overheadCost)}`}
          />
          <ReadinessLine
            label="Total"
            ok
            value={`${form.currency} ${moneyNumber(totals.totalCost)}`}
          />
        </Panel>

        <Panel title="Plan Readiness" subtitle="Before approval and conversion">
          <ReadinessLine
            label="Plan Name"
            ok={!!form.name}
            value={form.name || "Missing"}
          />
          <ReadinessLine
            label="Items"
            ok={items.length > 0}
            value={`${items.length} line(s)`}
          />
          <ReadinessLine
            label="All BOMs Selected"
            ok={!items.some((item) => !item.production_recipe_id)}
            value={
              items.some((item) => !item.production_recipe_id)
                ? "Missing BOM"
                : "Ready"
            }
          />
          <ReadinessLine
            label="Currency"
            ok={!!form.currency}
            value={form.currency || "Missing"}
          />
        </Panel>
      </div>
    </div>
  );
}

function LinesTab({ selectedItems, maxQty }) {
  return (
    <div className="h-full overflow-auto">
      <table className="w-full min-w-[1250px] border-collapse text-left text-[11px]">
        <thead className="sticky top-0 z-10 bg-[#0E4D4D] text-white">
          <tr>
            <GridTh>Line</GridTh>
            <GridTh>Product</GridTh>
            <GridTh>Recipe</GridTh>
            <GridTh align="right">Forecast</GridTh>
            <GridTh align="right">Current Stock</GridTh>
            <GridTh align="right">Safety</GridTh>
            <GridTh align="right">Planned</GridTh>
            <GridTh align="right">Batches</GridTh>
            <GridTh align="right">Unit Cost</GridTh>
            <GridTh align="right">Total Cost</GridTh>
            <GridTh>Visual</GridTh>
          </tr>
        </thead>

        <tbody>
          {selectedItems.length === 0 ? (
            <tr>
              <td colSpan={11} className="px-3 py-10 text-center text-[#627474]">
                No plan selected or no items.
              </td>
            </tr>
          ) : (
            selectedItems.map((item, index) => (
              <tr key={item.id || index} className="border-b border-[#CFD9D8]">
                <GridTd>{index + 1}</GridTd>
                <GridTd strong>{itemProductName(item)}</GridTd>
                <GridTd>{itemRecipeName(item)}</GridTd>
                <GridTd right>{qtyNumber(item.forecast_quantity)}</GridTd>
                <GridTd right>{qtyNumber(item.current_stock_quantity)}</GridTd>
                <GridTd right>{qtyNumber(item.safety_stock_quantity)}</GridTd>
                <GridTd right>{qtyNumber(item.planned_quantity)}</GridTd>
                <GridTd right>{qtyNumber(item.planned_batches)}</GridTd>
                <GridTd right>{moneyNumber(item.planned_unit_cost)}</GridTd>
                <GridTd right>{moneyNumber(item.planned_total_cost)}</GridTd>
                <GridTd>
                  <MiniBar
                    value={numberValue(item.planned_quantity)}
                    max={maxQty}
                  />
                </GridTd>
              </tr>
            ))
          )}
        </tbody>
      </table>
    </div>
  );
}

function MaterialsTab({ requirements, maxReq }) {
  return (
    <div className="h-full overflow-auto">
      <div className="border-b border-[#A4E5E4] bg-[#F1F8F8] px-2 py-1.5 text-[11px] font-medium text-[#057B7B]">
        Material requirement = recipe material quantity × planned batches +
        wastage. Shortage must be cleared before converting to orders.
      </div>

      <table className="w-full min-w-[1050px] border-collapse text-left text-[11px]">
        <thead className="sticky top-0 z-10 bg-[#0E4D4D] text-white">
          <tr>
            <GridTh>Line</GridTh>
            <GridTh>Material</GridTh>
            <GridTh align="right">Required</GridTh>
            <GridTh align="right">Available</GridTh>
            <GridTh align="right">Reserved</GridTh>
            <GridTh align="right">Shortage</GridTh>
            <GridTh>Status</GridTh>
            <GridTh>Visual</GridTh>
          </tr>
        </thead>

        <tbody>
          {requirements.length === 0 ? (
            <tr>
              <td colSpan={8} className="px-3 py-10 text-center text-[#627474]">
                No material requirements calculated.
              </td>
            </tr>
          ) : (
            requirements.map((row, index) => (
              <tr key={row.id || index} className="border-b border-[#CFD9D8]">
                <GridTd>{index + 1}</GridTd>
                <GridTd strong>{reqProductName(row)}</GridTd>
                <GridTd right>{qtyNumber(row.required_quantity)}</GridTd>
                <GridTd right>{qtyNumber(row.available_quantity)}</GridTd>
                <GridTd right>{qtyNumber(row.reserved_quantity)}</GridTd>
                <GridTd right>{qtyNumber(row.shortage_quantity)}</GridTd>
                <GridTd>
                  <StatusBadge status={row.status} />
                </GridTd>
                <GridTd>
                  <MiniBar
                    value={numberValue(row.required_quantity)}
                    max={maxReq}
                    danger={numberValue(row.shortage_quantity) > 0}
                  />
                </GridTd>
              </tr>
            ))
          )}
        </tbody>
      </table>
    </div>
  );
}

function OrdersTab({ orders }) {
  return (
    <div className="h-full overflow-auto">
      <table className="w-full min-w-[900px] border-collapse text-left text-[11px]">
        <thead className="sticky top-0 z-10 bg-[#0E4D4D] text-white">
          <tr>
            <GridTh>#</GridTh>
            <GridTh>Production No</GridTh>
            <GridTh>Product</GridTh>
            <GridTh>Status</GridTh>
            <GridTh align="right">Planned Qty</GridTh>
            <GridTh align="right">Actual Good</GridTh>
          </tr>
        </thead>

        <tbody>
          {orders.length === 0 ? (
            <tr>
              <td colSpan={6} className="px-3 py-10 text-center text-[#627474]">
                No converted production orders yet.
              </td>
            </tr>
          ) : (
            orders.map((order, index) => (
              <tr key={order.id || index} className="border-b border-[#CFD9D8]">
                <GridTd>{index + 1}</GridTd>
                <GridTd strong>{order.production_number || "-"}</GridTd>
                <GridTd>{orderProductName(order)}</GridTd>
                <GridTd>
                  <StatusBadge status={order.status} />
                </GridTd>
                <GridTd right>{qtyNumber(order.planned_output_quantity)}</GridTd>
                <GridTd right>{qtyNumber(order.actual_good_quantity)}</GridTd>
              </tr>
            ))
          )}
        </tbody>
      </table>
    </div>
  );
}

function VisualsTab({ selectedPlan, selectedItems, requirements, summary }) {
  return (
    <div className="h-full overflow-auto bg-[#F7F8FA] p-2">
      {!selectedPlan ? (
        <EmptyBlock text="Select a plan to view visuals." icon={PackageSearch} />
      ) : (
        <>
          <div className="grid gap-2 lg:grid-cols-4">
            <MetricCard
              title="Planned Qty"
              value={qtyNumber(summary.plannedQty)}
              icon={Factory}
            />
            <MetricCard
              title="Batches"
              value={qtyNumber(summary.batches)}
              icon={Layers}
            />
            <MetricCard
              title="Planned Cost"
              value={moneyNumber(summary.plannedCost)}
              icon={FileText}
            />
            <MetricCard
              title="Unit Cost"
              value={moneyNumber(summary.unitCost)}
              icon={ClipboardList}
            />
          </div>

          <div className="mt-2 grid gap-2 xl:grid-cols-2">
            <Panel title="Material Coverage" subtitle="Requirement versus shortage">
              <CostBar
                label="Covered"
                value={Math.max(
                  summary.requirementQty - summary.shortageQty,
                  0
                )}
                total={summary.requirementQty}
              />
              <CostBar
                label="Shortage"
                value={summary.shortageQty}
                total={summary.requirementQty}
                danger
              />
            </Panel>

            <Panel title="Readiness">
              <ReadinessLine
                label="Plan Items"
                ok={selectedItems.length > 0}
                value={`${selectedItems.length} line(s)`}
              />
              <ReadinessLine
                label="Materials Calculated"
                ok={requirements.length > 0}
                value={`${requirements.length} line(s)`}
              />
              <ReadinessLine
                label="No Shortage"
                ok={summary.shortageQty <= 0 && requirements.length > 0}
                value={qtyNumber(summary.shortageQty)}
              />
              <ReadinessLine
                label="Converted"
                ok={selectedPlan.status === "converted_to_orders"}
                value={statusLabel(selectedPlan.status)}
              />
            </Panel>
          </div>
        </>
      )}
    </div>
  );
}

function NoRadiusStyles() {
  return (
    <style>
      {`
        button,
        input,
        select,
        textarea {
          border-radius: 0 !important;
        }
      `}
    </style>
  );
}

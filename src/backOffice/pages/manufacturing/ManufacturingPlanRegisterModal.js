import React, { useEffect, useMemo, useState } from "react";
import {
  BarChart3,
  Factory,
  FileText,
  Layers,
  PackageSearch,
  PlayCircle,
  Plus,
  RefreshCw,
  Search,
  ShieldCheck,
  Truck,
  XCircle,
} from "lucide-react";

import FullScreenModal from "../../../components/FullScreenModal";
import { requestEnterpriseConfirmation } from "../../../components/EnterpriseConfirmHost";
import {
  approveProductionPlan,
  calculateProductionPlanMaterials,
  cancelProductionPlan,
  convertProductionPlanToOrders,
  getProductionPlan,
  getProductionPlans,
} from "../../../services/ManufacturingService";

import ManufacturingPlanFormModal from "./ManufacturingPlanFormModal";

import {
  ActionButton,
  EmptyBlock,
  GridTd,
  GridTh,
  LoadingPill,
  MetricCard,
  Notice,
  Panel,
  ReadinessLine,
  SectionTitle,
  SmallMetric,
  StatusBadge,
  getBranchId,
  getErrorMessage,
  getPayloadData,
  moneyNumber,
  normalizeRows,
  numberValue,
  qtyNumber,
  safeDate,
  statusLabel,
  useNotice,
} from "./ManufacturingShared";

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

export default function ManufacturingPlanRegisterModal({
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

  const [activeTab, setActiveTab] = useState("items");
  const [formOpen, setFormOpen] = useState(false);

  const [loading, setLoading] = useState(false);
  const [detailLoading, setDetailLoading] = useState(false);

  const { notice, showNotice, closeNotice } = useNotice();

  const loadPlans = async (selectFirst = false) => {
    try {
      setLoading(true);

      const params = {
        branch_id: branchId || undefined,
        per_page: 100,
      };

      if (statusFilter) params.status = statusFilter;

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
      showNotice(
        getErrorMessage(error, "Failed to load production plans."),
        "error",
        "Load Failed"
      );
    } finally {
      setLoading(false);
    }
  };

  const loadPlanDetails = async (planId, notify = false) => {
    if (!planId) return;

    try {
      setDetailLoading(true);

      const res = await getProductionPlan(planId);
      const data = getPayloadData(res);

      setSelectedPlan(data);
      setSelectedPlanId(planId);

      if (notify) {
        showNotice("Plan details refreshed.", "info", "Refreshed");
      }
    } catch (error) {
      showNotice(
        getErrorMessage(error, "Failed to load production plan details."),
        "error",
        "Load Failed"
      );
    } finally {
      setDetailLoading(false);
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
    }, 300);

    return () => window.clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusFilter]);

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

    const reqQty = requirements.reduce(
      (sum, item) => sum + numberValue(item.required_quantity),
      0
    );

    const shortage = requirements.reduce(
      (sum, item) => sum + numberValue(item.shortage_quantity),
      0
    );

    return {
      plannedQty,
      batches,
      plannedCost,
      reqQty,
      shortage,
      unitCost: plannedQty > 0 ? plannedCost / plannedQty : 0,
      coverage: reqQty > 0 ? ((reqQty - shortage) / reqQty) * 100 : 0,
    };
  }, [selectedItems, requirements]);

  const runPlanAction = async (action) => {
    if (!selectedPlan?.id) {
      showNotice("Select a production plan first.", "error", "No Plan");
      return;
    }

    const actionMap = {
      approve: {
        fn: () => approveProductionPlan(selectedPlan.id),
        message: "Plan approved.",
      },
      calculate: {
        fn: () => calculateProductionPlanMaterials(selectedPlan.id),
        message: "Material requirements calculated.",
      },
      convert: {
        fn: () => convertProductionPlanToOrders(selectedPlan.id),
        message: "Plan converted to production orders.",
      },
      cancel: {
        fn: () => cancelProductionPlan(selectedPlan.id),
        message: "Plan cancelled.",
      },
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

      const res = await actionMap[action].fn();

      showNotice(
        res?.message || actionMap[action].message,
        "info",
        "Completed"
      );

      await loadPlans(false);
      await loadPlanDetails(selectedPlan.id, false);
    } catch (error) {
      showNotice(
        getErrorMessage(error, "Plan action failed."),
        "error",
        "Action Failed"
      );
    } finally {
      setLoading(false);
    }
  };

  const closeFormAndRefresh = async () => {
    setFormOpen(false);
    await loadPlans(true);
  };

  if (!open) return null;

  return (
    <FullScreenModal
      open={open}
      onClose={onClose}
      title="PRODUCTION PLANNING"
      subtitle="Planning, material requirements, cost preview and conversion into production orders"
    >
      <Notice notice={notice} onClose={closeNotice} />
      <LoadingPill loading={loading || detailLoading} />

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
                  New plan opens as a modal for full visibility
                </div>
              </div>

              <div className="flex gap-1">
                <ActionButton
                  onClick={() => setFormOpen(true)}
                  label="New Plan"
                  icon={Plus}
                  primary
                />
                <ActionButton
                  onClick={() => loadPlans(false)}
                  label="Refresh"
                  icon={RefreshCw}
                />
              </div>
            </div>

            <div className="grid grid-cols-[minmax(260px,1fr)_180px_180px] gap-2 px-2 py-2">
              <label>
                <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">
                  Search
                </div>
                <div className="relative">
                  <Search className="absolute left-2 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-[#627474]" />
                  <input
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    className="h-7 w-full border border-[#AAB5B5] bg-white pl-8 pr-2 text-[11px] outline-none"
                    placeholder="Plan number, name, status..."
                  />
                </div>
              </label>

              <label>
                <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">
                  Status
                </div>
                <select
                  value={statusFilter}
                  onChange={(event) => setStatusFilter(event.target.value)}
                  className="h-7 w-full border border-[#AAB5B5] bg-white px-2 text-[11px] outline-none"
                >
                  <option value="">All</option>
                  <option value="draft">Draft</option>
                  <option value="approved">Approved</option>
                  <option value="converted_to_orders">Converted To Orders</option>
                  <option value="cancelled">Cancelled</option>
                </select>
              </label>

              <label>
                <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">
                  Basis
                </div>
                <select
                  value={basisFilter}
                  onChange={(event) => setBasisFilter(event.target.value)}
                  className="h-7 w-full border border-[#AAB5B5] bg-white px-2 text-[11px] outline-none"
                >
                  <option value="">All</option>
                  <option value="manual">Manual</option>
                  <option value="sales_forecast">Sales Forecast</option>
                  <option value="sales_order">Sales Order</option>
                  <option value="minimum_stock">Minimum Stock</option>
                  <option value="reorder_level">Reorder Level</option>
                </select>
              </label>
            </div>
          </div>

          <div className="grid min-h-[235px] grid-cols-[minmax(560px,58%)_minmax(360px,42%)] border-b border-[#AAB5B5]">
            <section className="min-w-0 border-r border-[#AAB5B5]">
              <SectionTitle title="Production Plans" subtitle="Register" />

              <div className="h-[205px] overflow-auto">
                <table className="w-full min-w-[820px] border-collapse text-left text-[11px]">
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
              <SectionTitle title="Selected Plan Control" subtitle="Workflow" />

              {!selectedPlan ? (
                <div className="h-[205px]">
                  <EmptyBlock text="Select a plan." icon={PackageSearch} />
                </div>
              ) : (
                <div className="h-[205px] overflow-auto p-2">
                  <div className="mb-2 grid grid-cols-3 gap-2">
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
                      label="Plan Cost"
                      value={moneyNumber(summary.plannedCost)}
                    />
                    <SmallMetric
                      label="Shortage"
                      value={qtyNumber(summary.shortage)}
                      danger={summary.shortage > 0}
                    />
                  </div>

                  <div className="flex flex-wrap gap-1 border border-[#CFD9D8] bg-white p-1.5">
                    <ActionButton
                      onClick={() => loadPlanDetails(selectedPlan.id, true)}
                      label="Reload"
                      icon={RefreshCw}
                    />
                    <ActionButton
                      onClick={() => runPlanAction("approve")}
                      label="Approve"
                      icon={ShieldCheck}
                      primary
                    />
                    <ActionButton
                      onClick={() => runPlanAction("calculate")}
                      label="Calculate Materials"
                      icon={BarChart3}
                      primary
                    />
                    <ActionButton
                      onClick={() => runPlanAction("convert")}
                      label="Convert To Orders"
                      icon={PlayCircle}
                      primary
                    />
                    <ActionButton
                      onClick={() => runPlanAction("cancel")}
                      label="Cancel"
                      icon={XCircle}
                      danger
                    />
                  </div>
                </div>
              )}
            </section>
          </div>

          <div className="flex min-h-0 flex-1 flex-col">
            <div className="flex h-8 items-end border-b border-[#AAB5B5] bg-[#F5F7FA] px-2">
              {[
                ["items", "Items"],
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
                      : "border-[#AAB5B5] bg-white text-[#324443]"
                  }`}
                >
                  {label}
                </button>
              ))}
            </div>

            <div className="min-h-0 flex-1 overflow-hidden">
              {!selectedPlan ? (
                <EmptyBlock text="Select a plan from the register." />
              ) : activeTab === "items" ? (
                <ItemsTab items={selectedItems} />
              ) : activeTab === "materials" ? (
                <MaterialsTab requirements={requirements} />
              ) : activeTab === "orders" ? (
                <OrdersTab orders={orders} />
              ) : (
                <VisualsTab
                  summary={summary}
                  selectedPlan={selectedPlan}
                  selectedItems={selectedItems}
                  requirements={requirements}
                />
              )}
            </div>
          </div>
        </div>
      </div>

      <ManufacturingPlanFormModal
        open={formOpen}
        onClose={closeFormAndRefresh}
        currentUser={currentUser}
      />

      <NoRadiusStyles />
    </FullScreenModal>
  );
}

function ItemsTab({ items }) {
  return (
    <div className="h-full overflow-auto">
      <table className="w-full min-w-[1100px] border-collapse text-left text-[11px]">
        <thead className="sticky top-0 z-10 bg-[#0E4D4D] text-white">
          <tr>
            <GridTh>#</GridTh>
            <GridTh>Product</GridTh>
            <GridTh>Recipe</GridTh>
            <GridTh align="right">Forecast</GridTh>
            <GridTh align="right">Stock</GridTh>
            <GridTh align="right">Safety</GridTh>
            <GridTh align="right">Planned</GridTh>
            <GridTh align="right">Batches</GridTh>
            <GridTh align="right">Total Cost</GridTh>
          </tr>
        </thead>

        <tbody>
          {items.length === 0 ? (
            <tr>
              <td colSpan={9} className="px-3 py-10 text-center text-[#627474]">
                No plan items found.
              </td>
            </tr>
          ) : (
            items.map((item, index) => (
              <tr key={item.id || index} className="border-b border-[#CFD9D8]">
                <GridTd>{index + 1}</GridTd>
                <GridTd strong>{itemProductName(item)}</GridTd>
                <GridTd>{itemRecipeName(item)}</GridTd>
                <GridTd right>{qtyNumber(item.forecast_quantity)}</GridTd>
                <GridTd right>{qtyNumber(item.current_stock_quantity)}</GridTd>
                <GridTd right>{qtyNumber(item.safety_stock_quantity)}</GridTd>
                <GridTd right>{qtyNumber(item.planned_quantity)}</GridTd>
                <GridTd right>{qtyNumber(item.planned_batches)}</GridTd>
                <GridTd right>{moneyNumber(item.planned_total_cost)}</GridTd>
              </tr>
            ))
          )}
        </tbody>
      </table>
    </div>
  );
}

function MaterialsTab({ requirements }) {
  return (
    <div className="h-full overflow-auto">
      <table className="w-full min-w-[900px] border-collapse text-left text-[11px]">
        <thead className="sticky top-0 z-10 bg-[#0E4D4D] text-white">
          <tr>
            <GridTh>#</GridTh>
            <GridTh>Material</GridTh>
            <GridTh align="right">Required</GridTh>
            <GridTh align="right">Available</GridTh>
            <GridTh align="right">Reserved</GridTh>
            <GridTh align="right">Shortage</GridTh>
            <GridTh>Status</GridTh>
          </tr>
        </thead>

        <tbody>
          {requirements.length === 0 ? (
            <tr>
              <td colSpan={7} className="px-3 py-10 text-center text-[#627474]">
                Click Calculate Materials to generate requirements.
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
            <GridTh>Status</GridTh>
            <GridTh>Product</GridTh>
            <GridTh align="right">Planned Qty</GridTh>
          </tr>
        </thead>

        <tbody>
          {orders.length === 0 ? (
            <tr>
              <td colSpan={5} className="px-3 py-10 text-center text-[#627474]">
                No converted production orders yet.
              </td>
            </tr>
          ) : (
            orders.map((order, index) => (
              <tr key={order.id || index} className="border-b border-[#CFD9D8]">
                <GridTd>{index + 1}</GridTd>
                <GridTd strong>{order.production_number || "-"}</GridTd>
                <GridTd>
                  <StatusBadge status={order.status} />
                </GridTd>
                <GridTd>
                  {order.finished_product?.name ||
                    order.finishedProduct?.name ||
                    "-"}
                </GridTd>
                <GridTd right>{qtyNumber(order.planned_output_quantity)}</GridTd>
              </tr>
            ))
          )}
        </tbody>
      </table>
    </div>
  );
}

function VisualsTab({ summary, selectedPlan, selectedItems, requirements }) {
  return (
    <div className="h-full overflow-auto bg-[#F7F8FA] p-2">
      <div className="grid gap-2 lg:grid-cols-4">
        <MetricCard
          title="Planned Qty"
          value={qtyNumber(summary.plannedQty)}
          icon={Factory}
        />
        <MetricCard
          title="Required Materials"
          value={qtyNumber(summary.reqQty)}
          icon={Layers}
        />
        <MetricCard
          title="Shortage"
          value={qtyNumber(summary.shortage)}
          icon={Truck}
          danger={summary.shortage > 0}
        />
        <MetricCard
          title="Unit Cost"
          value={moneyNumber(summary.unitCost)}
          icon={FileText}
        />
      </div>

      <div className="mt-2 grid gap-2 xl:grid-cols-2">
        <Panel title="Readiness">
          <ReadinessLine
            label="Plan Status"
            ok={selectedPlan.status !== "cancelled"}
            value={statusLabel(selectedPlan.status)}
          />
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
            ok={summary.shortage <= 0 && summary.reqQty > 0}
            value={qtyNumber(summary.shortage)}
          />
        </Panel>
      </div>
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

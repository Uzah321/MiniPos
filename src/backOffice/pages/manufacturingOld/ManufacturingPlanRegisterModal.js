import React, { useEffect, useMemo, useState } from "react";
import {
  BarChart3,
  CalendarDays,
  CheckCircle2,
  ClipboardList,
  Factory,
  FileText,
  Layers,
  PackageSearch,
  PlayCircle,
  RefreshCw,
  RotateCcw,
  Search,
  ShieldCheck,
  Truck,
  XCircle,
} from "lucide-react";

import FullScreenModal from "../../../components/FullScreenModal";
import {
  approveProductionPlan,
  calculateProductionPlanMaterials,
  cancelProductionPlan,
  convertProductionPlanToOrders,
  getProductionPlan,
  getProductionPlans,
} from "../../../services/ManufacturingService";
import {
  ActionButton,
  LoadingPill,
  Notice,
  SmallButton,
  StatusBadge,
  Td,
  getBranchId,
  getErrorMessage,
  normalizeRows,
  qtyNumber,
  useNotice,
} from "./ManufacturingShared";

const moneyNumber = (value) => Number(value || 0).toFixed(2);
const numberValue = (value) => Number(value || 0);
const safeDate = (value) => (value ? String(value).slice(0, 10) : "-");

const getPayloadData = (response) =>
  response?.data?.data || response?.data || response || null;

const statusOrder = ["draft", "approved", "converted_to_orders", "cancelled"];

const statusLabel = (status) =>
  String(status || "draft").replaceAll("_", " ").toUpperCase();

const getPlanItems = (plan) => {
  if (!plan) return [];
  if (Array.isArray(plan.items)) return plan.items;
  if (Array.isArray(plan.production_plan_items)) return plan.production_plan_items;
  return [];
};

const getRequirements = (plan) => {
  if (!plan) return [];
  if (Array.isArray(plan.material_requirements)) return plan.material_requirements;
  if (Array.isArray(plan.materialRequirements)) return plan.materialRequirements;
  return [];
};

const getProductName = (row) =>
  row?.finished_product?.name ||
  row?.finishedProduct?.name ||
  row?.product?.name ||
  row?.metadata?.product_name ||
  row?.finished_product_name ||
  "-";

const getRecipeName = (row) =>
  row?.recipe?.name || row?.production_recipe?.name || row?.recipe_name || "-";

const getRequirementProductName = (row) =>
  row?.product?.name || row?.product_name || row?.metadata?.product_name || "-";

const planItemPlannedTotal = (item) => {
  const direct = numberValue(item?.planned_total_cost);
  if (direct > 0) return direct;

  return (
    numberValue(item?.metadata?.planned_material_cost) +
    numberValue(item?.metadata?.planned_labour_cost) +
    numberValue(item?.metadata?.planned_overhead_cost)
  );
};

const planItemMaterialCost = (item) => {
  const fromMeta = numberValue(item?.metadata?.planned_material_cost);
  if (fromMeta > 0) return fromMeta;
  return numberValue(item?.planned_material_cost);
};

const planItemLabourCost = (item) => {
  const fromMeta = numberValue(item?.metadata?.planned_labour_cost);
  if (fromMeta > 0) return fromMeta;
  return numberValue(item?.planned_labour_cost);
};

const planItemOverheadCost = (item) => {
  const fromMeta = numberValue(item?.metadata?.planned_overhead_cost);
  if (fromMeta > 0) return fromMeta;
  return numberValue(item?.planned_overhead_cost);
};

const getBarPercent = (value, max) => {
  if (!max || max <= 0) return 0;
  return Math.min(100, Math.max(0, (Number(value || 0) / max) * 100));
};

export default function ManufacturingPlanRegisterModal({
  open,
  onClose,
  currentUser = null,
}) {
  const branchId = getBranchId(currentUser);

  const [plans, setPlans] = useState([]);
  const [selectedPlanId, setSelectedPlanId] = useState(null);
  const [selectedPlan, setSelectedPlan] = useState(null);
  const [loading, setLoading] = useState(false);
  const [detailLoading, setDetailLoading] = useState(false);

  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [basisFilter, setBasisFilter] = useState("");
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [activeTab, setActiveTab] = useState("lines");

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
        setSelectedPlanId(rows[0].id);
        await loadPlanDetails(rows[0].id, false);
      }

      if (selectedPlanId && rows.some((item) => item.id === selectedPlanId)) {
        await loadPlanDetails(selectedPlanId, false);
      }
    } catch (error) {
      showNotice(getErrorMessage(error, "Failed to load production plans."));
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

      if (notify) showNotice("Plan details refreshed.");
    } catch (error) {
      showNotice(getErrorMessage(error, "Failed to load production plan details."));
    } finally {
      setDetailLoading(false);
    }
  };

  useEffect(() => {
    if (open) {
      loadPlans(true);
    }
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
        String(plan.planning_basis || "").toLowerCase().includes(q) ||
        String(plan.status || "").toLowerCase().includes(q);

      const matchesBasis = !basisFilter || plan.planning_basis === basisFilter;

      return matchesSearch && matchesBasis;
    });
  }, [plans, search, basisFilter]);

  const selectedItems = useMemo(() => getPlanItems(selectedPlan), [selectedPlan]);

  const selectedRequirements = useMemo(
    () => getRequirements(selectedPlan),
    [selectedPlan]
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

    const materialCost = selectedItems.reduce(
      (sum, item) => sum + planItemMaterialCost(item),
      0
    );

    const labourCost = selectedItems.reduce(
      (sum, item) => sum + planItemLabourCost(item),
      0
    );

    const overheadCost = selectedItems.reduce(
      (sum, item) => sum + planItemOverheadCost(item),
      0
    );

    const totalCost = selectedItems.reduce(
      (sum, item) => sum + planItemPlannedTotal(item),
      0
    );

    const requirementQty = selectedRequirements.reduce(
      (sum, row) => sum + numberValue(row.required_quantity),
      0
    );

    const shortageQty = selectedRequirements.reduce(
      (sum, row) => sum + numberValue(row.shortage_quantity),
      0
    );

    return {
      plannedQty,
      batches,
      materialCost,
      labourCost,
      overheadCost,
      totalCost,
      requirementQty,
      shortageQty,
      unitCost: plannedQty > 0 ? totalCost / plannedQty : 0,
      shortagePercent:
        requirementQty > 0 ? (shortageQty / requirementQty) * 100 : 0,
    };
  }, [selectedItems, selectedRequirements]);

  const maxItemQty = useMemo(
    () =>
      Math.max(
        ...selectedItems.map((item) => numberValue(item.planned_quantity)),
        0
      ),
    [selectedItems]
  );

  const maxRequirementQty = useMemo(
    () =>
      Math.max(
        ...selectedRequirements.map((row) => numberValue(row.required_quantity)),
        0
      ),
    [selectedRequirements]
  );

  const runPlanAction = async (plan, action) => {
    if (!plan?.id) {
      showNotice("Select a production plan first.");
      return;
    }

    const actionMap = {
      approve: {
        label: "Approve Plan",
        fn: () => approveProductionPlan(plan.id),
        fallback: "Failed to approve production plan.",
      },
      calculate: {
        label: "Calculate Materials",
        fn: () => calculateProductionPlanMaterials(plan.id),
        fallback: "Failed to calculate material requirements.",
      },
      convert: {
        label: "Convert To Orders",
        fn: () => convertProductionPlanToOrders(plan.id),
        fallback: "Failed to convert plan to production orders.",
      },
      cancel: {
        label: "Cancel Plan",
        fn: () => cancelProductionPlan(plan.id),
        fallback: "Failed to cancel production plan.",
      },
    };

    const config = actionMap[action];
    if (!config) return;

    if (
      action === "cancel" &&
      !window.confirm(`Cancel production plan ${plan.plan_number || plan.name}?`)
    ) {
      return;
    }

    try {
      setLoading(true);
      const res = await config.fn();
      showNotice(res?.message || `${config.label} completed.`);
      await loadPlans(false);
      await loadPlanDetails(plan.id, false);
    } catch (error) {
      showNotice(getErrorMessage(error, config.fallback));
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

  if (!open) return null;

  return (
    <FullScreenModal
      open={open}
      onClose={onClose}
      title="PRODUCTION PLANNING"
      subtitle="Planning workbench, material requirement calculation, costing and conversion workflow"
    >
      <Notice notice={notice} />
      <LoadingPill loading={loading || detailLoading} />

      <div className="h-[calc(100vh-86px)] min-h-0 overflow-hidden bg-[#EEF1F1] p-2 text-[#1C2C2B]">
        <div className="flex h-full min-h-0 flex-col border border-[#AAB5B5] bg-white">
          <WorkbenchToolbar
            search={search}
            setSearch={setSearch}
            statusFilter={statusFilter}
            setStatusFilter={setStatusFilter}
            basisFilter={basisFilter}
            setBasisFilter={setBasisFilter}
            dateFrom={dateFrom}
            setDateFrom={setDateFrom}
            dateTo={dateTo}
            setDateTo={setDateTo}
            clearFilters={clearFilters}
            refresh={() => loadPlans(false)}
          />

          <div className="grid min-h-[235px] grid-cols-[minmax(430px,58%)_minmax(360px,42%)] border-b border-[#AAB5B5]">
            <PlanRegisterGrid
              plans={filteredPlans}
              selectedPlanId={selectedPlanId}
              loadPlanDetails={loadPlanDetails}
            />

            <PlanFormPanel
              selectedPlan={selectedPlan}
              selectedItems={selectedItems}
              selectedRequirements={selectedRequirements}
              summary={summary}
              runPlanAction={runPlanAction}
              reload={() => selectedPlan && loadPlanDetails(selectedPlan.id, true)}
            />
          </div>

          <div className="flex min-h-0 flex-1 flex-col">
            <BottomTabs activeTab={activeTab} setActiveTab={setActiveTab} />

            <div className="min-h-0 flex-1 overflow-hidden bg-white">
              {!selectedPlan ? (
                <EmptyBlock text="Select a production plan from the register." />
              ) : (
                <>
                  {activeTab === "lines" && (
                    <LinesTab
                      selectedItems={selectedItems}
                      maxItemQty={maxItemQty}
                    />
                  )}

                  {activeTab === "materials" && (
                    <MaterialsTab
                      selectedRequirements={selectedRequirements}
                      maxRequirementQty={maxRequirementQty}
                    />
                  )}

                  {activeTab === "visuals" && (
                    <VisualsTab
                      selectedPlan={selectedPlan}
                      selectedItems={selectedItems}
                      selectedRequirements={selectedRequirements}
                      summary={summary}
                    />
                  )}

                  {activeTab === "workflow" && (
                    <WorkflowTab
                      selectedPlan={selectedPlan}
                      selectedItems={selectedItems}
                      selectedRequirements={selectedRequirements}
                      summary={summary}
                    />
                  )}
                </>
              )}
            </div>
          </div>
        </div>
      </div>
    </FullScreenModal>
  );
}

function WorkbenchToolbar({
  search,
  setSearch,
  statusFilter,
  setStatusFilter,
  basisFilter,
  setBasisFilter,
  dateFrom,
  setDateFrom,
  dateTo,
  setDateTo,
  clearFilters,
  refresh,
}) {
  return (
    <div className="border-b border-[#AAB5B5] bg-[#F5F7FA]">
      <div className="flex h-9 items-center justify-between border-b border-[#CFD9D8] px-2">
        <div className="flex items-center gap-2">
          <div className="text-[13px] font-semibold text-[#0C1B1B]">
            Task planning
          </div>
          <div className="h-4 border-l border-[#AAB5B5]" />
          <div className="text-[10px] font-medium uppercase text-[#627474]">
            Production planning workbench
          </div>
        </div>

        <div className="flex items-center gap-1">
          <ActionButton
            onClick={refresh}
            label="Refresh"
            icon={RefreshCw}
            primary
          />
          <SmallButton onClick={clearFilters}>Clear</SmallButton>
        </div>
      </div>

      <div className="grid grid-cols-[minmax(260px,1.4fr)_150px_170px_140px_140px] gap-2 px-2 py-2">
        <label className="block">
          <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">
            Search
          </div>
          <div className="relative">
            <Search className="absolute left-2 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-[#627474]" />
            <input
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Plan number, name, status..."
              className="h-7 w-full border border-[#AAB5B5] bg-white pl-8 pr-2 text-[11px] outline-none focus:border-[#004343]"
            />
          </div>
        </label>

        <PlanSelect
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

        <PlanSelect
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

        <PlanInput
          label="Date From"
          type="date"
          value={dateFrom}
          onChange={setDateFrom}
        />

        <PlanInput
          label="Date To"
          type="date"
          value={dateTo}
          onChange={setDateTo}
        />
      </div>
    </div>
  );
}

function PlanRegisterGrid({ plans, selectedPlanId, loadPlanDetails }) {
  return (
    <section className="min-w-0 border-r border-[#AAB5B5]">
      <SectionTitle title="Production Plans" subtitle="Register" />

      <div className="h-[205px] overflow-auto">
        <table className="w-full border-collapse text-left text-[11px]">
          <thead className="sticky top-0 z-10 bg-[#0E4D4D] text-white">
            <tr>
              <th className="w-[26px] border-r border-[#465857] px-2 py-1.5 font-semibold">
                #
              </th>
              <th className="border-r border-[#465857] px-2 py-1.5 font-semibold">
                Plan number
              </th>
              <th className="border-r border-[#465857] px-2 py-1.5 font-semibold">
                Status
              </th>
              <th className="border-r border-[#465857] px-2 py-1.5 font-semibold">
                Task type
              </th>
              <th className="border-r border-[#465857] px-2 py-1.5 font-semibold">
                Start date
              </th>
              <th className="px-2 py-1.5 font-semibold">End date</th>
            </tr>
          </thead>

          <tbody>
            {plans.length === 0 ? (
              <tr>
                <td colSpan={6} className="px-3 py-10 text-center text-[#627474]">
                  No production plans found.
                </td>
              </tr>
            ) : (
              plans.map((plan, index) => {
                const selected = selectedPlanId === plan.id;

                return (
                  <tr
                    key={plan.id}
                    onClick={() => loadPlanDetails(plan.id)}
                    className={`cursor-pointer border-b border-[#CFD9D8] ${
                      selected
                        ? "bg-[#49A942] text-white"
                        : "bg-white text-[#324443] hover:bg-[#F2F8F2]"
                    }`}
                  >
                    <td className="border-r border-[#CFD9D8] px-2 py-1.5">
                      {index + 1}
                    </td>
                    <td className="border-r border-[#CFD9D8] px-2 py-1.5 font-semibold">
                      {plan.plan_number || "-"}
                    </td>
                    <td className="border-r border-[#CFD9D8] px-2 py-1.5">
                      {statusLabel(plan.status)}
                    </td>
                    <td className="border-r border-[#CFD9D8] px-2 py-1.5 capitalize">
                      {String(plan.planning_basis || "-").replaceAll("_", " ")}
                    </td>
                    <td className="border-r border-[#CFD9D8] px-2 py-1.5">
                      {safeDate(plan.period_start)}
                    </td>
                    <td className="px-2 py-1.5">{safeDate(plan.period_end)}</td>
                  </tr>
                );
              })
            )}
          </tbody>
        </table>
      </div>
    </section>
  );
}

function PlanFormPanel({
  selectedPlan,
  selectedItems,
  selectedRequirements,
  summary,
  runPlanAction,
  reload,
}) {
  return (
    <section className="min-w-0 bg-[#F7F8FA]">
      <SectionTitle title="Planning details" subtitle="Selected plan control panel" />

      {!selectedPlan ? (
        <div className="flex h-[205px] items-center justify-center text-[12px] font-semibold text-[#627474]">
          Select a plan to view details.
        </div>
      ) : (
        <div className="h-[205px] overflow-auto p-2">
          <div className="mb-2 grid grid-cols-3 gap-2">
            <FormField label="Category" value={selectedPlan.plan_number || "-"} />
            <FormField
              label="Status"
              value={statusLabel(selectedPlan.status)}
              badge={<StatusBadge status={selectedPlan.status} />}
            />
            <FormField
              label="Financial site"
              value={selectedPlan.branch?.name || selectedPlan.branch_name || "-"}
            />

            <FormField label="Task code" value={selectedPlan.name || "-"} />
            <FormField
              label="Description"
              value={selectedPlan.description || selectedPlan.name || "-"}
            />
            <FormField
              label="Sequence No."
              value={selectedPlan.reference_number || selectedPlan.id || "-"}
            />

            <FormField
              label="Start date"
              value={safeDate(selectedPlan.period_start)}
            />
            <FormField label="End date" value={safeDate(selectedPlan.period_end)} />
            <FormField
              label="Task type"
              value={String(selectedPlan.planning_basis || "-").replaceAll("_", " ")}
            />

            <FormField
              label="Planned qty"
              value={qtyNumber(summary.plannedQty)}
            />
            <FormField label="Planned batches" value={qtyNumber(summary.batches)} />
            <FormField label="Shortage qty" value={qtyNumber(summary.shortageQty)} />
          </div>

          <div className="mb-2 grid grid-cols-4 gap-2">
            <MiniMetric
              title="Items"
              value={selectedItems.length}
              icon={Factory}
            />
            <MiniMetric
              title="Materials"
              value={selectedRequirements.length}
              icon={Layers}
            />
            <MiniMetric
              title="Total cost"
              value={moneyNumber(summary.totalCost)}
              icon={FileText}
            />
            <MiniMetric
              title="Unit cost"
              value={moneyNumber(summary.unitCost)}
              icon={ClipboardList}
            />
          </div>

          <div className="flex flex-wrap gap-1 border border-[#CFD9D8] bg-white p-1.5">
            <ActionButton onClick={reload} label="Reload" icon={RefreshCw} />
            <ActionButton
              onClick={() => runPlanAction(selectedPlan, "approve")}
              label="Approve"
              icon={ShieldCheck}
              primary
            />
            <ActionButton
              onClick={() => runPlanAction(selectedPlan, "calculate")}
              label="Calculate Materials"
              icon={BarChart3}
              primary
            />
            <ActionButton
              onClick={() => runPlanAction(selectedPlan, "convert")}
              label="Convert To Orders"
              icon={PlayCircle}
              primary
            />
            <ActionButton
              onClick={() => runPlanAction(selectedPlan, "cancel")}
              label="Cancel"
              icon={XCircle}
              danger
            />
          </div>
        </div>
      )}
    </section>
  );
}

function BottomTabs({ activeTab, setActiveTab }) {
  const tabs = [
    ["lines", "Operations"],
    ["materials", "Products"],
    ["visuals", "Visuals"],
    ["workflow", "Workflow"],
  ];

  return (
    <div className="flex h-8 items-end border-b border-[#AAB5B5] bg-[#F5F7FA] px-2">
      {tabs.map(([key, label]) => {
        const active = activeTab === key;

        return (
          <button
            key={key}
            type="button"
            onClick={() => setActiveTab(key)}
            className={`h-7 border border-b-0 px-4 text-[11px] font-semibold ${
              active
                ? "border-[#31B44B] bg-[#31B44B] text-white"
                : "border-[#AAB5B5] bg-white text-[#324443] hover:bg-[#EEF2F2]"
            }`}
          >
            {label}
          </button>
        );
      })}
    </div>
  );
}

function LinesTab({ selectedItems, maxItemQty }) {
  return (
    <div className="h-full overflow-auto">
      <table className="w-full min-w-[1250px] border-collapse text-left text-[11px]">
        <thead className="sticky top-0 z-10 bg-[#0E4D4D] text-white">
          <tr>
            <GridTh>Line</GridTh>
            <GridTh>Status</GridTh>
            <GridTh>Finished product</GridTh>
            <GridTh>Recipe</GridTh>
            <GridTh align="right">Forecast</GridTh>
            <GridTh align="right">Current stock</GridTh>
            <GridTh align="right">Safety</GridTh>
            <GridTh align="right">Planned qty</GridTh>
            <GridTh align="right">Batches</GridTh>
            <GridTh align="right">Unit cost</GridTh>
            <GridTh align="right">Total cost</GridTh>
            <GridTh>Required by</GridTh>
            <GridTh>Visual</GridTh>
          </tr>
        </thead>

        <tbody>
          {selectedItems.length === 0 ? (
            <tr>
              <td colSpan={13} className="px-3 py-10 text-center text-[#627474]">
                No planned items found.
              </td>
            </tr>
          ) : (
            selectedItems.map((item, index) => (
              <tr
                key={item.id || index}
                className="border-b border-[#CFD9D8] bg-white hover:bg-[#F8FAFC]"
              >
                <GridTd>{index + 1}</GridTd>
                <GridTd>
                  <StatusBadge status={item.status || "planned"} />
                </GridTd>
                <GridTd strong>{getProductName(item)}</GridTd>
                <GridTd>{getRecipeName(item)}</GridTd>
                <GridTd right>{qtyNumber(item.forecast_quantity)}</GridTd>
                <GridTd right>{qtyNumber(item.current_stock_quantity)}</GridTd>
                <GridTd right>{qtyNumber(item.safety_stock_quantity)}</GridTd>
                <GridTd right>{qtyNumber(item.planned_quantity)}</GridTd>
                <GridTd right>{qtyNumber(item.planned_batches)}</GridTd>
                <GridTd right>{moneyNumber(item.planned_unit_cost)}</GridTd>
                <GridTd right>{moneyNumber(planItemPlannedTotal(item))}</GridTd>
                <GridTd>{safeDate(item.required_by)}</GridTd>
                <GridTd>
                  <MiniBar
                    value={numberValue(item.planned_quantity)}
                    max={maxItemQty}
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

function MaterialsTab({ selectedRequirements, maxRequirementQty }) {
  return (
    <div className="h-full overflow-auto">
      <div className="border-b border-[#A4E5E4] bg-[#F1F8F8] px-2 py-1.5 text-[11px] font-medium text-[#057B7B]">
        Formula used by the backend: required material = recipe material quantity
        × planned batches, then wastage percentage is added. Available stock is
        checked at branch level. Shortage becomes purchase required.
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
            <GridTh>Requirement bar</GridTh>
          </tr>
        </thead>

        <tbody>
          {selectedRequirements.length === 0 ? (
            <tr>
              <td colSpan={8} className="px-3 py-10 text-center text-[#627474]">
                No material requirements calculated yet. Click Calculate Materials.
              </td>
            </tr>
          ) : (
            selectedRequirements.map((row, index) => (
              <tr
                key={row.id || index}
                className="border-b border-[#CFD9D8] bg-white hover:bg-[#F8FAFC]"
              >
                <GridTd>{index + 1}</GridTd>
                <GridTd strong>{getRequirementProductName(row)}</GridTd>
                <GridTd right>{qtyNumber(row.required_quantity)}</GridTd>
                <GridTd right>{qtyNumber(row.available_quantity)}</GridTd>
                <GridTd right>{qtyNumber(row.reserved_quantity)}</GridTd>
                <GridTd right>{qtyNumber(row.shortage_quantity)}</GridTd>
                <GridTd>
                  <StatusBadge status={row.status} />
                </GridTd>
                <GridTd>
                  <StackedRequirementBar
                    required={numberValue(row.required_quantity)}
                    available={numberValue(row.available_quantity)}
                    shortage={numberValue(row.shortage_quantity)}
                    max={maxRequirementQty}
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

function VisualsTab({ selectedPlan, selectedItems, selectedRequirements, summary }) {
  return (
    <div className="h-full overflow-auto bg-[#F7F8FA] p-2">
      <div className="grid gap-2 lg:grid-cols-4">
        <MetricCard
          title="Planned Quantity"
          value={qtyNumber(summary.plannedQty)}
          icon={Factory}
        />
        <MetricCard
          title="Planned Batches"
          value={qtyNumber(summary.batches)}
          icon={Layers}
        />
        <MetricCard
          title="Total Planned Cost"
          value={moneyNumber(summary.totalCost)}
          icon={FileText}
        />
        <MetricCard
          title="Average Unit Cost"
          value={moneyNumber(summary.unitCost)}
          icon={ClipboardList}
        />
      </div>

      <div className="mt-2 grid gap-2 xl:grid-cols-[1.2fr_0.8fr]">
        <CostVisual
          materialCost={summary.materialCost}
          labourCost={summary.labourCost}
          overheadCost={summary.overheadCost}
        />

        <Panel title="Material Coverage" subtitle="Branch stock coverage for this plan.">
          <Gauge value={100 - summary.shortagePercent} />
          <div className="mt-2 grid grid-cols-2 gap-2">
            <SmallMetric label="Required" value={qtyNumber(summary.requirementQty)} />
            <SmallMetric
              label="Shortage"
              value={qtyNumber(summary.shortageQty)}
              danger={summary.shortageQty > 0}
            />
          </div>
        </Panel>
      </div>

      <div className="mt-2 grid gap-2 xl:grid-cols-3">
        <Panel title="Plan Summary" subtitle="Selected production plan">
          <ReadinessLine
            label="Plan number"
            ok
            value={selectedPlan.plan_number || "-"}
          />
          <ReadinessLine label="Status" ok value={statusLabel(selectedPlan.status)} />
          <ReadinessLine
            label="Items"
            ok={selectedItems.length > 0}
            value={`${selectedItems.length} item(s)`}
          />
          <ReadinessLine
            label="Materials"
            ok={selectedRequirements.length > 0}
            value={`${selectedRequirements.length} line(s)`}
          />
        </Panel>

        <WorkflowCard status={selectedPlan.status} />

        <Panel title="Readiness Check" subtitle="Operational conversion status">
          <ReadinessLine
            label="Plan Items"
            ok={selectedItems.length > 0}
            value={`${selectedItems.length} item(s)`}
          />
          <ReadinessLine
            label="Materials Calculated"
            ok={selectedRequirements.length > 0}
            value={`${selectedRequirements.length} line(s)`}
          />
          <ReadinessLine
            label="No Shortages"
            ok={summary.shortageQty <= 0 && selectedRequirements.length > 0}
            value={`${qtyNumber(summary.shortageQty)} shortage`}
          />
          <ReadinessLine
            label="Approved"
            ok={
              String(selectedPlan.status) === "approved" ||
              String(selectedPlan.status) === "converted_to_orders"
            }
            value={statusLabel(selectedPlan.status)}
          />
        </Panel>
      </div>
    </div>
  );
}

function WorkflowTab({ selectedPlan, selectedItems, selectedRequirements, summary }) {
  return (
    <div className="h-full overflow-auto bg-[#F7F8FA] p-2">
      <div className="grid gap-2 xl:grid-cols-3">
        <WorkflowCard status={selectedPlan.status} />

        <Panel title="Readiness Check" subtitle="Operational decision support">
          <ReadinessLine
            label="Plan Items"
            ok={selectedItems.length > 0}
            value={`${selectedItems.length} item(s)`}
          />
          <ReadinessLine
            label="Materials Calculated"
            ok={selectedRequirements.length > 0}
            value={`${selectedRequirements.length} line(s)`}
          />
          <ReadinessLine
            label="No Shortages"
            ok={summary.shortageQty <= 0 && selectedRequirements.length > 0}
            value={`${qtyNumber(summary.shortageQty)} shortage`}
          />
          <ReadinessLine
            label="Approved"
            ok={
              String(selectedPlan.status) === "approved" ||
              String(selectedPlan.status) === "converted_to_orders"
            }
            value={statusLabel(selectedPlan.status)}
          />
        </Panel>

        <Panel title="Activity Guidance" subtitle="What each button does">
          <ActivityTip
            icon={ShieldCheck}
            title="Approve"
            text="Moves a draft plan into an approved state. Approval is required before a clean conversion process."
          />
          <ActivityTip
            icon={BarChart3}
            title="Calculate Materials"
            text="Reads every plan item recipe, multiplies materials by planned batches, adds wastage, checks stock and creates material requirements."
          />
          <ActivityTip
            icon={Truck}
            title="Convert To Orders"
            text="Creates production orders from the plan items and recipe BOM lines. The plan status becomes converted_to_orders."
          />
          <ActivityTip
            icon={RotateCcw}
            title="Cancel"
            text="Cancels draft or approved plans that have not already been converted."
          />
        </Panel>
      </div>
    </div>
  );
}

function SectionTitle({ title, subtitle }) {
  return (
    <div className="flex h-7 items-center justify-between border-b border-[#AAB5B5] bg-[#EEF2F2] px-2">
      <div className="text-[11px] font-semibold uppercase text-[#324443]">
        {title}
      </div>
      {subtitle && (
        <div className="text-[10px] font-medium uppercase text-[#627474]">
          {subtitle}
        </div>
      )}
    </div>
  );
}

function FormField({ label, value, badge = null }) {
  return (
    <div>
      <div className="mb-0.5 text-[9px] font-semibold uppercase text-[#627474]">
        {label}
      </div>
      <div className="flex h-7 items-center border border-[#C4CDCD] bg-white px-2 text-[11px] font-medium text-[#324443]">
        {badge || <span className="truncate">{value}</span>}
      </div>
    </div>
  );
}

function MiniMetric({ title, value, icon: Icon }) {
  return (
    <div className="border border-[#C4CDCD] bg-white px-2 py-1.5">
      <div className="flex items-center gap-1 text-[9px] font-semibold uppercase text-[#627474]">
        <Icon className="h-3 w-3" />
        {title}
      </div>
      <div className="mt-1 truncate text-[14px] font-semibold text-[#004343]">
        {value}
      </div>
    </div>
  );
}

function GridTh({ children, align = "left" }) {
  return (
    <th
      className={`border-r border-[#465857] px-2 py-1.5 font-semibold ${
        align === "right" ? "text-right" : "text-left"
      }`}
    >
      {children}
    </th>
  );
}

function GridTd({ children, right = false, strong = false }) {
  return (
    <td
      className={`border-r border-[#CFD9D8] px-2 py-1.5 ${
        right ? "text-right" : "text-left"
      } ${strong ? "font-semibold text-[#0C1B1B]" : "text-[#324443]"}`}
    >
      {children}
    </td>
  );
}

function MetricCard({ title, value, icon: Icon }) {
  return (
    <div className="border border-[#AFB9B9] bg-white p-3">
      <div className="flex items-center gap-1 text-[10px] font-semibold uppercase text-[#627474]">
        <Icon className="h-3.5 w-3.5" />
        {title}
      </div>
      <div className="mt-2 truncate text-[22px] font-semibold text-[#004343]">
        {value}
      </div>
    </div>
  );
}

function Panel({ title, subtitle, children }) {
  return (
    <section className="border border-[#AFB9B9] bg-white">
      <div className="border-b border-[#D8DFDF] bg-[#EEF2F2] px-3 py-2">
        <div className="text-[12px] font-semibold uppercase text-[#324443]">
          {title}
        </div>
        {subtitle && (
          <div className="mt-0.5 text-[10px] font-medium text-[#627474]">
            {subtitle}
          </div>
        )}
      </div>
      <div className="p-2">{children}</div>
    </section>
  );
}

function CostVisual({ materialCost, labourCost, overheadCost }) {
  const total = materialCost + labourCost + overheadCost;

  return (
    <Panel
      title="Planned Cost Composition"
      subtitle="Materials, labour and overhead cost split"
    >
      <div className="grid gap-2 md:grid-cols-[1fr_220px]">
        <div className="space-y-2">
          <CostBar label="Materials" value={materialCost} total={total} />
          <CostBar label="Labour" value={labourCost} total={total} />
          <CostBar label="Overheads" value={overheadCost} total={total} />
        </div>

        <div className="border border-[#D8DFDF] bg-[#F8FAFC] p-3">
          <div className="text-[10px] font-semibold uppercase text-[#627474]">
            Total Planned Cost
          </div>
          <div className="mt-2 text-[24px] font-semibold text-[#004343]">
            {moneyNumber(total)}
          </div>
        </div>
      </div>
    </Panel>
  );
}

function CostBar({ label, value, total }) {
  const percent = total > 0 ? (value / total) * 100 : 0;

  return (
    <div>
      <div className="mb-1 flex justify-between text-[11px] font-semibold text-[#324443]">
        <span>{label}</span>
        <span>
          {moneyNumber(value)} ({percent.toFixed(1)}%)
        </span>
      </div>
      <div className="h-4 border border-[#B9C3C3] bg-[#F2F4F7]">
        <div
          className="h-full bg-[#004344]"
          style={{ width: `${Math.min(100, percent)}%` }}
        />
      </div>
    </div>
  );
}

function WorkflowCard({ status }) {
  const currentIndex = Math.max(0, statusOrder.indexOf(String(status || "draft")));

  return (
    <Panel title="Workflow Position" subtitle="Current production planning stage">
      <div className="space-y-2">
        {statusOrder.map((item, index) => {
          const isCurrent = item === status;
          const isDone = currentIndex >= index && status !== "cancelled";
          const isCancelled = status === "cancelled" && item === "cancelled";

          return (
            <div
              key={item}
              className={`flex items-center gap-2 border p-2 text-[11px] font-semibold uppercase ${
                isCurrent || isCancelled
                  ? "border-[#00BCBC] bg-[#E8F7F5] text-[#005A5A]"
                  : isDone
                    ? "border-[#ABEFC6] bg-[#ECFDF3] text-[#067647]"
                    : "border-[#E9EDED] bg-[#F9FAFB] text-[#627474]"
              }`}
            >
              <CheckCircle2 className="h-4 w-4" />
              {statusLabel(item)}
            </div>
          );
        })}
      </div>
    </Panel>
  );
}

function ReadinessLine({ label, ok, value }) {
  return (
    <div className="flex items-center justify-between border-b border-[#EFF2F2] py-2 text-[11px] last:border-b-0">
      <span className="font-semibold text-[#324443]">{label}</span>
      <span
        className={ok ? "font-semibold text-[#067647]" : "font-semibold text-[#B42318]"}
      >
        {value}
      </span>
    </div>
  );
}

function Gauge({ value }) {
  const clean = Math.min(100, Math.max(0, Number(value || 0)));

  return (
    <div>
      <div className="mb-1 flex justify-between text-[11px] font-semibold text-[#324443]">
        <span>Coverage</span>
        <span>{clean.toFixed(1)}%</span>
      </div>
      <div className="h-5 border border-[#B9C3C3] bg-[#FEF3F2]">
        <div className="h-full bg-[#027A48]" style={{ width: `${clean}%` }} />
      </div>
    </div>
  );
}

function SmallMetric({ label, value, danger = false }) {
  return (
    <div className="border border-[#D8DFDF] bg-[#F8FAFC] p-2">
      <div className="text-[9px] font-semibold uppercase text-[#627474]">
        {label}
      </div>
      <div
        className={`mt-1 text-[13px] font-semibold ${
          danger ? "text-[#B42318]" : "text-[#004343]"
        }`}
      >
        {value}
      </div>
    </div>
  );
}

function ActivityTip({ icon: Icon, title, text }) {
  return (
    <div className="mb-2 border border-[#D8DFDF] bg-[#F8FAFC] p-2 last:mb-0">
      <div className="flex items-center gap-1 text-[11px] font-semibold uppercase text-[#0C1B1B]">
        <Icon className="h-3.5 w-3.5" />
        {title}
      </div>
      <div className="mt-1 text-[11px] font-medium leading-4 text-[#627474]">
        {text}
      </div>
    </div>
  );
}

function MiniBar({ value, max }) {
  return (
    <div className="h-3 w-[120px] border border-[#B9C3C3] bg-[#F2F4F7]">
      <div
        className="h-full bg-[#004344]"
        style={{ width: `${getBarPercent(value, max)}%` }}
      />
    </div>
  );
}

function StackedRequirementBar({ required, available, shortage, max }) {
  const requiredWidth = getBarPercent(required, max);
  const availableWidth =
    required > 0 ? Math.min(100, (available / required) * 100) : 0;
  const shortageWidth =
    required > 0 ? Math.min(100, (shortage / required) * 100) : 0;

  return (
    <div className="w-[150px]">
      <div
        className="h-4 border border-[#B9C3C3] bg-[#F2F4F7]"
        style={{
          width: `${requiredWidth}%`,
          minWidth: required > 0 ? 24 : 0,
        }}
      >
        <div className="flex h-full w-full">
          <div
            className="h-full bg-[#027A48]"
            style={{ width: `${availableWidth}%` }}
          />
          <div
            className="h-full bg-[#B42318]"
            style={{ width: `${shortageWidth}%` }}
          />
        </div>
      </div>

      <div className="mt-1 flex gap-2 text-[9px] font-semibold uppercase text-[#627474]">
        <span>Available</span>
        <span>Shortage</span>
      </div>
    </div>
  );
}

function PlanInput({ label, value, onChange, type = "text" }) {
  return (
    <label className="block">
      <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">
        {label}
      </div>
      <input
        type={type}
        value={value || ""}
        onChange={(event) => onChange(event.target.value)}
        className="h-7 w-full border border-[#AAB5B5] bg-white px-2 text-[11px] outline-none focus:border-[#004343]"
      />
    </label>
  );
}

function PlanSelect({ label, value, onChange, options }) {
  return (
    <label className="block">
      <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">
        {label}
      </div>
      <select
        value={value || ""}
        onChange={(event) => onChange(event.target.value)}
        className="h-7 w-full border border-[#AAB5B5] bg-white px-2 text-[11px] outline-none focus:border-[#004343]"
      >
        {options.map(([key, text]) => (
          <option key={key} value={key}>
            {text}
          </option>
        ))}
      </select>
    </label>
  );
}

function EmptyBlock({ text }) {
  return (
    <div className="flex h-full flex-col items-center justify-center text-[12px] font-semibold text-[#627474]">
      <PackageSearch className="mb-2 h-8 w-8" />
      {text}
    </div>
  );
}
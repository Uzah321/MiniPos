import React, { useEffect, useMemo, useState } from "react";
import {
  CheckCircle2,
  ClipboardList,
  Factory,
  FileText,
  Layers,
  PackageSearch,
  PlayCircle,
  RefreshCw,
  Search,
  ShieldCheck,
  Trash2,
  Truck,
  Undo2,
  XCircle,
} from "lucide-react";

import FullScreenModal from "../../../components/FullScreenModal";

import {
  addProductionOverhead,
  approveProductionOrder,
  cancelProductionOrder,
  completeProductionOrder,
  getProductionOrder,
  getProductionOrders,
  getProductionPlan,
  getProductionPlans,
  issueProductionMaterials,
  recordProductionWastage,
  reserveProductionMaterials,
  returnProductionMaterial,
  startProductionOrder,
} from "../../../services/ManufacturingService";

import {
  ActionButton,
  LoadingPill,
  Notice,
  SmallButton,
  StatusBadge,
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

const orderStatusOrder = [
  "draft",
  "approved",
  "materials_reserved",
  "materials_issued",
  "in_progress",
  "completed",
  "cancelled",
];

const overheadTypes = [
  ["labour", "Labour"],
  ["salary", "Salary"],
  ["electricity", "Electricity"],
  ["fuel", "Fuel"],
  ["water", "Water"],
  ["rent", "Rent"],
  ["machine_maintenance", "Machine Maintenance"],
  ["depreciation", "Depreciation"],
  ["other", "Other"],
];

const statusLabel = (status) =>
  String(status || "draft").replaceAll("_", " ").toUpperCase();

const getPlanOrders = (plan) => {
  if (!plan) return [];
  if (Array.isArray(plan.production_orders)) return plan.production_orders;
  if (Array.isArray(plan.productionOrders)) return plan.productionOrders;
  return [];
};

const getOrderInputs = (order) => {
  if (!order) return [];
  if (Array.isArray(order.inputs)) return order.inputs;
  if (Array.isArray(order.production_order_inputs)) {
    return order.production_order_inputs;
  }
  return [];
};

const getOrderOutputs = (order) => {
  if (!order) return [];
  if (Array.isArray(order.outputs)) return order.outputs;
  if (Array.isArray(order.production_order_outputs)) {
    return order.production_order_outputs;
  }
  return [];
};

const getOrderOverheads = (order) => {
  if (!order) return [];
  if (Array.isArray(order.overheads)) return order.overheads;
  if (Array.isArray(order.production_order_overheads)) {
    return order.production_order_overheads;
  }
  return [];
};

const getFinishedProductName = (order) =>
  order?.finished_product?.name ||
  order?.finishedProduct?.name ||
  order?.product?.name ||
  order?.metadata?.finished_product_name ||
  "-";

const getRecipeName = (row) =>
  row?.recipe?.name ||
  row?.production_recipe?.name ||
  row?.metadata?.recipe_name ||
  "-";

const getInputProductName = (row) =>
  row?.product?.name || row?.metadata?.product_name || row?.product_name || "-";

const getOutputProductName = (row) =>
  row?.product?.name || row?.metadata?.product_name || row?.product_name || "-";

const getProductCode = (row) =>
  row?.product?.code || row?.metadata?.product_code || row?.product_code || "-";

const getUnitName = (row) =>
  row?.uom?.code ||
  row?.product?.uom?.code ||
  row?.product?.uom?.name ||
  row?.metadata?.unit ||
  "-";

const getLineCost = (row) => {
  const direct =
    numberValue(row?.planned_total_cost) ||
    numberValue(row?.actual_total_cost) ||
    numberValue(row?.total_cost);

  if (direct > 0) return direct;

  return (
    numberValue(row?.planned_quantity) *
    numberValue(row?.metadata?.unit_cost || row?.unit_cost)
  );
};

const getIssuedQty = (row) =>
  numberValue(
    row?.issued_quantity || row?.actual_quantity || row?.consumed_quantity
  );

const getReturnedQty = (row) => numberValue(row?.returned_quantity);
const getWastedQty = (row) => numberValue(row?.wasted_quantity);

const getBarPercent = (value, max) => {
  if (!max || max <= 0) return 0;
  return Math.min(100, Math.max(0, (Number(value || 0) / max) * 100));
};

const initialOverheadForm = {
  cost_type: "labour",
  description: "",
  amount: "",
};

const initialMaterialActionForm = {
  input: null,
  action: "",
  quantity: "",
  notes: "",
};

const initialCompletionForm = {
  actual_good_quantity: "",
  actual_reject_quantity: "0",
  actual_waste_quantity: "0",
};

export default function ManufacturingOrderWorkflowModal({
  open,
  onClose,
  currentUser = null,
}) {
  const branchId = getBranchId(currentUser);

  const [convertedPlans, setConvertedPlans] = useState([]);
  const [orders, setOrders] = useState([]);

  const [selectedPlanId, setSelectedPlanId] = useState(null);
  const [selectedPlan, setSelectedPlan] = useState(null);

  const [selectedOrderId, setSelectedOrderId] = useState(null);
  const [selectedOrder, setSelectedOrder] = useState(null);

  const [loading, setLoading] = useState(false);
  const [detailLoading, setDetailLoading] = useState(false);

  const [search, setSearch] = useState("");
  const [orderStatusFilter, setOrderStatusFilter] = useState("");
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");

  const [activeTab, setActiveTab] = useState("workflow");

  const [confirmModal, setConfirmModal] = useState({
    open: false,
    title: "",
    message: "",
    confirmText: "Confirm",
    danger: false,
    onConfirm: null,
  });

  const [overheadModalOpen, setOverheadModalOpen] = useState(false);
  const [overheadForm, setOverheadForm] = useState(initialOverheadForm);

  const [materialModalOpen, setMaterialModalOpen] = useState(false);
  const [materialActionForm, setMaterialActionForm] = useState(
    initialMaterialActionForm
  );

  const [completionModalOpen, setCompletionModalOpen] = useState(false);
  const [completionForm, setCompletionForm] = useState(initialCompletionForm);

  const { notice, showNotice } = useNotice();

  const loadConvertedPlans = async (selectFirst = false) => {
    try {
      setLoading(true);

      const params = {
        branch_id: branchId || undefined,
        per_page: 100,
        status: "converted_to_orders",
      };

      if (dateFrom) params.date_from = dateFrom;
      if (dateTo) params.date_to = dateTo;

      const res = await getProductionPlans(params);
      const rows = normalizeRows(res);

      setConvertedPlans(rows);

      if (selectFirst && rows.length && !selectedPlanId) {
        await loadPlanDetails(rows[0].id, false);
      }

      if (selectedPlanId && rows.some((row) => row.id === selectedPlanId)) {
        await loadPlanDetails(selectedPlanId, false);
      }
    } catch (error) {
      showNotice(
        getErrorMessage(error, "Failed to load converted production plans.")
      );
    } finally {
      setLoading(false);
    }
  };

  const loadOrders = async () => {
    try {
      setLoading(true);

      const params = {
        branch_id: branchId || undefined,
        per_page: 100,
      };

      if (orderStatusFilter) params.status = orderStatusFilter;
      if (dateFrom) params.date_from = dateFrom;
      if (dateTo) params.date_to = dateTo;
      if (search.trim()) params.q = search.trim();

      const res = await getProductionOrders(params);
      const rows = normalizeRows(res);

      setOrders(rows);

      if (selectedOrderId && rows.some((item) => item.id === selectedOrderId)) {
        await loadOrderDetails(selectedOrderId, false);
      }
    } catch (error) {
      showNotice(getErrorMessage(error, "Failed to load production orders."));
    } finally {
      setLoading(false);
    }
  };

  const loadData = async (selectFirst = false) => {
    await Promise.all([loadConvertedPlans(selectFirst), loadOrders()]);
  };

  const loadPlanDetails = async (planId, notify = false) => {
    if (!planId) return;

    try {
      setDetailLoading(true);

      const res = await getProductionPlan(planId);
      const data = getPayloadData(res);

      setSelectedPlan(data);
      setSelectedPlanId(planId);

      const planOrders = getPlanOrders(data);

      if (planOrders.length > 0) {
        const currentStillBelongs = planOrders.some(
          (order) => order.id === selectedOrderId
        );

        if (!currentStillBelongs) {
          setSelectedOrder(null);
          setSelectedOrderId(null);
        }
      }

      if (notify) showNotice("Converted plan details refreshed.");
    } catch (error) {
      showNotice(
        getErrorMessage(error, "Failed to load converted plan details.")
      );
    } finally {
      setDetailLoading(false);
    }
  };

  const loadOrderDetails = async (orderId, notify = false) => {
    if (!orderId) return;

    try {
      setDetailLoading(true);

      const res = await getProductionOrder(orderId);
      const data = getPayloadData(res);

      setSelectedOrder(data);
      setSelectedOrderId(orderId);
      setActiveTab("workflow");

      if (notify) showNotice("Production order details refreshed.");
    } catch (error) {
      showNotice(
        getErrorMessage(error, "Failed to load production order details.")
      );
    } finally {
      setDetailLoading(false);
    }
  };

  useEffect(() => {
    if (open) loadData(true);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  useEffect(() => {
    if (!open) return;

    const timer = window.setTimeout(() => {
      loadData(false);
    }, 350);

    return () => window.clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [orderStatusFilter, dateFrom, dateTo, search]);

  const filteredConvertedPlans = useMemo(() => {
    const q = search.trim().toLowerCase();

    return convertedPlans.filter((plan) => {
      if (!q) return true;

      return (
        String(plan.plan_number || "").toLowerCase().includes(q) ||
        String(plan.name || "").toLowerCase().includes(q) ||
        String(plan.planning_basis || "").toLowerCase().includes(q) ||
        String(plan.status || "").toLowerCase().includes(q)
      );
    });
  }, [convertedPlans, search]);

  const selectedPlanOrders = useMemo(() => {
    const directOrders = getPlanOrders(selectedPlan);

    if (directOrders.length > 0) return directOrders;

    if (!selectedPlan?.id) return [];

    return orders.filter(
      (order) =>
        order.production_plan_id === selectedPlan.id ||
        order.plan_id === selectedPlan.id ||
        order.plan?.id === selectedPlan.id
    );
  }, [selectedPlan, orders]);

  const selectedInputs = useMemo(
    () => getOrderInputs(selectedOrder),
    [selectedOrder]
  );

  const selectedOutputs = useMemo(
    () => getOrderOutputs(selectedOrder),
    [selectedOrder]
  );

  const selectedOverheads = useMemo(
    () => getOrderOverheads(selectedOrder),
    [selectedOrder]
  );

  const orderSummary = useMemo(() => {
    const plannedQty = numberValue(selectedOrder?.planned_output_quantity);
    const actualGood = numberValue(selectedOrder?.actual_good_quantity);
    const actualReject = numberValue(selectedOrder?.actual_reject_quantity);
    const actualWaste = numberValue(selectedOrder?.actual_waste_quantity);

    const plannedMaterialQty = selectedInputs.reduce(
      (sum, item) => sum + numberValue(item.planned_quantity),
      0
    );

    const issuedMaterialQty = selectedInputs.reduce(
      (sum, item) => sum + getIssuedQty(item),
      0
    );

    const returnedQty = selectedInputs.reduce(
      (sum, item) => sum + getReturnedQty(item),
      0
    );

    const wastedInputQty = selectedInputs.reduce(
      (sum, item) => sum + getWastedQty(item),
      0
    );

    const materialCost = selectedInputs.reduce(
      (sum, item) => sum + getLineCost(item),
      0
    );

    const overheadCost = selectedOverheads.reduce(
      (sum, item) => sum + numberValue(item.amount),
      0
    );

    const totalCost =
      numberValue(selectedOrder?.actual_total_cost) ||
      numberValue(selectedOrder?.planned_total_cost) ||
      materialCost + overheadCost;

    return {
      plannedQty,
      actualGood,
      actualReject,
      actualWaste,
      plannedMaterialQty,
      issuedMaterialQty,
      returnedQty,
      wastedInputQty,
      materialCost,
      overheadCost,
      totalCost,
      unitCost:
        actualGood > 0
          ? totalCost / actualGood
          : plannedQty > 0
            ? totalCost / plannedQty
            : 0,
      yieldPercent: plannedQty > 0 ? (actualGood / plannedQty) * 100 : 0,
      lossPercent:
        plannedQty > 0 ? ((actualReject + actualWaste) / plannedQty) * 100 : 0,
    };
  }, [selectedOrder, selectedInputs, selectedOverheads]);

  const maxInputQty = useMemo(
    () =>
      Math.max(
        ...selectedInputs.map((item) => numberValue(item.planned_quantity)),
        ...selectedInputs.map((item) => getIssuedQty(item)),
        0
      ),
    [selectedInputs]
  );

  const refreshAfterOrderAction = async (orderId) => {
    await loadOrders();

    if (selectedPlanId) {
      await loadPlanDetails(selectedPlanId, false);
    }

    if (orderId) {
      await loadOrderDetails(orderId, false);
    }
  };

  const runOrderAction = async (order, action) => {
    if (!order?.id) {
      showNotice("Select a production order first.");
      return;
    }

    const actionMap = {
      approve: {
        label: "Approve Order",
        fn: () => approveProductionOrder(order.id),
        fallback: "Failed to approve production order.",
      },
      reserve: {
        label: "Reserve Materials",
        fn: () => reserveProductionMaterials(order.id),
        fallback: "Failed to reserve materials.",
      },
      issue: {
        label: "Issue Materials",
        fn: () => issueProductionMaterials(order.id),
        fallback: "Failed to issue materials.",
      },
      start: {
        label: "Start Production",
        fn: () => startProductionOrder(order.id),
        fallback: "Failed to start production.",
      },
      cancel: {
        label: "Cancel Order",
        fn: () => cancelProductionOrder(order.id),
        fallback: "Failed to cancel production order.",
      },
    };

    const config = actionMap[action];
    if (!config) return;

    if (action === "cancel") {
      setConfirmModal({
        open: true,
        title: "Cancel Production Order",
        message: `You are about to cancel production order ${
          order.production_number || ""
        }. This will stop the order before completion. Continue?`,
        confirmText: "Cancel Order",
        danger: true,
        onConfirm: async () => {
          await executeOrderAction(order, config);
        },
      });
      return;
    }

    await executeOrderAction(order, config);
  };

  const executeOrderAction = async (order, config) => {
    try {
      setConfirmModal((prev) => ({ ...prev, open: false }));
      setLoading(true);

      const res = await config.fn();

      showNotice(res?.message || `${config.label} completed.`);
      await refreshAfterOrderAction(order.id);
    } catch (error) {
      showNotice(getErrorMessage(error, config.fallback));
    } finally {
      setLoading(false);
    }
  };

  const openCompletionModal = () => {
    if (!selectedOrder?.id) {
      showNotice("Select a production order first.");
      return;
    }

    setCompletionForm({
      actual_good_quantity:
        selectedOrder.actual_good_quantity ||
        selectedOrder.planned_output_quantity ||
        "",
      actual_reject_quantity: selectedOrder.actual_reject_quantity || "0",
      actual_waste_quantity: selectedOrder.actual_waste_quantity || "0",
    });

    setCompletionModalOpen(true);
  };

  const submitCompletion = async () => {
    if (!selectedOrder?.id) {
      showNotice("Select a production order first.");
      return;
    }

    const good = numberValue(completionForm.actual_good_quantity);
    const rejects = numberValue(completionForm.actual_reject_quantity);
    const waste = numberValue(completionForm.actual_waste_quantity);

    if (good <= 0) {
      showNotice("Actual good quantity must be greater than zero.");
      return;
    }

    if (rejects < 0 || waste < 0) {
      showNotice("Reject and waste quantities cannot be negative.");
      return;
    }

    try {
      setLoading(true);

      const res = await completeProductionOrder(selectedOrder.id, {
        actual_good_quantity: good,
        actual_reject_quantity: rejects,
        actual_waste_quantity: waste,
      });

      showNotice(res?.message || "Production order completed.");
      setCompletionModalOpen(false);
      await refreshAfterOrderAction(selectedOrder.id);
    } catch (error) {
      showNotice(getErrorMessage(error, "Failed to complete production order."));
    } finally {
      setLoading(false);
    }
  };

  const openOverheadModal = () => {
    if (!selectedOrder?.id) {
      showNotice("Select a production order first.");
      return;
    }

    setOverheadForm(initialOverheadForm);
    setOverheadModalOpen(true);
  };

  const submitOverhead = async () => {
    if (!selectedOrder?.id) {
      showNotice("Select a production order first.");
      return;
    }

    const amount = numberValue(overheadForm.amount);

    if (!overheadForm.cost_type) {
      showNotice("Cost type is required.");
      return;
    }

    if (amount < 0) {
      showNotice("Amount must be zero or greater.");
      return;
    }

    try {
      setLoading(true);

      const res = await addProductionOverhead(selectedOrder.id, {
        cost_type: overheadForm.cost_type,
        description: overheadForm.description || null,
        amount,
        currency: selectedOrder.currency || "USD",
      });

      showNotice(res?.message || "Staff/overhead cost added.");
      setOverheadModalOpen(false);
      await loadOrderDetails(selectedOrder.id, false);
    } catch (error) {
      showNotice(getErrorMessage(error, "Failed to add production overhead."));
    } finally {
      setLoading(false);
    }
  };

  const openMaterialActionModal = (input, action) => {
    if (!selectedOrder?.id) {
      showNotice("Select a production order first.");
      return;
    }

    if (!input?.id) {
      showNotice("Selected material input has no ID.");
      return;
    }

    setMaterialActionForm({
      input,
      action,
      quantity: "",
      notes:
        action === "return"
          ? "Unused material returned"
          : "Production material wastage",
    });

    setMaterialModalOpen(true);
  };

  const submitMaterialAction = async () => {
    if (!selectedOrder?.id) {
      showNotice("Select a production order first.");
      return;
    }

    const input = materialActionForm.input;
    const qty = numberValue(materialActionForm.quantity);

    if (!input?.id) {
      showNotice("Selected material input has no ID.");
      return;
    }

    if (qty <= 0) {
      showNotice("Quantity must be greater than zero.");
      return;
    }

    try {
      setLoading(true);

      const payload = {
        input_id: input.id,
        quantity: qty,
        notes: materialActionForm.notes || null,
      };

      const res =
        materialActionForm.action === "return"
          ? await returnProductionMaterial(selectedOrder.id, payload)
          : await recordProductionWastage(selectedOrder.id, payload);

      showNotice(
        res?.message ||
          (materialActionForm.action === "return"
            ? "Material returned."
            : "Wastage recorded.")
      );

      setMaterialModalOpen(false);
      await loadOrderDetails(selectedOrder.id, false);
    } catch (error) {
      showNotice(
        getErrorMessage(
          error,
          materialActionForm.action === "return"
            ? "Failed to return production material."
            : "Failed to record production wastage."
        )
      );
    } finally {
      setLoading(false);
    }
  };

  const clearFilters = () => {
    setSearch("");
    setOrderStatusFilter("");
    setDateFrom("");
    setDateTo("");
  };

  if (!open) return null;

  return (
    <FullScreenModal
      open={open}
      onClose={onClose}
      title="PRODUCTION WORKFLOW"
      subtitle="Converted plans, generated orders, stage execution, material issue, staff costs and output capture"
    >
      <Notice notice={notice} />
      <LoadingPill loading={loading || detailLoading} />

      <div className="h-[calc(100vh-86px)] min-h-0 overflow-hidden bg-[#EEF1F1] p-2 text-[#1C2C2B]">
        <div className="flex h-full min-h-0 flex-col border border-[#AAB5B5] bg-white">
          <WorkflowToolbar
            search={search}
            setSearch={setSearch}
            orderStatusFilter={orderStatusFilter}
            setOrderStatusFilter={setOrderStatusFilter}
            dateFrom={dateFrom}
            setDateFrom={setDateFrom}
            dateTo={dateTo}
            setDateTo={setDateTo}
            clearFilters={clearFilters}
            refresh={() => loadData(false)}
          />

          <div className="grid min-h-[238px] grid-cols-[minmax(470px,55%)_minmax(400px,45%)] border-b border-[#AAB5B5]">
            <ConvertedPlanGrid
              plans={filteredConvertedPlans}
              selectedPlanId={selectedPlanId}
              loadPlanDetails={loadPlanDetails}
            />

            <GeneratedOrderPanel
              selectedPlan={selectedPlan}
              selectedPlanOrders={selectedPlanOrders}
              selectedOrder={selectedOrder}
              selectedOrderId={selectedOrderId}
              loadOrderDetails={loadOrderDetails}
              reload={() =>
                selectedPlan && loadPlanDetails(selectedPlan.id, true)
              }
            />
          </div>

          <div className="flex min-h-0 flex-1 flex-col">
            <WorkflowTabs
              activeTab={activeTab}
              setActiveTab={setActiveTab}
              hasSelectedOrder={!!selectedOrder}
            />

            <div className="min-h-0 flex-1 overflow-hidden bg-white">
              {!selectedPlan ? (
                <EmptyBlock text="Select a converted plan to see generated production orders." />
              ) : !selectedOrder ? (
                <EmptyBlock text="Select a generated production order to run the workflow." />
              ) : (
                <>
                  {activeTab === "workflow" && (
                    <WorkflowStageTab
                      selectedOrder={selectedOrder}
                      selectedInputs={selectedInputs}
                      selectedOverheads={selectedOverheads}
                      selectedOutputs={selectedOutputs}
                      summary={orderSummary}
                      runOrderAction={runOrderAction}
                      openOverheadModal={openOverheadModal}
                      openCompletionModal={openCompletionModal}
                    />
                  )}

                  {activeTab === "inputs" && (
                    <InputsTab
                      selectedInputs={selectedInputs}
                      maxInputQty={maxInputQty}
                      openMaterialActionModal={openMaterialActionModal}
                    />
                  )}

                  {activeTab === "overheads" && (
                    <OverheadsTab
                      selectedOverheads={selectedOverheads}
                      openOverheadModal={openOverheadModal}
                    />
                  )}

                  {activeTab === "outputs" && (
                    <OutputsTab
                      selectedOrder={selectedOrder}
                      selectedOutputs={selectedOutputs}
                      summary={orderSummary}
                      openCompletionModal={openCompletionModal}
                    />
                  )}

                  {activeTab === "visuals" && (
                    <VisualsTab
                      selectedOrder={selectedOrder}
                      selectedInputs={selectedInputs}
                      selectedOverheads={selectedOverheads}
                      selectedOutputs={selectedOutputs}
                      summary={orderSummary}
                    />
                  )}
                </>
              )}
            </div>
          </div>
        </div>
      </div>

      <ConfirmActionModal
        open={confirmModal.open}
        title={confirmModal.title}
        message={confirmModal.message}
        confirmText={confirmModal.confirmText}
        danger={confirmModal.danger}
        loading={loading}
        onClose={() => setConfirmModal((prev) => ({ ...prev, open: false }))}
        onConfirm={confirmModal.onConfirm}
      />

      <OverheadEntryModal
        open={overheadModalOpen}
        form={overheadForm}
        setForm={setOverheadForm}
        loading={loading}
        selectedOrder={selectedOrder}
        onClose={() => setOverheadModalOpen(false)}
        onSubmit={submitOverhead}
      />

      <MaterialActionModal
        open={materialModalOpen}
        form={materialActionForm}
        setForm={setMaterialActionForm}
        loading={loading}
        onClose={() => setMaterialModalOpen(false)}
        onSubmit={submitMaterialAction}
      />

      <CompletionModal
        open={completionModalOpen}
        form={completionForm}
        setForm={setCompletionForm}
        loading={loading}
        selectedOrder={selectedOrder}
        summary={orderSummary}
        onClose={() => setCompletionModalOpen(false)}
        onSubmit={submitCompletion}
      />

      <ProductionWorkflowStyles />
    </FullScreenModal>
  );
}

function WorkflowToolbar({
  search,
  setSearch,
  orderStatusFilter,
  setOrderStatusFilter,
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
            Production workflow control
          </div>
          <div className="h-4 border-l border-[#AAB5B5]" />
          <div className="text-[10px] font-medium uppercase text-[#627474]">
            Converted plans only → generated orders → production execution
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

      <div className="grid grid-cols-[minmax(260px,1.4fr)_180px_150px_150px] gap-2 px-2 py-2">
        <label className="block">
          <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">
            Search
          </div>
          <div className="relative">
            <Search className="absolute left-2 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-[#627474]" />
            <input
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Plan number, plan name, production number..."
              className="h-7 w-full border border-[#AAB5B5] bg-white pl-8 pr-2 text-[11px] outline-none focus:border-[#004343]"
            />
          </div>
        </label>

        <WorkflowSelect
          label="Order Status"
          value={orderStatusFilter}
          onChange={setOrderStatusFilter}
          options={[
            ["", "All Orders"],
            ["draft", "Draft"],
            ["approved", "Approved"],
            ["materials_reserved", "Materials Reserved"],
            ["materials_issued", "Materials Issued"],
            ["in_progress", "In Progress"],
            ["completed", "Completed"],
            ["cancelled", "Cancelled"],
          ]}
        />

        <WorkflowInput
          label="Date From"
          type="date"
          value={dateFrom}
          onChange={setDateFrom}
        />

        <WorkflowInput
          label="Date To"
          type="date"
          value={dateTo}
          onChange={setDateTo}
        />
      </div>
    </div>
  );
}

function ConvertedPlanGrid({ plans, selectedPlanId, loadPlanDetails }) {
  return (
    <section className="min-w-0 border-r border-[#AAB5B5]">
      <SectionTitle
        title="Converted Production Plans"
        subtitle="converted_to_orders only"
      />

      <div className="h-[210px] overflow-auto">
        <table className="w-full border-collapse text-left text-[11px]">
          <thead className="sticky top-0 z-10 bg-[#0E4D4D] text-white">
            <tr>
              <GridTh>#</GridTh>
              <GridTh>Plan No</GridTh>
              <GridTh>Name</GridTh>
              <GridTh>Basis</GridTh>
              <GridTh>Period</GridTh>
              <GridTh>Status</GridTh>
            </tr>
          </thead>

          <tbody>
            {plans.length === 0 ? (
              <tr>
                <td colSpan={6} className="px-3 py-10 text-center text-[#627474]">
                  No converted_to_orders plans found.
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
                    <GridTd>{index + 1}</GridTd>
                    <GridTd strong>{plan.plan_number || "-"}</GridTd>
                    <GridTd>{plan.name || "-"}</GridTd>
                    <GridTd>
                      {String(plan.planning_basis || "-").replaceAll("_", " ")}
                    </GridTd>
                    <GridTd>
                      {safeDate(plan.period_start)} - {safeDate(plan.period_end)}
                    </GridTd>
                    <GridTd>{statusLabel(plan.status)}</GridTd>
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

function GeneratedOrderPanel({
  selectedPlan,
  selectedPlanOrders,
  selectedOrder,
  selectedOrderId,
  loadOrderDetails,
  reload,
}) {
  return (
    <section className="min-w-0 bg-[#F7F8FA]">
      <SectionTitle
        title="Generated Production Orders"
        subtitle="Select order to execute"
      />

      {!selectedPlan ? (
        <div className="flex h-[210px] items-center justify-center text-[12px] font-semibold text-[#627474]">
          Select a converted plan.
        </div>
      ) : (
        <div className="h-[210px] overflow-auto p-2">
          <div className="mb-2 grid grid-cols-3 gap-2">
            <FormField label="Plan No" value={selectedPlan.plan_number || "-"} />
            <FormField
              label="Plan Status"
              value={statusLabel(selectedPlan.status)}
              badge={<StatusBadge status={selectedPlan.status} />}
            />
            <FormField label="Orders" value={selectedPlanOrders.length} />
          </div>

          <div className="mb-2 flex flex-wrap gap-1 border border-[#CFD9D8] bg-white p-1.5">
            <ActionButton onClick={reload} label="Reload" icon={RefreshCw} />
            {selectedPlanOrders.slice(0, 5).map((order) => (
              <ActionButton
                key={order.id}
                onClick={() => loadOrderDetails(order.id)}
                label={order.production_number || "Open Order"}
                icon={Factory}
                primary={selectedOrder?.id === order.id}
              />
            ))}
          </div>

          <div className="border border-[#CFD9D8] bg-white">
            <table className="w-full border-collapse text-left text-[11px]">
              <thead className="bg-[#EEF2F2] text-[#324443]">
                <tr>
                  <th className="border-r border-[#CFD9D8] px-2 py-1.5">
                    Production No
                  </th>
                  <th className="border-r border-[#CFD9D8] px-2 py-1.5">
                    Status
                  </th>
                  <th className="px-2 py-1.5">Action</th>
                </tr>
              </thead>

              <tbody>
                {selectedPlanOrders.length === 0 ? (
                  <tr>
                    <td colSpan={3} className="px-3 py-6 text-center text-[#627474]">
                      No generated orders visible for this converted plan.
                    </td>
                  </tr>
                ) : (
                  selectedPlanOrders.map((order) => (
                    <tr
                      key={order.id}
                      className={`border-t border-[#CFD9D8] ${
                        selectedOrderId === order.id ? "bg-[#E8F7F5]" : ""
                      }`}
                    >
                      <td className="border-r border-[#CFD9D8] px-2 py-1.5 font-semibold">
                        {order.production_number || "-"}
                      </td>
                      <td className="border-r border-[#CFD9D8] px-2 py-1.5">
                        <StatusBadge status={order.status} />
                      </td>
                      <td className="px-2 py-1.5">
                        <SmallButton onClick={() => loadOrderDetails(order.id)}>
                          Open Workflow
                        </SmallButton>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </section>
  );
}

function WorkflowTabs({ activeTab, setActiveTab, hasSelectedOrder }) {
  const tabs = [
    ["workflow", "Production Workflow"],
    ["inputs", "Material Inputs"],
    ["overheads", "Staff / Overheads"],
    ["outputs", "Output Capture"],
    ["visuals", "Production Visuals"],
  ];

  return (
    <div className="flex h-8 items-end overflow-x-auto border-b border-[#AAB5B5] bg-[#F5F7FA] px-2">
      {tabs.map(([key, label]) => {
        const active = activeTab === key;

        return (
          <button
            key={key}
            type="button"
            disabled={!hasSelectedOrder}
            onClick={() => setActiveTab(key)}
            className={`h-7 whitespace-nowrap border border-b-0 px-4 text-[11px] font-semibold disabled:opacity-50 ${
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

function WorkflowStageTab({
  selectedOrder,
  selectedInputs,
  selectedOverheads,
  selectedOutputs,
  summary,
  runOrderAction,
  openOverheadModal,
  openCompletionModal,
}) {
  const status = String(selectedOrder?.status || "draft");

  const stageRows = [
    {
      key: "draft",
      number: "01",
      title: "Draft Order",
      subtitle: "Generated from converted production plan",
      icon: ClipboardList,
      actionLabel: "Waiting Approval",
      description:
        "The production order has been generated from planning. Review product, quantity, recipe and material inputs before approval.",
      isDone: [
        "approved",
        "materials_reserved",
        "materials_issued",
        "in_progress",
        "completed",
      ].includes(status),
      isCurrent: status === "draft",
      onClick: () => runOrderAction(selectedOrder, "approve"),
      buttonText: "Approve Order",
      buttonIcon: ShieldCheck,
      disabled: status !== "draft",
    },
    {
      key: "approved",
      number: "02",
      title: "Approved For Production",
      subtitle: "Production is authorised",
      icon: ShieldCheck,
      actionLabel: "Approved",
      description:
        "Approval confirms that this order is allowed to reserve stock and move into shop-floor processing.",
      isDone: [
        "materials_reserved",
        "materials_issued",
        "in_progress",
        "completed",
      ].includes(status),
      isCurrent: status === "approved",
      onClick: () => runOrderAction(selectedOrder, "reserve"),
      buttonText: "Reserve Materials",
      buttonIcon: Truck,
      disabled: status !== "approved",
    },
    {
      key: "materials_reserved",
      number: "03",
      title: "Materials Reserved",
      subtitle: "Raw materials locked",
      icon: Truck,
      actionLabel: "Reserved",
      description:
        "Required materials are reserved at branch level so they cannot be consumed by another sale, transfer or production order.",
      isDone: ["materials_issued", "in_progress", "completed"].includes(status),
      isCurrent: status === "materials_reserved",
      onClick: () => runOrderAction(selectedOrder, "issue"),
      buttonText: "Issue Materials",
      buttonIcon: Layers,
      disabled: !["approved", "materials_reserved"].includes(status),
    },
    {
      key: "materials_issued",
      number: "04",
      title: "Materials Issued",
      subtitle: "Inputs moved into production",
      icon: Layers,
      actionLabel: "Issued",
      description:
        "Raw materials are issued from stock into production. This creates the production material consumption cost.",
      isDone: ["in_progress", "completed"].includes(status),
      isCurrent: status === "materials_issued",
      onClick: () => runOrderAction(selectedOrder, "start"),
      buttonText: "Start Production",
      buttonIcon: PlayCircle,
      disabled: status !== "materials_issued",
    },
    {
      key: "in_progress",
      number: "05",
      title: "Production In Progress",
      subtitle: "Shop-floor work active",
      icon: PlayCircle,
      actionLabel: "Running",
      description:
        "Production is active. Capture staff, labour, overheads, returned materials, wastage and then final output.",
      isDone: status === "completed",
      isCurrent: status === "in_progress",
      onClick: openOverheadModal,
      buttonText: "Add Staff Cost",
      buttonIcon: FileText,
      disabled: !["materials_issued", "in_progress"].includes(status),
    },
    {
      key: "completed",
      number: "06",
      title: "Complete Production",
      subtitle: "Finished goods received",
      icon: CheckCircle2,
      actionLabel: "Output Capture",
      description:
        "Capture good quantity, rejects and waste. Finished goods are received into stock and actual unit cost is calculated.",
      isDone: status === "completed",
      isCurrent: status === "completed",
      onClick: openCompletionModal,
      buttonText: status === "completed" ? "Completed" : "Capture Output",
      buttonIcon: Factory,
      disabled: status === "completed",
      success: true,
    },
  ];

  return (
    <div className="h-full overflow-auto bg-[#F7F8FA] p-2">
      <div className="grid gap-2 xl:grid-cols-[1fr_360px]">
        <section className="border border-[#AFB9B9] bg-white">
          <div className="border-b border-[#D8DFDF] bg-[#EEF2F2] px-3 py-2">
            <div className="flex items-center justify-between gap-2">
              <div>
                <div className="text-[12px] font-semibold uppercase text-[#324443]">
                  Main Production Workflow
                </div>
                <div className="mt-0.5 text-[10px] font-medium text-[#627474]">
                  Execute production stage by stage
                </div>
              </div>

              <StatusBadge status={selectedOrder.status} />
            </div>
          </div>

          <div className="p-3">
            <ProductionMotionScene status={status} />

            <div className="mt-3 grid gap-2">
              {stageRows.map((stage, index) => (
                <ProductionStageCard
                  key={stage.key}
                  stage={stage}
                  index={index}
                  status={status}
                />
              ))}
            </div>
          </div>
        </section>

        <aside className="space-y-2">
          <Panel title="Selected Order" subtitle="Production execution record">
            <div className="grid gap-2">
              <SmallMetric
                label="Production No"
                value={selectedOrder.production_number || "-"}
              />
              <SmallMetric
                label="Finished Product"
                value={getFinishedProductName(selectedOrder)}
              />
              <SmallMetric label="Recipe" value={getRecipeName(selectedOrder)} />
              <SmallMetric
                label="Status"
                value={statusLabel(selectedOrder.status)}
              />
            </div>
          </Panel>

          <Panel title="Live Totals" subtitle="Current production values">
            <div className="grid grid-cols-2 gap-2">
              <SmallMetric
                label="Planned Output"
                value={qtyNumber(summary.plannedQty)}
              />
              <SmallMetric
                label="Actual Good"
                value={qtyNumber(summary.actualGood)}
              />
              <SmallMetric label="Inputs" value={selectedInputs.length} />
              <SmallMetric
                label="Staff / Overheads"
                value={selectedOverheads.length}
              />
              <SmallMetric label="Outputs" value={selectedOutputs.length} />
              <SmallMetric label="Unit Cost" value={moneyNumber(summary.unitCost)} />
            </div>
          </Panel>

          <Panel title="Quick Actions" subtitle="Production activity capture">
            <div className="grid gap-2">
              <WorkflowSideButton
                icon={FileText}
                title="Add Staff / Overhead"
                text="Capture labour, salary, power, fuel or maintenance."
                onClick={openOverheadModal}
              />

              <WorkflowSideButton
                icon={Factory}
                title="Complete & Capture Output"
                text="Capture good output, rejects and finished goods waste."
                onClick={openCompletionModal}
                green
              />

              <WorkflowSideButton
                icon={XCircle}
                title="Cancel Order"
                text="Stop the production order before completion."
                onClick={() => runOrderAction(selectedOrder, "cancel")}
                danger
              />
            </div>
          </Panel>
        </aside>
      </div>
    </div>
  );
}

function InputsTab({ selectedInputs, maxInputQty, openMaterialActionModal }) {
  return (
    <div className="h-full overflow-auto">
      <table className="w-full min-w-[1250px] border-collapse text-left text-[11px]">
        <thead className="sticky top-0 z-10 bg-[#0E4D4D] text-white">
          <tr>
            <GridTh>Line</GridTh>
            <GridTh>Code</GridTh>
            <GridTh>Material</GridTh>
            <GridTh>Unit</GridTh>
            <GridTh align="right">Planned</GridTh>
            <GridTh align="right">Reserved</GridTh>
            <GridTh align="right">Issued</GridTh>
            <GridTh align="right">Returned</GridTh>
            <GridTh align="right">Wasted</GridTh>
            <GridTh align="right">Line Cost</GridTh>
            <GridTh>Visual</GridTh>
            <GridTh>Actions</GridTh>
          </tr>
        </thead>

        <tbody>
          {selectedInputs.length === 0 ? (
            <tr>
              <td colSpan={12} className="px-3 py-10 text-center text-[#627474]">
                No material inputs found.
              </td>
            </tr>
          ) : (
            selectedInputs.map((input, index) => (
              <tr
                key={input.id || index}
                className="border-b border-[#CFD9D8] bg-white hover:bg-[#F8FAFC]"
              >
                <GridTd>{index + 1}</GridTd>
                <GridTd>{getProductCode(input)}</GridTd>
                <GridTd strong>{getInputProductName(input)}</GridTd>
                <GridTd>{getUnitName(input)}</GridTd>
                <GridTd right>{qtyNumber(input.planned_quantity)}</GridTd>
                <GridTd right>{qtyNumber(input.reserved_quantity)}</GridTd>
                <GridTd right>{qtyNumber(getIssuedQty(input))}</GridTd>
                <GridTd right>{qtyNumber(getReturnedQty(input))}</GridTd>
                <GridTd right>{qtyNumber(getWastedQty(input))}</GridTd>
                <GridTd right>{moneyNumber(getLineCost(input))}</GridTd>
                <GridTd>
                  <MiniBar
                    value={numberValue(input.planned_quantity)}
                    max={maxInputQty}
                  />
                </GridTd>
                <GridTd>
                  <div className="flex flex-wrap gap-1">
                    <SmallButton
                      onClick={() => openMaterialActionModal(input, "return")}
                    >
                      Return
                    </SmallButton>
                    <SmallButton
                      onClick={() => openMaterialActionModal(input, "wastage")}
                    >
                      Wastage
                    </SmallButton>
                  </div>
                </GridTd>
              </tr>
            ))
          )}
        </tbody>
      </table>
    </div>
  );
}

function OverheadsTab({ selectedOverheads, openOverheadModal }) {
  return (
    <div className="h-full overflow-auto">
      <div className="flex items-center justify-between border-b border-[#A4E5E4] bg-[#F1F8F8] px-2 py-1.5">
        <div className="text-[11px] font-medium text-[#057B7B]">
          Capture production staff and overheads: labour, salary, electricity,
          fuel, water, rent, maintenance, depreciation or other.
        </div>

        <SmallButton onClick={openOverheadModal}>Add Staff / Overhead</SmallButton>
      </div>

      <table className="w-full min-w-[850px] border-collapse text-left text-[11px]">
        <thead className="sticky top-0 z-10 bg-[#0E4D4D] text-white">
          <tr>
            <GridTh>Line</GridTh>
            <GridTh>Cost Type</GridTh>
            <GridTh>Description / Staff</GridTh>
            <GridTh>Currency</GridTh>
            <GridTh align="right">Amount</GridTh>
            <GridTh>Date</GridTh>
          </tr>
        </thead>

        <tbody>
          {selectedOverheads.length === 0 ? (
            <tr>
              <td colSpan={6} className="px-3 py-10 text-center text-[#627474]">
                No staff or overhead costs captured yet.
              </td>
            </tr>
          ) : (
            selectedOverheads.map((row, index) => (
              <tr
                key={row.id || index}
                className="border-b border-[#CFD9D8] bg-white hover:bg-[#F8FAFC]"
              >
                <GridTd>{index + 1}</GridTd>
                <GridTd strong>
                  {String(row.cost_type || "-").replaceAll("_", " ")}
                </GridTd>
                <GridTd>{row.description || "-"}</GridTd>
                <GridTd>{row.currency || "-"}</GridTd>
                <GridTd right>{moneyNumber(row.amount)}</GridTd>
                <GridTd>{safeDate(row.created_at)}</GridTd>
              </tr>
            ))
          )}
        </tbody>
      </table>
    </div>
  );
}

function OutputsTab({
  selectedOrder,
  selectedOutputs,
  summary,
  openCompletionModal,
}) {
  return (
    <div className="h-full overflow-auto bg-[#F7F8FA] p-2">
      <div className="grid gap-2 lg:grid-cols-4">
        <MetricCard
          title="Planned Output"
          value={qtyNumber(summary.plannedQty)}
          icon={Factory}
        />
        <MetricCard
          title="Actual Good"
          value={qtyNumber(summary.actualGood)}
          icon={CheckCircle2}
        />
        <MetricCard
          title="Rejected"
          value={qtyNumber(summary.actualReject)}
          icon={XCircle}
        />
        <MetricCard
          title="Waste"
          value={qtyNumber(summary.actualWaste)}
          icon={Trash2}
        />
      </div>

      <div className="mt-2 grid gap-2 xl:grid-cols-[1.2fr_0.8fr]">
        <Panel title="Output Capture" subtitle="Finished goods receiving">
          <div className="grid gap-2 md:grid-cols-3">
            <SmallMetric
              label="Production No"
              value={selectedOrder.production_number || "-"}
            />
            <SmallMetric
              label="Finished Product"
              value={getFinishedProductName(selectedOrder)}
            />
            <SmallMetric label="Status" value={statusLabel(selectedOrder.status)} />
            <SmallMetric
              label="Yield %"
              value={`${summary.yieldPercent.toFixed(1)}%`}
            />
            <SmallMetric
              label="Loss %"
              value={`${summary.lossPercent.toFixed(1)}%`}
              danger={summary.lossPercent > 0}
            />
            <SmallMetric label="Unit Cost" value={moneyNumber(summary.unitCost)} />
          </div>

          <div className="mt-3">
            <ActionButton
              onClick={openCompletionModal}
              label="Complete & Capture Output"
              icon={CheckCircle2}
              primary
            />
          </div>
        </Panel>

        <Panel title="Output Records" subtitle="Finished goods already received">
          {selectedOutputs.length === 0 ? (
            <div className="py-8 text-center text-[12px] font-semibold text-[#627474]">
              No output records yet. Complete production to receive finished
              goods.
            </div>
          ) : (
            <div className="space-y-2">
              {selectedOutputs.map((row, index) => (
                <div
                  key={row.id || index}
                  className="border border-[#D8DFDF] bg-[#F8FAFC] p-2 text-[11px]"
                >
                  <div className="font-semibold text-[#0C1B1B]">
                    {getOutputProductName(row)}
                  </div>

                  <div className="mt-1 grid grid-cols-4 gap-2">
                    <SmallMetric
                      label="Good"
                      value={qtyNumber(
                        row.quantity_produced ||
                          row.good_quantity ||
                          row.actual_good_quantity
                      )}
                    />
                    <SmallMetric
                      label="Reject"
                      value={qtyNumber(
                        row.quantity_rejected ||
                          row.reject_quantity ||
                          row.actual_reject_quantity
                      )}
                    />
                    <SmallMetric
                      label="Waste"
                      value={qtyNumber(
                        row.quantity_wasted ||
                          row.waste_quantity ||
                          row.actual_waste_quantity
                      )}
                    />
                    <SmallMetric
                      label="Unit Cost"
                      value={moneyNumber(row.unit_cost)}
                    />
                  </div>
                </div>
              ))}
            </div>
          )}
        </Panel>
      </div>
    </div>
  );
}

function VisualsTab({
  selectedOrder,
  selectedInputs,
  selectedOverheads,
  selectedOutputs,
  summary,
}) {
  return (
    <div className="h-full overflow-auto bg-[#F7F8FA] p-2">
      <div className="grid gap-2 lg:grid-cols-4">
        <MetricCard
          title="Material Cost"
          value={moneyNumber(summary.materialCost)}
          icon={Layers}
        />
        <MetricCard
          title="Staff / Overhead"
          value={moneyNumber(summary.overheadCost)}
          icon={FileText}
        />
        <MetricCard
          title="Total Cost"
          value={moneyNumber(summary.totalCost)}
          icon={ClipboardList}
        />
        <MetricCard
          title="Unit Cost"
          value={moneyNumber(summary.unitCost)}
          icon={Factory}
        />
      </div>

      <div className="mt-2 grid gap-2 xl:grid-cols-[1.2fr_0.8fr]">
        <CostVisual
          materialCost={summary.materialCost}
          overheadCost={summary.overheadCost}
        />

        <Panel title="Production Yield" subtitle="Good output against planned output">
          <Gauge value={summary.yieldPercent} />

          <div className="mt-2 grid grid-cols-2 gap-2">
            <SmallMetric label="Planned" value={qtyNumber(summary.plannedQty)} />
            <SmallMetric
              label="Actual Good"
              value={qtyNumber(summary.actualGood)}
            />
          </div>
        </Panel>
      </div>

      <div className="mt-2 grid gap-2 xl:grid-cols-3">
        <Panel title="Order Summary" subtitle="Selected production order">
          <ReadinessLine
            label="Production no"
            ok
            value={selectedOrder.production_number || "-"}
          />
          <ReadinessLine
            label="Status"
            ok
            value={statusLabel(selectedOrder.status)}
          />
          <ReadinessLine
            label="Inputs"
            ok={selectedInputs.length > 0}
            value={`${selectedInputs.length} line(s)`}
          />
          <ReadinessLine
            label="Staff / Overheads"
            ok={selectedOverheads.length > 0}
            value={`${selectedOverheads.length} line(s)`}
          />
          <ReadinessLine
            label="Outputs"
            ok={selectedOutputs.length > 0}
            value={`${selectedOutputs.length} line(s)`}
          />
        </Panel>

        <OrderWorkflowMiniCard status={selectedOrder.status} />

        <Panel title="Completion Readiness" subtitle="Operational status">
          <ReadinessLine
            label="Approved"
            ok={[
              "approved",
              "materials_reserved",
              "materials_issued",
              "in_progress",
              "completed",
            ].includes(String(selectedOrder.status))}
            value={statusLabel(selectedOrder.status)}
          />
          <ReadinessLine
            label="Materials Reserved"
            ok={[
              "materials_reserved",
              "materials_issued",
              "in_progress",
              "completed",
            ].includes(String(selectedOrder.status))}
            value={statusLabel(selectedOrder.status)}
          />
          <ReadinessLine
            label="Materials Issued"
            ok={["materials_issued", "in_progress", "completed"].includes(
              String(selectedOrder.status)
            )}
            value={statusLabel(selectedOrder.status)}
          />
          <ReadinessLine
            label="Production Started"
            ok={["in_progress", "completed"].includes(
              String(selectedOrder.status)
            )}
            value={statusLabel(selectedOrder.status)}
          />
          <ReadinessLine
            label="Completed"
            ok={String(selectedOrder.status) === "completed"}
            value={statusLabel(selectedOrder.status)}
          />
        </Panel>
      </div>
    </div>
  );
}

function ProductionStageCard({ stage, index, status }) {
  const Icon = stage.icon;
  const ButtonIcon = stage.buttonIcon;

  const cls = stage.isCurrent
    ? "stage-card is-current"
    : stage.isDone
      ? "stage-card is-done"
      : "stage-card";

  return (
    <div className={cls}>
      <div className="stage-number">{stage.number}</div>

      <div className="stage-icon-wrap">
        <Icon className="h-5 w-5" />
        {stage.isCurrent && <span className="stage-pulse" />}
      </div>

      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2">
          <div className="stage-title">{stage.title}</div>
          <span className="stage-label">{stage.actionLabel}</span>
        </div>

        <div className="stage-subtitle">{stage.subtitle}</div>
        <div className="stage-description">{stage.description}</div>
      </div>

      <div className="stage-action">
        <button
          type="button"
          disabled={stage.disabled}
          onClick={stage.onClick}
          className={`stage-button ${stage.success ? "success" : ""}`}
        >
          <ButtonIcon className="h-3.5 w-3.5" />
          {stage.buttonText}
        </button>

        <div className="stage-status-text">
          {stage.isCurrent
            ? "Current Stage"
            : stage.isDone
              ? "Done"
              : status === "cancelled"
                ? "Stopped"
                : "Pending"}
        </div>
      </div>

      {index < 5 && <div className="stage-connector" />}
    </div>
  );
}

function ProductionMotionScene({ status }) {
  const cleanStatus = String(status || "draft");

  const statusText = {
    draft: "Order generated from converted plan",
    approved: "Approved for production",
    materials_reserved: "Materials locked for this order",
    materials_issued: "Materials issued to production",
    in_progress: "Production running on shop floor",
    completed: "Finished goods received into stock",
    cancelled: "Production stopped",
  };

  return (
    <div className="production-scene">
      <div className="production-scene-header">
        <div>
          <div className="production-scene-title">Live Production Flow</div>
          <div className="production-scene-subtitle">
            {statusText[cleanStatus] || statusText.draft}
          </div>
        </div>

        <div className={`production-live-badge ${cleanStatus}`}>
          {statusLabel(cleanStatus)}
        </div>
      </div>

      <div className="factory-animation">
        <div className="factory-building">
          <Factory className="h-9 w-9" />
          <div className="smoke smoke-one" />
          <div className="smoke smoke-two" />
          <div className="smoke smoke-three" />
        </div>

        <div className="conveyor">
          <div className="belt-lines" />
          <div className={`box box-one ${cleanStatus}`} />
          <div className={`box box-two ${cleanStatus}`} />
          <div className={`box box-three ${cleanStatus}`} />
        </div>

        <div className="warehouse">
          <PackageSearch className="h-8 w-8" />
          <div className="warehouse-label">Finished Stock</div>
        </div>
      </div>
    </div>
  );
}

function WorkflowSideButton({
  icon: Icon,
  title,
  text,
  onClick,
  green = false,
  danger = false,
}) {
  let cls = "border-[#D8DFDF] bg-white hover:border-[#004343]";
  if (green) cls = "border-[#ABEFC6] bg-[#ECFDF3]";
  if (danger) cls = "border-[#FECDCA] bg-[#FEF3F2]";

  return (
    <button
      type="button"
      onClick={onClick}
      className={`min-h-[72px] border p-2 text-left ${cls}`}
    >
      <div className="flex items-center gap-1 text-[11px] font-semibold uppercase text-[#0C1B1B]">
        <Icon className="h-3.5 w-3.5" />
        {title}
      </div>
      <div className="mt-1 text-[11px] font-medium leading-4 text-[#627474]">
        {text}
      </div>
    </button>
  );
}

function OrderWorkflowMiniCard({ status }) {
  const cleanStatus = String(status || "draft");
  const currentIndex = Math.max(0, orderStatusOrder.indexOf(cleanStatus));

  const stages = [
    ["draft", "Draft", ClipboardList],
    ["approved", "Approved", ShieldCheck],
    ["materials_reserved", "Reserved", Truck],
    ["materials_issued", "Issued", Layers],
    ["in_progress", "Running", PlayCircle],
    ["completed", "Done", CheckCircle2],
  ];

  return (
    <Panel title="Workflow Position" subtitle="Current production order stage">
      <div className="workflow-mini-track">
        {stages.map(([key, label, Icon], index) => {
          const done = currentIndex >= index && cleanStatus !== "cancelled";
          const current = cleanStatus === key;

          return (
            <div
              key={key}
              className={`workflow-mini-stage ${
                current ? "is-current" : done ? "is-done" : ""
              }`}
            >
              <div className="workflow-mini-node">
                <Icon className="h-3.5 w-3.5" />
              </div>
              <div className="workflow-mini-label">{label}</div>
            </div>
          );
        })}
      </div>

      {cleanStatus === "cancelled" && (
        <div className="mt-3 border border-[#FECDCA] bg-[#FEF3F2] p-2 text-[11px] font-semibold uppercase text-[#B42318]">
          Production order cancelled
        </div>
      )}
    </Panel>
  );
}

function ConfirmActionModal({
  open,
  title,
  message,
  confirmText,
  danger,
  loading,
  onClose,
  onConfirm,
}) {
  if (!open) return null;

  return (
    <EnterpriseModalShell
      title={title}
      subtitle="Please review before continuing"
      onClose={onClose}
      maxWidth="max-w-lg"
      footer={
        <>
          <ModalSecondaryButton onClick={onClose} disabled={loading}>
            Keep Order
          </ModalSecondaryButton>
          <ModalPrimaryButton onClick={onConfirm} disabled={loading} danger={danger}>
            {loading ? "Processing..." : confirmText}
          </ModalPrimaryButton>
        </>
      }
    >
      <div className="border border-[#FECACA] bg-[#FEF2F2] p-3 text-[12px] font-medium leading-5 text-[#991B1B]">
        {message}
      </div>
    </EnterpriseModalShell>
  );
}

function OverheadEntryModal({
  open,
  form,
  setForm,
  selectedOrder,
  loading,
  onClose,
  onSubmit,
}) {
  if (!open) return null;

  return (
    <EnterpriseModalShell
      title="Add Staff / Production Overhead"
      subtitle={selectedOrder?.production_number || "Production overhead capture"}
      onClose={onClose}
      maxWidth="max-w-2xl"
      footer={
        <>
          <ModalSecondaryButton onClick={onClose} disabled={loading}>
            Cancel
          </ModalSecondaryButton>
          <ModalPrimaryButton onClick={onSubmit} disabled={loading}>
            {loading ? "Saving..." : "Save Cost"}
          </ModalPrimaryButton>
        </>
      }
    >
      <div className="grid gap-3 md:grid-cols-2">
        <ModalField label="Cost Type">
          <select
            value={form.cost_type}
            onChange={(event) =>
              setForm((prev) => ({ ...prev, cost_type: event.target.value }))
            }
            className="enterprise-input"
          >
            {overheadTypes.map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </select>
        </ModalField>

        <ModalField label="Amount">
          <input
            type="number"
            step="0.0001"
            value={form.amount}
            onChange={(event) =>
              setForm((prev) => ({ ...prev, amount: event.target.value }))
            }
            className="enterprise-input"
          />
        </ModalField>

        <ModalField label="Description / Staff Name" span>
          <textarea
            rows={3}
            value={form.description}
            onChange={(event) =>
              setForm((prev) => ({ ...prev, description: event.target.value }))
            }
            placeholder="Example: Brick moulding labour team, electricity for kiln, diesel for mixer..."
            className="enterprise-textarea"
          />
        </ModalField>
      </div>

      <div className="mt-3 border border-[#A4E5E4] bg-[#F1F8F8] p-3 text-[11px] font-medium leading-5 text-[#057B7B]">
        This cost is attached to the production order and included in actual
        production costing when the order is completed.
      </div>
    </EnterpriseModalShell>
  );
}

function MaterialActionModal({
  open,
  form,
  setForm,
  loading,
  onClose,
  onSubmit,
}) {
  if (!open) return null;

  const isReturn = form.action === "return";
  const materialName = getInputProductName(form.input);

  return (
    <EnterpriseModalShell
      title={isReturn ? "Return Production Material" : "Record Material Wastage"}
      subtitle={materialName}
      onClose={onClose}
      maxWidth="max-w-2xl"
      footer={
        <>
          <ModalSecondaryButton onClick={onClose} disabled={loading}>
            Cancel
          </ModalSecondaryButton>
          <ModalPrimaryButton onClick={onSubmit} disabled={loading} danger={!isReturn}>
            {loading ? "Saving..." : isReturn ? "Return Material" : "Record Wastage"}
          </ModalPrimaryButton>
        </>
      }
    >
      <div className="grid gap-3 md:grid-cols-3">
        <SmallMetric label="Material" value={materialName} />
        <SmallMetric label="Issued" value={qtyNumber(getIssuedQty(form.input))} />
        <SmallMetric
          label="Already Returned"
          value={qtyNumber(getReturnedQty(form.input))}
        />
      </div>

      <div className="mt-3 grid gap-3 md:grid-cols-2">
        <ModalField label={isReturn ? "Return Quantity" : "Wastage Quantity"}>
          <input
            type="number"
            step="0.0001"
            value={form.quantity}
            onChange={(event) =>
              setForm((prev) => ({ ...prev, quantity: event.target.value }))
            }
            className="enterprise-input"
          />
        </ModalField>

        <ModalField label="Unit">
          <input
            value={getUnitName(form.input)}
            disabled
            className="enterprise-input disabled:bg-[#EEF2F2]"
          />
        </ModalField>

        <ModalField label="Notes" span>
          <textarea
            rows={3}
            value={form.notes}
            onChange={(event) =>
              setForm((prev) => ({ ...prev, notes: event.target.value }))
            }
            className="enterprise-textarea"
          />
        </ModalField>
      </div>
    </EnterpriseModalShell>
  );
}

function CompletionModal({
  open,
  form,
  setForm,
  loading,
  selectedOrder,
  summary,
  onClose,
  onSubmit,
}) {
  if (!open) return null;

  const good = numberValue(form.actual_good_quantity);
  const reject = numberValue(form.actual_reject_quantity);
  const waste = numberValue(form.actual_waste_quantity);
  const totalOutput = good + reject + waste;

  const yieldPercent =
    numberValue(selectedOrder?.planned_output_quantity) > 0
      ? (good / numberValue(selectedOrder?.planned_output_quantity)) * 100
      : 0;

  return (
    <EnterpriseModalShell
      title="Complete Production / Capture Output"
      subtitle={selectedOrder?.production_number || "Finished goods receiving"}
      onClose={onClose}
      maxWidth="max-w-3xl"
      footer={
        <>
          <ModalSecondaryButton onClick={onClose} disabled={loading}>
            Cancel
          </ModalSecondaryButton>
          <ModalPrimaryButton onClick={onSubmit} disabled={loading}>
            {loading ? "Completing..." : "Complete Production"}
          </ModalPrimaryButton>
        </>
      }
    >
      <div className="grid gap-2 md:grid-cols-4">
        <SmallMetric
          label="Finished Product"
          value={getFinishedProductName(selectedOrder)}
        />
        <SmallMetric
          label="Planned Output"
          value={qtyNumber(selectedOrder?.planned_output_quantity)}
        />
        <SmallMetric
          label="Current Unit Cost"
          value={moneyNumber(summary.unitCost)}
        />
        <SmallMetric label="Status" value={statusLabel(selectedOrder?.status)} />
      </div>

      <div className="mt-3 grid gap-3 md:grid-cols-3">
        <ModalField label="Actual Good Quantity">
          <input
            type="number"
            step="0.0001"
            value={form.actual_good_quantity}
            onChange={(event) =>
              setForm((prev) => ({
                ...prev,
                actual_good_quantity: event.target.value,
              }))
            }
            className="enterprise-input"
          />
        </ModalField>

        <ModalField label="Reject Quantity">
          <input
            type="number"
            step="0.0001"
            value={form.actual_reject_quantity}
            onChange={(event) =>
              setForm((prev) => ({
                ...prev,
                actual_reject_quantity: event.target.value,
              }))
            }
            className="enterprise-input"
          />
        </ModalField>

        <ModalField label="Finished Goods Waste">
          <input
            type="number"
            step="0.0001"
            value={form.actual_waste_quantity}
            onChange={(event) =>
              setForm((prev) => ({
                ...prev,
                actual_waste_quantity: event.target.value,
              }))
            }
            className="enterprise-input"
          />
        </ModalField>
      </div>

      <div className="mt-3 grid gap-2 md:grid-cols-3">
        <SmallMetric label="Total Captured Output" value={qtyNumber(totalOutput)} />
        <SmallMetric label="Calculated Yield" value={`${yieldPercent.toFixed(1)}%`} />
        <SmallMetric label="Expected Cost" value={moneyNumber(summary.totalCost)} />
      </div>

      <div className="mt-3 border border-[#ABEFC6] bg-[#ECFDF3] p-3 text-[11px] font-medium leading-5 text-[#067647]">
        Completing production receives the good finished quantity into stock,
        calculates actual unit cost, creates output records, and moves the order
        to completed.
      </div>
    </EnterpriseModalShell>
  );
}

function EnterpriseModalShell({
  title,
  subtitle,
  children,
  footer,
  onClose,
  maxWidth = "max-w-xl",
}) {
  return (
    <div className="fixed inset-0 z-[100000] flex items-center justify-center bg-black/55 p-4">
      <div className={`w-full ${maxWidth} border border-[#ACB8B8] bg-white shadow-2xl`}>
        <div className="flex min-h-[48px] items-center justify-between border-b border-[#00BCBC] bg-[#004142] px-3">
          <div>
            <h2 className="text-[12px] font-semibold uppercase tracking-wide text-white">
              {title}
            </h2>

            {subtitle && (
              <div className="mt-0.5 text-[10px] font-medium uppercase text-white/75">
                {subtitle}
              </div>
            )}
          </div>

          <button
            type="button"
            onClick={onClose}
            className="h-8 w-8 text-[20px] font-medium leading-none text-white hover:bg-white/10"
          >
            ×
          </button>
        </div>

        <div className="bg-[#DFE5E5] p-2">
          <div className="max-h-[72vh] overflow-y-auto border border-[#CAD5D5] bg-white p-3">
            {children}
          </div>
        </div>

        <div className="flex min-h-[48px] items-center justify-end gap-2 border-t border-[#CAD5D5] bg-[#F8FAFC] px-3">
          {footer}
        </div>
      </div>
    </div>
  );
}

function ModalField({ label, children, span = false }) {
  return (
    <label className={span ? "block md:col-span-2" : "block"}>
      <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">
        {label}
      </div>
      {children}
    </label>
  );
}

function ModalPrimaryButton({ children, onClick, disabled, danger = false }) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      className={`h-8 px-4 text-[11px] font-semibold uppercase text-white disabled:opacity-60 ${
        danger ? "bg-[#B42318]" : "bg-[#069495]"
      }`}
    >
      {children}
    </button>
  );
}

function ModalSecondaryButton({ children, onClick, disabled }) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      className="h-8 border border-[#ACB8B8] bg-white px-4 text-[11px] font-semibold uppercase text-[#003636] disabled:opacity-60"
    >
      {children}
    </button>
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

function CostVisual({ materialCost, overheadCost }) {
  const total = materialCost + overheadCost;

  return (
    <Panel
      title="Production Cost Composition"
      subtitle="Material, staff and overhead cost split"
    >
      <div className="grid gap-2 md:grid-cols-[1fr_220px]">
        <div className="space-y-2">
          <CostBar label="Materials" value={materialCost} total={total} />
          <CostBar label="Staff / Overheads" value={overheadCost} total={total} />
        </div>

        <div className="border border-[#D8DFDF] bg-[#F8FAFC] p-3">
          <div className="text-[10px] font-semibold uppercase text-[#627474]">
            Total Production Cost
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

function ReadinessLine({ label, ok, value }) {
  return (
    <div className="flex items-center justify-between border-b border-[#EFF2F2] py-2 text-[11px] last:border-b-0">
      <span className="font-semibold text-[#324443]">{label}</span>

      <span
        className={
          ok ? "font-semibold text-[#067647]" : "font-semibold text-[#B42318]"
        }
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
        <span>Yield</span>
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
        className={`mt-1 truncate text-[13px] font-semibold ${
          danger ? "text-[#B42318]" : "text-[#004343]"
        }`}
      >
        {value}
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

function WorkflowInput({ label, value, onChange, type = "text" }) {
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

function WorkflowSelect({ label, value, onChange, options }) {
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

function ProductionWorkflowStyles() {
  return (
    <style>
      {`
        .enterprise-input {
          width: 100%;
          height: 34px;
          border: 1px solid #93A6A6;
          background: #ffffff;
          padding: 0 8px;
          font-size: 12px;
          outline: none;
          border-radius: 0 !important;
        }

        .enterprise-input:focus {
          border-color: #069495;
          box-shadow: inset 0 0 0 1px #93F5F4;
        }

        .enterprise-textarea {
          width: 100%;
          border: 1px solid #93A6A6;
          background: #ffffff;
          padding: 8px;
          font-size: 12px;
          outline: none;
          resize: vertical;
          border-radius: 0 !important;
        }

        .enterprise-textarea:focus {
          border-color: #069495;
          box-shadow: inset 0 0 0 1px #93F5F4;
        }

        .production-scene {
          border: 1px solid #AFB9B9;
          background: linear-gradient(180deg, #F8FAFC 0%, #EEF2F2 100%);
          padding: 10px;
        }

        .production-scene-header {
          display: flex;
          align-items: center;
          justify-content: space-between;
          gap: 10px;
          margin-bottom: 10px;
        }

        .production-scene-title {
          font-size: 12px;
          font-weight: 700;
          text-transform: uppercase;
          color: #004343;
        }

        .production-scene-subtitle {
          margin-top: 2px;
          font-size: 11px;
          font-weight: 500;
          color: #627474;
        }

        .production-live-badge {
          border: 1px solid #B9C3C3;
          background: white;
          padding: 5px 8px;
          font-size: 10px;
          font-weight: 800;
          text-transform: uppercase;
          color: #324443;
        }

        .production-live-badge.in_progress,
        .production-live-badge.materials_issued {
          border-color: #F59E0B;
          background: #FFFBEB;
          color: #92400E;
        }

        .production-live-badge.completed {
          border-color: #ABEFC6;
          background: #ECFDF3;
          color: #067647;
        }

        .production-live-badge.cancelled {
          border-color: #FECDCA;
          background: #FEF3F2;
          color: #B42318;
        }

        .factory-animation {
          position: relative;
          display: grid;
          grid-template-columns: 90px minmax(220px, 1fr) 120px;
          align-items: center;
          gap: 12px;
          min-height: 90px;
          overflow: hidden;
        }

        .factory-building {
          position: relative;
          display: flex;
          height: 72px;
          align-items: center;
          justify-content: center;
          border: 1px solid #AFB9B9;
          background: #ffffff;
          color: #004343;
        }

        .smoke {
          position: absolute;
          top: 6px;
          width: 8px;
          height: 8px;
          border-radius: 999px;
          background: rgba(99, 120, 119, 0.35);
          animation: smoke-rise 2.8s infinite ease-in-out;
        }

        .smoke-one {
          left: 22px;
          animation-delay: 0s;
        }

        .smoke-two {
          left: 36px;
          animation-delay: 0.5s;
        }

        .smoke-three {
          left: 50px;
          animation-delay: 1s;
        }

        @keyframes smoke-rise {
          0% {
            transform: translateY(12px) scale(0.7);
            opacity: 0;
          }

          35% {
            opacity: 0.65;
          }

          100% {
            transform: translateY(-18px) scale(1.4);
            opacity: 0;
          }
        }

        .conveyor {
          position: relative;
          height: 44px;
          border: 1px solid #AFB9B9;
          background: #D8DFDF;
          overflow: hidden;
        }

        .belt-lines {
          position: absolute;
          inset: 0;
          background-image: repeating-linear-gradient(
            90deg,
            rgba(9, 27, 27, 0.18) 0,
            rgba(9, 27, 27, 0.18) 12px,
            transparent 12px,
            transparent 24px
          );
          animation: belt-move 1.2s linear infinite;
        }

        @keyframes belt-move {
          from {
            transform: translateX(0);
          }

          to {
            transform: translateX(24px);
          }
        }

        .box {
          position: absolute;
          top: 12px;
          width: 20px;
          height: 20px;
          border: 1px solid #004343;
          background: #00BCBC;
          animation: box-move 4.2s linear infinite;
        }

        .box.draft,
        .box.approved {
          animation-play-state: paused;
          background: #93A6A6;
        }

        .box.completed {
          background: #027A48;
        }

        .box.cancelled {
          animation-play-state: paused;
          background: #B42318;
        }

        .box-one {
          left: 10%;
          animation-delay: 0s;
        }

        .box-two {
          left: 35%;
          animation-delay: 0.7s;
        }

        .box-three {
          left: 65%;
          animation-delay: 1.4s;
        }

        @keyframes box-move {
          0% {
            transform: translateX(-80px);
          }

          100% {
            transform: translateX(520px);
          }
        }

        .warehouse {
          display: flex;
          height: 72px;
          flex-direction: column;
          align-items: center;
          justify-content: center;
          border: 1px solid #AFB9B9;
          background: white;
          color: #004343;
        }

        .warehouse-label {
          margin-top: 4px;
          font-size: 9px;
          font-weight: 800;
          text-transform: uppercase;
          color: #627474;
        }

        .stage-card {
          position: relative;
          display: grid;
          grid-template-columns: 44px 46px minmax(0, 1fr) 150px;
          align-items: center;
          gap: 10px;
          border: 1px solid #CFD9D8;
          background: #FFFFFF;
          padding: 10px;
          overflow: hidden;
        }

        .stage-card.is-current {
          border-color: #00BCBC;
          background: #E8F7F5;
          box-shadow: inset 4px 0 0 #00BCBC;
        }

        .stage-card.is-done {
          border-color: #ABEFC6;
          background: #F6FEF9;
          box-shadow: inset 4px 0 0 #12B76A;
        }

        .stage-number {
          font-size: 16px;
          font-weight: 900;
          color: #96A5A4;
        }

        .stage-card.is-current .stage-number {
          color: #057B7C;
        }

        .stage-card.is-done .stage-number {
          color: #067647;
        }

        .stage-icon-wrap {
          position: relative;
          display: flex;
          height: 36px;
          width: 36px;
          align-items: center;
          justify-content: center;
          border: 1px solid #AFB9B9;
          background: #F8FAFC;
          color: #004343;
        }

        .stage-card.is-current .stage-icon-wrap {
          border-color: #00BCBC;
          background: #CCFBF1;
          color: #005A5A;
        }

        .stage-card.is-done .stage-icon-wrap {
          border-color: #ABEFC6;
          background: #ECFDF3;
          color: #067647;
        }

        .stage-pulse {
          position: absolute;
          inset: -5px;
          border: 2px solid rgba(0, 188, 188, 0.5);
          animation: stage-pulse 1.5s infinite ease-out;
        }

        @keyframes stage-pulse {
          from {
            transform: scale(0.9);
            opacity: 0.75;
          }

          to {
            transform: scale(1.25);
            opacity: 0;
          }
        }

        .stage-title {
          font-size: 12px;
          font-weight: 800;
          text-transform: uppercase;
          color: #0C1B1B;
        }

        .stage-subtitle {
          margin-top: 2px;
          font-size: 10px;
          font-weight: 700;
          text-transform: uppercase;
          color: #627474;
        }

        .stage-description {
          margin-top: 4px;
          font-size: 11px;
          font-weight: 500;
          line-height: 16px;
          color: #465757;
        }

        .stage-label {
          border: 1px solid #CFD6D6;
          background: #F9FAFB;
          padding: 2px 6px;
          font-size: 9px;
          font-weight: 800;
          text-transform: uppercase;
          color: #465757;
        }

        .stage-card.is-current .stage-label {
          border-color: #93F5F4;
          background: #CCFBF1;
          color: #005A5A;
        }

        .stage-card.is-done .stage-label {
          border-color: #ABEFC6;
          background: #ECFDF3;
          color: #067647;
        }

        .stage-action {
          display: flex;
          flex-direction: column;
          align-items: stretch;
          gap: 5px;
        }

        .stage-button {
          display: inline-flex;
          height: 30px;
          align-items: center;
          justify-content: center;
          gap: 5px;
          border: 1px solid #004343;
          background: #004344;
          padding: 0 8px;
          font-size: 10px;
          font-weight: 800;
          text-transform: uppercase;
          color: white;
        }

        .stage-button.success {
          border-color: #027A48;
          background: #027A48;
        }

        .stage-button:disabled {
          cursor: not-allowed;
          border-color: #CFD6D6;
          background: #E9EDED;
          color: #96A5A4;
        }

        .stage-status-text {
          text-align: center;
          font-size: 9px;
          font-weight: 800;
          text-transform: uppercase;
          color: #627474;
        }

        .stage-connector {
          position: absolute;
          left: 31px;
          bottom: -10px;
          height: 20px;
          border-left: 2px dashed #C4CDCD;
        }

        .workflow-mini-track {
          display: grid;
          grid-template-columns: repeat(6, minmax(0, 1fr));
          gap: 4px;
        }

        .workflow-mini-stage {
          border: 1px solid #CFD6D6;
          background: #F9FAFB;
          padding: 6px 4px;
          text-align: center;
          color: #627474;
        }

        .workflow-mini-stage.is-done {
          border-color: #ABEFC6;
          background: #ECFDF3;
          color: #067647;
        }

        .workflow-mini-stage.is-current {
          border-color: #00BCBC;
          background: #CCFBF1;
          color: #005A5A;
          animation: current-glow 1.4s infinite alternate;
        }

        @keyframes current-glow {
          from {
            box-shadow: 0 0 0 rgba(0, 188, 188, 0);
          }

          to {
            box-shadow: 0 0 12px rgba(0, 188, 188, 0.35);
          }
        }

        .workflow-mini-node {
          display: flex;
          justify-content: center;
        }

        .workflow-mini-label {
          margin-top: 4px;
          font-size: 9px;
          font-weight: 800;
          text-transform: uppercase;
        }

        @media (max-width: 1100px) {
          .stage-card {
            grid-template-columns: 36px 40px minmax(0, 1fr);
          }

          .stage-action {
            grid-column: 1 / -1;
          }

          .factory-animation {
            grid-template-columns: 70px minmax(180px, 1fr) 90px;
          }
        }
      `}
    </style>
  );
}
import React, { useEffect, useMemo, useState } from "react";
import {
  BarChart3,
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

const getPlanItems = (plan) => {
  if (!plan) return [];
  if (Array.isArray(plan.items)) return plan.items;
  if (Array.isArray(plan.production_plan_items)) return plan.production_plan_items;
  return [];
};

const getPlanRequirements = (plan) => {
  if (!plan) return [];
  if (Array.isArray(plan.material_requirements)) return plan.material_requirements;
  if (Array.isArray(plan.materialRequirements)) return plan.materialRequirements;
  return [];
};

const getPlanOrders = (plan) => {
  if (!plan) return [];
  if (Array.isArray(plan.production_orders)) return plan.production_orders;
  if (Array.isArray(plan.productionOrders)) return plan.productionOrders;
  return [];
};

const getOrderInputs = (order) => {
  if (!order) return [];
  if (Array.isArray(order.inputs)) return order.inputs;
  if (Array.isArray(order.production_order_inputs)) return order.production_order_inputs;
  return [];
};

const getOrderOutputs = (order) => {
  if (!order) return [];
  if (Array.isArray(order.outputs)) return order.outputs;
  if (Array.isArray(order.production_order_outputs)) return order.production_order_outputs;
  return [];
};

const getOrderOverheads = (order) => {
  if (!order) return [];
  if (Array.isArray(order.overheads)) return order.overheads;
  if (Array.isArray(order.production_order_overheads)) return order.production_order_overheads;
  return [];
};

const getFinishedProductName = (order) =>
  order?.finished_product?.name ||
  order?.finishedProduct?.name ||
  order?.product?.name ||
  order?.metadata?.finished_product_name ||
  "-";

const getPlanItemProductName = (item) =>
  item?.finished_product?.name ||
  item?.finishedProduct?.name ||
  item?.metadata?.product_name ||
  item?.metadata?.finished_product_name ||
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

const getRequirementProductName = (row) =>
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
  numberValue(row?.issued_quantity || row?.actual_quantity || row?.consumed_quantity);

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

export default function ManufacturingOrderRegisterModal({
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
  const [activeTab, setActiveTab] = useState("plan_orders");

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
      showNotice(getErrorMessage(error, "Failed to load converted production plans."));
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

      setActiveTab("plan_orders");

      if (notify) showNotice("Converted plan details refreshed.");
    } catch (error) {
      showNotice(getErrorMessage(error, "Failed to load converted plan details."));
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
      setActiveTab("inputs");

      if (notify) showNotice("Production order details refreshed.");
    } catch (error) {
      showNotice(getErrorMessage(error, "Failed to load production order details."));
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

  const selectedPlanItems = useMemo(() => getPlanItems(selectedPlan), [selectedPlan]);

  const selectedPlanRequirements = useMemo(
    () => getPlanRequirements(selectedPlan),
    [selectedPlan]
  );

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

  const selectedInputs = useMemo(() => getOrderInputs(selectedOrder), [selectedOrder]);
  const selectedOutputs = useMemo(() => getOrderOutputs(selectedOrder), [selectedOrder]);
  const selectedOverheads = useMemo(
    () => getOrderOverheads(selectedOrder),
    [selectedOrder]
  );

  const planSummary = useMemo(() => {
    const plannedQty = selectedPlanItems.reduce(
      (sum, item) => sum + numberValue(item.planned_quantity),
      0
    );

    const batches = selectedPlanItems.reduce(
      (sum, item) => sum + numberValue(item.planned_batches),
      0
    );

    const requirementQty = selectedPlanRequirements.reduce(
      (sum, item) => sum + numberValue(item.required_quantity),
      0
    );

    const shortageQty = selectedPlanRequirements.reduce(
      (sum, item) => sum + numberValue(item.shortage_quantity),
      0
    );

    const totalCost = selectedPlanItems.reduce(
      (sum, item) => sum + numberValue(item.planned_total_cost),
      0
    );

    const completedOrders = selectedPlanOrders.filter(
      (order) => order.status === "completed"
    ).length;

    return {
      plannedQty,
      batches,
      requirementQty,
      shortageQty,
      totalCost,
      unitCost: plannedQty > 0 ? totalCost / plannedQty : 0,
      orderCount: selectedPlanOrders.length,
      completedOrders,
      openOrders: selectedPlanOrders.length - completedOrders,
    };
  }, [selectedPlanItems, selectedPlanRequirements, selectedPlanOrders]);

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

  const maxRequirementQty = useMemo(
    () =>
      Math.max(
        ...selectedPlanRequirements.map((item) =>
          numberValue(item.required_quantity)
        ),
        0
      ),
    [selectedPlanRequirements]
  );

  const refreshAfterOrderAction = async (orderId) => {
    await loadOrders();
    if (selectedPlanId) await loadPlanDetails(selectedPlanId, false);
    if (orderId) await loadOrderDetails(orderId, false);
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
      title="PRODUCTION CONTROL"
      subtitle="Converted plans, generated orders, production execution, outputs, staff costs and completion"
    >
      <Notice notice={notice} />
      <LoadingPill loading={loading || detailLoading} />

      <div className="h-[calc(100vh-86px)] min-h-0 overflow-hidden bg-[#EEF1F1] p-2 text-[#1C2C2B]">
        <div className="flex h-full min-h-0 flex-col border border-[#AAB5B5] bg-white">
          <ProductionToolbar
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

          <div className="grid min-h-[260px] grid-cols-[minmax(470px,58%)_minmax(380px,42%)] border-b border-[#AAB5B5]">
            <ConvertedPlanGrid
              plans={filteredConvertedPlans}
              selectedPlanId={selectedPlanId}
              loadPlanDetails={loadPlanDetails}
            />

            {selectedPlan ? (
              <PlanControlPanel
                selectedPlan={selectedPlan}
                selectedPlanItems={selectedPlanItems}
                selectedPlanRequirements={selectedPlanRequirements}
                selectedPlanOrders={selectedPlanOrders}
                selectedOrder={selectedOrder}
                planSummary={planSummary}
                loadOrderDetails={loadOrderDetails}
                reload={() => selectedPlan && loadPlanDetails(selectedPlan.id, true)}
              />
            ) : (
              <section className="min-w-0 bg-[#F7F8FA]">
                <SectionTitle title="Converted Plan Details" subtitle="Control panel" />
                <div className="flex h-[232px] items-center justify-center text-[12px] font-semibold text-[#627474]">
                  Select a converted production plan.
                </div>
              </section>
            )}
          </div>

          <div className="flex min-h-0 flex-1 flex-col">
            <BottomTabs
              activeTab={activeTab}
              setActiveTab={setActiveTab}
              hasSelectedOrder={!!selectedOrder}
            />

            <div className="min-h-0 flex-1 overflow-hidden bg-white">
              {!selectedPlan ? (
                <EmptyBlock text="Select a converted plan to see its production orders." />
              ) : (
                <>
                  {activeTab === "plan_orders" && (
                    <PlanOrdersTab
                      selectedPlanOrders={selectedPlanOrders}
                      selectedOrderId={selectedOrderId}
                      loadOrderDetails={loadOrderDetails}
                    />
                  )}

                  {activeTab === "plan_items" && (
                    <PlanItemsTab selectedPlanItems={selectedPlanItems} />
                  )}

                  {activeTab === "plan_materials" && (
                    <PlanMaterialsTab
                      selectedPlanRequirements={selectedPlanRequirements}
                      maxRequirementQty={maxRequirementQty}
                    />
                  )}

                  {activeTab === "inputs" && selectedOrder && (
                    <InputsTab
                      selectedInputs={selectedInputs}
                      maxInputQty={maxInputQty}
                      openMaterialActionModal={openMaterialActionModal}
                    />
                  )}

                  {activeTab === "overheads" && selectedOrder && (
                    <OverheadsTab
                      selectedOverheads={selectedOverheads}
                      openOverheadModal={openOverheadModal}
                    />
                  )}

                  {activeTab === "outputs" && selectedOrder && (
                    <OutputsTab
                      selectedOrder={selectedOrder}
                      selectedOutputs={selectedOutputs}
                      summary={orderSummary}
                      openCompletionModal={openCompletionModal}
                    />
                  )}

                  {activeTab === "visuals" && selectedOrder && (
                    <VisualsTab
                      selectedOrder={selectedOrder}
                      selectedInputs={selectedInputs}
                      selectedOverheads={selectedOverheads}
                      selectedOutputs={selectedOutputs}
                      summary={orderSummary}
                    />
                  )}

                  {activeTab === "workflow" && selectedOrder && (
                    <WorkflowTab
                      selectedOrder={selectedOrder}
                      selectedInputs={selectedInputs}
                      selectedOverheads={selectedOverheads}
                      selectedOutputs={selectedOutputs}
                      summary={orderSummary}
                      runOrderAction={runOrderAction}
                      openCompletionModal={openCompletionModal}
                    />
                  )}

                  {["inputs", "overheads", "outputs", "visuals", "workflow"].includes(
                    activeTab
                  ) &&
                    !selectedOrder && (
                      <EmptyBlock text="Select one generated production order from the plan orders tab." />
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
    </FullScreenModal>
  );
}

function ProductionToolbar({
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
            Production execution
          </div>
          <div className="h-4 border-l border-[#AAB5B5]" />
          <div className="text-[10px] font-medium uppercase text-[#627474]">
            Showing converted_to_orders plans only
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

        <PlanSelect
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

function ConvertedPlanGrid({ plans, selectedPlanId, loadPlanDetails }) {
  return (
    <section className="min-w-0 border-r border-[#AAB5B5]">
      <SectionTitle title="Converted Production Plans" subtitle="converted_to_orders only" />

      <div className="h-[232px] overflow-auto">
        <table className="w-full border-collapse text-left text-[11px]">
          <thead className="sticky top-0 z-10 bg-[#0E4D4D] text-white">
            <tr>
              <th className="w-[28px] border-r border-[#465857] px-2 py-1.5 font-semibold">
                #
              </th>
              <th className="border-r border-[#465857] px-2 py-1.5 font-semibold">
                Plan no
              </th>
              <th className="border-r border-[#465857] px-2 py-1.5 font-semibold">
                Name
              </th>
              <th className="border-r border-[#465857] px-2 py-1.5 font-semibold">
                Basis
              </th>
              <th className="border-r border-[#465857] px-2 py-1.5 font-semibold">
                Period
              </th>
              <th className="px-2 py-1.5 font-semibold">Status</th>
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
                    <td className="border-r border-[#CFD9D8] px-2 py-1.5">
                      {index + 1}
                    </td>
                    <td className="border-r border-[#CFD9D8] px-2 py-1.5 font-semibold">
                      {plan.plan_number || "-"}
                    </td>
                    <td className="border-r border-[#CFD9D8] px-2 py-1.5">
                      {plan.name || "-"}
                    </td>
                    <td className="border-r border-[#CFD9D8] px-2 py-1.5 capitalize">
                      {String(plan.planning_basis || "-").replaceAll("_", " ")}
                    </td>
                    <td className="border-r border-[#CFD9D8] px-2 py-1.5">
                      {safeDate(plan.period_start)} - {safeDate(plan.period_end)}
                    </td>
                    <td className="px-2 py-1.5">
                      {statusLabel(plan.status)}
                    </td>
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

function PlanControlPanel({
  selectedPlan,
  selectedPlanItems,
  selectedPlanRequirements,
  selectedPlanOrders,
  selectedOrder,
  planSummary,
  loadOrderDetails,
  reload,
}) {
  return (
    <section className="min-w-0 bg-[#F7F8FA]">
      <SectionTitle title="Converted Plan Details" subtitle="Generated production orders" />

      <div className="h-[232px] overflow-auto p-2">
        <div className="mb-2 grid grid-cols-3 gap-2">
          <FormField label="Plan no" value={selectedPlan.plan_number || "-"} />
          <FormField
            label="Status"
            value={statusLabel(selectedPlan.status)}
            badge={<StatusBadge status={selectedPlan.status} />}
          />
          <FormField
            label="Planning basis"
            value={String(selectedPlan.planning_basis || "-").replaceAll("_", " ")}
          />

          <FormField label="Name" value={selectedPlan.name || "-"} />
          <FormField label="Period start" value={safeDate(selectedPlan.period_start)} />
          <FormField label="Period end" value={safeDate(selectedPlan.period_end)} />

          <FormField label="Orders" value={selectedPlanOrders.length} />
          <FormField label="Completed" value={planSummary.completedOrders} />
          <FormField label="Open" value={planSummary.openOrders} />
        </div>

        <div className="mb-2 grid grid-cols-4 gap-2">
          <MiniMetric title="Plan Items" value={selectedPlanItems.length} icon={Factory} />
          <MiniMetric title="Materials" value={selectedPlanRequirements.length} icon={Layers} />
          <MiniMetric title="Orders" value={selectedPlanOrders.length} icon={ClipboardList} />
          <MiniMetric title="Selected Order" value={selectedOrder?.production_number || "-"} icon={PlayCircle} />
        </div>

        <div className="flex flex-wrap gap-1 border border-[#CFD9D8] bg-white p-1.5">
          <ActionButton onClick={reload} label="Reload" icon={RefreshCw} />

          {selectedPlanOrders.slice(0, 4).map((order) => (
            <ActionButton
              key={order.id}
              onClick={() => loadOrderDetails(order.id)}
              label={order.production_number || "Open Order"}
              icon={Factory}
              primary={selectedOrder?.id === order.id}
            />
          ))}
        </div>
      </div>
    </section>
  );
}

function BottomTabs({ activeTab, setActiveTab, hasSelectedOrder }) {
  const tabs = [
    ["plan_orders", "Generated Orders"],
    ["plan_items", "Plan Items"],
    ["plan_materials", "Plan Materials"],
    ["inputs", "Inputs"],
    ["overheads", "Staff / Overheads"],
    ["outputs", "Outputs"],
    ["visuals", "Visuals"],
    ["workflow", "Workflow"],
  ];

  return (
    <div className="flex h-8 items-end overflow-x-auto border-b border-[#AAB5B5] bg-[#F5F7FA] px-2">
      {tabs.map(([key, label]) => {
        const active = activeTab === key;
        const orderOnly = ["inputs", "overheads", "outputs", "visuals", "workflow"].includes(key);

        return (
          <button
            key={key}
            type="button"
            disabled={orderOnly && !hasSelectedOrder}
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

function PlanOrdersTab({ selectedPlanOrders, selectedOrderId, loadOrderDetails }) {
  return (
    <div className="h-full overflow-auto">
      <div className="border-b border-[#A4E5E4] bg-[#F1F8F8] px-2 py-1.5 text-[11px] font-medium text-[#057B7B]">
        These are the production orders generated from the converted plan. Select an order to reserve, issue, start, capture staff/overheads, and complete production.
      </div>

      <table className="w-full min-w-[1100px] border-collapse text-left text-[11px]">
        <thead className="sticky top-0 z-10 bg-[#0E4D4D] text-white">
          <tr>
            <GridTh>Line</GridTh>
            <GridTh>Production No</GridTh>
            <GridTh>Status</GridTh>
            <GridTh>Finished Product</GridTh>
            <GridTh>Recipe</GridTh>
            <GridTh align="right">Planned Qty</GridTh>
            <GridTh align="right">Actual Good</GridTh>
            <GridTh>Start</GridTh>
            <GridTh>End</GridTh>
            <GridTh>Action</GridTh>
          </tr>
        </thead>

        <tbody>
          {selectedPlanOrders.length === 0 ? (
            <tr>
              <td colSpan={10} className="px-3 py-10 text-center text-[#627474]">
                This converted plan has no visible production orders in the response.
              </td>
            </tr>
          ) : (
            selectedPlanOrders.map((order, index) => {
              const selected = selectedOrderId === order.id;

              return (
                <tr
                  key={order.id || index}
                  className={`border-b border-[#CFD9D8] ${
                    selected ? "bg-[#E8F7F5]" : "bg-white hover:bg-[#F8FAFC]"
                  }`}
                >
                  <GridTd>{index + 1}</GridTd>
                  <GridTd strong>{order.production_number || "-"}</GridTd>
                  <GridTd><StatusBadge status={order.status} /></GridTd>
                  <GridTd>{getFinishedProductName(order)}</GridTd>
                  <GridTd>{getRecipeName(order)}</GridTd>
                  <GridTd right>{qtyNumber(order.planned_output_quantity)}</GridTd>
                  <GridTd right>{qtyNumber(order.actual_good_quantity)}</GridTd>
                  <GridTd>{safeDate(order.planned_start_date)}</GridTd>
                  <GridTd>{safeDate(order.planned_end_date)}</GridTd>
                  <GridTd>
                    <SmallButton onClick={() => loadOrderDetails(order.id)}>
                      Open Production
                    </SmallButton>
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

function PlanItemsTab({ selectedPlanItems }) {
  return (
    <div className="h-full overflow-auto">
      <table className="w-full min-w-[1100px] border-collapse text-left text-[11px]">
        <thead className="sticky top-0 z-10 bg-[#0E4D4D] text-white">
          <tr>
            <GridTh>Line</GridTh>
            <GridTh>Finished Product</GridTh>
            <GridTh>Recipe</GridTh>
            <GridTh align="right">Forecast</GridTh>
            <GridTh align="right">Current Stock</GridTh>
            <GridTh align="right">Safety</GridTh>
            <GridTh align="right">Planned Qty</GridTh>
            <GridTh align="right">Batches</GridTh>
            <GridTh>Required By</GridTh>
          </tr>
        </thead>

        <tbody>
          {selectedPlanItems.length === 0 ? (
            <tr>
              <td colSpan={9} className="px-3 py-10 text-center text-[#627474]">
                No plan items found.
              </td>
            </tr>
          ) : (
            selectedPlanItems.map((item, index) => (
              <tr key={item.id || index} className="border-b border-[#CFD9D8] bg-white hover:bg-[#F8FAFC]">
                <GridTd>{index + 1}</GridTd>
                <GridTd strong>{getPlanItemProductName(item)}</GridTd>
                <GridTd>{getRecipeName(item)}</GridTd>
                <GridTd right>{qtyNumber(item.forecast_quantity)}</GridTd>
                <GridTd right>{qtyNumber(item.current_stock_quantity)}</GridTd>
                <GridTd right>{qtyNumber(item.safety_stock_quantity)}</GridTd>
                <GridTd right>{qtyNumber(item.planned_quantity)}</GridTd>
                <GridTd right>{qtyNumber(item.planned_batches)}</GridTd>
                <GridTd>{safeDate(item.required_by)}</GridTd>
              </tr>
            ))
          )}
        </tbody>
      </table>
    </div>
  );
}

function PlanMaterialsTab({ selectedPlanRequirements, maxRequirementQty }) {
  return (
    <div className="h-full overflow-auto">
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
            <GridTh>Requirement Bar</GridTh>
          </tr>
        </thead>

        <tbody>
          {selectedPlanRequirements.length === 0 ? (
            <tr>
              <td colSpan={8} className="px-3 py-10 text-center text-[#627474]">
                No material requirements found on this converted plan.
              </td>
            </tr>
          ) : (
            selectedPlanRequirements.map((row, index) => (
              <tr key={row.id || index} className="border-b border-[#CFD9D8] bg-white hover:bg-[#F8FAFC]">
                <GridTd>{index + 1}</GridTd>
                <GridTd strong>{getRequirementProductName(row)}</GridTd>
                <GridTd right>{qtyNumber(row.required_quantity)}</GridTd>
                <GridTd right>{qtyNumber(row.available_quantity)}</GridTd>
                <GridTd right>{qtyNumber(row.reserved_quantity)}</GridTd>
                <GridTd right>{qtyNumber(row.shortage_quantity)}</GridTd>
                <GridTd><StatusBadge status={row.status} /></GridTd>
                <GridTd>
                  <MiniBar
                    value={numberValue(row.required_quantity)}
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

function InputsTab({
  selectedInputs,
  maxInputQty,
  openMaterialActionModal,
}) {
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
              <tr key={input.id || index} className="border-b border-[#CFD9D8] bg-white hover:bg-[#F8FAFC]">
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
                  <MiniBar value={numberValue(input.planned_quantity)} max={maxInputQty} />
                </GridTd>
                <GridTd>
                  <div className="flex flex-wrap gap-1">
                    <SmallButton onClick={() => openMaterialActionModal(input, "return")}>
                      Return
                    </SmallButton>
                    <SmallButton onClick={() => openMaterialActionModal(input, "wastage")}>
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
          Capture staff and production overheads here: labour, salary, electricity, fuel, water, rent, maintenance, depreciation or other.
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
              <tr key={row.id || index} className="border-b border-[#CFD9D8] bg-white hover:bg-[#F8FAFC]">
                <GridTd>{index + 1}</GridTd>
                <GridTd strong>{String(row.cost_type || "-").replaceAll("_", " ")}</GridTd>
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

function OutputsTab({ selectedOrder, selectedOutputs, summary, openCompletionModal }) {
  return (
    <div className="h-full overflow-auto bg-[#F7F8FA] p-2">
      <div className="grid gap-2 lg:grid-cols-4">
        <MetricCard title="Planned Output" value={qtyNumber(summary.plannedQty)} icon={Factory} />
        <MetricCard title="Actual Good" value={qtyNumber(summary.actualGood)} icon={CheckCircle2} />
        <MetricCard title="Rejected" value={qtyNumber(summary.actualReject)} icon={XCircle} />
        <MetricCard title="Waste" value={qtyNumber(summary.actualWaste)} icon={Trash2} />
      </div>

      <div className="mt-2 grid gap-2 xl:grid-cols-[1.2fr_0.8fr]">
        <Panel title="Output Capture" subtitle="Finished goods receiving">
          <div className="grid gap-2 md:grid-cols-3">
            <SmallMetric label="Production No" value={selectedOrder.production_number || "-"} />
            <SmallMetric label="Finished Product" value={getFinishedProductName(selectedOrder)} />
            <SmallMetric label="Status" value={statusLabel(selectedOrder.status)} />
            <SmallMetric label="Yield %" value={`${summary.yieldPercent.toFixed(1)}%`} />
            <SmallMetric label="Loss %" value={`${summary.lossPercent.toFixed(1)}%`} danger={summary.lossPercent > 0} />
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
              No output records yet. Complete production to receive finished goods.
            </div>
          ) : (
            <div className="space-y-2">
              {selectedOutputs.map((row, index) => (
                <div key={row.id || index} className="border border-[#D8DFDF] bg-[#F8FAFC] p-2 text-[11px]">
                  <div className="font-semibold text-[#0C1B1B]">
                    {getOutputProductName(row)}
                  </div>
                  <div className="mt-1 grid grid-cols-4 gap-2">
                    <SmallMetric label="Good" value={qtyNumber(row.quantity_produced || row.good_quantity || row.actual_good_quantity)} />
                    <SmallMetric label="Reject" value={qtyNumber(row.quantity_rejected || row.reject_quantity || row.actual_reject_quantity)} />
                    <SmallMetric label="Waste" value={qtyNumber(row.quantity_wasted || row.waste_quantity || row.actual_waste_quantity)} />
                    <SmallMetric label="Unit Cost" value={moneyNumber(row.unit_cost)} />
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
        <MetricCard title="Material Cost" value={moneyNumber(summary.materialCost)} icon={Layers} />
        <MetricCard title="Staff / Overhead" value={moneyNumber(summary.overheadCost)} icon={FileText} />
        <MetricCard title="Total Cost" value={moneyNumber(summary.totalCost)} icon={ClipboardList} />
        <MetricCard title="Unit Cost" value={moneyNumber(summary.unitCost)} icon={Factory} />
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
            <SmallMetric label="Actual Good" value={qtyNumber(summary.actualGood)} />
          </div>
        </Panel>
      </div>

      <div className="mt-2 grid gap-2 xl:grid-cols-3">
        <Panel title="Order Summary" subtitle="Selected production order">
          <ReadinessLine label="Production no" ok value={selectedOrder.production_number || "-"} />
          <ReadinessLine label="Status" ok value={statusLabel(selectedOrder.status)} />
          <ReadinessLine label="Inputs" ok={selectedInputs.length > 0} value={`${selectedInputs.length} line(s)`} />
          <ReadinessLine label="Staff / Overheads" ok={selectedOverheads.length > 0} value={`${selectedOverheads.length} line(s)`} />
          <ReadinessLine label="Outputs" ok={selectedOutputs.length > 0} value={`${selectedOutputs.length} line(s)`} />
        </Panel>

        <OrderWorkflowCard status={selectedOrder.status} />

        <Panel title="Readiness Check" subtitle="Operational completion support">
          <ReadinessLine label="Approved" ok={["approved", "materials_reserved", "materials_issued", "in_progress", "completed"].includes(String(selectedOrder.status))} value={statusLabel(selectedOrder.status)} />
          <ReadinessLine label="Materials Reserved" ok={["materials_reserved", "materials_issued", "in_progress", "completed"].includes(String(selectedOrder.status))} value={statusLabel(selectedOrder.status)} />
          <ReadinessLine label="Materials Issued" ok={["materials_issued", "in_progress", "completed"].includes(String(selectedOrder.status))} value={statusLabel(selectedOrder.status)} />
          <ReadinessLine label="Production Started" ok={["in_progress", "completed"].includes(String(selectedOrder.status))} value={statusLabel(selectedOrder.status)} />
          <ReadinessLine label="Completed" ok={String(selectedOrder.status) === "completed"} value={statusLabel(selectedOrder.status)} />
        </Panel>
      </div>
    </div>
  );
}

function WorkflowTab({
  selectedOrder,
  selectedInputs,
  selectedOverheads,
  selectedOutputs,
  summary,
  runOrderAction,
  openCompletionModal,
}) {
  return (
    <div className="h-full overflow-auto bg-[#F7F8FA] p-2">
      <div className="grid gap-2 xl:grid-cols-3">
        <OrderWorkflowCard status={selectedOrder.status} />

        <Panel title="Action Board" subtitle="Run production workflow steps">
          <div className="grid gap-2">
            <WorkflowButton
              icon={ShieldCheck}
              title="1. Approve"
              text="Confirms that the order is ready for shop-floor processing."
              onClick={() => runOrderAction(selectedOrder, "approve")}
            />

            <WorkflowButton
              icon={Truck}
              title="2. Reserve Materials"
              text="Locks planned raw materials against branch stock."
              onClick={() => runOrderAction(selectedOrder, "reserve")}
            />

            <WorkflowButton
              icon={Layers}
              title="3. Issue Materials"
              text="Consumes raw materials into production and creates stock movement records."
              onClick={() => runOrderAction(selectedOrder, "issue")}
            />

            <WorkflowButton
              icon={PlayCircle}
              title="4. Start Production"
              text="Moves a material-issued order into active production."
              onClick={() => runOrderAction(selectedOrder, "start")}
            />

            <WorkflowButton
              icon={CheckCircle2}
              title="5. Complete Production"
              text="Captures output, reject, waste, receives finished goods and finalizes costing."
              onClick={openCompletionModal}
              green
            />

            <WorkflowButton
              icon={XCircle}
              title="Cancel Order"
              text="Cancels the order before completion."
              onClick={() => runOrderAction(selectedOrder, "cancel")}
              danger
            />
          </div>
        </Panel>

        <Panel title="Activity Guidance" subtitle="What happens behind the scenes">
          <ActivityTip
            icon={ShieldCheck}
            title="Approve"
            text="Production order moves from draft to approved."
          />
          <ActivityTip
            icon={Truck}
            title="Reserve"
            text="Materials are reserved so they cannot be used elsewhere."
          />
          <ActivityTip
            icon={Layers}
            title="Issue"
            text="Raw materials leave stock and become production consumption."
          />
          <ActivityTip
            icon={Undo2}
            title="Return"
            text="Unused issued material can be returned to stock."
          />
          <ActivityTip
            icon={Trash2}
            title="Wastage"
            text="Damaged or lost material is recorded against the order."
          />
          <ActivityTip
            icon={CheckCircle2}
            title="Complete"
            text="Finished goods are received into stock and actual unit cost is calculated."
          />

          <div className="mt-2 border border-[#D8DFDF] bg-[#F8FAFC] p-2">
            <ReadinessLine label="Inputs" ok={selectedInputs.length > 0} value={`${selectedInputs.length} line(s)`} />
            <ReadinessLine label="Staff / Overheads" ok={selectedOverheads.length > 0} value={`${selectedOverheads.length} line(s)`} />
            <ReadinessLine label="Outputs" ok={selectedOutputs.length > 0} value={`${selectedOutputs.length} line(s)`} />
            <ReadinessLine label="Yield" ok={summary.yieldPercent >= 95 || String(selectedOrder.status) !== "completed"} value={`${summary.yieldPercent.toFixed(1)}%`} />
          </div>
        </Panel>
      </div>
    </div>
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
        This cost will be added to the production order and will be included in final actual costing when the order is completed.
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
        <SmallMetric label="Already Returned" value={qtyNumber(getReturnedQty(form.input))} />
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
        <SmallMetric label="Finished Product" value={getFinishedProductName(selectedOrder)} />
        <SmallMetric label="Planned Output" value={qtyNumber(selectedOrder?.planned_output_quantity)} />
        <SmallMetric label="Current Unit Cost" value={moneyNumber(summary.unitCost)} />
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
        Completing production will receive the good finished quantity into stock, calculate actual unit cost, create output records, and move the order to completed.
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
          `}
        </style>
      </div>
    </div>
  );
}

function ModalField({ label, children, span = false }) {
  return (
    <label className={span ? "md:col-span-2 block" : "block"}>
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

function CostVisual({ materialCost, overheadCost }) {
  const total = materialCost + overheadCost;

  return (
    <Panel title="Production Cost Composition" subtitle="Material, staff and overhead cost split">
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

function OrderWorkflowCard({ status }) {
  const currentIndex = Math.max(0, orderStatusOrder.indexOf(String(status || "draft")));

  return (
    <Panel title="Workflow Position" subtitle="Current production order stage">
      <div className="space-y-2">
        {orderStatusOrder.map((item, index) => {
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

function WorkflowButton({ icon: Icon, title, text, onClick, green = false, danger = false }) {
  let cls = "border-[#D8DFDF] bg-white hover:border-[#004343]";
  if (green) cls = "border-[#ABEFC6] bg-[#ECFDF3]";
  if (danger) cls = "border-[#FECDCA] bg-[#FEF3F2]";

  return (
    <button
      type="button"
      onClick={onClick}
      className={`min-h-[76px] border p-2 text-left ${cls}`}
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
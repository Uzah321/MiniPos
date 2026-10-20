import React, { useEffect, useMemo, useState } from "react";
import {
  CheckCircle2,
  ClipboardList,
  Factory,
  FileText,
  Layers,
  PackageSearch,
  Plus,
  RefreshCw,
  Search,
  Trash2,
} from "lucide-react";

import FullScreenModal from "../../../components/FullScreenModal";
import { getProducts } from "../../../services/ProductService";
import {
  getProductionPlan,
  getProductionPlans,
  getProductionRecipes,
  saveProductionOrder,
} from "../../../services/ManufacturingService";
import ManufacturingProductLookupModal from "./ManufacturingProductLookupModal";

import {
  ActionButton,
  IconButton,
  LoadingPill,
  MiniInput,
  Notice,
  SmallButton,
  StatusBadge,
  getBranchId,
  getErrorMessage,
  getProductCode,
  getProductCost,
  getProductName,
  getProductStock,
  getProductUnit,
  moneyNumber,
  normalizeRows,
  numberValue,
  qtyNumber,
  today,
  todayPlusDays,
  useNotice,
} from "./ManufacturingShared";

const emptyOrderForm = (branchId = "") => ({
  source_type: "manual",

  branch_id: branchId,
  production_plan_id: "",
  production_plan_item_id: "",

  production_recipe_id: "",
  finished_product_id: "",
  finished_product_name: "",
  finished_product_code: "",

  planned_output_quantity: 1,
  currency: "USD",
  planned_start_date: today(),
  planned_end_date: todayPlusDays(1),
  batch_number: "",
});

const MANUFACTURING_MATERIAL_TYPES = [
  "raw_material",
  "semi_finished",
  "packaging",
  "consumable",
];

const FINISHED_PRODUCT_TYPES = ["finished_good", "semi_finished"];

const safeNumber = (value) => Number(value || 0);

const getPayloadData = (response) =>
  response?.data?.data || response?.data || response || null;

const getPlanItems = (plan) => {
  if (!plan) return [];
  if (Array.isArray(plan.items)) return plan.items;
  if (Array.isArray(plan.production_plan_items)) return plan.production_plan_items;
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

const getFinishedProductNameFromOrder = (order) =>
  order?.finished_product?.name ||
  order?.finishedProduct?.name ||
  order?.metadata?.finished_product_name ||
  "-";

const getFinishedProductNameFromPlanItem = (item) =>
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

const getInputProductCode = (row) =>
  row?.product?.code || row?.metadata?.product_code || row?.product_code || "-";

const getUnitName = (row) =>
  row?.uom?.code ||
  row?.product?.uom?.code ||
  row?.product?.uom?.name ||
  row?.metadata?.unit ||
  "-";

const getLineCost = (row) => {
  const direct = safeNumber(row?.total_cost);

  if (direct > 0) return direct;

  return safeNumber(row?.planned_quantity) * safeNumber(row?.metadata?.unit_cost || row?.unit_cost);
};

export default function ManufacturingOrderFormModal({
  open,
  onClose,
  currentUser = null,
}) {
  const branchId = getBranchId(currentUser);

  const [form, setForm] = useState(emptyOrderForm(branchId));
  const [inputs, setInputs] = useState([]);
  const [overheads, setOverheads] = useState([]);

  const [recipes, setRecipes] = useState([]);
  const [products, setProducts] = useState([]);

  const [convertedPlans, setConvertedPlans] = useState([]);
  const [selectedConvertedPlan, setSelectedConvertedPlan] = useState(null);
  const [selectedConvertedOrder, setSelectedConvertedOrder] = useState(null);

  const [lookupOpen, setLookupOpen] = useState(false);
  const [lookupMode, setLookupMode] = useState("finished");
  const [productSearch, setProductSearch] = useState("");

  const [loading, setLoading] = useState(false);
  const [activeTab, setActiveTab] = useState("inputs");

  const { notice, showNotice } = useNotice();

  const manualTotals = useMemo(() => {
    const materialCost = inputs.reduce(
      (sum, line) =>
        sum + safeNumber(line.planned_quantity) * safeNumber(line.unit_cost),
      0
    );

    const overheadCost = overheads.reduce(
      (sum, line) => sum + safeNumber(line.amount),
      0
    );

    const totalCost = materialCost + overheadCost;
    const outputQty = Math.max(safeNumber(form.planned_output_quantity), 1);

    return {
      materialCost,
      overheadCost,
      totalCost,
      unitCost: totalCost / outputQty,
      inputQty: inputs.reduce(
        (sum, line) => sum + safeNumber(line.planned_quantity),
        0
      ),
    };
  }, [inputs, overheads, form.planned_output_quantity]);

  const convertedInputs = useMemo(
    () => getOrderInputs(selectedConvertedOrder),
    [selectedConvertedOrder]
  );

  const convertedOutputs = useMemo(
    () => getOrderOutputs(selectedConvertedOrder),
    [selectedConvertedOrder]
  );

  const convertedOverheads = useMemo(
    () => getOrderOverheads(selectedConvertedOrder),
    [selectedConvertedOrder]
  );

  const convertedTotals = useMemo(() => {
    const materialCost = convertedInputs.reduce(
      (sum, line) => sum + getLineCost(line),
      0
    );

    const overheadCost = convertedOverheads.reduce(
      (sum, line) => sum + safeNumber(line.amount),
      0
    );

    const totalCost =
      safeNumber(selectedConvertedOrder?.actual_total_cost) ||
      safeNumber(selectedConvertedOrder?.planned_total_cost) ||
      materialCost + overheadCost;

    const outputQty =
      safeNumber(selectedConvertedOrder?.actual_good_quantity) ||
      safeNumber(selectedConvertedOrder?.planned_output_quantity) ||
      1;

    return {
      materialCost,
      overheadCost,
      totalCost,
      unitCost: totalCost / Math.max(outputQty, 1),
      inputQty: convertedInputs.reduce(
        (sum, line) => sum + safeNumber(line.planned_quantity),
        0
      ),
    };
  }, [convertedInputs, convertedOverheads, selectedConvertedOrder]);

  const isConvertedMode = form.source_type === "converted_plan";

  const totals = isConvertedMode ? convertedTotals : manualTotals;

  useEffect(() => {
    if (!open) return;

    setForm(emptyOrderForm(branchId));
    setInputs([]);
    setOverheads([]);
    setConvertedPlans([]);
    setSelectedConvertedPlan(null);
    setSelectedConvertedOrder(null);
    setActiveTab("inputs");

    loadRecipes();
    loadConvertedPlans();
    searchProducts("");
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const loadRecipes = async () => {
    try {
      const res = await getProductionRecipes({
        branch_id: branchId || undefined,
        per_page: 100,
        is_active: true,
      });

      setRecipes(normalizeRows(res));
    } catch (error) {
      showNotice(getErrorMessage(error, "Failed to load recipes."));
    }
  };

  const loadConvertedPlans = async () => {
    try {
      const res = await getProductionPlans({
        branch_id: branchId || undefined,
        per_page: 100,
        status: "converted_to_orders",
      });

      setConvertedPlans(normalizeRows(res));
    } catch (error) {
      showNotice(
        getErrorMessage(error, "Failed to load converted production plans.")
      );
    }
  };

  const loadConvertedPlanDetails = async (planId) => {
    if (!planId) {
      setSelectedConvertedPlan(null);
      setSelectedConvertedOrder(null);

      setForm((prev) => ({
        ...prev,
        production_plan_id: "",
        production_plan_item_id: "",
      }));

      return;
    }

    try {
      setLoading(true);

      const res = await getProductionPlan(planId);
      const data = getPayloadData(res);

      setSelectedConvertedPlan(data);
      setSelectedConvertedOrder(null);

      setForm((prev) => ({
        ...prev,
        production_plan_id: planId,
        production_plan_item_id: "",
      }));
    } catch (error) {
      showNotice(
        getErrorMessage(error, "Failed to load converted plan details.")
      );
    } finally {
      setLoading(false);
    }
  };

  const selectConvertedOrder = (order) => {
    setSelectedConvertedOrder(order);

    setForm((prev) => ({
      ...prev,
      production_plan_item_id: order?.production_plan_item_id || "",
      production_recipe_id: order?.production_recipe_id || "",
      finished_product_id: order?.finished_product_id || "",
      finished_product_name: getFinishedProductNameFromOrder(order),
      planned_output_quantity: order?.planned_output_quantity || 1,
      currency: order?.currency || "USD",
      planned_start_date: String(order?.planned_start_date || today()).slice(0, 10),
      planned_end_date: String(order?.planned_end_date || todayPlusDays(1)).slice(
        0,
        10
      ),
      batch_number: order?.batch_number || "",
    }));

    setActiveTab("inputs");
  };

  const searchProducts = async (q = "") => {
    try {
      const res = await getProducts({
        q,
        search: q,
        per_page: 100,
        limit: 100,
        is_active: true,
      });

      const rows = normalizeRows(res);
      const allowedTypes =
        lookupMode === "finished"
          ? FINISHED_PRODUCT_TYPES
          : MANUFACTURING_MATERIAL_TYPES;

      setProducts(
        rows.filter((product) => allowedTypes.includes(product.product_type))
      );
    } catch (error) {
      showNotice(getErrorMessage(error, "Failed to search products."));
    }
  };

  const openLookup = (mode) => {
    if (isConvertedMode) {
      showNotice(
        "Converted plans already created their orders. Use Production Control to process those orders."
      );
      return;
    }

    setLookupMode(mode);
    setProductSearch("");
    setLookupOpen(true);

    window.setTimeout(() => {
      searchProducts("");
    }, 0);
  };

  const selectProduct = (product) => {
    if (lookupMode === "finished") {
      setForm((prev) => ({
        ...prev,
        finished_product_id: product.id,
        finished_product_name: getProductName(product),
        finished_product_code: getProductCode(product),
      }));

      setLookupOpen(false);
      return;
    }

    setInputs((prev) => [
      ...prev,
      {
        line_id: `${product.id}-${Date.now()}`,
        product_id: product.id,
        product_name: getProductName(product),
        product_code: getProductCode(product),
        product_type: product.product_type,
        uom_id: product?.base_uom_id || product?.uom_id || null,
        unit: getProductUnit(product),
        planned_quantity: 1,
        unit_cost: getProductCost(product),
        available_stock: getProductStock(product),
      },
    ]);

    setLookupOpen(false);
  };

  const updateInput = (lineId, patch) => {
    setInputs((prev) =>
      prev.map((line) => (line.line_id === lineId ? { ...line, ...patch } : line))
    );
  };

  const removeInput = (lineId) => {
    setInputs((prev) => prev.filter((line) => line.line_id !== lineId));
  };

  const addOverhead = () => {
    if (isConvertedMode) {
      showNotice(
        "For converted plan orders, add overheads from Production Control."
      );
      return;
    }

    setOverheads((prev) => [
      ...prev,
      {
        line_id: `oh-${Date.now()}`,
        cost_type: "labour",
        description: "",
        amount: 0,
      },
    ]);
  };

  const updateOverhead = (lineId, patch) => {
    setOverheads((prev) =>
      prev.map((line) => (line.line_id === lineId ? { ...line, ...patch } : line))
    );
  };

  const removeOverhead = (lineId) => {
    setOverheads((prev) => prev.filter((line) => line.line_id !== lineId));
  };

  const applyRecipe = () => {
    if (isConvertedMode) {
      showNotice(
        "Converted plan orders already have recipe-based inputs from planning conversion."
      );
      return;
    }

    const recipe = recipes.find((item) => item.id === form.production_recipe_id);

    if (!recipe) {
      showNotice("Select a recipe first.");
      return;
    }

    const finishedProduct =
      recipe.finished_product || recipe.finishedProduct || recipe.product;

    if (finishedProduct?.id) {
      setForm((prev) => ({
        ...prev,
        finished_product_id: finishedProduct.id,
        finished_product_name: getProductName(finishedProduct),
        finished_product_code: getProductCode(finishedProduct),
      }));
    }

    const recipeItems = Array.isArray(recipe.items) ? recipe.items : [];

    if (!recipeItems.length) {
      showNotice("Recipe loaded, but it has no visible BOM items in this response.");
      return;
    }

    const outputQty = Math.max(safeNumber(form.planned_output_quantity), 1);
    const batchQty = Math.max(safeNumber(recipe.batch_output_quantity), 1);
    const batches = outputQty / batchQty;

    const mappedInputs = recipeItems
      .map((item) => {
        const product = item.product || item.material || null;

        if (!product?.id) return null;

        const itemQty =
          safeNumber(item.quantity) ||
          safeNumber(item.material_quantity) ||
          safeNumber(item.quantity_per_batch) ||
          safeNumber(item.required_quantity);

        const wastagePercent = safeNumber(item.wastage_percent);

        const plannedQty = itemQty * batches * (1 + wastagePercent / 100);

        return {
          line_id: `${product.id}-${Date.now()}-${Math.random()}`,
          product_id: product.id,
          product_name: getProductName(product),
          product_code: getProductCode(product),
          product_type: product.product_type,
          uom_id: product?.base_uom_id || product?.uom_id || item.uom_id || null,
          unit: getProductUnit(product),
          planned_quantity: plannedQty || 1,
          unit_cost: getProductCost(product),
          available_stock: getProductStock(product),
        };
      })
      .filter(Boolean);

    if (mappedInputs.length) {
      setInputs(mappedInputs);
      showNotice("Recipe materials loaded into the production order.");
    }
  };

  const saveOrder = async () => {
    if (isConvertedMode) {
      showNotice(
        "This plan is already converted. Do not create another order here. Open Production Control to approve, reserve, issue, start and complete the linked order."
      );
      return;
    }

    if (!form.branch_id && !branchId) {
      showNotice("Branch is required.");
      return;
    }

    if (!form.finished_product_id) {
      showNotice("Select a finished product.");
      return;
    }

    if (!safeNumber(form.planned_output_quantity)) {
      showNotice("Planned output quantity must be greater than zero.");
      return;
    }

    if (!inputs.length) {
      showNotice("Add at least one material input.");
      return;
    }

    const payload = {
      branch_id: form.branch_id || branchId,
      production_plan_id: null,
      production_plan_item_id: null,
      production_recipe_id: form.production_recipe_id || null,
      finished_product_id: form.finished_product_id,
      planned_output_quantity: safeNumber(form.planned_output_quantity),
      currency: String(form.currency || "USD").toUpperCase(),
      planned_start_date: form.planned_start_date || null,
      planned_end_date: form.planned_end_date || null,
      batch_number: form.batch_number || null,
      metadata: {
        source_type: "manual",
        frontend_material_cost: manualTotals.materialCost,
        frontend_overhead_cost: manualTotals.overheadCost,
        frontend_total_cost: manualTotals.totalCost,
        frontend_unit_cost: manualTotals.unitCost,
        finished_product_code: form.finished_product_code,
        finished_product_name: form.finished_product_name,
      },
      inputs: inputs.map((line) => ({
        product_id: line.product_id,
        uom_id: line.uom_id || null,
        planned_quantity: safeNumber(line.planned_quantity),
        metadata: {
          product_code: line.product_code,
          product_name: line.product_name,
          product_type: line.product_type,
          unit: line.unit,
          unit_cost: safeNumber(line.unit_cost),
          available_stock: safeNumber(line.available_stock),
        },
      })),
      overheads: overheads.map((line) => ({
        cost_type: line.cost_type,
        description: line.description || null,
        amount: safeNumber(line.amount),
      })),
    };

    try {
      setLoading(true);

      const res = await saveProductionOrder(payload);

      showNotice(res?.message || "Production order saved.");
      setForm(emptyOrderForm(branchId));
      setInputs([]);
      setOverheads([]);
      setActiveTab("inputs");
    } catch (error) {
      showNotice(getErrorMessage(error, "Failed to save production order."));
    } finally {
      setLoading(false);
    }
  };

  const switchSourceType = (sourceType) => {
    setForm((prev) => ({
      ...prev,
      source_type: sourceType,
      production_plan_id: "",
      production_plan_item_id: "",
    }));

    setSelectedConvertedPlan(null);
    setSelectedConvertedOrder(null);
    setInputs([]);
    setOverheads([]);
    setActiveTab("inputs");
  };

  const convertedPlanItems = getPlanItems(selectedConvertedPlan);
  const convertedPlanOrders = getPlanOrders(selectedConvertedPlan);

  const displayInputs = isConvertedMode ? convertedInputs : inputs;
  const displayOverheads = isConvertedMode ? convertedOverheads : overheads;
  const displayOutputs = isConvertedMode ? convertedOutputs : [];

  if (!open) return null;

  return (
    <FullScreenModal
      open={open}
      onClose={onClose}
      title="CREATE / LINK PRODUCTION ORDER"
      subtitle="Manual production entry or linked converted production plan review"
    >
      <Notice notice={notice} />
      <LoadingPill loading={loading} />

      <div className="h-[calc(100vh-86px)] min-h-0 overflow-hidden bg-[#EEF1F1] p-2 text-[#1C2C2B]">
        <div className="flex h-full min-h-0 flex-col border border-[#AAB5B5] bg-white">
          <div className="border-b border-[#AAB5B5] bg-[#F5F7FA]">
            <div className="flex h-9 items-center justify-between border-b border-[#CFD9D8] px-2">
              <div className="flex items-center gap-2">
                <div className="text-[13px] font-semibold text-[#0C1B1B]">
                  Production order entry
                </div>
                <div className="h-4 border-l border-[#AAB5B5]" />
                <div className="text-[10px] font-medium uppercase text-[#627474]">
                  Manual order or converted plan order review
                </div>
              </div>

              <div className="flex items-center gap-1">
                <ActionButton
                  onClick={() => {
                    loadRecipes();
                    loadConvertedPlans();
                  }}
                  label="Refresh"
                  icon={RefreshCw}
                />

                <ActionButton
                  onClick={saveOrder}
                  label={isConvertedMode ? "Already Converted" : "Save Order"}
                  icon={CheckCircle2}
                  primary={!isConvertedMode}
                />
              </div>
            </div>

            <div className="grid grid-cols-4 gap-2 px-2 py-2">
              <OrderField label="Source">
                <select
                  value={form.source_type}
                  onChange={(event) => switchSourceType(event.target.value)}
                  className="h-8 w-full border border-[#AAB5B5] bg-white px-2 text-[11px] outline-none"
                >
                  <option value="manual">Manual Order</option>
                  <option value="converted_plan">From Converted Plan</option>
                </select>
              </OrderField>

              {isConvertedMode ? (
                <>
                  <OrderField label="Converted Plan">
                    <select
                      value={form.production_plan_id}
                      onChange={(event) =>
                        loadConvertedPlanDetails(event.target.value)
                      }
                      className="h-8 w-full border border-[#AAB5B5] bg-white px-2 text-[11px] outline-none"
                    >
                      <option value="">Select converted plan</option>

                      {convertedPlans.map((plan) => (
                        <option key={plan.id} value={plan.id}>
                          {plan.plan_number || plan.name} - {plan.name}
                        </option>
                      ))}
                    </select>
                  </OrderField>

                  <OrderField label="Linked Order">
                    <select
                      value={selectedConvertedOrder?.id || ""}
                      onChange={(event) => {
                        const order = convertedPlanOrders.find(
                          (item) => item.id === event.target.value
                        );

                        if (order) selectConvertedOrder(order);
                      }}
                      className="h-8 w-full border border-[#AAB5B5] bg-white px-2 text-[11px] outline-none"
                    >
                      <option value="">Select linked order</option>

                      {convertedPlanOrders.map((order) => (
                        <option key={order.id} value={order.id}>
                          {order.production_number || order.id} -{" "}
                          {getFinishedProductNameFromOrder(order)}
                        </option>
                      ))}
                    </select>
                  </OrderField>

                  <OrderField label="Plan Status">
                    <div className="flex h-8 items-center border border-[#AAB5B5] bg-white px-2 text-[11px]">
                      <StatusBadge
                        status={selectedConvertedPlan?.status || "not_selected"}
                      />
                    </div>
                  </OrderField>
                </>
              ) : (
                <>
                  <OrderField label="Finished Product">
                    <button
                      type="button"
                      onClick={() => openLookup("finished")}
                      className="h-8 w-full border border-[#AAB5B5] bg-white px-2 text-left text-[11px] font-semibold text-[#004343]"
                    >
                      <span className="block truncate">
                        {form.finished_product_name || "Select finished good"}
                      </span>
                    </button>
                  </OrderField>

                  <OrderField label="Recipe">
                    <div className="grid grid-cols-[1fr_auto]">
                      <select
                        value={form.production_recipe_id}
                        onChange={(event) =>
                          setForm((prev) => ({
                            ...prev,
                            production_recipe_id: event.target.value,
                          }))
                        }
                        className="h-8 border border-[#AAB5B5] bg-white px-2 text-[11px] outline-none"
                      >
                        <option value="">No Recipe</option>
                        {recipes.map((recipe) => (
                          <option key={recipe.id} value={recipe.id}>
                            {recipe.name}
                          </option>
                        ))}
                      </select>

                      <button
                        type="button"
                        onClick={applyRecipe}
                        className="h-8 border border-l-0 border-[#AAB5B5] bg-[#EEF2F2] px-2 text-[10px] font-semibold uppercase text-[#324443]"
                      >
                        Apply
                      </button>
                    </div>
                  </OrderField>

                  <OrderField label="Quick Add">
                    <button
                      type="button"
                      onClick={() => openLookup("input")}
                      className="h-8 w-full border border-[#027A48] bg-[#027A48] px-2 text-[11px] font-semibold uppercase text-white"
                    >
                      Add Material
                    </button>
                  </OrderField>
                </>
              )}

              <OrderField label="Output Qty">
                <input
                  type="number"
                  step="0.0001"
                  disabled={isConvertedMode}
                  value={form.planned_output_quantity}
                  onChange={(event) =>
                    setForm((prev) => ({
                      ...prev,
                      planned_output_quantity: Number(event.target.value || 0),
                    }))
                  }
                  className="h-8 w-full border border-[#AAB5B5] bg-white px-2 text-[11px] outline-none disabled:bg-[#EEF2F2]"
                />
              </OrderField>

              <OrderField label="Currency">
                <input
                  disabled={isConvertedMode}
                  value={form.currency}
                  onChange={(event) =>
                    setForm((prev) => ({
                      ...prev,
                      currency: event.target.value.toUpperCase(),
                    }))
                  }
                  className="h-8 w-full border border-[#AAB5B5] bg-white px-2 text-[11px] outline-none disabled:bg-[#EEF2F2]"
                />
              </OrderField>

              <OrderField label="Start Date">
                <input
                  type="date"
                  disabled={isConvertedMode}
                  value={form.planned_start_date}
                  onChange={(event) =>
                    setForm((prev) => ({
                      ...prev,
                      planned_start_date: event.target.value,
                    }))
                  }
                  className="h-8 w-full border border-[#AAB5B5] bg-white px-2 text-[11px] outline-none disabled:bg-[#EEF2F2]"
                />
              </OrderField>

              <OrderField label="End Date">
                <input
                  type="date"
                  disabled={isConvertedMode}
                  value={form.planned_end_date}
                  onChange={(event) =>
                    setForm((prev) => ({
                      ...prev,
                      planned_end_date: event.target.value,
                    }))
                  }
                  className="h-8 w-full border border-[#AAB5B5] bg-white px-2 text-[11px] outline-none disabled:bg-[#EEF2F2]"
                />
              </OrderField>

              <OrderField label="Batch Number">
                <input
                  disabled={isConvertedMode}
                  value={form.batch_number}
                  onChange={(event) =>
                    setForm((prev) => ({
                      ...prev,
                      batch_number: event.target.value,
                    }))
                  }
                  className="h-8 w-full border border-[#AAB5B5] bg-white px-2 text-[11px] outline-none disabled:bg-[#EEF2F2]"
                />
              </OrderField>
            </div>
          </div>

          {isConvertedMode && (
            <div className="border-b border-[#A4E5E4] bg-[#F1F8F8] px-2 py-1.5 text-[11px] font-medium text-[#057B7B]">
              Converted plans already created production orders. Select a linked
              order above to view its inputs, outputs, overheads and costing.
              Continue processing it in Production Control.
            </div>
          )}

          <div className="grid min-h-[110px] grid-cols-4 gap-2 border-b border-[#AAB5B5] bg-[#F7F8FA] p-2">
            <MetricCard title="Input Qty" value={qtyNumber(totals.inputQty)} icon={Layers} />
            <MetricCard title="Material Cost" value={moneyNumber(totals.materialCost)} icon={Layers} />
            <MetricCard title="Overhead Cost" value={moneyNumber(totals.overheadCost)} icon={FileText} />
            <MetricCard title="Unit Cost" value={moneyNumber(totals.unitCost)} icon={Factory} />
          </div>

          {isConvertedMode && selectedConvertedPlan && (
            <ConvertedPlanStrip
              plan={selectedConvertedPlan}
              items={convertedPlanItems}
              orders={convertedPlanOrders}
            />
          )}

          <div className="flex h-8 items-end border-b border-[#AAB5B5] bg-[#F5F7FA] px-2">
            {[
              ["inputs", "Material Inputs"],
              ["overheads", "Overheads"],
              ["outputs", "Outputs"],
              ["visuals", "Cost Visuals"],
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
            {activeTab === "inputs" && (
              <InputsTable
                inputs={displayInputs}
                readonly={isConvertedMode}
                updateInput={updateInput}
                removeInput={removeInput}
                openLookup={() => openLookup("input")}
              />
            )}

            {activeTab === "overheads" && (
              <OverheadsTable
                overheads={displayOverheads}
                readonly={isConvertedMode}
                addOverhead={addOverhead}
                updateOverhead={updateOverhead}
                removeOverhead={removeOverhead}
              />
            )}

            {activeTab === "outputs" && (
              <OutputsTable outputs={displayOutputs} />
            )}

            {activeTab === "visuals" && (
              <VisualsPanel
                form={form}
                totals={totals}
                inputCount={displayInputs.length}
                overheadCount={displayOverheads.length}
                outputCount={displayOutputs.length}
                convertedMode={isConvertedMode}
                selectedConvertedOrder={selectedConvertedOrder}
              />
            )}
          </div>
        </div>
      </div>

      <ManufacturingProductLookupModal
        open={lookupOpen}
        title={
          lookupMode === "finished"
            ? "Select Finished Product"
            : "Select Material Input"
        }
        search={productSearch}
        setSearch={setProductSearch}
        products={products}
        searchProducts={searchProducts}
        onSelect={selectProduct}
        onClose={() => setLookupOpen(false)}
      />
    </FullScreenModal>
  );
}

function ConvertedPlanStrip({ plan, items, orders }) {
  return (
    <div className="grid grid-cols-4 gap-2 border-b border-[#AAB5B5] bg-white p-2">
      <SmallMetric label="Plan No" value={plan.plan_number || "-"} />
      <SmallMetric label="Plan Name" value={plan.name || "-"} />
      <SmallMetric label="Plan Items" value={items.length} />
      <SmallMetric label="Created Orders" value={orders.length} />
    </div>
  );
}

function InputsTable({
  inputs,
  readonly,
  updateInput,
  removeInput,
  openLookup,
}) {
  return (
    <div className="h-full overflow-auto">
      <div className="flex items-center justify-between border-b border-[#A4E5E4] bg-[#F1F8F8] px-2 py-1.5">
        <div className="text-[11px] font-medium text-[#057B7B]">
          {readonly
            ? "These are the material inputs already generated from the converted plan order."
            : "Add raw materials, semi-finished goods, packaging or consumables used by this production order."}
        </div>

        {!readonly && <SmallButton onClick={openLookup}>Add Material</SmallButton>}
      </div>

      <table className="w-full min-w-[1250px] border-collapse text-left text-[11px]">
        <thead className="sticky top-0 z-10 bg-[#0E4D4D] text-white">
          <tr>
            <GridTh>Line</GridTh>
            <GridTh>Code</GridTh>
            <GridTh>Material</GridTh>
            <GridTh>Type</GridTh>
            <GridTh>Unit</GridTh>
            <GridTh align="right">Planned Qty</GridTh>
            <GridTh align="right">Reserved</GridTh>
            <GridTh align="right">Issued</GridTh>
            <GridTh align="right">Returned</GridTh>
            <GridTh align="right">Wasted</GridTh>
            <GridTh align="right">Unit Cost</GridTh>
            <GridTh align="right">Line Cost</GridTh>
            {!readonly && <GridTh>Action</GridTh>}
          </tr>
        </thead>

        <tbody>
          {inputs.length === 0 ? (
            <tr>
              <td
                colSpan={readonly ? 12 : 13}
                className="px-3 py-10 text-center text-[#627474]"
              >
                No material inputs found.
              </td>
            </tr>
          ) : (
            inputs.map((line, index) => {
              const rowId = line.line_id || line.id || `${index}`;

              return (
                <tr
                  key={rowId}
                  className="border-b border-[#CFD9D8] bg-white hover:bg-[#F8FAFC]"
                >
                  <GridTd>{index + 1}</GridTd>
                  <GridTd>{line.product_code || getInputProductCode(line)}</GridTd>
                  <GridTd strong>{line.product_name || getInputProductName(line)}</GridTd>
                  <GridTd>
                    {String(line.product_type || line.metadata?.product_type || "-").replaceAll("_", " ")}
                  </GridTd>
                  <GridTd>{line.unit || getUnitName(line)}</GridTd>

                  <GridTd right>
                    {readonly ? (
                      qtyNumber(line.planned_quantity)
                    ) : (
                      <MiniInput
                        type="number"
                        value={line.planned_quantity}
                        onChange={(value) =>
                          updateInput(rowId, {
                            planned_quantity: Number(value || 0),
                          })
                        }
                      />
                    )}
                  </GridTd>

                  <GridTd right>{qtyNumber(line.reserved_quantity)}</GridTd>
                  <GridTd right>{qtyNumber(line.issued_quantity)}</GridTd>
                  <GridTd right>{qtyNumber(line.returned_quantity)}</GridTd>
                  <GridTd right>{qtyNumber(line.wasted_quantity)}</GridTd>

                  <GridTd right>
                    {readonly ? (
                      moneyNumber(line.unit_cost || line.metadata?.unit_cost)
                    ) : (
                      <MiniInput
                        type="number"
                        value={line.unit_cost}
                        onChange={(value) =>
                          updateInput(rowId, {
                            unit_cost: Number(value || 0),
                          })
                        }
                      />
                    )}
                  </GridTd>

                  <GridTd right>
                    {moneyNumber(
                      readonly
                        ? getLineCost(line)
                        : safeNumber(line.planned_quantity) *
                            safeNumber(line.unit_cost)
                    )}
                  </GridTd>

                  {!readonly && (
                    <GridTd>
                      <IconButton
                        onClick={() => removeInput(rowId)}
                        icon={Trash2}
                        danger
                      />
                    </GridTd>
                  )}
                </tr>
              );
            })
          )}
        </tbody>
      </table>
    </div>
  );
}

function OverheadsTable({
  overheads,
  readonly,
  addOverhead,
  updateOverhead,
  removeOverhead,
}) {
  return (
    <div className="h-full overflow-auto">
      <div className="flex items-center justify-between border-b border-[#A4E5E4] bg-[#F1F8F8] px-2 py-1.5">
        <div className="text-[11px] font-medium text-[#057B7B]">
          {readonly
            ? "These are overhead costs linked to the converted production order."
            : "Overheads are additional production costs such as labour, electricity, fuel, water and maintenance."}
        </div>

        {!readonly && <SmallButton onClick={addOverhead}>Add Overhead</SmallButton>}
      </div>

      <table className="w-full min-w-[850px] border-collapse text-left text-[11px]">
        <thead className="sticky top-0 z-10 bg-[#0E4D4D] text-white">
          <tr>
            <GridTh>Line</GridTh>
            <GridTh>Cost Type</GridTh>
            <GridTh>Description</GridTh>
            <GridTh>Currency</GridTh>
            <GridTh align="right">Amount</GridTh>
            {!readonly && <GridTh>Action</GridTh>}
          </tr>
        </thead>

        <tbody>
          {overheads.length === 0 ? (
            <tr>
              <td
                colSpan={readonly ? 5 : 6}
                className="px-3 py-10 text-center text-[#627474]"
              >
                No overhead costs found.
              </td>
            </tr>
          ) : (
            overheads.map((line, index) => {
              const rowId = line.line_id || line.id || `${index}`;

              return (
                <tr
                  key={rowId}
                  className="border-b border-[#CFD9D8] bg-white hover:bg-[#F8FAFC]"
                >
                  <GridTd>{index + 1}</GridTd>
                  <GridTd>
                    {readonly ? (
                      String(line.cost_type || "-").replaceAll("_", " ")
                    ) : (
                      <select
                        value={line.cost_type}
                        onChange={(event) =>
                          updateOverhead(rowId, {
                            cost_type: event.target.value,
                          })
                        }
                        className="h-8 w-full border border-[#B9C3C3] bg-white px-2 text-[11px]"
                      >
                        <option value="labour">Labour</option>
                        <option value="salary">Salary</option>
                        <option value="electricity">Electricity</option>
                        <option value="fuel">Fuel</option>
                        <option value="water">Water</option>
                        <option value="rent">Rent</option>
                        <option value="machine_maintenance">
                          Machine Maintenance
                        </option>
                        <option value="depreciation">Depreciation</option>
                        <option value="other">Other</option>
                      </select>
                    )}
                  </GridTd>

                  <GridTd>
                    {readonly ? (
                      line.description || "-"
                    ) : (
                      <MiniInput
                        value={line.description}
                        onChange={(value) =>
                          updateOverhead(rowId, { description: value })
                        }
                      />
                    )}
                  </GridTd>

                  <GridTd>{line.currency || "-"}</GridTd>

                  <GridTd right>
                    {readonly ? (
                      moneyNumber(line.amount)
                    ) : (
                      <MiniInput
                        type="number"
                        value={line.amount}
                        onChange={(value) =>
                          updateOverhead(rowId, {
                            amount: Number(value || 0),
                          })
                        }
                      />
                    )}
                  </GridTd>

                  {!readonly && (
                    <GridTd>
                      <IconButton
                        onClick={() => removeOverhead(rowId)}
                        icon={Trash2}
                        danger
                      />
                    </GridTd>
                  )}
                </tr>
              );
            })
          )}
        </tbody>
      </table>
    </div>
  );
}

function OutputsTable({ outputs }) {
  return (
    <div className="h-full overflow-auto">
      <div className="border-b border-[#A4E5E4] bg-[#F1F8F8] px-2 py-1.5 text-[11px] font-medium text-[#057B7B]">
        Outputs appear after production is completed. They show finished goods
        received, rejected quantity, wastage and final costing.
      </div>

      <table className="w-full min-w-[950px] border-collapse text-left text-[11px]">
        <thead className="sticky top-0 z-10 bg-[#0E4D4D] text-white">
          <tr>
            <GridTh>Line</GridTh>
            <GridTh>Product</GridTh>
            <GridTh>Unit</GridTh>
            <GridTh align="right">Good Qty</GridTh>
            <GridTh align="right">Rejected</GridTh>
            <GridTh align="right">Wasted</GridTh>
            <GridTh align="right">Unit Cost</GridTh>
            <GridTh align="right">Total Cost</GridTh>
          </tr>
        </thead>

        <tbody>
          {outputs.length === 0 ? (
            <tr>
              <td colSpan={8} className="px-3 py-10 text-center text-[#627474]">
                No output records yet. Complete production in Production Control.
              </td>
            </tr>
          ) : (
            outputs.map((row, index) => (
              <tr
                key={row.id || index}
                className="border-b border-[#CFD9D8] bg-white hover:bg-[#F8FAFC]"
              >
                <GridTd>{index + 1}</GridTd>
                <GridTd strong>{getOutputProductName(row)}</GridTd>
                <GridTd>{row?.uom?.code || row?.product?.uom?.code || "-"}</GridTd>
                <GridTd right>{qtyNumber(row.quantity_produced)}</GridTd>
                <GridTd right>{qtyNumber(row.quantity_rejected)}</GridTd>
                <GridTd right>{qtyNumber(row.quantity_wasted)}</GridTd>
                <GridTd right>{moneyNumber(row.unit_cost)}</GridTd>
                <GridTd right>{moneyNumber(row.total_cost)}</GridTd>
              </tr>
            ))
          )}
        </tbody>
      </table>
    </div>
  );
}

function VisualsPanel({
  form,
  totals,
  inputCount,
  overheadCount,
  outputCount,
  convertedMode,
  selectedConvertedOrder,
}) {
  const total = totals.totalCost;
  const materialPercent = total > 0 ? (totals.materialCost / total) * 100 : 0;
  const overheadPercent = total > 0 ? (totals.overheadCost / total) * 100 : 0;

  return (
    <div className="h-full overflow-auto bg-[#F7F8FA] p-2">
      <div className="grid gap-2 lg:grid-cols-4">
        <MetricCard
          title="Output Qty"
          value={qtyNumber(
            convertedMode
              ? selectedConvertedOrder?.planned_output_quantity
              : form.planned_output_quantity
          )}
          icon={Factory}
        />
        <MetricCard title="Inputs" value={inputCount} icon={Layers} />
        <MetricCard title="Overheads" value={overheadCount} icon={FileText} />
        <MetricCard title="Outputs" value={outputCount} icon={ClipboardList} />
      </div>

      <div className="mt-2 grid gap-2 xl:grid-cols-2">
        <Panel title="Cost Composition" subtitle="Live / linked order costing">
          <CostBar
            label="Materials"
            value={totals.materialCost}
            percent={materialPercent}
          />
          <CostBar
            label="Overheads"
            value={totals.overheadCost}
            percent={overheadPercent}
          />

          <div className="mt-3 grid grid-cols-2 gap-2">
            <SmallMetric label="Material Cost" value={moneyNumber(totals.materialCost)} />
            <SmallMetric label="Overhead Cost" value={moneyNumber(totals.overheadCost)} />
            <SmallMetric label="Total Cost" value={moneyNumber(totals.totalCost)} />
            <SmallMetric label="Unit Cost" value={moneyNumber(totals.unitCost)} />
          </div>
        </Panel>

        <Panel
          title={convertedMode ? "Converted Order Status" : "Production Readiness"}
          subtitle={
            convertedMode
              ? "This order came from planning conversion"
              : "Before saving manual order"
          }
        >
          <ReadinessLine
            label="Source"
            ok
            value={convertedMode ? "Converted Plan" : "Manual Order"}
          />
          <ReadinessLine
            label="Finished Product"
            ok={!!form.finished_product_id}
            value={form.finished_product_name || "Not selected"}
          />
          <ReadinessLine
            label="Output Quantity"
            ok={safeNumber(form.planned_output_quantity) > 0}
            value={qtyNumber(form.planned_output_quantity)}
          />
          <ReadinessLine
            label="Material Inputs"
            ok={inputCount > 0}
            value={`${inputCount} line(s)`}
          />
          <ReadinessLine
            label="Currency"
            ok={String(form.currency || "").length === 3}
            value={form.currency || "-"}
          />
          {convertedMode && (
            <ReadinessLine
              label="Order Status"
              ok
              value={String(selectedConvertedOrder?.status || "-").replaceAll(
                "_",
                " "
              )}
            />
          )}
        </Panel>
      </div>
    </div>
  );
}

function OrderField({ label, children }) {
  return (
    <label className="block">
      <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">
        {label}
      </div>
      {children}
    </label>
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

function CostBar({ label, value, percent }) {
  return (
    <div className="mb-2">
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
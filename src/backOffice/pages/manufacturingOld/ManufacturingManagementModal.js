// src/backOffice/pages/manufacturing/ManufacturingManagementModal.js

import React, { useEffect, useMemo, useState } from "react";
import {
  AlertCircle,
  CheckCircle2,
  ClipboardList,
  Factory,
  FileText,
  PackageSearch,
  Plus,
  RefreshCw,
  Search,
  Trash2,
  X,
} from "lucide-react";

import FullScreenModal from "../../../components/FullScreenModal";
import { getProducts } from "../../../services/ProductService";

import {
  getManufacturingDashboard,
  getManufacturingReports,
  getProductionRecipes,
  saveProductionRecipe,
  getProductionPlans,
  saveProductionPlan,
  approveProductionPlan,
  calculateProductionPlanMaterials,
  convertProductionPlanToOrders,
  getProductionOrders,
  saveProductionOrder,
  approveProductionOrder,
  reserveProductionMaterials,
  issueProductionMaterials,
  startProductionOrder,
  completeProductionOrder,
  cancelProductionOrder,
} from "../../../services/ManufacturingService";

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

const numberValue = (value) => Number(value || 0);

const moneyNumber = (value) => Number(value || 0).toFixed(2);

const qtyNumber = (value) => Number(value || 0).toFixed(4);

const today = () => new Date().toISOString().slice(0, 10);

const todayPlusDays = (days = 7) =>
  new Date(Date.now() + days * 86400000).toISOString().slice(0, 10);

const normalizeRows = (response) => {
  const payload = response?.data ?? response;

  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data)) return payload.data;
  if (Array.isArray(payload?.data?.data)) return payload.data.data;
  if (Array.isArray(payload?.items)) return payload.items;
  if (Array.isArray(payload?.rows)) return payload.rows;

  return [];
};

const getPayloadData = (response) =>
  response?.data?.data || response?.data || response || null;

const getErrorMessage = (error, fallback) => {
  const data = error?.response?.data;

  if (data?.errors && typeof data.errors === "object") {
    return Object.values(data.errors).flat().join("\n");
  }

  return data?.message || data?.error || error?.message || fallback;
};

const getProductName = (product) =>
  product?.name || product?.product_name || product?.description || "Product";

const getProductCode = (product) =>
  product?.code || product?.sku || product?.barcode || "";

const getProductUnit = (product) =>
  product?.uom?.code || product?.uom?.name || "EACH";

const getProductStock = (product) => {
  if (!Array.isArray(product?.stocks)) return 0;

  return product.stocks.reduce(
    (sum, stock) =>
      sum + Number(stock.available_quantity ?? stock.quantity_on_hand ?? 0),
    0
  );
};

const getProductCost = (product) => {
  if (Array.isArray(product?.costs) && product.costs.length) {
    const current = product.costs.find((item) => item?.is_current);
    const cost = current || product.costs[0];

    return Number(cost?.average_cost || cost?.last_cost || 0);
  }

  return Number(
    product?.average_cost ||
      product?.last_cost ||
      product?.cost_price ||
      product?.unit_cost ||
      0
  );
};

const statusTone = (status) => {
  const clean = String(status || "").toLowerCase();

  if (["approved", "materials_reserved"].includes(clean)) {
    return "border-[#A4E5E4] bg-[#F1F8F8] text-[#057B7B]";
  }

  if (["materials_issued", "in_progress"].includes(clean)) {
    return "border-[#FEDF89] bg-[#FFFAEB] text-[#B54708]";
  }

  if (["completed", "converted_to_orders"].includes(clean)) {
    return "border-[#ABEFC6] bg-[#ECFDF3] text-[#067647]";
  }

  if (["cancelled"].includes(clean)) {
    return "border-[#FECDCA] bg-[#FEF3F2] text-[#B42318]";
  }

  return "border-[#E4E8E8] bg-[#F9FAFB] text-[#324443]";
};

const emptyRecipeForm = () => ({
  id: null,
  branch_id: "",
  finished_product_id: "",
  finished_product_name: "",
  finished_product_code: "",
  name: "",
  code: "",
  costing_basis: "per_batch",
  batch_output_quantity: 1,
  standard_wastage_percent: 0,
  is_active: true,
});

const emptyPlanForm = () => ({
  id: null,
  branch_id: "",
  name: "",
  planning_basis: "manual",
  period_start: today(),
  period_end: todayPlusDays(7),
});

const emptyOrderForm = () => ({
  id: null,
  branch_id: "",
  production_recipe_id: "",
  finished_product_id: "",
  finished_product_name: "",
  planned_output_quantity: 1,
  currency: "USD",
  planned_start_date: today(),
  planned_end_date: todayPlusDays(1),
  batch_number: "",
});

/*
|--------------------------------------------------------------------------
| Main Modal
|--------------------------------------------------------------------------
*/

export default function ManufacturingManagementModal({
  open,
  onClose,
  initialTab = "dashboard",
  currentUser = null,
}) {
  const branchId =
    currentUser?.branch_id ||
    currentUser?.branch?.id ||
    currentUser?.data?.branch_id ||
    "";

  const [activePage, setActivePage] = useState(initialTab || "dashboard");
  const [notice, setNotice] = useState("");
  const [loading, setLoading] = useState(false);

  const [dashboard, setDashboard] = useState(null);
  const [reports, setReports] = useState(null);

  const [recipes, setRecipes] = useState([]);
  const [plans, setPlans] = useState([]);
  const [orders, setOrders] = useState([]);

  const [recipeForm, setRecipeForm] = useState(emptyRecipeForm());
  const [recipeItems, setRecipeItems] = useState([]);

  const [planForm, setPlanForm] = useState(emptyPlanForm());
  const [planItems, setPlanItems] = useState([]);

  const [orderForm, setOrderForm] = useState(emptyOrderForm());
  const [orderInputs, setOrderInputs] = useState([]);
  const [orderOverheads, setOrderOverheads] = useState([]);

  const [selectedOrder, setSelectedOrder] = useState(null);

  const [lookupOpen, setLookupOpen] = useState(false);
  const [lookupMode, setLookupMode] = useState("");
  const [productSearch, setProductSearch] = useState("");
  const [products, setProducts] = useState([]);

  const recipeTotals = useMemo(() => {
    const materialCost = recipeItems.reduce(
      (sum, line) =>
        sum + numberValue(line.quantity_required) * numberValue(line.unit_cost),
      0
    );

    const batchQty = Math.max(numberValue(recipeForm.batch_output_quantity), 1);

    return {
      material_cost: materialCost,
      cost_per_unit: materialCost / batchQty,
    };
  }, [recipeItems, recipeForm.batch_output_quantity]);

  const orderTotals = useMemo(() => {
    const materialCost = orderInputs.reduce(
      (sum, line) =>
        sum + numberValue(line.planned_quantity) * numberValue(line.unit_cost),
      0
    );

    const overheadCost = orderOverheads.reduce(
      (sum, line) => sum + numberValue(line.amount),
      0
    );

    const totalCost = materialCost + overheadCost;
    const outputQty = Math.max(numberValue(orderForm.planned_output_quantity), 1);

    return {
      material_cost: materialCost,
      overhead_cost: overheadCost,
      total_cost: totalCost,
      unit_cost: totalCost / outputQty,
    };
  }, [orderInputs, orderOverheads, orderForm.planned_output_quantity]);

  useEffect(() => {
    if (!open) return;

    resetForms();
    setActivePage(initialTab || "dashboard");
    loadAll();
  }, [open, initialTab]);

  if (!open) return null;

  const showNotice = (message) => {
    setNotice(message);
    window.setTimeout(() => setNotice(""), 4000);
  };

  const runAction = async (fn, fallback) => {
    try {
      setLoading(true);
      const res = await fn();
      showNotice(res?.message || "Action completed successfully.");
      return res;
    } catch (error) {
      showNotice(getErrorMessage(error, fallback));
      return null;
    } finally {
      setLoading(false);
    }
  };

  const resetForms = () => {
    setRecipeForm({ ...emptyRecipeForm(), branch_id: branchId });
    setRecipeItems([]);

    setPlanForm({ ...emptyPlanForm(), branch_id: branchId });
    setPlanItems([]);

    setOrderForm({ ...emptyOrderForm(), branch_id: branchId });
    setOrderInputs([]);
    setOrderOverheads([]);

    setSelectedOrder(null);
    setLookupOpen(false);
    setLookupMode("");
  };

  const loadAll = async () => {
    await Promise.all([
      loadDashboard(),
      loadRecipes(),
      loadPlans(),
      loadOrders(),
      searchProducts(""),
    ]);
  };

  const loadDashboard = async () => {
    await runAction(async () => {
      const res = await getManufacturingDashboard({ branch_id: branchId });
      setDashboard(getPayloadData(res));
      return { message: "" };
    }, "Failed to load manufacturing dashboard.");
  };

  const loadReports = async () => {
    await runAction(async () => {
      const res = await getManufacturingReports({
        branch_id: branchId || undefined,
        date_from: today(),
        date_to: today(),
      });

      setReports(getPayloadData(res));
      return { message: "Reports refreshed." };
    }, "Failed to load manufacturing reports.");
  };

  const loadRecipes = async () => {
    await runAction(async () => {
      const res = await getProductionRecipes({
        branch_id: branchId || undefined,
        per_page: 100,
      });

      setRecipes(normalizeRows(res));
      return { message: "" };
    }, "Failed to load recipes.");
  };

  const loadPlans = async () => {
    await runAction(async () => {
      const res = await getProductionPlans({
        branch_id: branchId || undefined,
        per_page: 100,
      });

      setPlans(normalizeRows(res));
      return { message: "" };
    }, "Failed to load production plans.");
  };

  const loadOrders = async () => {
    await runAction(async () => {
      const res = await getProductionOrders({
        branch_id: branchId || undefined,
        per_page: 100,
      });

      setOrders(normalizeRows(res));
      return { message: "" };
    }, "Failed to load production orders.");
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

      setProducts(normalizeRows(res));
    } catch (error) {
      showNotice(getErrorMessage(error, "Failed to search products."));
    }
  };

  /*
  |--------------------------------------------------------------------------
  | Product Lookup
  |--------------------------------------------------------------------------
  */

  const openLookup = (mode) => {
    setLookupMode(mode);
    setLookupOpen(true);
  };

  const selectProduct = (product) => {
    if (lookupMode === "recipe-finished-product") {
      setRecipeForm((prev) => ({
        ...prev,
        finished_product_id: product.id,
        finished_product_name: getProductName(product),
        finished_product_code: getProductCode(product),
      }));
    }

    if (lookupMode === "recipe-material") {
      setRecipeItems((prev) => [
        ...prev,
        {
          line_id: `${product.id}-${Date.now()}`,
          product_id: product.id,
          product_name: getProductName(product),
          product_code: getProductCode(product),
          uom_id: product?.base_uom_id || product?.uom_id || null,
          unit: getProductUnit(product),
          quantity_required: 1,
          wastage_percent: 0,
          unit_cost: getProductCost(product),
          available_stock: getProductStock(product),
        },
      ]);
    }

    if (lookupMode === "plan-finished-product") {
      setPlanItems((prev) => [
        ...prev,
        {
          line_id: `${product.id}-${Date.now()}`,
          finished_product_id: product.id,
          finished_product_name: getProductName(product),
          finished_product_code: getProductCode(product),
          production_recipe_id: "",
          forecast_quantity: 0,
          safety_stock_quantity: 0,
          planned_quantity: 1,
          planned_labour_cost: 0,
          planned_overhead_cost: 0,
          required_by: todayPlusDays(1),
        },
      ]);
    }

    if (lookupMode === "order-finished-product") {
      setOrderForm((prev) => ({
        ...prev,
        finished_product_id: product.id,
        finished_product_name: getProductName(product),
      }));
    }

    if (lookupMode === "order-input") {
      setOrderInputs((prev) => [
        ...prev,
        {
          line_id: `${product.id}-${Date.now()}`,
          product_id: product.id,
          product_name: getProductName(product),
          product_code: getProductCode(product),
          uom_id: product?.base_uom_id || product?.uom_id || null,
          unit: getProductUnit(product),
          planned_quantity: 1,
          unit_cost: getProductCost(product),
          available_stock: getProductStock(product),
        },
      ]);
    }

    setLookupOpen(false);
  };

  /*
  |--------------------------------------------------------------------------
  | Save Actions
  |--------------------------------------------------------------------------
  */

  const saveRecipe = async () => {
    if (!recipeForm.name) {
      showNotice("Recipe name is required.");
      return;
    }

    if (!recipeForm.finished_product_id) {
      showNotice("Select a finished product.");
      return;
    }

    if (!recipeItems.length) {
      showNotice("Add at least one raw material.");
      return;
    }

    const payload = {
      id: recipeForm.id || undefined,
      branch_id: recipeForm.branch_id || branchId || null,
      finished_product_id: recipeForm.finished_product_id,
      name: recipeForm.name,
      code: recipeForm.code || null,
      costing_basis: recipeForm.costing_basis,
      batch_output_quantity: numberValue(recipeForm.batch_output_quantity),
      standard_wastage_percent: numberValue(
        recipeForm.standard_wastage_percent
      ),
      is_active: recipeForm.is_active,
      metadata: {
        frontend_material_cost: recipeTotals.material_cost,
        frontend_cost_per_unit: recipeTotals.cost_per_unit,
      },
      items: recipeItems.map((line) => ({
        product_id: line.product_id,
        uom_id: line.uom_id || null,
        quantity_required: numberValue(line.quantity_required),
        wastage_percent: numberValue(line.wastage_percent),
        metadata: {
          product_code: line.product_code,
          product_name: line.product_name,
          unit: line.unit,
          unit_cost: numberValue(line.unit_cost),
          available_stock: numberValue(line.available_stock),
        },
      })),
    };

    const res = await runAction(
      () => saveProductionRecipe(payload),
      "Failed to save recipe."
    );

    if (res) {
      const data = getPayloadData(res);

      setRecipeForm((prev) => ({
        ...prev,
        id: data?.id || prev.id,
        code: data?.code || prev.code,
      }));

      await loadRecipes();
      setActivePage("recipes");
    }
  };

  const savePlan = async () => {
    if (!planForm.name) {
      showNotice("Plan name is required.");
      return;
    }

    if (!planForm.branch_id && !branchId) {
      showNotice("Branch is required for production planning.");
      return;
    }

    if (!planItems.length) {
      showNotice("Add at least one planned finished product.");
      return;
    }

    const invalidLine = planItems.find((line) => !line.production_recipe_id);

    if (invalidLine) {
      showNotice("Each plan item must have a recipe selected.");
      return;
    }

    const payload = {
      id: planForm.id || undefined,
      branch_id: planForm.branch_id || branchId,
      name: planForm.name,
      planning_basis: planForm.planning_basis,
      period_start: planForm.period_start,
      period_end: planForm.period_end,
      items: planItems.map((line) => ({
        finished_product_id: line.finished_product_id,
        production_recipe_id: line.production_recipe_id,
        forecast_quantity: numberValue(line.forecast_quantity),
        safety_stock_quantity: numberValue(line.safety_stock_quantity),
        planned_quantity: numberValue(line.planned_quantity),
        planned_labour_cost: numberValue(line.planned_labour_cost),
        planned_overhead_cost: numberValue(line.planned_overhead_cost),
        required_by: line.required_by || null,
        metadata: {
          product_code: line.finished_product_code,
          product_name: line.finished_product_name,
        },
      })),
    };

    const res = await runAction(
      () => saveProductionPlan(payload),
      "Failed to save production plan."
    );

    if (res) {
      await loadPlans();
      setActivePage("plans");
    }
  };

  const saveOrder = async () => {
    if (!orderForm.branch_id && !branchId) {
      showNotice("Branch is required.");
      return;
    }

    if (!orderForm.finished_product_id) {
      showNotice("Select a finished product.");
      return;
    }

    if (!orderInputs.length) {
      showNotice("Add at least one raw material input.");
      return;
    }

    const payload = {
      id: orderForm.id || undefined,
      branch_id: orderForm.branch_id || branchId,
      production_recipe_id: orderForm.production_recipe_id || null,
      finished_product_id: orderForm.finished_product_id,
      planned_output_quantity: numberValue(orderForm.planned_output_quantity),
      currency: String(orderForm.currency || "USD").toUpperCase(),
      planned_start_date: orderForm.planned_start_date || null,
      planned_end_date: orderForm.planned_end_date || null,
      batch_number: orderForm.batch_number || null,
      metadata: {
        frontend_material_cost: orderTotals.material_cost,
        frontend_overhead_cost: orderTotals.overhead_cost,
        frontend_total_cost: orderTotals.total_cost,
        frontend_unit_cost: orderTotals.unit_cost,
      },
      inputs: orderInputs.map((line) => ({
        product_id: line.product_id,
        uom_id: line.uom_id || null,
        planned_quantity: numberValue(line.planned_quantity),
        metadata: {
          product_code: line.product_code,
          product_name: line.product_name,
          unit: line.unit,
          unit_cost: numberValue(line.unit_cost),
          available_stock: numberValue(line.available_stock),
        },
      })),
      overheads: orderOverheads.map((line) => ({
        cost_type: line.cost_type,
        description: line.description || null,
        amount: numberValue(line.amount),
      })),
    };

    const res = await runAction(
      () => saveProductionOrder(payload),
      "Failed to save production order."
    );

    if (res) {
      await loadOrders();
      setActivePage("orders");
    }
  };

  /*
  |--------------------------------------------------------------------------
  | Order Actions
  |--------------------------------------------------------------------------
  */

  const selectOrderForAction = (order) => {
    setSelectedOrder(order);
    setActivePage("order-actions");
  };

  const runOrderAction = async (action) => {
    if (!selectedOrder?.id) {
      showNotice("Select a production order first.");
      return;
    }

    let fn;
    let fallback;

    if (action === "approve") {
      fn = () => approveProductionOrder(selectedOrder.id);
      fallback = "Failed to approve production order.";
    }

    if (action === "reserve") {
      fn = () => reserveProductionMaterials(selectedOrder.id);
      fallback = "Failed to reserve materials.";
    }

    if (action === "issue") {
      fn = () => issueProductionMaterials(selectedOrder.id);
      fallback = "Failed to issue materials.";
    }

    if (action === "start") {
      fn = () => startProductionOrder(selectedOrder.id);
      fallback = "Failed to start production.";
    }

    if (action === "complete") {
      const qty = Number(
        window.prompt("Enter actual good quantity:", selectedOrder.planned_output_quantity || 1)
      );

      if (!qty || qty <= 0) {
        showNotice("Actual good quantity must be greater than zero.");
        return;
      }

      fn = () =>
        completeProductionOrder(selectedOrder.id, {
          actual_good_quantity: qty,
          actual_reject_quantity: 0,
          actual_waste_quantity: 0,
        });
      fallback = "Failed to complete production.";
    }

    if (action === "cancel") {
      fn = () => cancelProductionOrder(selectedOrder.id);
      fallback = "Failed to cancel production order.";
    }

    const res = await runAction(fn, fallback);

    if (res) {
      await loadOrders();
      const data = getPayloadData(res);
      setSelectedOrder(data || selectedOrder);
    }
  };

  /*
  |--------------------------------------------------------------------------
  | Render
  |--------------------------------------------------------------------------
  */

  return (
    <FullScreenModal
      open={open}
      onClose={onClose}
      title="MANUFACTURING"
      subtitle="Recipe → Plan → Material Requirements → Production Orders → Reports"
    >
      <div className="relative h-[calc(100vh-86px)] overflow-hidden bg-[#D8DFDF] text-[#1C2C2B]">
        {notice && (
          <div className="absolute left-1/2 top-3 z-[200] max-w-[760px] -translate-x-1/2 whitespace-pre-line border border-[#004343] bg-[#004344] px-4 py-2 text-center text-[12px] font-semibold text-white shadow-xl">
            {notice}
          </div>
        )}

        {loading && (
          <div className="absolute right-3 top-3 z-[200] border border-[#A4E5E4] bg-white px-3 py-2 text-[11px] font-semibold text-[#057B7B] shadow-xl">
            Processing...
          </div>
        )}

        <div className="grid h-full grid-cols-[260px_minmax(0,1fr)]">
          <Sidebar activePage={activePage} setActivePage={setActivePage} />

          <main className="min-w-0 overflow-hidden p-3">
            <div className="h-full min-h-0 overflow-hidden">
              {activePage === "dashboard" && (
                <DashboardPage
                  dashboard={dashboard}
                  loadDashboard={loadDashboard}
                />
              )}

              {activePage === "recipes" && (
                <RecipesPage
                  recipes={recipes}
                  onRefresh={loadRecipes}
                  onCreate={() => {
                    setRecipeForm({ ...emptyRecipeForm(), branch_id: branchId });
                    setRecipeItems([]);
                    setActivePage("recipe-form");
                  }}
                  onOpen={(recipe) => {
                    setRecipeForm({
                      ...emptyRecipeForm(),
                      ...recipe,
                      recipe_code: recipe.code,
                      finished_product_id: recipe.finished_product_id,
                      finished_product_name:
                        recipe.finished_product?.name ||
                        recipe.finished_product_name ||
                        "",
                    });

                    setRecipeItems(
                      (recipe.items || []).map((item) => ({
                        line_id: item.id || `${item.product_id}-${Date.now()}`,
                        product_id: item.product_id,
                        product_name:
                          item.product?.name ||
                          item.metadata?.product_name ||
                          "",
                        product_code:
                          item.product?.code ||
                          item.metadata?.product_code ||
                          "",
                        uom_id: item.uom_id || null,
                        unit:
                          item.uom?.code ||
                          item.product?.uom?.code ||
                          item.metadata?.unit ||
                          "EACH",
                        quantity_required: numberValue(
                          item.quantity_required
                        ),
                        wastage_percent: numberValue(item.wastage_percent),
                        unit_cost: numberValue(item.metadata?.unit_cost),
                        available_stock: numberValue(
                          item.metadata?.available_stock
                        ),
                      }))
                    );

                    setActivePage("recipe-form");
                  }}
                />
              )}

              {activePage === "recipe-form" && (
                <RecipeFormPage
                  recipeForm={recipeForm}
                  setRecipeForm={setRecipeForm}
                  recipeItems={recipeItems}
                  setRecipeItems={setRecipeItems}
                  recipeTotals={recipeTotals}
                  onBack={() => setActivePage("recipes")}
                  onSave={saveRecipe}
                  openLookup={openLookup}
                />
              )}

              {activePage === "plans" && (
                <PlansPage
                  plans={plans}
                  onRefresh={loadPlans}
                  onCreate={() => {
                    setPlanForm({ ...emptyPlanForm(), branch_id: branchId });
                    setPlanItems([]);
                    setActivePage("plan-form");
                  }}
                  onApprove={(plan) =>
                    runAction(
                      () => approveProductionPlan(plan.id),
                      "Failed to approve plan."
                    ).then(loadPlans)
                  }
                  onCalculate={(plan) =>
                    runAction(
                      () => calculateProductionPlanMaterials(plan.id),
                      "Failed to calculate material requirements."
                    ).then(loadPlans)
                  }
                  onConvert={(plan) =>
                    runAction(
                      () => convertProductionPlanToOrders(plan.id),
                      "Failed to convert plan to production orders."
                    ).then(async () => {
                      await loadPlans();
                      await loadOrders();
                    })
                  }
                />
              )}

              {activePage === "plan-form" && (
                <PlanFormPage
                  planForm={planForm}
                  setPlanForm={setPlanForm}
                  planItems={planItems}
                  setPlanItems={setPlanItems}
                  recipes={recipes}
                  onBack={() => setActivePage("plans")}
                  onSave={savePlan}
                  openLookup={openLookup}
                />
              )}

              {activePage === "orders" && (
                <OrdersPage
                  orders={orders}
                  onRefresh={loadOrders}
                  onCreate={() => {
                    setOrderForm({ ...emptyOrderForm(), branch_id: branchId });
                    setOrderInputs([]);
                    setOrderOverheads([]);
                    setActivePage("order-form");
                  }}
                  onOpenActions={selectOrderForAction}
                />
              )}

              {activePage === "order-form" && (
                <OrderFormPage
                  orderForm={orderForm}
                  setOrderForm={setOrderForm}
                  orderInputs={orderInputs}
                  setOrderInputs={setOrderInputs}
                  orderOverheads={orderOverheads}
                  setOrderOverheads={setOrderOverheads}
                  recipes={recipes}
                  orderTotals={orderTotals}
                  onBack={() => setActivePage("orders")}
                  onSave={saveOrder}
                  openLookup={openLookup}
                />
              )}

              {activePage === "order-actions" && (
                <OrderActionsPage
                  order={selectedOrder}
                  onBack={() => setActivePage("orders")}
                  onAction={runOrderAction}
                />
              )}

              {activePage === "reports" && (
                <ReportsPage reports={reports} onRefresh={loadReports} />
              )}
            </div>
          </main>
        </div>

        <ProductLookupModal
          open={lookupOpen}
          lookupMode={lookupMode}
          search={productSearch}
          setSearch={setProductSearch}
          products={products}
          searchProducts={searchProducts}
          onSelect={selectProduct}
          onClose={() => setLookupOpen(false)}
        />
      </div>
    </FullScreenModal>
  );
}

/*
|--------------------------------------------------------------------------
| Layout
|--------------------------------------------------------------------------
*/

function Sidebar({ activePage, setActivePage }) {
  const items = [
    ["dashboard", "Dashboard", Factory],
    ["recipes", "Recipes / BOM", ClipboardList],
    ["plans", "Production Plans", FileText],
    ["orders", "Production Orders", Factory],
    ["reports", "Reports", FileText],
  ];

  return (
    <aside className="h-full border-r border-[#AFB9B9] bg-[#004344] text-white">
      <div className="border-b border-white/15 px-4 py-4">
        <div className="text-[15px] font-black uppercase tracking-wide">
          Manufacturing
        </div>
        <div className="mt-1 text-[11px] text-white/70">
          Controlled production workflow
        </div>
      </div>

      <nav className="p-2">
        {items.map(([key, label, Icon]) => (
          <button
            key={key}
            type="button"
            onClick={() => setActivePage(key)}
            className={`mb-1 flex h-10 w-full items-center gap-2 border px-3 text-left text-[12px] font-semibold ${
              activePage === key
                ? "border-[#00BCBC] bg-[#00BCBC] text-[#004343]"
                : "border-white/10 bg-white/5 text-white hover:bg-white/10"
            }`}
          >
            <Icon className="h-4 w-4" />
            {label}
          </button>
        ))}
      </nav>
    </aside>
  );
}

function PageShell({ title, subtitle, children, actions }) {
  return (
    <div className="flex h-full min-h-0 flex-col border border-[#AFB9B9] bg-white">
      <div className="flex shrink-0 items-center justify-between border-b border-[#AFB9B9] bg-[#EEF2F2] px-3 py-2">
        <div>
          <div className="text-[14px] font-bold text-[#0C1B1B]">{title}</div>
          <div className="text-[11px] text-[#627474]">{subtitle}</div>
        </div>

        <div className="flex items-center gap-2">{actions}</div>
      </div>

      <div className="min-h-0 flex-1 overflow-auto p-3">{children}</div>
    </div>
  );
}

/*
|--------------------------------------------------------------------------
| Pages
|--------------------------------------------------------------------------
*/

function DashboardPage({ dashboard, loadDashboard }) {
  return (
    <PageShell
      title="Manufacturing Dashboard"
      subtitle="High-level production summary."
      actions={<ActionButton onClick={loadDashboard} label="Refresh" icon={RefreshCw} />}
    >
      <div className="grid gap-3 md:grid-cols-4">
        <MetricCard title="Orders" value={dashboard?.orders ?? 0} />
        <MetricCard
          title="Completed Orders"
          value={dashboard?.completed_orders ?? 0}
        />
        <MetricCard
          title="Planned Output"
          value={qtyNumber(dashboard?.planned_output_quantity ?? 0)}
        />
        <MetricCard
          title="Actual Good Qty"
          value={qtyNumber(dashboard?.actual_good_quantity ?? 0)}
        />
      </div>

      <div className="mt-3 grid gap-3 md:grid-cols-3">
        <MetricCard
          title="Planned Cost"
          value={moneyNumber(dashboard?.planned_total_cost ?? 0)}
        />
        <MetricCard
          title="Actual Cost"
          value={moneyNumber(dashboard?.actual_total_cost ?? 0)}
        />
        <MetricCard
          title="Cost Variance"
          value={moneyNumber(dashboard?.cost_variance_amount ?? 0)}
        />
      </div>
    </PageShell>
  );
}

function RecipesPage({ recipes, onRefresh, onCreate, onOpen }) {
  return (
    <PageShell
      title="Recipes / Bill of Materials"
      subtitle="Define finished product recipes and raw material requirements."
      actions={
        <>
          <ActionButton onClick={onRefresh} label="Refresh" icon={RefreshCw} />
          <ActionButton onClick={onCreate} label="New Recipe" icon={Plus} primary />
        </>
      }
    >
      <DataTable
        columns={["Code", "Recipe", "Finished Product", "Basis", "Batch Qty", "Status", ""]}
        emptyText="No recipes found."
      >
        {recipes.map((recipe) => (
          <tr key={recipe.id} className="border-b border-[#E9EDED]">
            <Td>{recipe.code || "-"}</Td>
            <Td strong>{recipe.name}</Td>
            <Td>{recipe.finished_product?.name || "-"}</Td>
            <Td>{recipe.costing_basis}</Td>
            <Td right>{qtyNumber(recipe.batch_output_quantity)}</Td>
            <Td>
              <StatusBadge status={recipe.is_active ? "active" : "inactive"} />
            </Td>
            <Td right>
              <SmallButton onClick={() => onOpen(recipe)}>Open</SmallButton>
            </Td>
          </tr>
        ))}
      </DataTable>
    </PageShell>
  );
}

function RecipeFormPage({
  recipeForm,
  setRecipeForm,
  recipeItems,
  setRecipeItems,
  recipeTotals,
  onBack,
  onSave,
  openLookup,
}) {
  const updateLine = (lineId, patch) => {
    setRecipeItems((prev) =>
      prev.map((line) => (line.line_id === lineId ? { ...line, ...patch } : line))
    );
  };

  const removeLine = (lineId) => {
    setRecipeItems((prev) => prev.filter((line) => line.line_id !== lineId));
  };

  return (
    <PageShell
      title={recipeForm.id ? "Edit Recipe" : "Create Recipe"}
      subtitle="Recipe header and raw material BOM lines."
      actions={
        <>
          <ActionButton onClick={onBack} label="Back" />
          <ActionButton onClick={onSave} label="Save Recipe" icon={CheckCircle2} primary />
        </>
      }
    >
      <Panel title="Recipe Header">
        <div className="grid gap-2 md:grid-cols-4">
          <Field
            label="Recipe Name"
            value={recipeForm.name}
            onChange={(v) => setRecipeForm((p) => ({ ...p, name: v }))}
          />

          <Field
            label="Recipe Code"
            value={recipeForm.code}
            onChange={(v) => setRecipeForm((p) => ({ ...p, code: v }))}
            placeholder="Optional"
          />

          <SelectField
            label="Costing Basis"
            value={recipeForm.costing_basis}
            onChange={(v) => setRecipeForm((p) => ({ ...p, costing_basis: v }))}
            options={[
              ["per_batch", "Per Batch"],
              ["per_unit", "Per Unit"],
            ]}
          />

          <Field
            label="Batch Output Qty"
            type="number"
            value={recipeForm.batch_output_quantity}
            onChange={(v) =>
              setRecipeForm((p) => ({
                ...p,
                batch_output_quantity: Number(v || 0),
              }))
            }
          />

          <Field
            label="Std Wastage %"
            type="number"
            value={recipeForm.standard_wastage_percent}
            onChange={(v) =>
              setRecipeForm((p) => ({
                ...p,
                standard_wastage_percent: Number(v || 0),
              }))
            }
          />

          <button
            type="button"
            onClick={() => openLookup("recipe-finished-product")}
            className="h-[54px] border border-[#004343] bg-white px-3 text-left text-[12px] font-semibold text-[#004343]"
          >
            <div className="text-[10px] uppercase text-[#627474]">
              Finished Product
            </div>
            <div className="truncate">
              {recipeForm.finished_product_name || "Select Product"}
            </div>
          </button>
        </div>
      </Panel>

      <Panel
        title="Raw Materials"
        actions={
          <ActionButton
            onClick={() => openLookup("recipe-material")}
            label="Add Material"
            icon={Plus}
            primary
          />
        }
      >
        <DataTable
          columns={[
            "Code",
            "Material",
            "Unit",
            "Required Qty",
            "Waste %",
            "Unit Cost",
            "Available",
            "",
          ]}
          emptyText="No raw materials added."
        >
          {recipeItems.map((line) => (
            <tr key={line.line_id} className="border-b border-[#E9EDED]">
              <Td>{line.product_code || "-"}</Td>
              <Td strong>{line.product_name}</Td>
              <Td>{line.unit || "EACH"}</Td>
              <Td>
                <MiniInput
                  type="number"
                  value={line.quantity_required}
                  onChange={(v) =>
                    updateLine(line.line_id, {
                      quantity_required: Number(v || 0),
                    })
                  }
                />
              </Td>
              <Td>
                <MiniInput
                  type="number"
                  value={line.wastage_percent}
                  onChange={(v) =>
                    updateLine(line.line_id, {
                      wastage_percent: Number(v || 0),
                    })
                  }
                />
              </Td>
              <Td>
                <MiniInput
                  type="number"
                  value={line.unit_cost}
                  onChange={(v) =>
                    updateLine(line.line_id, {
                      unit_cost: Number(v || 0),
                    })
                  }
                />
              </Td>
              <Td right>{qtyNumber(line.available_stock)}</Td>
              <Td right>
                <IconButton onClick={() => removeLine(line.line_id)} icon={Trash2} danger />
              </Td>
            </tr>
          ))}
        </DataTable>
      </Panel>

      <SummaryBar
        items={[
          ["Material Cost", moneyNumber(recipeTotals.material_cost)],
          ["Cost Per Unit", moneyNumber(recipeTotals.cost_per_unit)],
        ]}
      />
    </PageShell>
  );
}

function PlansPage({
  plans,
  onRefresh,
  onCreate,
  onApprove,
  onCalculate,
  onConvert,
}) {
  return (
    <PageShell
      title="Production Plans"
      subtitle="Create plans, calculate materials, and convert plans into production orders."
      actions={
        <>
          <ActionButton onClick={onRefresh} label="Refresh" icon={RefreshCw} />
          <ActionButton onClick={onCreate} label="New Plan" icon={Plus} primary />
        </>
      }
    >
      <DataTable
        columns={["Plan No", "Name", "Basis", "Period", "Status", "Actions"]}
        emptyText="No production plans found."
      >
        {plans.map((plan) => (
          <tr key={plan.id} className="border-b border-[#E9EDED]">
            <Td>{plan.plan_number || "-"}</Td>
            <Td strong>{plan.name}</Td>
            <Td>{plan.planning_basis}</Td>
            <Td>
              {String(plan.period_start || "").slice(0, 10)} →{" "}
              {String(plan.period_end || "").slice(0, 10)}
            </Td>
            <Td>
              <StatusBadge status={plan.status} />
            </Td>
            <Td>
              <div className="flex flex-wrap gap-1">
                <SmallButton onClick={() => onApprove(plan)}>Approve</SmallButton>
                <SmallButton onClick={() => onCalculate(plan)}>Materials</SmallButton>
                <SmallButton onClick={() => onConvert(plan)}>Convert</SmallButton>
              </div>
            </Td>
          </tr>
        ))}
      </DataTable>
    </PageShell>
  );
}

function PlanFormPage({
  planForm,
  setPlanForm,
  planItems,
  setPlanItems,
  recipes,
  onBack,
  onSave,
  openLookup,
}) {
  const updateLine = (lineId, patch) => {
    setPlanItems((prev) =>
      prev.map((line) => (line.line_id === lineId ? { ...line, ...patch } : line))
    );
  };

  const removeLine = (lineId) => {
    setPlanItems((prev) => prev.filter((line) => line.line_id !== lineId));
  };

  return (
    <PageShell
      title="Create Production Plan"
      subtitle="Plan finished goods and link each item to a recipe."
      actions={
        <>
          <ActionButton onClick={onBack} label="Back" />
          <ActionButton onClick={onSave} label="Save Plan" icon={CheckCircle2} primary />
        </>
      }
    >
      <Panel title="Plan Header">
        <div className="grid gap-2 md:grid-cols-4">
          <Field
            label="Plan Name"
            value={planForm.name}
            onChange={(v) => setPlanForm((p) => ({ ...p, name: v }))}
          />

          <SelectField
            label="Planning Basis"
            value={planForm.planning_basis}
            onChange={(v) => setPlanForm((p) => ({ ...p, planning_basis: v }))}
            options={[
              ["manual", "Manual"],
              ["sales_forecast", "Sales Forecast"],
              ["sales_order", "Sales Order"],
              ["minimum_stock", "Minimum Stock"],
              ["reorder_level", "Reorder Level"],
            ]}
          />

          <Field
            label="Period Start"
            type="date"
            value={planForm.period_start}
            onChange={(v) => setPlanForm((p) => ({ ...p, period_start: v }))}
          />

          <Field
            label="Period End"
            type="date"
            value={planForm.period_end}
            onChange={(v) => setPlanForm((p) => ({ ...p, period_end: v }))}
          />
        </div>
      </Panel>

      <Panel
        title="Plan Items"
        actions={
          <ActionButton
            onClick={() => openLookup("plan-finished-product")}
            label="Add Product"
            icon={Plus}
            primary
          />
        }
      >
        <DataTable
          columns={[
            "Product",
            "Recipe",
            "Forecast",
            "Safety",
            "Planned Qty",
            "Labour",
            "Overhead",
            "Required By",
            "",
          ]}
          emptyText="No plan items added."
        >
          {planItems.map((line) => (
            <tr key={line.line_id} className="border-b border-[#E9EDED]">
              <Td strong>{line.finished_product_name}</Td>
              <Td>
                <select
                  value={line.production_recipe_id}
                  onChange={(e) =>
                    updateLine(line.line_id, {
                      production_recipe_id: e.target.value,
                    })
                  }
                  className="h-8 w-full border border-[#B9C3C3] bg-white px-2 text-[11px]"
                >
                  <option value="">Select Recipe</option>
                  {recipes.map((recipe) => (
                    <option key={recipe.id} value={recipe.id}>
                      {recipe.name}
                    </option>
                  ))}
                </select>
              </Td>
              <Td>
                <MiniInput
                  type="number"
                  value={line.forecast_quantity}
                  onChange={(v) =>
                    updateLine(line.line_id, {
                      forecast_quantity: Number(v || 0),
                    })
                  }
                />
              </Td>
              <Td>
                <MiniInput
                  type="number"
                  value={line.safety_stock_quantity}
                  onChange={(v) =>
                    updateLine(line.line_id, {
                      safety_stock_quantity: Number(v || 0),
                    })
                  }
                />
              </Td>
              <Td>
                <MiniInput
                  type="number"
                  value={line.planned_quantity}
                  onChange={(v) =>
                    updateLine(line.line_id, {
                      planned_quantity: Number(v || 0),
                    })
                  }
                />
              </Td>
              <Td>
                <MiniInput
                  type="number"
                  value={line.planned_labour_cost}
                  onChange={(v) =>
                    updateLine(line.line_id, {
                      planned_labour_cost: Number(v || 0),
                    })
                  }
                />
              </Td>
              <Td>
                <MiniInput
                  type="number"
                  value={line.planned_overhead_cost}
                  onChange={(v) =>
                    updateLine(line.line_id, {
                      planned_overhead_cost: Number(v || 0),
                    })
                  }
                />
              </Td>
              <Td>
                <MiniInput
                  type="date"
                  value={line.required_by}
                  onChange={(v) =>
                    updateLine(line.line_id, {
                      required_by: v,
                    })
                  }
                />
              </Td>
              <Td right>
                <IconButton onClick={() => removeLine(line.line_id)} icon={Trash2} danger />
              </Td>
            </tr>
          ))}
        </DataTable>
      </Panel>
    </PageShell>
  );
}

function OrdersPage({ orders, onRefresh, onCreate, onOpenActions }) {
  return (
    <PageShell
      title="Production Orders"
      subtitle="Approve, reserve, issue, start, complete or cancel production orders."
      actions={
        <>
          <ActionButton onClick={onRefresh} label="Refresh" icon={RefreshCw} />
          <ActionButton onClick={onCreate} label="New Order" icon={Plus} primary />
        </>
      }
    >
      <DataTable
        columns={[
          "Production No",
          "Finished Product",
          "Planned Qty",
          "Actual Qty",
          "Status",
          "",
        ]}
        emptyText="No production orders found."
      >
        {orders.map((order) => (
          <tr key={order.id} className="border-b border-[#E9EDED]">
            <Td>{order.production_number || "-"}</Td>
            <Td strong>{order.finished_product?.name || "-"}</Td>
            <Td right>{qtyNumber(order.planned_output_quantity)}</Td>
            <Td right>{qtyNumber(order.actual_good_quantity)}</Td>
            <Td>
              <StatusBadge status={order.status} />
            </Td>
            <Td right>
              <SmallButton onClick={() => onOpenActions(order)}>
                Actions
              </SmallButton>
            </Td>
          </tr>
        ))}
      </DataTable>
    </PageShell>
  );
}

function OrderFormPage({
  orderForm,
  setOrderForm,
  orderInputs,
  setOrderInputs,
  orderOverheads,
  setOrderOverheads,
  recipes,
  orderTotals,
  onBack,
  onSave,
  openLookup,
}) {
  const updateInput = (lineId, patch) => {
    setOrderInputs((prev) =>
      prev.map((line) => (line.line_id === lineId ? { ...line, ...patch } : line))
    );
  };

  const removeInput = (lineId) => {
    setOrderInputs((prev) => prev.filter((line) => line.line_id !== lineId));
  };

  const addOverhead = () => {
    setOrderOverheads((prev) => [
      ...prev,
      {
        line_id: `oh-${Date.now()}`,
        cost_type: "other",
        description: "",
        amount: 0,
      },
    ]);
  };

  const updateOverhead = (lineId, patch) => {
    setOrderOverheads((prev) =>
      prev.map((line) => (line.line_id === lineId ? { ...line, ...patch } : line))
    );
  };

  const removeOverhead = (lineId) => {
    setOrderOverheads((prev) => prev.filter((line) => line.line_id !== lineId));
  };

  return (
    <PageShell
      title="Create Production Order"
      subtitle="Create a manual production order with raw material inputs."
      actions={
        <>
          <ActionButton onClick={onBack} label="Back" />
          <ActionButton onClick={onSave} label="Save Order" icon={CheckCircle2} primary />
        </>
      }
    >
      <Panel title="Order Header">
        <div className="grid gap-2 md:grid-cols-4">
          <button
            type="button"
            onClick={() => openLookup("order-finished-product")}
            className="h-[54px] border border-[#004343] bg-white px-3 text-left text-[12px] font-semibold text-[#004343]"
          >
            <div className="text-[10px] uppercase text-[#627474]">
              Finished Product
            </div>
            <div className="truncate">
              {orderForm.finished_product_name || "Select Product"}
            </div>
          </button>

          <SelectField
            label="Recipe"
            value={orderForm.production_recipe_id}
            onChange={(v) =>
              setOrderForm((p) => ({ ...p, production_recipe_id: v }))
            }
            options={[
              ["", "No Recipe"],
              ...recipes.map((recipe) => [recipe.id, recipe.name]),
            ]}
          />

          <Field
            label="Output Qty"
            type="number"
            value={orderForm.planned_output_quantity}
            onChange={(v) =>
              setOrderForm((p) => ({
                ...p,
                planned_output_quantity: Number(v || 0),
              }))
            }
          />

          <Field
            label="Currency"
            value={orderForm.currency}
            onChange={(v) =>
              setOrderForm((p) => ({ ...p, currency: v.toUpperCase() }))
            }
          />

          <Field
            label="Start Date"
            type="date"
            value={orderForm.planned_start_date}
            onChange={(v) =>
              setOrderForm((p) => ({ ...p, planned_start_date: v }))
            }
          />

          <Field
            label="End Date"
            type="date"
            value={orderForm.planned_end_date}
            onChange={(v) =>
              setOrderForm((p) => ({ ...p, planned_end_date: v }))
            }
          />

          <Field
            label="Batch Number"
            value={orderForm.batch_number}
            onChange={(v) =>
              setOrderForm((p) => ({ ...p, batch_number: v }))
            }
          />
        </div>
      </Panel>

      <Panel
        title="Raw Material Inputs"
        actions={
          <ActionButton
            onClick={() => openLookup("order-input")}
            label="Add Input"
            icon={Plus}
            primary
          />
        }
      >
        <DataTable
          columns={["Code", "Material", "Unit", "Planned Qty", "Unit Cost", "Available", ""]}
          emptyText="No raw material inputs added."
        >
          {orderInputs.map((line) => (
            <tr key={line.line_id} className="border-b border-[#E9EDED]">
              <Td>{line.product_code || "-"}</Td>
              <Td strong>{line.product_name}</Td>
              <Td>{line.unit}</Td>
              <Td>
                <MiniInput
                  type="number"
                  value={line.planned_quantity}
                  onChange={(v) =>
                    updateInput(line.line_id, {
                      planned_quantity: Number(v || 0),
                    })
                  }
                />
              </Td>
              <Td>
                <MiniInput
                  type="number"
                  value={line.unit_cost}
                  onChange={(v) =>
                    updateInput(line.line_id, {
                      unit_cost: Number(v || 0),
                    })
                  }
                />
              </Td>
              <Td right>{qtyNumber(line.available_stock)}</Td>
              <Td right>
                <IconButton onClick={() => removeInput(line.line_id)} icon={Trash2} danger />
              </Td>
            </tr>
          ))}
        </DataTable>
      </Panel>

      <Panel
        title="Planned Overheads"
        actions={
          <ActionButton
            onClick={addOverhead}
            label="Add Overhead"
            icon={Plus}
            primary
          />
        }
      >
        <DataTable
          columns={["Cost Type", "Description", "Amount", ""]}
          emptyText="No overheads added."
        >
          {orderOverheads.map((line) => (
            <tr key={line.line_id} className="border-b border-[#E9EDED]">
              <Td>
                <select
                  value={line.cost_type}
                  onChange={(e) =>
                    updateOverhead(line.line_id, {
                      cost_type: e.target.value,
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
                  <option value="machine_maintenance">Machine Maintenance</option>
                  <option value="depreciation">Depreciation</option>
                  <option value="other">Other</option>
                </select>
              </Td>
              <Td>
                <MiniInput
                  value={line.description}
                  onChange={(v) =>
                    updateOverhead(line.line_id, {
                      description: v,
                    })
                  }
                />
              </Td>
              <Td>
                <MiniInput
                  type="number"
                  value={line.amount}
                  onChange={(v) =>
                    updateOverhead(line.line_id, {
                      amount: Number(v || 0),
                    })
                  }
                />
              </Td>
              <Td right>
                <IconButton onClick={() => removeOverhead(line.line_id)} icon={Trash2} danger />
              </Td>
            </tr>
          ))}
        </DataTable>
      </Panel>

      <SummaryBar
        items={[
          ["Material Cost", moneyNumber(orderTotals.material_cost)],
          ["Overhead Cost", moneyNumber(orderTotals.overhead_cost)],
          ["Total Cost", moneyNumber(orderTotals.total_cost)],
          ["Unit Cost", moneyNumber(orderTotals.unit_cost)],
        ]}
      />
    </PageShell>
  );
}

function OrderActionsPage({ order, onBack, onAction }) {
  if (!order) {
    return (
      <PageShell
        title="Production Order Actions"
        subtitle="No order selected."
        actions={<ActionButton onClick={onBack} label="Back" />}
      >
        <EmptyState text="Select a production order first." />
      </PageShell>
    );
  }

  return (
    <PageShell
      title="Production Order Actions"
      subtitle="Follow the backend status flow: approve → reserve → issue → start → complete."
      actions={<ActionButton onClick={onBack} label="Back" />}
    >
      <div className="mb-3 grid gap-3 md:grid-cols-4">
        <MetricCard title="Production No" value={order.production_number || "-"} />
        <MetricCard
          title="Product"
          value={order.finished_product?.name || "-"}
          small
        />
        <MetricCard
          title="Planned Qty"
          value={qtyNumber(order.planned_output_quantity)}
        />
        <div className="border border-[#AFB9B9] bg-white p-3">
          <div className="text-[10px] font-semibold uppercase text-[#627474]">
            Status
          </div>
          <div className="mt-2">
            <StatusBadge status={order.status} />
          </div>
        </div>
      </div>

      <Panel title="Production Workflow Actions">
        <div className="grid gap-2 md:grid-cols-3">
          <WorkflowButton
            title="1. Approve"
            description="Approve draft production order."
            onClick={() => onAction("approve")}
          />
          <WorkflowButton
            title="2. Reserve Materials"
            description="Reserve stock for raw materials."
            onClick={() => onAction("reserve")}
          />
          <WorkflowButton
            title="3. Issue Materials"
            description="Issue raw materials to production."
            onClick={() => onAction("issue")}
          />
          <WorkflowButton
            title="4. Start Production"
            description="Move order into production."
            onClick={() => onAction("start")}
          />
          <WorkflowButton
            title="5. Complete Production"
            description="Receive finished goods into stock."
            onClick={() => onAction("complete")}
            green
          />
          <WorkflowButton
            title="Cancel Order"
            description="Cancel this production order."
            onClick={() => onAction("cancel")}
            danger
          />
        </div>
      </Panel>
    </PageShell>
  );
}

function ReportsPage({ reports, onRefresh }) {
  const summary = reports?.summary || {};
  const materialRequirements = normalizeRows(reports?.material_requirements);
  const variance = normalizeRows(reports?.variance);

  return (
    <PageShell
      title="Manufacturing Reports"
      subtitle="Production summary, material requirements and variance."
      actions={<ActionButton onClick={onRefresh} label="Refresh" icon={RefreshCw} primary />}
    >
      <div className="grid gap-3 md:grid-cols-4">
        <MetricCard title="Orders" value={summary.orders ?? 0} />
        <MetricCard title="Completed" value={summary.completed_orders ?? 0} />
        <MetricCard
          title="Actual Good Qty"
          value={qtyNumber(summary.actual_good_quantity ?? 0)}
        />
        <MetricCard
          title="Cost Variance"
          value={moneyNumber(summary.cost_variance_amount ?? 0)}
        />
      </div>

      <Panel title="Material Requirements">
        <DataTable
          columns={["Product", "Required", "Available", "Reserved", "Shortage", "Status"]}
          emptyText="No material requirements found."
        >
          {materialRequirements.map((row) => (
            <tr key={row.id} className="border-b border-[#E9EDED]">
              <Td strong>{row.product?.name || "-"}</Td>
              <Td right>{qtyNumber(row.required_quantity)}</Td>
              <Td right>{qtyNumber(row.available_quantity)}</Td>
              <Td right>{qtyNumber(row.reserved_quantity)}</Td>
              <Td right>{qtyNumber(row.shortage_quantity)}</Td>
              <Td>
                <StatusBadge status={row.status} />
              </Td>
            </tr>
          ))}
        </DataTable>
      </Panel>

      <Panel title="Production Variance">
        <DataTable
          columns={["Production No", "Product", "Good Qty", "Total Cost", "Unit Cost", "Status"]}
          emptyText="No variance rows found."
        >
          {variance.map((row) => (
            <tr key={row.id} className="border-b border-[#E9EDED]">
              <Td>{row.production_number}</Td>
              <Td strong>{row.finished_product?.name || "-"}</Td>
              <Td right>{qtyNumber(row.actual_good_quantity)}</Td>
              <Td right>{moneyNumber(row.actual_total_cost)}</Td>
              <Td right>{moneyNumber(row.actual_unit_cost)}</Td>
              <Td>
                <StatusBadge status={row.status} />
              </Td>
            </tr>
          ))}
        </DataTable>
      </Panel>
    </PageShell>
  );
}

/*
|--------------------------------------------------------------------------
| Product Lookup
|--------------------------------------------------------------------------
*/

function ProductLookupModal({
  open,
  lookupMode,
  search,
  setSearch,
  products,
  searchProducts,
  onSelect,
  onClose,
}) {
  if (!open) return null;

  return (
    <div className="absolute inset-0 z-[300] flex items-center justify-center bg-[#004040]/60 p-4">
      <div className="flex h-[74vh] w-full max-w-[980px] flex-col border border-[#AFB9B9] bg-white shadow-2xl">
        <div className="flex h-11 shrink-0 items-center justify-between border-b border-[#AFB9B9] bg-[#EEF2F2] px-3">
          <div>
            <div className="text-[13px] font-bold text-[#0C1B1B]">
              Product Lookup
            </div>
            <div className="text-[10px] text-[#627474]">{lookupMode}</div>
          </div>

          <button
            type="button"
            onClick={onClose}
            className="flex h-8 w-8 items-center justify-center border border-[#AFB9B9] bg-white"
          >
            <X className="h-4 w-4" />
          </button>
        </div>

        <div className="border-b border-[#D8DFDF] p-2">
          <div className="flex gap-2">
            <div className="relative min-w-0 flex-1">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[#627474]" />
              <input
                autoFocus
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === "Enter") searchProducts(search);
                }}
                placeholder="Search item code, SKU, barcode or description..."
                className="h-9 w-full border border-[#B9C3C3] bg-white pl-9 pr-3 text-[12px] outline-none focus:border-[#004343]"
              />
            </div>

            <ActionButton
              onClick={() => searchProducts(search)}
              label="Search"
              icon={Search}
              primary
            />
          </div>
        </div>

        <div className="min-h-0 flex-1 overflow-auto">
          <DataTable
            columns={["Code", "Product", "Category", "Stock", "Cost", ""]}
            emptyText="No products found."
          >
            {products.map((product) => (
              <tr key={product.id} className="border-b border-[#E9EDED]">
                <Td>{getProductCode(product) || "-"}</Td>
                <Td strong>{getProductName(product)}</Td>
                <Td>{product.category?.name || "Uncategorised"}</Td>
                <Td right>{qtyNumber(getProductStock(product))}</Td>
                <Td right>{moneyNumber(getProductCost(product))}</Td>
                <Td right>
                  <SmallButton onClick={() => onSelect(product)}>
                    Select
                  </SmallButton>
                </Td>
              </tr>
            ))}
          </DataTable>
        </div>
      </div>
    </div>
  );
}

/*
|--------------------------------------------------------------------------
| Base Components
|--------------------------------------------------------------------------
*/

function Panel({ title, children, actions }) {
  return (
    <div className="mb-3 border border-[#AFB9B9] bg-white">
      <div className="flex items-center justify-between border-b border-[#D8DFDF] bg-[#EEF2F2] px-2 py-2">
        <div className="text-[12px] font-bold text-[#324443]">{title}</div>
        <div>{actions}</div>
      </div>
      <div className="p-2">{children}</div>
    </div>
  );
}

function MetricCard({ title, value, small = false }) {
  return (
    <div className="border border-[#AFB9B9] bg-white p-3">
      <div className="text-[10px] font-semibold uppercase text-[#627474]">
        {title}
      </div>
      <div
        className={`mt-2 font-black text-[#004343] ${
          small ? "truncate text-[15px]" : "text-[24px]"
        }`}
      >
        {value}
      </div>
    </div>
  );
}

function SummaryBar({ items }) {
  return (
    <div className="grid gap-2 md:grid-cols-4">
      {items.map(([label, value]) => (
        <MetricCard key={label} title={label} value={value} />
      ))}
    </div>
  );
}

function DataTable({ columns, children, emptyText }) {
  const hasRows = React.Children.count(children) > 0;

  return (
    <div className="overflow-auto border border-[#D8DFDF]">
      <table className="w-full min-w-[860px] border-collapse text-[12px]">
        <thead>
          <tr className="border-b border-[#D8DFDF] bg-[#F4F6F8] text-left text-[10px] uppercase text-[#627474]">
            {columns.map((column) => (
              <th key={column} className="px-2 py-2 font-bold">
                {column}
              </th>
            ))}
          </tr>
        </thead>

        <tbody>
          {hasRows ? (
            children
          ) : (
            <tr>
              <td
                colSpan={columns.length}
                className="py-10 text-center text-[12px] text-[#627474]"
              >
                <PackageSearch className="mx-auto mb-2 h-7 w-7" />
                {emptyText}
              </td>
            </tr>
          )}
        </tbody>
      </table>
    </div>
  );
}

function Td({ children, right = false, strong = false }) {
  return (
    <td
      className={`px-2 py-2 text-[12px] ${
        right ? "text-right" : ""
      } ${strong ? "font-semibold text-[#0C1B1B]" : "text-[#324443]"}`}
    >
      {children}
    </td>
  );
}

function StatusBadge({ status }) {
  return (
    <span
      className={`inline-flex border px-2 py-1 text-[9px] font-bold uppercase ${statusTone(
        status
      )}`}
    >
      {status || "draft"}
    </span>
  );
}

function EmptyState({ text }) {
  return (
    <div className="flex h-[260px] flex-col items-center justify-center text-[12px] text-[#627474]">
      <AlertCircle className="mb-2 h-8 w-8" />
      {text}
    </div>
  );
}

function ActionButton({
  label,
  onClick,
  icon: Icon,
  primary = false,
  danger = false,
}) {
  let cls = "border-[#B9C3C3] bg-white text-[#324443]";

  if (primary) cls = "border-[#004343] bg-[#004344] text-white";
  if (danger) cls = "border-[#B42318] bg-[#B42318] text-white";

  return (
    <button
      type="button"
      onClick={onClick}
      className={`inline-flex h-8 items-center gap-1 border px-3 text-[11px] font-bold ${cls}`}
    >
      {Icon && <Icon className="h-3.5 w-3.5" />}
      {label}
    </button>
  );
}

function SmallButton({ children, onClick }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="h-7 border border-[#004343] bg-[#004344] px-2 text-[10px] font-bold text-white"
    >
      {children}
    </button>
  );
}

function IconButton({ onClick, icon: Icon, danger = false }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={`inline-flex h-7 w-7 items-center justify-center border ${
        danger
          ? "border-[#FECDCA] bg-white text-[#B42318]"
          : "border-[#B9C3C3] bg-white text-[#324443]"
      }`}
    >
      <Icon className="h-3.5 w-3.5" />
    </button>
  );
}

function WorkflowButton({ title, description, onClick, green = false, danger = false }) {
  let cls = "border-[#B9C3C3] bg-white hover:border-[#004343]";

  if (green) cls = "border-[#027A48] bg-[#ECFDF3]";
  if (danger) cls = "border-[#FECDCA] bg-[#FEF3F2]";

  return (
    <button
      type="button"
      onClick={onClick}
      className={`min-h-[86px] border p-3 text-left ${cls}`}
    >
      <div className="text-[12px] font-black text-[#0C1B1B]">{title}</div>
      <div className="mt-1 text-[11px] text-[#627474]">{description}</div>
    </button>
  );
}

function Field({
  label,
  value,
  onChange,
  type = "text",
  placeholder = "",
  disabled = false,
}) {
  return (
    <label className="block">
      <div className="mb-1 text-[10px] font-bold uppercase text-[#627474]">
        {label}
      </div>
      <input
        type={type}
        value={value || ""}
        placeholder={placeholder}
        disabled={disabled}
        onChange={(e) => onChange?.(e.target.value)}
        className="h-9 w-full border border-[#B9C3C3] bg-white px-2 text-[12px] outline-none focus:border-[#004343] disabled:bg-[#EEF2F2]"
      />
    </label>
  );
}

function SelectField({ label, value, onChange, options = [] }) {
  return (
    <label className="block">
      <div className="mb-1 text-[10px] font-bold uppercase text-[#627474]">
        {label}
      </div>
      <select
        value={value || ""}
        onChange={(e) => onChange?.(e.target.value)}
        className="h-9 w-full border border-[#B9C3C3] bg-white px-2 text-[12px] outline-none focus:border-[#004343]"
      >
        {options.map(([key, labelText]) => (
          <option key={key} value={key}>
            {labelText}
          </option>
        ))}
      </select>
    </label>
  );
}

function MiniInput({ value, onChange, type = "text" }) {
  return (
    <input
      type={type}
      value={value || ""}
      onChange={(e) => onChange?.(e.target.value)}
      className="h-8 w-full border border-[#B9C3C3] bg-white px-2 text-[11px] outline-none focus:border-[#004343]"
    />
  );
}
import React, { useEffect, useMemo, useState } from "react";
import {
  CheckCircle2,
  Factory,
  FileText,
  Layers,
  RefreshCw,
  Trash2,
} from "lucide-react";

import FullScreenModal from "../../../components/FullScreenModal";
import { getProducts } from "../../../services/ProductService";
import { getBaseCurrencies } from "../../../services/CurrencyService";
import {
  getProductionRecipes,
  saveProductionPlan,
} from "../../../services/ManufacturingService";

import ManufacturingProductLookupModal from "./ManufacturingProductLookupModal";

import {
  ActionButton,
  Field,
  GridTd,
  GridTh,
  IconButton,
  LoadingPill,
  MetricCard,
  MiniInput,
  Notice,
  Panel,
  ReadinessLine,
  SelectField,
  SmallButton,
  getBranchId,
  getErrorMessage,
  getProductCode,
  getProductName,
  getProductStock,
  moneyNumber,
  normalizeRows,
  numberValue,
  qtyNumber,
  today,
  todayPlusDays,
  useNotice,
} from "./ManufacturingShared";

const FINISHED_PRODUCT_TYPES = ["finished_good", "semi_finished"];

const emptyForm = (branchId = "", currency = "") => ({
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

export default function ManufacturingPlanFormModal({
  open,
  onClose,
  currentUser = null,
}) {
  const branchId = getBranchId(currentUser);

  const [baseCurrencies, setBaseCurrencies] = useState([]);
  const [form, setForm] = useState(emptyForm(branchId, "USD"));

  const [items, setItems] = useState([]);
  const [recipes, setRecipes] = useState([]);
  const [products, setProducts] = useState([]);

  const [lookupOpen, setLookupOpen] = useState(false);
  const [productSearch, setProductSearch] = useState("");

  const [loading, setLoading] = useState(false);
  const [activeTab, setActiveTab] = useState("items");

  const { notice, showNotice, closeNotice } = useNotice();

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

    // Production planning now estimates material/BOM cost only.
    // Labour, salary allocation and overhead are captured later in the
    // production workflow when the real production order is executed.
    const totalCost = materialCost;

    return {
      plannedQty,
      forecastQty,
      stockQty,
      safetyQty,
      materialCost,
      labourCost: 0,
      overheadCost: 0,
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
      setForm(emptyForm(branchId, currency));
    } catch (error) {
      showNotice(
        getErrorMessage(error, "Failed to load plan setup data."),
        "error",
        "Load Failed"
      );
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
      showNotice(
        getErrorMessage(error, "Failed to load recipes."),
        "error",
        "Load Failed"
      );
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

        // Backend should support this using whereIn.
        product_types: FINISHED_PRODUCT_TYPES,
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
        "error",
        "Search Failed"
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
      showNotice("Plan name is required.", "error", "Validation Error");
      return;
    }

    if (!items.length) {
      showNotice(
        "Add at least one finished good to plan.",
        "error",
        "Validation Error"
      );
      return;
    }

    if (items.some((line) => !line.production_recipe_id)) {
      showNotice(
        "Each planned product must have a BOM/recipe selected.",
        "error",
        "Validation Error"
      );
      return;
    }

    if (!form.currency) {
      showNotice("Currency is required.", "error", "Validation Error");
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
        frontend_labour_cost: 0,
        frontend_overhead_cost: 0,
        costing_note:
          "Production plan carries BOM/material estimate only. Labour, salaries and overheads are captured in production workflow.",
      },
      items: items.map((line) => ({
        finished_product_id: line.finished_product_id,
        production_recipe_id: line.production_recipe_id,
        forecast_quantity: numberValue(line.forecast_quantity),
        current_stock_quantity: numberValue(line.current_stock_quantity),
        safety_stock_quantity: numberValue(line.safety_stock_quantity),
        planned_quantity: numberValue(line.planned_quantity),
        planned_labour_cost: 0,
        planned_overhead_cost: 0,
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

      showNotice(res?.message || "Production plan saved.", "info", "Plan Saved");

      setForm(emptyForm(branchId, defaultCurrency));
      setItems([]);

      onClose?.();
    } catch (error) {
      showNotice(
        getErrorMessage(error, "Failed to save production plan."),
        "error",
        "Save Failed"
      );
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
      subtitle="Create a production plan from finished goods, semi-finished goods and BOM recipes"
    >
      <Notice notice={notice} onClose={closeNotice} />
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
              <Field
                label="Plan Name"
                value={form.name}
                onChange={(value) =>
                  setForm((prev) => ({ ...prev, name: value }))
                }
              />

              <SelectField
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

              <Field
                label="Period Start"
                type="date"
                value={form.period_start}
                onChange={(value) =>
                  setForm((prev) => ({ ...prev, period_start: value }))
                }
              />

              <Field
                label="Period End"
                type="date"
                value={form.period_end}
                onChange={(value) =>
                  setForm((prev) => ({ ...prev, period_end: value }))
                }
              />

              <SelectField
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
            Finished-product lookup is filtered to finished goods and
            semi-finished goods only. Planned Qty = Forecast + Safety Stock -
            Current Stock. Labour, salaries and overheads are captured in the
            production workflow, not in the production plan.
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
                    : "border-[#AAB5B5] bg-white text-[#324443]"
                }`}
              >
                {label}
              </button>
            ))}
          </div>

          <div className="min-h-0 flex-1 overflow-hidden">
            {activeTab === "items" ? (
              <ItemsTable
                items={items}
                recipes={recipes}
                updateLine={updateLine}
                removeLine={removeLine}
                selectRecipeForLine={selectRecipeForLine}
                recalcLinePlannedQty={recalcLinePlannedQty}
                currency={form.currency}
              />
            ) : (
              <VisualsPanel form={form} items={items} totals={totals} />
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

      <NoRadiusStyles />
    </FullScreenModal>
  );
}

function ItemsTable({
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
      <table className="w-full min-w-[1250px] border-collapse text-left text-[11px]">
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
            <GridTh>Required By</GridTh>
            <GridTh>Action</GridTh>
          </tr>
        </thead>

        <tbody>
          {items.length === 0 ? (
            <tr>
              <td colSpan={12} className="px-3 py-10 text-center text-[#627474]">
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

function VisualsPanel({ form, items, totals }) {
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
        <Panel title="Cost Composition" subtitle="Material/BOM estimate only">
          <ReadinessLine
            label="Material / BOM Cost"
            ok
            value={`${form.currency} ${moneyNumber(totals.materialCost)}`}
          />
          <ReadinessLine
            label="Production Workflow Costs"
            ok
            value="Labour, salary and overhead captured later"
          />
          <ReadinessLine
            label="Planned Material Total"
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
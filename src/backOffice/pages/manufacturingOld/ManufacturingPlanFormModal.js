import React, { useEffect, useMemo, useState } from "react";
import {
  AlertCircle,
  CheckCircle2,
  ClipboardList,
  Factory,
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
  getProductionRecipes,
  saveProductionPlan,
} from "../../../services/ManufacturingService";

import {
  ActionButton,
  Field,
  IconButton,
  LoadingPill,
  MiniInput,
  Notice,
  SelectField,
  StatusBadge,
  getBranchId,
  getErrorMessage,
  getProductCode,
  getProductName,
  normalizeRows,
  numberValue,
  qtyNumber,
  today,
  todayPlusDays,
  useNotice,
} from "./ManufacturingShared";

const MANUFACTURING_FINISHED_PRODUCT_TYPES = [
  "finished_good",
  "semi_finished",
];

const emptyPlanForm = (branchId = "") => ({
  branch_id: branchId,
  name: "",
  planning_basis: "manual",
  period_start: today(),
  period_end: todayPlusDays(7),
});

const moneyNumber = (value) => Number(value || 0).toFixed(2);

const getProductType = (product) =>
  product?.product_type || product?.type || "finished_good";

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

const getRecipeBatchQty = (recipe) =>
  Number(recipe?.batch_output_quantity || recipe?.batch_size || 1);

const getRecipeName = (recipe) =>
  recipe?.name || recipe?.recipe_name || recipe?.code || "Recipe";

const getRecipeForLine = (recipes, recipeId) =>
  recipes.find((recipe) => String(recipe.id) === String(recipeId)) || null;

export default function ManufacturingPlanFormModal({
  open,
  onClose,
  currentUser = null,
}) {
  const branchId = getBranchId(currentUser);

  const [form, setForm] = useState(emptyPlanForm(branchId));
  const [items, setItems] = useState([]);
  const [recipes, setRecipes] = useState([]);
  const [products, setProducts] = useState([]);

  const [lookupOpen, setLookupOpen] = useState(false);
  const [productSearch, setProductSearch] = useState("");

  const [loading, setLoading] = useState(false);
  const [searchingProducts, setSearchingProducts] = useState(false);

  const { notice, showNotice } = useNotice();

  const totals = useMemo(() => {
    const plannedQuantity = items.reduce(
      (sum, line) => sum + numberValue(line.planned_quantity),
      0
    );

    const forecastQuantity = items.reduce(
      (sum, line) => sum + numberValue(line.forecast_quantity),
      0
    );

    const safetyStock = items.reduce(
      (sum, line) => sum + numberValue(line.safety_stock_quantity),
      0
    );

    const currentStock = items.reduce(
      (sum, line) => sum + numberValue(line.current_stock_quantity),
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

    const estimatedMaterialCost = items.reduce(
      (sum, line) => sum + numberValue(line.estimated_material_cost),
      0
    );

    const totalCost = estimatedMaterialCost + labourCost + overheadCost;

    return {
      line_count: items.length,
      planned_quantity: plannedQuantity,
      forecast_quantity: forecastQuantity,
      safety_stock: safetyStock,
      current_stock: currentStock,
      shortage_quantity: Math.max(
        forecastQuantity + safetyStock - currentStock,
        0
      ),
      estimated_material_cost: estimatedMaterialCost,
      labour_cost: labourCost,
      overhead_cost: overheadCost,
      total_cost: totalCost,
      average_cost_per_unit:
        plannedQuantity > 0 ? totalCost / plannedQuantity : 0,
    };
  }, [items]);

  useEffect(() => {
    if (!open) return;

    setForm(emptyPlanForm(branchId));
    setItems([]);
    setProductSearch("");

    loadRecipes();
    searchProducts("");
  }, [open, branchId]);

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

  const searchProducts = async (q = "") => {
    try {
      setSearchingProducts(true);

      const results = await Promise.all(
        MANUFACTURING_FINISHED_PRODUCT_TYPES.map((type) =>
          getProducts({
            q,
            search: q,
            product_type: type,
            per_page: 100,
            limit: 100,
            is_active: true,
          })
        )
      );

      const merged = results.flatMap((res) => normalizeRows(res));

      const unique = Array.from(
        new Map(merged.map((product) => [product.id, product])).values()
      );

      setProducts(unique);
    } catch (error) {
      showNotice(getErrorMessage(error, "Failed to search products."));
    } finally {
      setSearchingProducts(false);
    }
  };

  const selectProduct = (product) => {
    const productType = getProductType(product);

    if (!MANUFACTURING_FINISHED_PRODUCT_TYPES.includes(productType)) {
      showNotice(
        "Only finished goods and semi-finished goods can be used in a production plan."
      );
      return;
    }

    setItems((prev) => [
      ...prev,
      {
        line_id: `${product.id}-${Date.now()}`,
        finished_product_id: product.id,
        finished_product_name: getProductName(product),
        finished_product_code: getProductCode(product),
        finished_product_type: productType,
        production_recipe_id: "",
        recipe_batch_quantity: 1,
        forecast_quantity: 0,
        current_stock_quantity: getProductStock(product),
        safety_stock_quantity: 0,
        planned_quantity: 1,
        estimated_batches: 1,
        estimated_material_cost: 0,
        planned_labour_cost: 0,
        planned_overhead_cost: 0,
        estimated_unit_cost: 0,
        required_by: todayPlusDays(1),
      },
    ]);

    setLookupOpen(false);
  };

  const updateLine = (lineId, patch) => {
    setItems((prev) =>
      prev.map((line) => {
        if (line.line_id !== lineId) return line;

        const next = {
          ...line,
          ...patch,
        };

        const recipe = getRecipeForLine(recipes, next.production_recipe_id);
        const batchQty = getRecipeBatchQty(recipe);

        const forecast = numberValue(next.forecast_quantity);
        const safety = numberValue(next.safety_stock_quantity);
        const currentStock = numberValue(next.current_stock_quantity);

        const requiredProduction =
          numberValue(next.planned_quantity) > 0
            ? numberValue(next.planned_quantity)
            : Math.max(forecast + safety - currentStock, 0);

        const batches =
          batchQty > 0 ? Math.max(Math.ceil(requiredProduction / batchQty), 1) : 1;

        const materialCost = estimateRecipeMaterialCost(recipe, batches);

        const labour = numberValue(next.planned_labour_cost);
        const overhead = numberValue(next.planned_overhead_cost);
        const totalCost = materialCost + labour + overhead;

        return {
          ...next,
          recipe_batch_quantity: batchQty,
          planned_quantity: requiredProduction || 1,
          estimated_batches: batches,
          estimated_material_cost: materialCost,
          estimated_unit_cost:
            requiredProduction > 0 ? totalCost / requiredProduction : 0,
        };
      })
    );
  };

  const removeLine = (lineId) => {
    setItems((prev) => prev.filter((line) => line.line_id !== lineId));
  };

  const autoCalculateLine = (lineId) => {
    const line = items.find((item) => item.line_id === lineId);
    if (!line) return;

    const forecast = numberValue(line.forecast_quantity);
    const safety = numberValue(line.safety_stock_quantity);
    const currentStock = numberValue(line.current_stock_quantity);

    const requiredProduction = Math.max(forecast + safety - currentStock, 0);

    updateLine(lineId, {
      planned_quantity: requiredProduction > 0 ? requiredProduction : 1,
    });
  };

  const estimateRecipeMaterialCost = (recipe, batches) => {
    if (!recipe || !Array.isArray(recipe.items)) return 0;

    return recipe.items.reduce((sum, item) => {
      const qty = numberValue(item.quantity_required);
      const wastePercent = numberValue(item.wastage_percent);
      const qtyWithWaste = qty + qty * (wastePercent / 100);

      const product = item.product || {};
      const cost =
        numberValue(item.metadata?.unit_cost) ||
        getProductCost(product) ||
        numberValue(product.average_cost) ||
        numberValue(product.last_cost);

      return sum + qtyWithWaste * batches * cost;
    }, 0);
  };

  const validatePlan = () => {
    if (!form.name.trim()) {
      showNotice("Plan name is required.");
      return false;
    }

    if (!form.branch_id && !branchId) {
      showNotice("Branch is required.");
      return false;
    }

    if (!items.length) {
      showNotice("Add at least one finished or semi-finished product.");
      return false;
    }

    const missingRecipe = items.find((line) => !line.production_recipe_id);
    if (missingRecipe) {
      showNotice(`Select a recipe for ${missingRecipe.finished_product_name}.`);
      return false;
    }

    const invalidQty = items.find((line) => numberValue(line.planned_quantity) <= 0);
    if (invalidQty) {
      showNotice(
        `Planned quantity must be greater than zero for ${invalidQty.finished_product_name}.`
      );
      return false;
    }

    return true;
  };

  const savePlan = async () => {
    if (!validatePlan()) return;

    const payload = {
      branch_id: form.branch_id || branchId,
      name: form.name.trim(),
      planning_basis: form.planning_basis,
      period_start: form.period_start,
      period_end: form.period_end,
      metadata: {
        frontend_summary: {
          planned_quantity: totals.planned_quantity,
          forecast_quantity: totals.forecast_quantity,
          safety_stock: totals.safety_stock,
          current_stock: totals.current_stock,
          shortage_quantity: totals.shortage_quantity,
          estimated_material_cost: totals.estimated_material_cost,
          labour_cost: totals.labour_cost,
          overhead_cost: totals.overhead_cost,
          total_cost: totals.total_cost,
          average_cost_per_unit: totals.average_cost_per_unit,
        },
      },
      items: items.map((line) => ({
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
          product_type: line.finished_product_type,
          current_stock_quantity: numberValue(line.current_stock_quantity),
          estimated_batches: numberValue(line.estimated_batches),
          recipe_batch_quantity: numberValue(line.recipe_batch_quantity),
          estimated_material_cost: numberValue(line.estimated_material_cost),
          estimated_unit_cost: numberValue(line.estimated_unit_cost),
        },
      })),
    };

    try {
      setLoading(true);

      const res = await saveProductionPlan(payload);

      showNotice(res?.message || "Production plan saved successfully.");

      setForm(emptyPlanForm(branchId));
      setItems([]);
    } catch (error) {
      showNotice(getErrorMessage(error, "Failed to save production plan."));
    } finally {
      setLoading(false);
    }
  };

  if (!open) return null;

  return (
    <FullScreenModal
      open={open}
      onClose={onClose}
      title="CREATE PRODUCTION PLAN"
      subtitle="Enterprise manufacturing planning overview"
    >
      <Notice notice={notice} />
      <LoadingPill loading={loading} />

      <div className="h-[calc(100vh-86px)] min-h-0 overflow-hidden bg-[#D8DFDF] text-[#1C2C2B]">
        <div className="grid h-full min-h-0 grid-rows-[auto_auto_minmax(0,1fr)] gap-2 p-2">
          <div className="grid shrink-0 grid-cols-1 gap-2 xl:grid-cols-[300px_minmax(0,1fr)_300px]">
            <PlanDeskPanel title="Plan Card">
              <div className="grid grid-cols-[100px_1fr] items-center gap-1 text-[11px]">
                <PlanDeskLabel>Lines</PlanDeskLabel>
                <PlanDeskField value={totals.line_count} disabled />

                <PlanDeskLabel>Planned Qty</PlanDeskLabel>
                <PlanDeskField value={qtyNumber(totals.planned_quantity)} disabled />

                <PlanDeskLabel>Shortage</PlanDeskLabel>
                <PlanDeskField value={qtyNumber(totals.shortage_quantity)} disabled />

                <PlanDeskLabel>Total Cost</PlanDeskLabel>
                <PlanDeskField value={moneyNumber(totals.total_cost)} disabled />
              </div>
            </PlanDeskPanel>

            <PlanDeskPanel title="Plan Header">
              <div className="grid grid-cols-1 gap-2 md:grid-cols-4">
                <Field
                  label="Plan Name"
                  value={form.name}
                  onChange={(v) => setForm((p) => ({ ...p, name: v }))}
                />

                <SelectField
                  label="Planning Basis"
                  value={form.planning_basis}
                  onChange={(v) =>
                    setForm((p) => ({ ...p, planning_basis: v }))
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
                  onChange={(v) => setForm((p) => ({ ...p, period_start: v }))}
                />

                <Field
                  label="Period End"
                  type="date"
                  value={form.period_end}
                  onChange={(v) => setForm((p) => ({ ...p, period_end: v }))}
                />
              </div>
            </PlanDeskPanel>

            <PlanDeskPanel title="Cost Summary">
              <div className="grid grid-cols-[130px_1fr] items-center gap-1 text-[11px]">
                <PlanDeskLabel>Material</PlanDeskLabel>
                <PlanDeskField
                  value={moneyNumber(totals.estimated_material_cost)}
                  disabled
                />

                <PlanDeskLabel>Labour</PlanDeskLabel>
                <PlanDeskField value={moneyNumber(totals.labour_cost)} disabled />

                <PlanDeskLabel>Overhead</PlanDeskLabel>
                <PlanDeskField
                  value={moneyNumber(totals.overhead_cost)}
                  disabled
                />

                <PlanDeskLabel>Avg Cost / Unit</PlanDeskLabel>
                <PlanDeskField
                  value={moneyNumber(totals.average_cost_per_unit)}
                  disabled
                />
              </div>
            </PlanDeskPanel>
          </div>

          <div className="flex shrink-0 flex-wrap items-center justify-between gap-2 border border-[#B9C3C3] bg-[#EEF2F2] px-2 py-1">
            <div className="flex flex-wrap items-center gap-1">
              <PlanDeskCompactTool onClick={() => setLookupOpen(true)} green>
                Add Finished Product
              </PlanDeskCompactTool>

              <PlanDeskCompactTool onClick={loadRecipes}>
                Refresh Recipes
              </PlanDeskCompactTool>

              <PlanDeskCompactTool onClick={() => searchProducts(productSearch)}>
                Refresh Products
              </PlanDeskCompactTool>

              <PlanDeskCompactTool
                onClick={() => {
                  setItems([]);
                  setForm(emptyPlanForm(branchId));
                }}
              >
                Clear
              </PlanDeskCompactTool>
            </div>

            <div className="flex flex-wrap items-center gap-1">
              <div className="hidden text-[11px] font-semibold text-[#465757] md:block">
                Only finished goods and semi-finished goods are allowed.
              </div>

              <PlanDeskCompactTool onClick={savePlan} green disabled={loading}>
                {loading ? "Saving..." : "Save Plan"}
              </PlanDeskCompactTool>
            </div>
          </div>

          <div className="min-h-0 border border-[#AFB9B9] bg-white">
            <div className="flex h-full min-h-0 flex-col">
              <PlanDeskGridHeader columns="grid-cols-[42px_minmax(190px,1.4fr)_180px_82px_82px_92px_82px_72px_86px_88px_88px_90px_76px]">
                <PlanDeskHead>Line</PlanDeskHead>
                <PlanDeskHead>Product</PlanDeskHead>
                <PlanDeskHead>Recipe</PlanDeskHead>
                <PlanDeskHead>Forecast</PlanDeskHead>
                <PlanDeskHead>Stock</PlanDeskHead>
                <PlanDeskHead>Safety</PlanDeskHead>
                <PlanDeskHead>Planned</PlanDeskHead>
                <PlanDeskHead>Batches</PlanDeskHead>
                <PlanDeskHead>Material</PlanDeskHead>
                <PlanDeskHead>Labour</PlanDeskHead>
                <PlanDeskHead>Overhead</PlanDeskHead>
                <PlanDeskHead>Required</PlanDeskHead>
                <PlanDeskHead>Action</PlanDeskHead>
              </PlanDeskGridHeader>

              <div className="min-h-0 flex-1 max-w-full overflow-auto">
                {items.length === 0 ? (
                  <PlanDeskEmpty
                    title="No planned products added."
                    message="Click Add Finished Product to start building the production plan."
                  />
                ) : (
                  items.map((line, index) => {
                    const selectedRecipe = getRecipeForLine(
                      recipes,
                      line.production_recipe_id
                    );

                    return (
                      <div
                        key={line.line_id}
                        className="grid min-w-[1180px] grid-cols-[42px_minmax(190px,1.4fr)_180px_82px_82px_92px_82px_72px_86px_88px_88px_90px_76px] border-b border-[#D8DFDF] bg-white text-[10.5px] hover:bg-[#F8FAFC]"
                      >
                        <PlanDeskCell muted center>
                          {index + 1}
                        </PlanDeskCell>

                        <PlanDeskCell>
                          <div className="truncate font-semibold text-[#0C1B1B]">
                            {line.finished_product_name}
                          </div>
                          <div className="mt-[2px] truncate text-[#627474]">
                            {line.finished_product_code || "-"} ·{" "}
                            {line.finished_product_type || "finished_good"}
                          </div>
                        </PlanDeskCell>

                        <PlanDeskCell>
                          <select
                            value={line.production_recipe_id}
                            onChange={(e) =>
                              updateLine(line.line_id, {
                                production_recipe_id: e.target.value,
                              })
                            }
                            className="h-7 w-full min-w-0 border border-[#B9C3C3] bg-white px-1.5 text-[10.5px] outline-none"
                          >
                            <option value="">Select Recipe</option>

                            {recipes
                              .filter((recipe) => {
                                if (!recipe.finished_product_id) return true;
                                return (
                                  String(recipe.finished_product_id) ===
                                  String(line.finished_product_id)
                                );
                              })
                              .map((recipe) => (
                                <option key={recipe.id} value={recipe.id}>
                                  {getRecipeName(recipe)}
                                </option>
                              ))}
                          </select>

                          {selectedRecipe && (
                            <div className="mt-1 truncate text-[9px] text-[#627474]">
                              Batch: {qtyNumber(getRecipeBatchQty(selectedRecipe))}
                            </div>
                          )}
                        </PlanDeskCell>

                        <PlanDeskCell>
                          <MiniInput
                            type="number"
                            value={line.forecast_quantity}
                            onChange={(v) =>
                              updateLine(line.line_id, {
                                forecast_quantity: Number(v || 0),
                              })
                            }
                          />
                        </PlanDeskCell>

                        <PlanDeskCell>
                          <MiniInput
                            type="number"
                            value={line.current_stock_quantity}
                            onChange={(v) =>
                              updateLine(line.line_id, {
                                current_stock_quantity: Number(v || 0),
                              })
                            }
                          />
                        </PlanDeskCell>

                        <PlanDeskCell>
                          <MiniInput
                            type="number"
                            value={line.safety_stock_quantity}
                            onChange={(v) =>
                              updateLine(line.line_id, {
                                safety_stock_quantity: Number(v || 0),
                              })
                            }
                          />
                        </PlanDeskCell>

                        <PlanDeskCell>
                          <MiniInput
                            type="number"
                            value={line.planned_quantity}
                            onChange={(v) =>
                              updateLine(line.line_id, {
                                planned_quantity: Number(v || 0),
                              })
                            }
                          />
                          <button
                            type="button"
                            onClick={() => autoCalculateLine(line.line_id)}
                            className="mt-1 h-5 w-full border border-[#B9C3C3] bg-[#F8FAFC] text-[9px] font-semibold text-[#324443]"
                          >
                            Auto
                          </button>
                        </PlanDeskCell>

                        <PlanDeskCell right>
                          <div className="font-semibold text-[#004343]">
                            {line.estimated_batches}
                          </div>
                        </PlanDeskCell>

                        <PlanDeskCell right>
                          <div className="font-semibold text-[#004343]">
                            {moneyNumber(line.estimated_material_cost)}
                          </div>
                        </PlanDeskCell>

                        <PlanDeskCell>
                          <MiniInput
                            type="number"
                            value={line.planned_labour_cost}
                            onChange={(v) =>
                              updateLine(line.line_id, {
                                planned_labour_cost: Number(v || 0),
                              })
                            }
                          />
                        </PlanDeskCell>

                        <PlanDeskCell>
                          <MiniInput
                            type="number"
                            value={line.planned_overhead_cost}
                            onChange={(v) =>
                              updateLine(line.line_id, {
                                planned_overhead_cost: Number(v || 0),
                              })
                            }
                          />
                        </PlanDeskCell>

                        <PlanDeskCell>
                          <MiniInput
                            type="date"
                            value={line.required_by}
                            onChange={(v) =>
                              updateLine(line.line_id, {
                                required_by: v,
                              })
                            }
                          />
                        </PlanDeskCell>

                        <PlanDeskCell center>
                          <IconButton
                            onClick={() => removeLine(line.line_id)}
                            icon={Trash2}
                            danger
                          />
                        </PlanDeskCell>
                      </div>
                    );
                  })
                )}
              </div>

              <div className="grid shrink-0 grid-cols-2 gap-0 border-t border-[#AFB9B9] bg-[#EEF2F2] text-[11px] md:grid-cols-6">
                <FooterTotal label="Lines" value={totals.line_count} />
                <FooterTotal
                  label="Planned Qty"
                  value={qtyNumber(totals.planned_quantity)}
                />
                <FooterTotal
                  label="Material"
                  value={moneyNumber(totals.estimated_material_cost)}
                />
                <FooterTotal label="Labour" value={moneyNumber(totals.labour_cost)} />
                <FooterTotal
                  label="Overhead"
                  value={moneyNumber(totals.overhead_cost)}
                />
                <FooterTotal label="Total" value={moneyNumber(totals.total_cost)} strong />
              </div>
            </div>
          </div>
        </div>
      </div>

      <ProductLookupModal
        open={lookupOpen}
        search={productSearch}
        setSearch={setProductSearch}
        products={products}
        searching={searchingProducts}
        searchProducts={searchProducts}
        onSelect={selectProduct}
        onClose={() => setLookupOpen(false)}
      />
    </FullScreenModal>
  );
}

function ProductLookupModal({
  open,
  search,
  setSearch,
  products,
  searching,
  searchProducts,
  onSelect,
  onClose,
}) {
  if (!open) return null;

  return (
    <div className="fixed inset-0 z-[100000] flex items-center justify-center bg-black/50 p-4">
      <div className="flex h-[76vh] w-full max-w-[1050px] flex-col border border-[#AFB9B9] bg-white shadow-2xl">
        <div className="flex h-11 shrink-0 items-center justify-between border-b border-[#AFB9B9] bg-[#EEF2F2] px-3">
          <div>
            <div className="text-[13px] font-bold text-[#0C1B1B]">
              Add Finished Product
            </div>
            <div className="text-[10px] text-[#627474]">
              Showing finished_good and semi_finished products only
            </div>
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
                placeholder="Search finished goods or semi-finished goods..."
                className="h-9 w-full border border-[#B9C3C3] bg-white pl-9 pr-3 text-[12px] outline-none focus:border-[#004343]"
              />
            </div>

            <button
              type="button"
              onClick={() => searchProducts(search)}
              className="inline-flex h-9 items-center gap-1 border border-[#004343] bg-[#004344] px-4 text-[11px] font-bold text-white"
            >
              <Search className="h-3.5 w-3.5" />
              Search
            </button>
          </div>
        </div>

        <div className="min-h-0 flex-1 overflow-auto">
          {searching ? (
            <div className="flex h-[220px] items-center justify-center text-[12px] text-[#627474]">
              Searching products...
            </div>
          ) : products.length === 0 ? (
            <div className="flex h-[220px] flex-col items-center justify-center text-[12px] text-[#627474]">
              <PackageSearch className="mb-2 h-7 w-7" />
              No manufacturing finished products found.
            </div>
          ) : (
            <table className="w-full min-w-[850px] border-collapse text-[12px]">
              <thead>
                <tr className="border-b border-[#D8DFDF] bg-[#F4F6F8] text-left text-[10px] uppercase text-[#627474]">
                  <th className="px-2 py-2 font-bold">Code</th>
                  <th className="px-2 py-2 font-bold">Product</th>
                  <th className="px-2 py-2 font-bold">Type</th>
                  <th className="px-2 py-2 font-bold">Category</th>
                  <th className="px-2 py-2 text-right font-bold">Stock</th>
                  <th className="px-2 py-2 text-right font-bold">Cost</th>
                  <th className="px-2 py-2 text-right font-bold">Action</th>
                </tr>
              </thead>

              <tbody>
                {products.map((product) => (
                  <tr key={product.id} className="border-b border-[#E9EDED]">
                    <td className="px-2 py-2 text-[#324443]">
                      {getProductCode(product) || "-"}
                    </td>
                    <td className="px-2 py-2 font-semibold text-[#0C1B1B]">
                      {getProductName(product)}
                    </td>
                    <td className="px-2 py-2 text-[#324443]">
                      <StatusBadge status={getProductType(product)} />
                    </td>
                    <td className="px-2 py-2 text-[#324443]">
                      {product.category?.name || "Uncategorised"}
                    </td>
                    <td className="px-2 py-2 text-right text-[#324443]">
                      {qtyNumber(getProductStock(product))}
                    </td>
                    <td className="px-2 py-2 text-right text-[#324443]">
                      {moneyNumber(getProductCost(product))}
                    </td>
                    <td className="px-2 py-2 text-right">
                      <button
                        type="button"
                        onClick={() => onSelect(product)}
                        className="h-7 border border-[#004343] bg-[#004344] px-3 text-[10px] font-bold text-white"
                      >
                        Select
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </div>
    </div>
  );
}

function PlanDeskPanel({ title, children }) {
  return (
    <div className="border border-[#AFB9B9] bg-white">
      <div className="border-b border-[#D8DFDF] bg-[#EEF2F2] px-2 py-1 text-[11px] font-semibold text-[#324443]">
        {title}
      </div>
      <div className="p-2">{children}</div>
    </div>
  );
}

function PlanDeskLabel({ children }) {
  return <div className="text-[11px] text-[#465757]">{children}</div>;
}

function PlanDeskField({ value, disabled = false }) {
  return (
    <input
      value={value ?? ""}
      disabled={disabled}
      readOnly
      className="h-7 border border-[#B9C3C3] bg-white px-2 text-[11px] outline-none disabled:bg-[#EEF2F2]"
    />
  );
}

function PlanDeskCompactTool({
  children,
  onClick,
  disabled = false,
  green = false,
  danger = false,
}) {
  let cls = "border-[#B9C3C3] bg-white text-[#324443]";

  if (green) cls = "border-[#027A48] bg-[#027A48] text-white";
  if (danger) cls = "border-[#FECDCA] bg-white text-[#B42318]";

  return (
    <button
      type="button"
      disabled={disabled}
      onClick={onClick}
      className={`h-7 border px-3 text-[11px] font-semibold disabled:opacity-50 ${cls}`}
    >
      {children}
    </button>
  );
}

function PlanDeskGridHeader({ columns, children }) {
  return (
    <div
      className={`grid min-w-[1180px] shrink-0 ${columns} border-b border-[#AFB9B9] bg-[#EEF2F2] text-[10px] font-semibold uppercase text-[#465757]`}
    >
      {children}
    </div>
  );
}

function PlanDeskHead({ children }) {
  return (
    <div className="border-r border-[#D8DFDF] px-1.5 py-2 text-[10px]">
      {children}
    </div>
  );
}

function PlanDeskCell({
  children,
  right = false,
  center = false,
  muted = false,
}) {
  return (
    <div
      className={`min-h-[38px] min-w-0 overflow-hidden border-r border-[#E4EAEA] px-1.5 py-1.5 ${
        right ? "text-right" : center ? "text-center" : ""
      } ${muted ? "text-[#627474]" : "text-[#1C2C2B]"}`}
    >
      {children}
    </div>
  );
}

function PlanDeskEmpty({ title, message }) {
  return (
    <div className="flex h-[260px] flex-col items-center justify-center text-center text-[12px] text-[#627474]">
      <AlertCircle className="mb-2 h-8 w-8" />
      <div className="font-semibold text-[#324443]">{title}</div>
      <div className="mt-1">{message}</div>
    </div>
  );
}

function FooterTotal({ label, value, strong = false }) {
  return (
    <div className="border-r border-[#D8DFDF] px-2 py-2">
      <div className="text-[9px] font-semibold uppercase text-[#627474]">
        {label}
      </div>
      <div
        className={`mt-1 text-[12px] ${
          strong ? "font-black text-[#004343]" : "font-semibold text-[#324443]"
        }`}
      >
        {value}
      </div>
    </div>
  );
}
import React, { useEffect, useMemo, useState } from "react";
import {
  CheckCircle2,
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
import { getBaseCurrencies } from "../../../services/CurrencyService";
import {
  getProductionRecipe,
  saveProductionRecipe,
} from "../../../services/ManufacturingService";
import ManufacturingProductLookupModal from "./ManufacturingProductLookupModal";

import {
  ActionButton,
  IconButton,
  LoadingPill,
  MiniInput,
  Notice,
  SmallButton,
  getBranchId,
  getErrorMessage,
  getProductCode,
  getProductName,
  getProductStock,
  getProductUnit,
  moneyNumber,
  normalizeRows,
  numberValue,
  qtyNumber,
  useNotice,
} from "./ManufacturingShared";

const MANUFACTURING_MATERIAL_TYPES = [
  "raw_material",
  "semi_finished",
  "packaging",
  "consumable",
];

const FINISHED_PRODUCT_TYPES = ["finished_good", "semi_finished"];

const emptyRecipeForm = (branchId = "", currency = "") => ({
  id: null,
  branch_id: branchId,
  finished_product_id: "",
  finished_product_name: "",
  finished_product_code: "",
  name: "",
  code: "",
  costing_basis: "per_unit",
  batch_output_quantity: 1,
  standard_wastage_percent: 0,
  currency,
  is_active: true,
});

const safeNumber = (value) => Number(value || 0);

const getPayloadData = (response) =>
  response?.data?.data || response?.data || response || null;

const formatProductType = (type) =>
  String(type || "-").replaceAll("_", " ").toUpperCase();

const getReadonlyProductAverageCost = (product, fallback = 0) => {
  return numberValue(
    product?.costs?.[0]?.average_cost ||
      product?.cost?.average_cost ||
      product?.average_cost ||
      fallback
  );
};

const getReadonlyProductCurrency = (product, fallback = "") => {
  return (
    product?.costs?.[0]?.currency ||
    product?.cost?.currency ||
    product?.currency ||
    fallback ||
    ""
  );
};

const formatMoneyWithCurrency = (currency, value) => {
  const code = currency || "";
  return `${code} ${moneyNumber(value)}`.trim();
};

export default function ManufacturingRecipeFormModal({
  open,
  onClose,
  currentUser = null,
  recipeId = null,
  onSaved,
}) {
  const branchId = getBranchId(currentUser);

  const [form, setForm] = useState(emptyRecipeForm(branchId));
  const [materials, setMaterials] = useState([]);
  const [products, setProducts] = useState([]);
  const [baseCurrencies, setBaseCurrencies] = useState([]);

  const [lookupOpen, setLookupOpen] = useState(false);
  const [lookupMode, setLookupMode] = useState("finished");
  const [productSearch, setProductSearch] = useState("");

  const [loading, setLoading] = useState(false);
  const [activeTab, setActiveTab] = useState("materials");

  const { notice, showNotice } = useNotice();

  const defaultCurrency = useMemo(() => {
    return (
      form.currency ||
      baseCurrencies?.[0]?.code ||
      baseCurrencies?.[0]?.currency ||
      "USD"
    );
  }, [form.currency, baseCurrencies]);

  const totals = useMemo(() => {
    const batchOutput = Math.max(safeNumber(form.batch_output_quantity), 1);

    const materialCost = materials.reduce((sum, line) => {
      const qty = safeNumber(line.quantity_required);
      const wastageQty = qty * (safeNumber(line.wastage_percent) / 100);
      const totalQty = qty + wastageQty;
      const unitCost = safeNumber(line.unit_cost);

      return sum + totalQty * unitCost;
    }, 0);

    const costPerUnit =
      form.costing_basis === "per_batch"
        ? materialCost / batchOutput
        : materialCost;

    const totalMaterialQty = materials.reduce((sum, line) => {
      const qty = safeNumber(line.quantity_required);
      const wastageQty = qty * (safeNumber(line.wastage_percent) / 100);
      return sum + qty + wastageQty;
    }, 0);

    return {
      materialCost,
      costPerUnit,
      totalMaterialQty,
      batchOutput,
    };
  }, [materials, form.batch_output_quantity, form.costing_basis]);

  useEffect(() => {
    if (!open) return;

    setForm(emptyRecipeForm(branchId));
    setMaterials([]);
    setProducts([]);
    setActiveTab("materials");
    setProductSearch("");

    loadBaseCurrencies();

    if (recipeId) {
      loadRecipe(recipeId);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, recipeId]);

  useEffect(() => {
    if (!open) return;

    if (!form.currency && baseCurrencies.length > 0) {
      setForm((prev) => ({
        ...prev,
        currency: baseCurrencies?.[0]?.code || "USD",
      }));
    }
  }, [open, baseCurrencies, form.currency]);

  const loadBaseCurrencies = async () => {
    try {
      const res = await getBaseCurrencies();
      const rows = normalizeRows(res);
      setBaseCurrencies(rows);
    } catch (error) {
      showNotice(getErrorMessage(error, "Failed to load base currencies."));
    }
  };

  const loadRecipe = async (id) => {
    try {
      setLoading(true);

      const res = await getProductionRecipe(id);
      const recipe = getPayloadData(res);

      const recipeCurrency =
        recipe?.currency ||
        recipe?.metadata?.currency ||
        baseCurrencies?.[0]?.code ||
        "USD";

      const finishedProduct =
        recipe?.finished_product ||
        recipe?.finishedProduct ||
        recipe?.product ||
        null;

      setForm({
        id: recipe?.id || null,
        branch_id: recipe?.branch_id || branchId,
        finished_product_id:
          recipe?.finished_product_id || finishedProduct?.id || "",
        finished_product_name:
          getProductName(finishedProduct) ||
          recipe?.metadata?.finished_product_name ||
          "",
        finished_product_code:
          getProductCode(finishedProduct) ||
          recipe?.metadata?.finished_product_code ||
          "",
        name: recipe?.name || "",
        code: recipe?.code || "",
        costing_basis: recipe?.costing_basis || "per_unit",
        batch_output_quantity: recipe?.batch_output_quantity || 1,
        standard_wastage_percent: recipe?.standard_wastage_percent || 0,
        currency: recipeCurrency,
        is_active: recipe?.is_active ?? true,
      });

      const materialRows = Array.isArray(recipe?.items) ? recipe.items : [];

      setMaterials(
        materialRows.map((item) => {
          const product = item.product || item.material || null;

          const lineCurrency =
            item.currency ||
            item.metadata?.currency ||
            getReadonlyProductCurrency(product, recipeCurrency);

          const lineAverageCost = getReadonlyProductAverageCost(
            product,
            item.unit_cost || item.metadata?.unit_cost || 0
          );

          return {
            line_id: item.id || `${item.product_id}-${Date.now()}`,
            id: item.id,
            product_id: item.product_id || product?.id,
            product_name:
              getProductName(product) ||
              item.metadata?.product_name ||
              item.product_name ||
              "",
            product_code:
              getProductCode(product) ||
              item.metadata?.product_code ||
              item.product_code ||
              "",
            product_type:
              product?.product_type ||
              item.metadata?.product_type ||
              item.product_type ||
              "",
            uom_id: item.uom_id || product?.base_uom_id || product?.uom_id || null,
            unit:
              item.uom?.code ||
              item.metadata?.unit ||
              getProductUnit(product) ||
              "",
            quantity_required: item.quantity_required || item.quantity || 0,
            wastage_percent: item.wastage_percent || 0,

            unit_cost: lineAverageCost,
            currency: lineCurrency,

            basis: item.basis || item.metadata?.quantity_basis || "batch",
            is_active: item.is_active ?? true,
            available_stock: getProductStock(product),
          };
        })
      );
    } catch (error) {
      showNotice(getErrorMessage(error, "Failed to load recipe."));
    } finally {
      setLoading(false);
    }
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

    const productCurrency = getReadonlyProductCurrency(
      product,
      form.currency || defaultCurrency
    );

    const productAverageCost = getReadonlyProductAverageCost(product, 0);

    setMaterials((prev) => [
      ...prev,
      {
        line_id: `${product.id}-${Date.now()}`,
        product_id: product.id,
        product_name: getProductName(product),
        product_code: getProductCode(product),
        product_type: product.product_type,
        uom_id: product.base_uom_id || product.uom_id || null,
        unit: getProductUnit(product),
        quantity_required: 1,
        wastage_percent: 0,

        unit_cost: productAverageCost,
        currency: productCurrency,

        basis: "batch",
        is_active: true,
        available_stock: getProductStock(product),
      },
    ]);

    setLookupOpen(false);
  };

  const updateMaterial = (lineId, patch) => {
    setMaterials((prev) =>
      prev.map((line) => {
        if (line.line_id !== lineId) return line;

        return {
          ...line,
          ...patch,

          unit_cost: line.unit_cost,
          currency: line.currency,
        };
      })
    );
  };

  const removeMaterial = (lineId) => {
    setMaterials((prev) => prev.filter((line) => line.line_id !== lineId));
  };

  const validateForm = () => {
    if (!form.branch_id && !branchId) {
      showNotice("Branch is required.");
      return false;
    }

    if (!form.finished_product_id) {
      showNotice("Finished product is required.");
      return false;
    }

    if (!form.name.trim()) {
      showNotice("Recipe name is required.");
      return false;
    }

    if (!safeNumber(form.batch_output_quantity)) {
      showNotice("Batch output quantity must be greater than zero.");
      return false;
    }

    if (!materials.length) {
      showNotice("Add at least one material.");
      return false;
    }

    const invalidMaterial = materials.find(
      (line) => !line.product_id || safeNumber(line.quantity_required) <= 0
    );

    if (invalidMaterial) {
      showNotice("Every material must have a product and quantity greater than zero.");
      return false;
    }

    return true;
  };

  const saveRecipe = async () => {
    if (!validateForm()) return;

    const payload = {
      id: form.id || undefined,
      branch_id: form.branch_id || branchId,
      finished_product_id: form.finished_product_id,
      name: form.name.trim(),
      code: form.code || null,
      costing_basis: form.costing_basis,
      batch_output_quantity: safeNumber(form.batch_output_quantity),
      standard_wastage_percent: safeNumber(form.standard_wastage_percent),
      currency: form.currency || defaultCurrency,
      is_active: !!form.is_active,
      metadata: {
        bom_management_version: "enterprise_cost_readonly_v2",
        finished_product_name: form.finished_product_name,
        finished_product_code: form.finished_product_code,
        material_cost: totals.materialCost,
        cost_per_unit: totals.costPerUnit,
        currency: form.currency || defaultCurrency,
        basis_explanation:
          form.costing_basis === "per_batch"
            ? "User entered material quantities for the full batch output."
            : "User entered material quantities per one finished unit.",
      },
      items: materials.map((line) => {
        const qty = safeNumber(line.quantity_required);
        const wasteQty = qty * (safeNumber(line.wastage_percent) / 100);
        const totalQty = qty + wasteQty;
        const unitCost = safeNumber(line.unit_cost);
        const currency = line.currency || defaultCurrency;

        return {
          id: line.id || undefined,
          product_id: line.product_id,
          uom_id: line.uom_id || null,
          quantity_required: qty,
          wastage_percent: safeNumber(line.wastage_percent),
          is_active: line.is_active ?? true,
          metadata: {
            product_code: line.product_code,
            product_name: line.product_name,
            product_type: line.product_type,
            unit: line.unit,
            quantity_basis: line.basis || "batch",
            entered_quantity_required: qty,
            backend_quantity_required: qty,
            waste_quantity: wasteQty,
            total_quantity: totalQty,
            unit_cost: unitCost,
            currency,
            available_stock: safeNumber(line.available_stock),
            line_cost: totalQty * unitCost,
            cost_source: "product_average_cost_readonly",
          },
        };
      }),
    };

    try {
      setLoading(true);

      const res = await saveProductionRecipe(payload);

      showNotice(res?.message || "Recipe saved successfully.");

      if (onSaved) onSaved(res);

      if (!form.id) {
        setForm(emptyRecipeForm(branchId, defaultCurrency));
        setMaterials([]);
        setActiveTab("materials");
      }
    } catch (error) {
      showNotice(getErrorMessage(error, "Failed to save recipe."));
    } finally {
      setLoading(false);
    }
  };

  if (!open) return null;

  return (
    <FullScreenModal
      open={open}
      onClose={onClose}
      title={form.id ? "EDIT BOM / RECIPE" : "CREATE BOM / RECIPE"}
      subtitle="Manufacturing bill of materials with read-only product average cost"
    >
      <Notice notice={notice} />
      <LoadingPill loading={loading} />

      <div className="h-[calc(100vh-86px)] min-h-0 overflow-hidden bg-[#EEF1F1] p-2 text-[#1C2C2B]">
        <div className="flex h-full min-h-0 flex-col border border-[#AAB5B5] bg-white">
          <div className="border-b border-[#AAB5B5] bg-[#F5F7FA]">
            <div className="flex h-9 items-center justify-between border-b border-[#CFD9D8] px-2">
              <div className="flex items-center gap-2">
                <div className="text-[13px] font-semibold text-[#0C1B1B]">
                  BOM setup
                </div>
                <div className="h-4 border-l border-[#AAB5B5]" />
                <div className="text-[10px] font-medium uppercase text-[#627474]">
                  Product materials, quantities, wastage and read-only average costing
                </div>
              </div>

              <div className="flex items-center gap-1">
                {form.id && (
                  <ActionButton
                    onClick={() => loadRecipe(form.id)}
                    label="Reload"
                    icon={RefreshCw}
                  />
                )}

                <ActionButton
                  onClick={saveRecipe}
                  label={form.id ? "Update BOM" : "Save BOM"}
                  icon={CheckCircle2}
                  primary
                />
              </div>
            </div>

            <div className="grid grid-cols-4 gap-2 px-2 py-2">
              <RecipeField label="Finished Product">
                <button
                  type="button"
                  onClick={() => openLookup("finished")}
                  className="h-8 w-full border border-[#AAB5B5] bg-white px-2 text-left text-[11px] font-semibold text-[#004343]"
                >
                  <span className="block truncate">
                    {form.finished_product_name || "Select finished product"}
                  </span>
                </button>
              </RecipeField>

              <RecipeField label="Recipe Name">
                <input
                  value={form.name}
                  onChange={(event) =>
                    setForm((prev) => ({ ...prev, name: event.target.value }))
                  }
                  className="h-8 w-full border border-[#AAB5B5] bg-white px-2 text-[11px] outline-none"
                />
              </RecipeField>

              <RecipeField label="Recipe Code">
                <input
                  value={form.code}
                  onChange={(event) =>
                    setForm((prev) => ({ ...prev, code: event.target.value }))
                  }
                  className="h-8 w-full border border-[#AAB5B5] bg-white px-2 text-[11px] outline-none"
                />
              </RecipeField>

              <RecipeField label="Currency">
                <select
                  value={form.currency || defaultCurrency}
                  onChange={(event) =>
                    setForm((prev) => ({
                      ...prev,
                      currency: event.target.value,
                    }))
                  }
                  className="h-8 w-full border border-[#AAB5B5] bg-white px-2 text-[11px] outline-none"
                >
                  {(baseCurrencies.length ? baseCurrencies : [{ code: "USD", name: "US Dollar" }]).map(
                    (currency) => (
                      <option
                        key={currency.id || currency.code}
                        value={currency.code}
                      >
                        {currency.code}
                        {currency.name ? ` - ${currency.name}` : ""}
                      </option>
                    )
                  )}
                </select>
              </RecipeField>

              <RecipeField label="Costing Basis">
                <select
                  value={form.costing_basis}
                  onChange={(event) =>
                    setForm((prev) => ({
                      ...prev,
                      costing_basis: event.target.value,
                    }))
                  }
                  className="h-8 w-full border border-[#AAB5B5] bg-white px-2 text-[11px] outline-none"
                >
                  <option value="per_unit">Per Unit</option>
                  <option value="per_batch">Per Batch</option>
                </select>
              </RecipeField>

              <RecipeField label="Batch Output Qty">
                <input
                  type="number"
                  step="0.0001"
                  value={form.batch_output_quantity}
                  onChange={(event) =>
                    setForm((prev) => ({
                      ...prev,
                      batch_output_quantity: event.target.value,
                    }))
                  }
                  className="h-8 w-full border border-[#AAB5B5] bg-white px-2 text-[11px] outline-none"
                />
              </RecipeField>

              <RecipeField label="Standard Wastage %">
                <input
                  type="number"
                  step="0.0001"
                  value={form.standard_wastage_percent}
                  onChange={(event) =>
                    setForm((prev) => ({
                      ...prev,
                      standard_wastage_percent: event.target.value,
                    }))
                  }
                  className="h-8 w-full border border-[#AAB5B5] bg-white px-2 text-[11px] outline-none"
                />
              </RecipeField>

              <RecipeField label="Quick Add">
                <button
                  type="button"
                  onClick={() => openLookup("material")}
                  className="h-8 w-full border border-[#027A48] bg-[#027A48] px-2 text-[11px] font-semibold uppercase text-white"
                >
                  Add Material
                </button>
              </RecipeField>
            </div>
          </div>

          <div className="grid min-h-[110px] grid-cols-4 gap-2 border-b border-[#AAB5B5] bg-[#F7F8FA] p-2">
            <MetricCard
              title="Material Lines"
              value={materials.length}
              icon={Layers}
            />
            <MetricCard
              title="Total Material Qty"
              value={qtyNumber(totals.totalMaterialQty)}
              icon={PackageSearch}
            />
            <MetricCard
              title="BOM Material Cost"
              value={formatMoneyWithCurrency(defaultCurrency, totals.materialCost)}
              icon={FileText}
            />
            <MetricCard
              title="Cost Per Unit"
              value={formatMoneyWithCurrency(defaultCurrency, totals.costPerUnit)}
              icon={Factory}
            />
          </div>

          <div className="flex h-8 items-end border-b border-[#AAB5B5] bg-[#F5F7FA] px-2">
            {[
              ["materials", "Materials"],
              ["visuals", "Cost Visuals"],
              ["guidance", "Basis Guide"],
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
            {activeTab === "materials" && (
              <MaterialsTable
                materials={materials}
                updateMaterial={updateMaterial}
                removeMaterial={removeMaterial}
                openLookup={() => openLookup("material")}
                defaultCurrency={defaultCurrency}
              />
            )}

            {activeTab === "visuals" && (
              <VisualsPanel
                form={form}
                materials={materials}
                totals={totals}
                defaultCurrency={defaultCurrency}
              />
            )}

            {activeTab === "guidance" && <BasisGuide />}
          </div>
        </div>
      </div>

      <ManufacturingProductLookupModal
        open={lookupOpen}
        title={
          lookupMode === "finished"
            ? "Select Finished Product"
            : "Select BOM Material"
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

function MaterialsTable({
  materials,
  updateMaterial,
  removeMaterial,
  openLookup,
  defaultCurrency,
}) {
  return (
    <div className="h-full overflow-auto">
      <div className="flex items-center justify-between border-b border-[#D8DFDF] bg-[#F8FAFC] px-2 py-1.5">
        <div className="text-[11px] font-semibold text-[#324443]">
          Only raw materials, semi-finished goods, packaging and consumables
          belong here. Unit cost is read-only from product average cost.
        </div>

        <SmallButton onClick={openLookup}>Add Material</SmallButton>
      </div>

      <table className="w-full min-w-[1180px] border-collapse text-left text-[11px]">
        <thead className="sticky top-0 z-10 bg-[#0E4D4D] text-white">
          <tr>
            <GridTh>Line</GridTh>
            <GridTh>Code</GridTh>
            <GridTh>Material</GridTh>
            <GridTh>Type</GridTh>
            <GridTh>Unit</GridTh>
            <GridTh align="right">Qty Required</GridTh>
            <GridTh align="right">Wastage %</GridTh>
            <GridTh align="right">Material Unit Cost</GridTh>
            <GridTh>Basis</GridTh>
            <GridTh align="right">Line Cost</GridTh>
            <GridTh>Action</GridTh>
          </tr>
        </thead>

        <tbody>
          {materials.length === 0 ? (
            <tr>
              <td
                colSpan={11}
                className="px-3 py-10 text-center text-[#627474]"
              >
                No materials added.
              </td>
            </tr>
          ) : (
            materials.map((line, index) => {
              const qty = numberValue(line.quantity_required);
              const wastageQty =
                qty * (numberValue(line.wastage_percent) / 100);
              const totalQty = qty + wastageQty;
              const currency = line.currency || defaultCurrency;
              const unitCost = numberValue(line.unit_cost);
              const lineCost = totalQty * unitCost;

              return (
                <tr
                  key={line.line_id}
                  className="border-b border-[#CFD9D8] bg-white hover:bg-[#F8FAFC]"
                >
                  <GridTd>{index + 1}</GridTd>
                  <GridTd>{line.product_code || "-"}</GridTd>
                  <GridTd strong>{line.product_name || "-"}</GridTd>
                  <GridTd>{formatProductType(line.product_type)}</GridTd>
                  <GridTd>{line.unit || "-"}</GridTd>

                  <GridTd right>
                    <MiniInput
                      type="number"
                      value={line.quantity_required}
                      onChange={(value) =>
                        updateMaterial(line.line_id, {
                          quantity_required: value,
                        })
                      }
                    />
                  </GridTd>

                  <GridTd right>
                    <MiniInput
                      type="number"
                      value={line.wastage_percent}
                      onChange={(value) =>
                        updateMaterial(line.line_id, {
                          wastage_percent: value,
                        })
                      }
                    />
                  </GridTd>

                  <GridTd right>
                    <input
                      value={formatMoneyWithCurrency(currency, unitCost)}
                      disabled
                      readOnly
                      title="Read-only average cost from product costing"
                      className="h-8 w-full cursor-not-allowed border border-[#B9C3C3] bg-[#EEF2F2] px-2 text-right text-[11px] font-semibold text-[#004343]"
                    />
                  </GridTd>

                  <GridTd>
                    <select
                      value={line.basis || "batch"}
                      onChange={(event) =>
                        updateMaterial(line.line_id, {
                          basis: event.target.value,
                        })
                      }
                      className="h-8 w-full border border-[#B9C3C3] bg-white px-2 text-[11px]"
                    >
                      <option value="batch">Per Batch</option>
                      <option value="unit">Per Unit</option>
                    </select>
                  </GridTd>

                  <GridTd right>
                    {formatMoneyWithCurrency(currency, lineCost)}
                  </GridTd>

                  <GridTd>
                    <IconButton
                      onClick={() => removeMaterial(line.line_id)}
                      icon={Trash2}
                      danger
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

function VisualsPanel({ form, materials, totals, defaultCurrency }) {
  return (
    <div className="h-full overflow-auto bg-[#F7F8FA] p-2">
      <div className="grid gap-2 lg:grid-cols-4">
        <MetricCard
          title="Batch Output"
          value={qtyNumber(form.batch_output_quantity)}
          icon={Factory}
        />
        <MetricCard title="Material Lines" value={materials.length} icon={Layers} />
        <MetricCard
          title="Material Cost"
          value={formatMoneyWithCurrency(defaultCurrency, totals.materialCost)}
          icon={FileText}
        />
        <MetricCard
          title="Cost Per Unit"
          value={formatMoneyWithCurrency(defaultCurrency, totals.costPerUnit)}
          icon={CheckCircle2}
        />
      </div>

      <div className="mt-2 grid gap-2 xl:grid-cols-2">
        <Panel title="Cost Composition" subtitle="Live BOM material cost">
          <CostBar
            label="Materials"
            value={totals.materialCost}
            percent={100}
            currency={defaultCurrency}
          />

          <div className="mt-3 grid grid-cols-2 gap-2">
            <SmallMetric
              label="Material Cost"
              value={formatMoneyWithCurrency(defaultCurrency, totals.materialCost)}
            />
            <SmallMetric
              label="Cost Per Unit"
              value={formatMoneyWithCurrency(defaultCurrency, totals.costPerUnit)}
            />
            <SmallMetric
              label="Batch Output"
              value={qtyNumber(form.batch_output_quantity)}
            />
            <SmallMetric
              label="Costing Basis"
              value={String(form.costing_basis || "").replaceAll("_", " ")}
            />
          </div>
        </Panel>

        <Panel title="BOM Readiness" subtitle="Before saving recipe">
          <ReadinessLine
            label="Finished Product"
            ok={!!form.finished_product_id}
            value={form.finished_product_name || "Not selected"}
          />
          <ReadinessLine
            label="Recipe Name"
            ok={!!form.name}
            value={form.name || "Missing"}
          />
          <ReadinessLine
            label="Material Lines"
            ok={materials.length > 0}
            value={`${materials.length} line(s)`}
          />
          <ReadinessLine
            label="Material Unit Cost"
            ok={materials.every((item) => numberValue(item.unit_cost) >= 0)}
            value="Read-only from product average cost"
          />
        </Panel>
      </div>
    </div>
  );
}

function BasisGuide() {
  return (
    <div className="h-full overflow-auto bg-[#F7F8FA] p-2">
      <div className="grid gap-2 xl:grid-cols-2">
        <Panel title="Per Unit BOM" subtitle="Use this when the quantities are for one finished item.">
          <div className="space-y-2 text-[12px] leading-5 text-[#324443]">
            <p>
              Example: if one brick uses 0.055 KG cement, enter 0.055 and keep
              costing basis as Per Unit.
            </p>
            <p>
              If you plan 100 bricks, the production plan multiplies 0.055 ×
              100 = 5.5 KG cement before wastage.
            </p>
          </div>
        </Panel>

        <Panel title="Per Batch BOM" subtitle="Use this when quantities are for a batch.">
          <div className="space-y-2 text-[12px] leading-5 text-[#324443]">
            <p>
              Example: if one batch makes 100 bricks and uses 35 KG cement,
              enter batch output as 100 and cement quantity as 35.
            </p>
            <p>
              The unit cost becomes total batch cost divided by 100 finished
              bricks.
            </p>
          </div>
        </Panel>

        <Panel title="Material Unit Cost" subtitle="Read-only costing rule">
          <div className="space-y-2 text-[12px] leading-5 text-[#324443]">
            <p>
              Material unit cost is not typed in the BOM. It comes from product
              average cost in inventory costing.
            </p>
            <p>
              To change the material cost, update the material product stock
              cost through opening stock, purchase, or stock costing process.
            </p>
          </div>
        </Panel>

        <Panel title="Currency" subtitle="Cost display rule">
          <div className="space-y-2 text-[12px] leading-5 text-[#324443]">
            <p>
              If product cost has a currency, that currency is shown. If the
              product cost currency is missing, the system uses the selected BOM
              currency or base currency.
            </p>
          </div>
        </Panel>
      </div>
    </div>
  );
}

function RecipeField({ label, children }) {
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
      <div className="mt-2 truncate text-[20px] font-semibold text-[#004343]">
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

function CostBar({ label, value, percent, currency }) {
  return (
    <div className="mb-2">
      <div className="mb-1 flex justify-between text-[11px] font-semibold text-[#324443]">
        <span>{label}</span>
        <span>
          {formatMoneyWithCurrency(currency, value)} ({percent.toFixed(1)}%)
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
        className={
          ok ? "font-semibold text-[#067647]" : "font-semibold text-[#B42318]"
        }
      >
        {value}
      </span>
    </div>
  );
}
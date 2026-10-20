// src/backOffice/pages/manufacturing/ManufacturingRecipeRegisterModal.js

import React, { useEffect, useMemo, useState } from "react";
import {
  CheckCircle2,
  Plus,
  RefreshCw,
  Search,
  Trash2,
} from "lucide-react";

import FullScreenModal from "../../../components/FullScreenModal";
import { requestEnterpriseConfirmation } from "../../../components/EnterpriseConfirmHost";
import { getProducts } from "../../../services/ProductService";
import { getBaseCurrencies } from "../../../services/CurrencyService";

import {
  deleteProductionRecipe,
  getProductionRecipes,
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
  StatusBadge,
  getBranchId,
  getErrorMessage,
  getProductCode,
  getProductName,
  getProductStock,
  getProductUnit,
  normalizeRows,
  numberValue,
  qtyNumber,
  useNotice,
  titleCase
} from "./ManufacturingShared";

const MANUFACTURING_MATERIAL_TYPES = [
  "raw_material",
  "semi_finished",
  "packaging",
  "consumable",
];

const FINISHED_PRODUCT_TYPES = ["finished_good", "semi_finished"];

const emptyForm = (branchId = "", currency = "") => ({
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
  is_active: true,
  currency: currency || "USD",
});

const safeNumber = (value) => Number(value || 0);

const getPayloadData = (response) =>
  response?.data?.data || response?.data || response || null;

const getDefaultCurrencyCode = (rows = []) => {
  return rows?.[0]?.code || "USD";
};

const formatMoneyWithCurrency = (currency, value) => {
  return `${currency || "USD"} ${Number(value || 0).toFixed(4)}`;
};

const getReadonlyProductAverageCost = (product, fallback = 0) => {
  return Number(
    product?.costs?.[0]?.average_cost ||
      product?.cost?.average_cost ||
      product?.average_cost ||
      fallback ||
      0
  );
};

const getReadonlyProductCurrency = (product, fallback = "USD") => {
  return (
    product?.costs?.[0]?.currency ||
    product?.cost?.currency ||
    product?.currency ||
    fallback ||
    "USD"
  );
};

const getRecipeMaterialRows = (recipe) => {
  if (!recipe) return [];
  if (Array.isArray(recipe.items)) return recipe.items;
  if (Array.isArray(recipe.recipe_items)) return recipe.recipe_items;
  if (Array.isArray(recipe.materials)) return recipe.materials;
  return [];
};

export default function ManufacturingRecipeRegisterModal({
  open,
  onClose,
  currentUser = null,
}) {
  const branchId = getBranchId(currentUser);

  const [recipes, setRecipes] = useState([]);
  const [products, setProducts] = useState([]);
  const [baseCurrencies, setBaseCurrencies] = useState([]);

  const [form, setForm] = useState(emptyForm(branchId, "USD"));
  const [materials, setMaterials] = useState([]);

  const [lookupOpen, setLookupOpen] = useState(false);
  const [lookupMode, setLookupMode] = useState("material");
  const [productSearch, setProductSearch] = useState("");

  const [search, setSearch] = useState("");
  const [selectedRecipeId, setSelectedRecipeId] = useState(null);
  const [activeTab, setActiveTab] = useState("materials");

  const [loading, setLoading] = useState(false);
  const { notice, showNotice } = useNotice();

  const defaultCurrency = useMemo(
    () => getDefaultCurrencyCode(baseCurrencies),
    [baseCurrencies]
  );

  const totals = useMemo(() => {
    const batchOutput = Math.max(safeNumber(form.batch_output_quantity), 1);

    const materialCost = materials.reduce((sum, line) => {
      const qty = safeNumber(line.quantity_required);
      const wastePercent = safeNumber(line.wastage_percent);
      const totalQty = qty + qty * (wastePercent / 100);
      return sum + totalQty * safeNumber(line.unit_cost);
    }, 0);

    const unitCost = materialCost / batchOutput;

    return {
      materials: materials.length,
      materialCost,
      batchCost: materialCost,
      unitCost,
    };
  }, [materials, form.batch_output_quantity]);

  const filteredRecipes = useMemo(() => {
    const q = search.trim().toLowerCase();

    if (!q) return recipes;

    return recipes.filter((recipe) => {
      const finishedName =
        recipe?.finished_product?.name ||
        recipe?.finishedProduct?.name ||
        recipe?.metadata?.finished_product_name ||
        "";

      return (
        String(recipe.name || "").toLowerCase().includes(q) ||
        String(recipe.code || "").toLowerCase().includes(q) ||
        String(finishedName || "").toLowerCase().includes(q)
      );
    });
  }, [recipes, search]);

  useEffect(() => {
    if (!open) return;

    loadAll();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const loadAll = async () => {
    try {
      setLoading(true);

      const [currencyRes, recipesRes] = await Promise.all([
        getBaseCurrencies(),
        getProductionRecipes({
          branch_id: branchId || undefined,
          per_page: 100,
        }),
      ]);

      const currencyRows = normalizeRows(currencyRes);
      const currency = getDefaultCurrencyCode(currencyRows);

      setBaseCurrencies(currencyRows);
      setRecipes(normalizeRows(recipesRes));

      setForm(emptyForm(branchId, currency));
      setMaterials([]);
      setSelectedRecipeId(null);
    } catch (error) {
      showNotice(getErrorMessage(error, "Failed to load BOM data."));
    } finally {
      setLoading(false);
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

    const unitCost = getReadonlyProductAverageCost(product, 0);
    const currency = getReadonlyProductCurrency(
      product,
      form.currency || defaultCurrency || "USD"
    );

    setMaterials((prev) => [
      ...prev,
      {
        line_id: `${product.id}-${Date.now()}`,
        product_id: product.id,
        product_name: getProductName(product),
        product_code: getProductCode(product),
        product_type: product.product_type,
        uom_id: product?.base_uom_id || product?.uom_id || product?.uom?.id || null,
        unit: getProductUnit(product),
        quantity_required: 1,
        wastage_percent: 0,
        unit_cost: unitCost,
        currency,
        available_stock: getProductStock(product),
        is_active: true,
      },
    ]);

    setLookupOpen(false);
  };

  const selectRecipe = (recipe) => {
    const finishedProduct =
      recipe.finished_product || recipe.finishedProduct || null;

    const materialRows = getRecipeMaterialRows(recipe);

    const currency =
      recipe?.metadata?.currency ||
      recipe?.currency ||
      defaultCurrency ||
      "USD";

    setSelectedRecipeId(recipe.id);

    setForm({
      id: recipe.id,
      branch_id: recipe.branch_id || branchId || "",
      finished_product_id: recipe.finished_product_id || finishedProduct?.id || "",
      finished_product_name:
        finishedProduct?.name ||
        recipe?.metadata?.finished_product_name ||
        "",
      finished_product_code:
        finishedProduct?.code ||
        recipe?.metadata?.finished_product_code ||
        "",
      name: recipe.name || "",
      code: recipe.code || "",
      costing_basis: recipe.costing_basis || "per_unit",
      batch_output_quantity: recipe.batch_output_quantity || 1,
      standard_wastage_percent: recipe.standard_wastage_percent || 0,
      is_active: recipe.is_active ?? true,
      currency,
    });

    setMaterials(
      materialRows.map((item) => {
        const product = item.product || item.material || null;

        const unitCost = getReadonlyProductAverageCost(
          product,
          item.metadata?.unit_cost || item.unit_cost || 0
        );

        const lineCurrency = getReadonlyProductCurrency(
          product,
          item.metadata?.currency || currency || defaultCurrency || "USD"
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
          unit_cost: unitCost,
          currency: lineCurrency,
          available_stock: getProductStock(product),
          is_active: item.is_active ?? true,
        };
      })
    );

    setActiveTab("materials");
  };

  const newBom = () => {
    setSelectedRecipeId(null);
    setForm(emptyForm(branchId, defaultCurrency));
    setMaterials([]);
    setActiveTab("materials");
  };

  const updateMaterial = (lineId, patch) => {
    setMaterials((prev) =>
      prev.map((line) => {
        if (line.line_id !== lineId) return line;

        return {
          ...line,
          ...patch,

          // Costing fields are read-only and must remain from product average cost.
          unit_cost: line.unit_cost,
          currency: line.currency,
        };
      })
    );
  };

  const removeMaterial = (lineId) => {
    setMaterials((prev) => prev.filter((line) => line.line_id !== lineId));
  };

  const saveBom = async () => {
    if (!form.name.trim()) {
      showNotice("BOM name is required.");
      return;
    }

    if (!form.finished_product_id) {
      showNotice("Finished product is required.");
      return;
    }

    if (!safeNumber(form.batch_output_quantity)) {
      showNotice("Batch output quantity must be greater than zero.");
      return;
    }

    if (!materials.length) {
      showNotice("Add at least one BOM material.");
      return;
    }

    const payload = {
      id: form.id || undefined,
      branch_id: form.branch_id || branchId,
      finished_product_id: form.finished_product_id,
      name: form.name.trim(),
      code: form.code || null,
      costing_basis: form.costing_basis,
      batch_output_quantity: safeNumber(form.batch_output_quantity),
      standard_wastage_percent: safeNumber(form.standard_wastage_percent),
      is_active: !!form.is_active,
      metadata: {
        bom_management_version: "enterprise_readonly_average_cost_v2",
        finished_product_name: form.finished_product_name,
        finished_product_code: form.finished_product_code,
        currency: form.currency || defaultCurrency || "USD",
        material_cost: totals.materialCost,
        batch_cost: totals.batchCost,
        cost_per_unit: totals.unitCost,
        basis_explanation:
          form.costing_basis === "per_unit"
            ? "Material quantities are entered for one finished unit."
            : "Material quantities are entered for the full batch output.",
      },
      items: materials.map((line) => {
        const qty = safeNumber(line.quantity_required);
        const wastePercent = safeNumber(line.wastage_percent);
        const totalQty = qty + qty * (wastePercent / 100);

        return {
          id: line.id || undefined,
          product_id: line.product_id,
          uom_id: line.uom_id || null,
          quantity_required: qty,
          wastage_percent: wastePercent,
          is_active: line.is_active ?? true,
          metadata: {
            product_code: line.product_code,
            product_name: line.product_name,
            product_type: line.product_type,
            unit: line.unit,
            unit_cost: safeNumber(line.unit_cost),
            currency: line.currency || form.currency || defaultCurrency || "USD",
            available_stock: safeNumber(line.available_stock),
            entered_quantity_required: qty,
            waste_quantity: qty * (wastePercent / 100),
            total_quantity: totalQty,
            line_cost: totalQty * safeNumber(line.unit_cost),
            quantity_basis: form.costing_basis,
            cost_source: "product_average_cost_readonly",
          },
        };
      }),
    };

    try {
      setLoading(true);

      const res = await saveProductionRecipe(payload);

      showNotice(res?.message || "BOM saved successfully.");
      await loadAll();

      const saved = getPayloadData(res);
      if (saved?.id) {
        selectRecipe(saved);
      }
    } catch (error) {
      showNotice(getErrorMessage(error, "Failed to save BOM."));
    } finally {
      setLoading(false);
    }
  };

  const deleteBom = async () => {
    if (!form.id) {
      showNotice("Select a BOM to delete.");
      return;
    }

    if (!await requestEnterpriseConfirmation({
      title: "Delete BOM",
      message: `Delete BOM ${form.name}?`,
      confirmLabel: "Delete BOM",
    })) return;

    try {
      setLoading(true);

      const res = await deleteProductionRecipe(form.id);

      showNotice(res?.message || "BOM deleted.");
      await loadAll();
    } catch (error) {
      showNotice(getErrorMessage(error, "Failed to delete BOM."));
    } finally {
      setLoading(false);
    }
  };

  if (!open) return null;

  return (
    <FullScreenModal
      open={open}
      onClose={onClose}
      title="BOM MANAGEMENT"
      subtitle="Material formula, batch output and manufacturing cost estimate"
    >
      <Notice notice={notice} />
      <LoadingPill loading={loading} />

      <div className="h-[calc(100vh-86px)] min-h-0 overflow-hidden bg-[#EEF1F1] p-2 text-[#1C2C2B]">
        <div className="flex h-full min-h-0 flex-col border border-[#AAB5B5] bg-white">
          <div className="border-b border-[#AAB5B5] bg-[#F5F7FA]">
            <div className="flex h-9 items-center justify-between border-b border-[#CFD9D8] px-2">
              <div className="flex items-center gap-2">
                <div className="text-[13px] font-semibold text-[#0C1B1B]">
                  BOM workbench
                </div>
                <div className="h-4 border-l border-[#AAB5B5]" />
                <div className="text-[10px] font-medium uppercase text-[#627474]">
                  Material formula only. Labour and overhead are captured during
                  production.
                </div>
              </div>

              <div className="flex items-center gap-1">
                <ActionButton onClick={newBom} label="New" icon={Plus} />
                <ActionButton onClick={loadAll} label="Refresh" icon={RefreshCw} />
                <ActionButton
                  onClick={saveBom}
                  label="Save BOM"
                  icon={CheckCircle2}
                  primary
                />
                <ActionButton
                  onClick={deleteBom}
                  label="Delete"
                  icon={Trash2}
                  danger
                />
              </div>
            </div>

            <div className="grid grid-cols-[minmax(260px,1fr)_190px_190px_190px_190px] gap-2 px-2 py-2">
              <label className="block">
                <div className="relative">
                  <Search className="absolute left-2 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-[#627474]" />
                  <input
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder="Search BOM, code, product..."
                    className="h-8 w-full border border-[#AAB5B5] bg-white pl-8 pr-2 text-[11px] outline-none focus:border-[#004343]"
                  />
                </div>
              </label>

              <SummaryBox label="BOMs" value={recipes.length} />
              <SummaryBox label="Materials" value={materials.length} />
              <SummaryBox
                label="Batch Cost"
                value={formatMoneyWithCurrency(
                  form.currency || defaultCurrency,
                  totals.batchCost
                )}
              />
              <SummaryBox
                label="Unit Cost"
                value={formatMoneyWithCurrency(
                  form.currency || defaultCurrency,
                  totals.unitCost
                )}
              />
            </div>
          </div>

          <div className="grid min-h-[320px] grid-cols-[minmax(480px,45%)_minmax(620px,55%)] border-b border-[#AAB5B5]">
            <section className="min-w-0 border-r border-[#AAB5B5]">
              <SectionTitle title="BOM Register" subtitle="Recipes" />

              <div className="h-[292px] overflow-auto">
                <table className="w-full min-w-[760px] border-collapse text-left text-[11px]">
                  <thead className="sticky top-0 z-10 bg-[#0E4D4D] text-white">
                    <tr>
                      <GridTh>#</GridTh>
                      <GridTh>Code</GridTh>
                      <GridTh>Recipe</GridTh>
                      <GridTh>Finished Product</GridTh>
                      <GridTh>Status</GridTh>
                    </tr>
                  </thead>

                  <tbody>
                    {filteredRecipes.length === 0 ? (
                      <tr>
                        <td
                          colSpan={5}
                          className="px-3 py-10 text-center text-[#627474]"
                        >
                          No BOMs found.
                        </td>
                      </tr>
                    ) : (
                      filteredRecipes.map((recipe, index) => {
                        const selected = selectedRecipeId === recipe.id;
                        const finishedName =
                          recipe?.finished_product?.name ||
                          recipe?.finishedProduct?.name ||
                          recipe?.metadata?.finished_product_name ||
                          "-";

                        return (
                          <tr
                            key={recipe.id}
                            onClick={() => selectRecipe(recipe)}
                            className={`cursor-pointer border-b border-[#CFD9D8] ${
                              selected
                                ? "bg-[#64A84F] text-white"
                                : "bg-white text-[#324443] hover:bg-[#F2F8F2]"
                            }`}
                          >
                            <GridTd>{index + 1}</GridTd>
                            <GridTd strong>{recipe.code || "-"}</GridTd>
                            <GridTd strong>{recipe.name || "-"}</GridTd>
                            <GridTd>{finishedName}</GridTd>
                            <GridTd>
                              <StatusBadge
                                status={recipe.is_active ? "active" : "inactive"}
                              />
                            </GridTd>
                          </tr>
                        );
                      })
                    )}
                  </tbody>
                </table>
              </div>
            </section>

            <section className="min-w-0 bg-[#F7F8FA]">
              <SectionTitle title="BOM Header" subtitle="Formula setup" />

              <div className="h-[292px] overflow-auto p-2">
                <div className="grid grid-cols-4 gap-2">
                  <BomField label="BOM Name">
                    <input
                      value={form.name}
                      onChange={(event) =>
                        setForm((prev) => ({
                          ...prev,
                          name: event.target.value,
                        }))
                      }
                      className="bom-input"
                    />
                  </BomField>

                  <BomField label="Code">
                    <input
                      value={form.code}
                      onChange={(event) =>
                        setForm((prev) => ({
                          ...prev,
                          code: event.target.value,
                        }))
                      }
                      className="bom-input"
                    />
                  </BomField>

                  <BomField label="Finished Product">
                    <button
                      type="button"
                      onClick={() => openLookup("finished")}
                      className="h-8 w-full border border-[#AAB5B5] bg-white px-2 text-left text-[11px] font-semibold text-[#004343]"
                    >
                      <span className="block truncate">
                        {form.finished_product_name || "Select finished product"}
                      </span>
                    </button>
                  </BomField>

                  <BomField label="Costing Basis">
                    <select
                      value={form.costing_basis}
                      onChange={(event) =>
                        setForm((prev) => ({
                          ...prev,
                          costing_basis: event.target.value,
                        }))
                      }
                      className="bom-input"
                    >
                      <option value="per_unit">Material qty is per 1 unit</option>
                      <option value="per_batch">Material qty is for full batch</option>
                    </select>
                  </BomField>

                  <BomField label="Batch Output Qty">
                    <input
                      type="number"
                      step="0.0001"
                      value={form.batch_output_quantity}
                      onChange={(event) =>
                        setForm((prev) => ({
                          ...prev,
                          batch_output_quantity: Number(event.target.value || 0),
                        }))
                      }
                      className="bom-input"
                    />
                  </BomField>

                  <BomField label="Standard Wastage %">
                    <input
                      type="number"
                      step="0.0001"
                      value={form.standard_wastage_percent}
                      onChange={(event) =>
                        setForm((prev) => ({
                          ...prev,
                          standard_wastage_percent: Number(
                            event.target.value || 0
                          ),
                        }))
                      }
                      className="bom-input"
                    />
                  </BomField>

                  <BomField label="Status">
                    <select
                      value={form.is_active ? "1" : "0"}
                      onChange={(event) =>
                        setForm((prev) => ({
                          ...prev,
                          is_active: event.target.value === "1",
                        }))
                      }
                      className="bom-input"
                    >
                      <option value="1">Active</option>
                      <option value="0">Inactive</option>
                    </select>
                  </BomField>

                  <BomField label="Cost / Unit">
                    <input
                      value={formatMoneyWithCurrency(
                        form.currency || defaultCurrency,
                        totals.unitCost
                      )}
                      readOnly
                      disabled
                      className="h-8 w-full cursor-not-allowed border border-[#AAB5B5] bg-[#EEF2F2] px-2 text-[11px] font-semibold text-[#004343]"
                    />
                  </BomField>
                </div>

                <div className="mt-3 border border-[#A4E5E4] bg-[#F1F8F8] px-2 py-2 text-[11px] font-semibold leading-5 text-[#057B7B]">
                  Example: If costing basis is full batch, enter cement, sand and
                  water needed for the whole batch output. If basis is per unit,
                  enter what one finished unit consumes. Material costs are
                  read-only and come from product average cost.
                </div>
              </div>
            </section>
          </div>

          <div className="flex h-8 items-end border-b border-[#AAB5B5] bg-[#F5F7FA] px-2">
            {[
              ["materials", "Materials"],
              ["visuals", "Cost Visuals"],
              ["readiness", "Readiness"],
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
              />
            )}

            {activeTab === "visuals" && (
              <VisualsPanel
                form={form}
                materials={materials}
                totals={totals}
                currency={form.currency || defaultCurrency}
              />
            )}

            {activeTab === "readiness" && (
              <ReadinessPanel
                form={form}
                materials={materials}
                totals={totals}
                currency={form.currency || defaultCurrency}
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
            : "Select BOM Material"
        }
        search={productSearch}
        setSearch={setProductSearch}
        products={products}
        searchProducts={searchProducts}
        onSelect={selectProduct}
        onClose={() => setLookupOpen(false)}
      />

      <BomInlineStyles />
    </FullScreenModal>
  );
}

function MaterialsTable({
  materials,
  updateMaterial,
  removeMaterial,
  openLookup,
}) {
  return (
    <div className="h-full overflow-auto">
      <div className="flex items-center justify-between border-b border-[#A4E5E4] bg-[#F1F8F8] px-2 py-1.5">
        <div className="text-[11px] font-medium text-[#057B7B]">
          Add raw materials, semi-finished goods, packaging and consumables used by
          the BOM. Material unit cost is read-only from product average cost.
        </div>

        <SmallButton onClick={openLookup}>Add Material</SmallButton>
      </div>

      <table className="w-full min-w-[1350px] border-collapse text-left text-[11px]">
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
            <GridTh align="right">Available</GridTh>
            <GridTh align="right">Line Cost</GridTh>
            <GridTh>Action</GridTh>
          </tr>
        </thead>

        <tbody>
          {materials.length === 0 ? (
            <tr>
              <td colSpan={11} className="px-3 py-10 text-center text-[#627474]">
                No BOM materials added.
              </td>
            </tr>
          ) : (
            materials.map((line, index) => {
              const qty = safeNumber(line.quantity_required);
              const wastePercent = safeNumber(line.wastage_percent);
              const totalQty = qty + qty * (wastePercent / 100);
              const lineCost = totalQty * safeNumber(line.unit_cost);

              return (
                <tr
                  key={line.line_id}
                  className="border-b border-[#CFD9D8] bg-white hover:bg-[#F8FAFC]"
                >
                  <GridTd>{index + 1}</GridTd>
                  <GridTd>{line.product_code || "-"}</GridTd>
                  <GridTd strong>{line.product_name}</GridTd>
                  <GridTd>{String(line.product_type || "-").replaceAll("_", " ")}</GridTd>
                  <GridTd>{line.unit || "-"}</GridTd>

                  <GridTd right>
                    <MiniInput
                      type="number"
                      value={line.quantity_required}
                      onChange={(value) =>
                        updateMaterial(line.line_id, {
                          quantity_required: Number(value || 0),
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
                          wastage_percent: Number(value || 0),
                        })
                      }
                    />
                  </GridTd>

                  <GridTd right>
                    <input
                      value={formatMoneyWithCurrency(
                        line.currency,
                        safeNumber(line.unit_cost)
                      )}
                      readOnly
                      disabled
                      title="Read-only average cost from product costing"
                      className="h-8 w-full cursor-not-allowed border border-[#B9C3C3] bg-[#EEF2F2] px-2 text-right text-[11px] font-semibold text-[#004343]"
                    />
                  </GridTd>

                  <GridTd right>{qtyNumber(line.available_stock)}</GridTd>

                  <GridTd right>
                    {formatMoneyWithCurrency(line.currency, lineCost)}
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

function VisualsPanel({ form, materials, totals, currency }) {
  const batchCost = totals.batchCost;
  const unitCost = totals.unitCost;

  return (
    <div className="h-full overflow-auto bg-[#F7F8FA] p-2">
      <div className="grid gap-2 lg:grid-cols-4">
        <MetricCard title="Materials" value={materials.length} />
        <MetricCard
          title="Batch Output"
          value={qtyNumber(form.batch_output_quantity)}
        />
        <MetricCard
          title="Batch Cost"
          value={formatMoneyWithCurrency(currency, batchCost)}
        />
        <MetricCard
          title="Unit Cost"
          value={formatMoneyWithCurrency(currency, unitCost)}
        />
      </div>

      <div className="mt-2 grid gap-2 xl:grid-cols-2">
        <Panel
          title="BOM Cost Composition"
          subtitle="Material-only cost estimate"
        >
          <CostBar
            label="Materials"
            value={batchCost}
            percent={batchCost > 0 ? 100 : 0}
            currency={currency}
          />

          <div className="mt-3 grid grid-cols-2 gap-2">
            <SmallMetric
              label="Batch Cost"
              value={formatMoneyWithCurrency(currency, batchCost)}
            />
            <SmallMetric
              label="Unit Cost"
              value={formatMoneyWithCurrency(currency, unitCost)}
            />
            <SmallMetric label="Costing Basis" value={form.costing_basis} />
            <SmallMetric
              label="Material Lines"
              value={`${materials.length} line(s)`}
            />
          </div>
        </Panel>

        <Panel
          title="Material Cost Lines"
          subtitle="Average cost from product costing"
        >
          {materials.length === 0 ? (
            <div className="py-8 text-center text-[12px] font-semibold text-[#627474]">
              Add materials to see cost lines.
            </div>
          ) : (
            <div className="space-y-2">
              {materials.map((line) => {
                const qty = safeNumber(line.quantity_required);
                const wastePercent = safeNumber(line.wastage_percent);
                const totalQty = qty + qty * (wastePercent / 100);
                const lineCost = totalQty * safeNumber(line.unit_cost);

                return (
                  <div
                    key={line.line_id}
                    className="border border-[#D8DFDF] bg-[#F8FAFC] p-2"
                  >
                    <div className="flex justify-between gap-2 text-[11px] font-semibold text-[#0C1B1B]">
                      <span>{line.product_name}</span>
                      <span>
                        {formatMoneyWithCurrency(line.currency, lineCost)}
                      </span>
                    </div>
                    <div className="mt-1 text-[10px] text-[#627474]">
                      Qty {qtyNumber(totalQty)} ×{" "}
                      {formatMoneyWithCurrency(line.currency, line.unit_cost)}
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </Panel>
      </div>
    </div>
  );
}

function ReadinessPanel({ form, materials, totals, currency }) {
  return (
    <div className="h-full overflow-auto bg-[#F7F8FA] p-2">
      <div className="grid gap-2 xl:grid-cols-3">
        <Panel title="BOM Readiness" subtitle="Checks before saving">
          <ReadinessLine
            label="BOM Name"
            ok={!!form.name}
            value={form.name || "Missing"}
          />
          <ReadinessLine
            label="Finished Product"
            ok={!!form.finished_product_id}
            value={form.finished_product_name || "Missing"}
          />
          <ReadinessLine
            label="Batch Output"
            ok={safeNumber(form.batch_output_quantity) > 0}
            value={qtyNumber(form.batch_output_quantity)}
          />
          <ReadinessLine
            label="Materials"
            ok={materials.length > 0}
            value={`${materials.length} line(s)`}
          />
        </Panel>

        <Panel title="Cost Check" subtitle="Read-only average costing">
          <ReadinessLine
            label="Batch Cost"
            ok={totals.batchCost >= 0}
            value={formatMoneyWithCurrency(currency, totals.batchCost)}
          />
          <ReadinessLine
            label="Unit Cost"
            ok={totals.unitCost >= 0}
            value={formatMoneyWithCurrency(currency, totals.unitCost)}
          />
          <ReadinessLine
            label="Currency"
            ok={!!currency}
            value={currency || "Missing"}
          />
          <ReadinessLine
            label="Cost Source"
            ok
            value="Product average cost"
          />
        </Panel>

        <Panel title="Important Rule" subtitle="Enterprise costing control">
          <div className="text-[12px] leading-5 text-[#324443]">
            BOM stores the material formula only. The material unit cost displayed
            here is not typed manually. It comes from product average cost and is
            shown with the currency code. Labour, salary, electricity, fuel,
            rent, machine maintenance and other indirect costs must be captured
            during production execution, not inside the BOM.
          </div>
        </Panel>
      </div>
    </div>
  );
}

function SummaryBox({ label, value }) {
  return (
    <div className="border border-[#D8DFDF] bg-white px-3 py-2">
      <div className="text-[10px] font-semibold uppercase text-[#627474]">
        {label}
      </div>
      <div className="mt-1 truncate text-[14px] font-semibold text-[#004343]">
        {value}
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

function BomField({ label, children }) {
  return (
    <label className="block">
      <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">
        {label}
      </div>
      {children}
    </label>
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

function MetricCard({ title, value }) {
  return (
    <div className="border border-[#AFB9B9] bg-white p-3">
      <div className="text-[10px] font-semibold uppercase text-[#627474]">
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
        className={ok ? "font-semibold text-[#067647]" : "font-semibold text-[#B42318]"}
      >
        {value}
      </span>
    </div>
  );
}

function BomInlineStyles() {
  return (
    <style>
      {`
        .bom-input {
          height: 2rem;
          width: 100%;
          border: 1px solid #AAB5B5;
          background: #ffffff;
          padding: 0 0.5rem;
          font-size: 11px;
          outline: none;
        }

        .bom-input:focus {
          border-color: #004343;
          box-shadow: inset 0 0 0 1px #004343;
        }

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

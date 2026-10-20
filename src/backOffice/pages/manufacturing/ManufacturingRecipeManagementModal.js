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
  EmptyBlock,
  FormInput,
  FormSelect,
  GridTd,
  GridTh,
  IconButton,
  LoadingPill,
  MetricCard,
  MiniInput,
  Notice,
  Panel,
  ReadinessLine,
  SectionTitle,
  SmallButton,
  SmallMetric,
  StatusBadge,
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
  titleCase,
  useNotice,
} from "./ManufacturingShared";

const MANUFACTURING_MATERIAL_TYPES = [
  "raw_material",
  "semi_finished",
  "packaging",
  "consumable",
];

const FINISHED_PRODUCT_TYPES = ["finished_good", "semi_finished"];

const emptyRecipeForm = (branchId = "", currency = "USD") => ({
  id: null,
  branch_id: branchId,
  name: "",
  code: "",
  finished_product_id: "",
  finished_product_name: "",
  finished_product_code: "",
  batch_output_quantity: 1,
  costing_basis: "per_batch",
  standard_wastage_percent: 0,
  is_active: true,
  description: "",
  currency: currency || "USD",
});

const getPayloadData = (response) =>
  response?.data?.data || response?.data || response || null;

const getDefaultCurrencyCode = (rows = []) => {
  return rows?.[0]?.code || rows?.[0]?.currency || "USD";
};

const normalizeProductType = (type) =>
  String(type || "")
    .trim()
    .toLowerCase()
    .replaceAll(" ", "_")
    .replaceAll("-", "_");

const productMatchesType = (product, allowedTypes) => {
  const productType = normalizeProductType(
    product?.product_type ||
      product?.type ||
      product?.category?.product_type ||
      product?.metadata?.product_type
  );

  return allowedTypes.includes(productType);
};

const getProductCostRecord = (product) => {
  if (Array.isArray(product?.costs) && product.costs.length > 0) {
    return product.costs[0];
  }

  if (product?.cost) return product.cost;

  return null;
};

const getReadonlyProductAverageCost = (product, fallback = 0) => {
  const costRecord = getProductCostRecord(product);

  return numberValue(
    costRecord?.average_cost ||
      product?.average_cost ||
      product?.unit_cost ||
      fallback ||
      0
  );
};

const getReadonlyProductCurrency = (product, fallback = "USD") => {
  const costRecord = getProductCostRecord(product);

  return (
    costRecord?.currency ||
    product?.currency ||
    product?.metadata?.currency ||
    fallback ||
    "USD"
  );
};

const getMaterialCurrency = (item, product, recipeCurrency, defaultCurrency) => {
  return (
    item?.currency ||
    item?.metadata?.currency ||
    getReadonlyProductCurrency(product, recipeCurrency || defaultCurrency || "USD") ||
    recipeCurrency ||
    defaultCurrency ||
    "USD"
  );
};

const formatMoneyWithCurrency = (currency, value) => {
  const code = currency || "USD";
  return `${code} ${moneyNumber(value)}`;
};

const emptyMaterialLine = (product = null, currency = "USD") => {
  const lineCurrency = getReadonlyProductCurrency(product, currency);
  const unitCost = getReadonlyProductAverageCost(product, 0);

  return {
    line_id: `${product?.id || "new"}-${Date.now()}-${Math.random()}`,
    product_id: product?.id || "",
    product_name: product ? getProductName(product) : "",
    product_code: product ? getProductCode(product) : "",
    product_type: product?.product_type || "",
    uom_id: product?.base_uom_id || product?.uom_id || product?.uom?.id || null,
    unit: product ? getProductUnit(product) : "",
    quantity_required: 1,
    wastage_percent: 0,
    unit_cost: unitCost,
    currency: lineCurrency || currency || "USD",
    available_stock: product ? getProductStock(product) : 0,
    is_active: true,
  };
};

export default function ManufacturingRecipeManagementModal({
  open,
  onClose,
  currentUser = null,
}) {
  const branchId = getBranchId(currentUser);

  const [recipes, setRecipes] = useState([]);
  const [baseCurrencies, setBaseCurrencies] = useState([]);

  const [selectedRecipeId, setSelectedRecipeId] = useState(null);
  const [form, setForm] = useState(emptyRecipeForm(branchId, "USD"));
  const [materials, setMaterials] = useState([]);

  const [products, setProducts] = useState([]);
  const [lookupOpen, setLookupOpen] = useState(false);
  const [lookupMode, setLookupMode] = useState("material");
  const [productSearch, setProductSearch] = useState("");

  const [search, setSearch] = useState("");
  const [loading, setLoading] = useState(false);
  const [activeTab, setActiveTab] = useState("materials");

  const { notice, showNotice } = useNotice();

  const defaultCurrency = useMemo(() => {
    return form.currency || getDefaultCurrencyCode(baseCurrencies) || "USD";
  }, [form.currency, baseCurrencies]);

  const totals = useMemo(() => {
    const materialCost = materials.reduce((sum, line) => {
      const required = numberValue(line.quantity_required);
      const wastage = required * (numberValue(line.wastage_percent) / 100);
      return sum + (required + wastage) * numberValue(line.unit_cost);
    }, 0);

    const batchQty = Math.max(numberValue(form.batch_output_quantity), 1);

    const costPerUnit =
      form.costing_basis === "per_unit" ? materialCost : materialCost / batchQty;

    return {
      materialCost,
      totalCost: materialCost,
      costPerUnit,
      materialCount: materials.length,
      totalRequiredQty: materials.reduce(
        (sum, line) => sum + numberValue(line.quantity_required),
        0
      ),
    };
  }, [materials, form.batch_output_quantity, form.costing_basis]);

  const filteredRecipes = useMemo(() => {
    const q = search.trim().toLowerCase();

    return recipes.filter((recipe) => {
      if (!q) return true;

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

    loadInitialData();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const loadInitialData = async () => {
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

      resetForm(currency);
    } catch (error) {
      showNotice(getErrorMessage(error, "Failed to load BOM recipes."), "error");
    } finally {
      setLoading(false);
    }
  };

  const loadRecipes = async () => {
    try {
      setLoading(true);

      const res = await getProductionRecipes({
        branch_id: branchId || undefined,
        per_page: 100,
      });

      setRecipes(normalizeRows(res));
    } catch (error) {
      showNotice(getErrorMessage(error, "Failed to load BOM recipes."), "error");
    } finally {
      setLoading(false);
    }
  };

  const resetForm = (currency = defaultCurrency) => {
    setSelectedRecipeId(null);
    setForm(emptyRecipeForm(branchId, currency || "USD"));
    setMaterials([]);
    setActiveTab("materials");
  };

  const searchProductsByMode = async (q = "", mode = lookupMode) => {
    try {
      const allowedTypes =
        mode === "finished" ? FINISHED_PRODUCT_TYPES : MANUFACTURING_MATERIAL_TYPES;

      const res = await getProducts({
        q,
        search: q,
        branch_id: branchId || undefined,
        per_page: 100,
        limit: 100,
        is_active: true,

        // Kept here in case the backend supports server-side filtering.
        product_type: allowedTypes.join(","),
        product_types: allowedTypes,
      });

      const rows = normalizeRows(res);

      setProducts(rows.filter((product) => productMatchesType(product, allowedTypes)));
    } catch (error) {
      showNotice(getErrorMessage(error, "Failed to search products."), "error");
    }
  };

  const searchProducts = async (q = "") => {
    await searchProductsByMode(q, lookupMode);
  };

  const openLookup = (mode) => {
    setLookupMode(mode);
    setProductSearch("");
    setProducts([]);
    setLookupOpen(true);

    window.setTimeout(() => {
      searchProductsByMode("", mode);
    }, 0);
  };

  const selectRecipe = (recipe) => {
    const finished =
      recipe.finished_product || recipe.finishedProduct || recipe.product || null;

    const recipeCurrency =
      recipe.currency ||
      recipe?.metadata?.currency ||
      form.currency ||
      defaultCurrency ||
      "USD";

    setSelectedRecipeId(recipe.id);

    setForm({
      ...emptyRecipeForm(branchId, recipeCurrency),
      id: recipe.id,
      branch_id: recipe.branch_id || branchId,
      name: recipe.name || "",
      code: recipe.code || "",
      finished_product_id: recipe.finished_product_id || finished?.id || "",
      finished_product_name:
        getProductName(finished) ||
        recipe?.metadata?.finished_product_name ||
        "",
      finished_product_code:
        getProductCode(finished) ||
        recipe?.metadata?.finished_product_code ||
        "",
      batch_output_quantity: recipe.batch_output_quantity || 1,
      costing_basis: recipe.costing_basis || "per_batch",
      standard_wastage_percent: recipe.standard_wastage_percent || 0,
      is_active: recipe.is_active ?? true,
      description: recipe.description || "",
      currency: recipeCurrency,
    });

    const rows = Array.isArray(recipe.items)
      ? recipe.items
      : Array.isArray(recipe.recipe_items)
        ? recipe.recipe_items
        : Array.isArray(recipe.materials)
          ? recipe.materials
          : [];

    setMaterials(
      rows.map((item) => {
        const product = item.product || item.material || null;
        const lineCurrency = getMaterialCurrency(
          item,
          product,
          recipeCurrency,
          defaultCurrency
        );

        return {
          line_id: item.id || `${item.product_id}-${Date.now()}-${Math.random()}`,
          id: item.id,
          product_id: item.product_id || product?.id || "",
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
          uom_id:
            item.uom_id ||
            product?.base_uom_id ||
            product?.uom_id ||
            product?.uom?.id ||
            null,
          unit:
            item.uom?.code ||
            item.metadata?.unit ||
            getProductUnit(product) ||
            item.unit ||
            "",
          quantity_required: item.quantity_required || item.quantity || 1,
          wastage_percent: item.wastage_percent || 0,
          unit_cost: getReadonlyProductAverageCost(
            product,
            item.unit_cost || item.metadata?.unit_cost || 0
          ),
          currency: lineCurrency,
          available_stock:
            getProductStock(product) ||
            item.available_stock ||
            item.metadata?.available_stock ||
            0,
          is_active: item.is_active ?? true,
        };
      })
    );

    setActiveTab("materials");
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

    setMaterials((prev) => [
      ...prev,
      emptyMaterialLine(product, form.currency || defaultCurrency || "USD"),
    ]);

    setLookupOpen(false);
  };

  const updateMaterial = (lineId, patch) => {
    setMaterials((prev) =>
      prev.map((line) =>
        line.line_id === lineId
          ? {
              ...line,
              ...patch,

              // Cost and currency remain product costing controlled.
              unit_cost: line.unit_cost,
              currency: line.currency,
            }
          : line
      )
    );
  };

  const removeMaterial = (lineId) => {
    setMaterials((prev) => prev.filter((line) => line.line_id !== lineId));
  };

  const saveRecipe = async () => {
    if (!form.name.trim()) {
      showNotice("BOM / recipe name is required.", "error");
      return;
    }

    if (!form.finished_product_id) {
      showNotice("Select a finished product.", "error");
      return;
    }

    if (numberValue(form.batch_output_quantity) <= 0) {
      showNotice("Batch output quantity must be greater than zero.", "error");
      return;
    }

    if (!materials.length) {
      showNotice("Add at least one material line.", "error");
      return;
    }

    if (
      materials.some(
        (line) => !line.product_id || numberValue(line.quantity_required) <= 0
      )
    ) {
      showNotice(
        "Each material must have a product and quantity greater than zero.",
        "error"
      );
      return;
    }

    const recipeCurrency = form.currency || defaultCurrency || "USD";

    const payload = {
      id: form.id || undefined,
      branch_id: form.branch_id || branchId,
      name: form.name.trim(),
      code: form.code || null,
      finished_product_id: form.finished_product_id,
      batch_output_quantity: numberValue(form.batch_output_quantity),
      costing_basis: form.costing_basis,
      standard_wastage_percent: numberValue(form.standard_wastage_percent),
      is_active: !!form.is_active,
      description: form.description || null,
      currency: recipeCurrency,
      metadata: {
        bom_management_version: "enterprise_filtered_currency_v3",
        finished_product_name: form.finished_product_name,
        finished_product_code: form.finished_product_code,
        currency: recipeCurrency,
        frontend_material_cost: totals.materialCost,
        frontend_cost_per_unit: totals.costPerUnit,
        basis_note:
          form.costing_basis === "per_unit"
            ? "Quantities entered per one finished unit; backend stores material formula lines."
            : "Quantities entered for full batch output quantity.",
      },
      items: materials.map((line) => {
        const required = numberValue(line.quantity_required);
        const wastagePercent = numberValue(line.wastage_percent);
        const wasteQuantity = required * (wastagePercent / 100);
        const totalQuantity = required + wasteQuantity;
        const unitCost = numberValue(line.unit_cost);
        const lineCurrency = line.currency || recipeCurrency || "USD";

        return {
          id: line.id || undefined,
          product_id: line.product_id,
          uom_id: line.uom_id || null,
          quantity_required: required,
          wastage_percent: wastagePercent,
          is_active: !!line.is_active,
          metadata: {
            product_code: line.product_code,
            product_name: line.product_name,
            product_type: line.product_type,
            unit: line.unit,
            unit_cost: unitCost,
            currency: lineCurrency,
            available_stock: numberValue(line.available_stock),
            entered_quantity_required: required,
            backend_quantity_required: required,
            quantity_basis: form.costing_basis,
            waste_quantity: wasteQuantity,
            total_quantity: totalQuantity,
            line_cost: totalQuantity * unitCost,
            cost_source: "product_average_cost_readonly",
          },
        };
      }),
    };

    try {
      setLoading(true);

      const res = await saveProductionRecipe(payload);

      showNotice(res?.message || "BOM recipe saved successfully.", "success");

      await loadRecipes();

      const saved = getPayloadData(res);

      if (saved?.id) {
        selectRecipe(saved);
      } else {
        resetForm(recipeCurrency);
      }
    } catch (error) {
      showNotice(getErrorMessage(error, "Failed to save BOM recipe."), "error");
    } finally {
      setLoading(false);
    }
  };

  const removeRecipe = async () => {
    if (!selectedRecipeId) {
      showNotice("Select a BOM recipe first.", "error");
      return;
    }

    if (!await requestEnterpriseConfirmation({
      title: "Delete BOM Recipe",
      message: `Delete BOM recipe ${form.name || ""}?`,
      confirmLabel: "Delete recipe",
    })) return;

    try {
      setLoading(true);

      const res = await deleteProductionRecipe(selectedRecipeId);

      showNotice(res?.message || "BOM recipe deleted.", "success");

      await loadRecipes();
      resetForm(defaultCurrency);
    } catch (error) {
      showNotice(getErrorMessage(error, "Failed to delete BOM recipe."), "error");
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

              <div className="flex gap-1">
                <ActionButton onClick={() => resetForm(defaultCurrency)} label="New" icon={Plus} />
                <ActionButton onClick={loadRecipes} label="Refresh" icon={RefreshCw} />
                <ActionButton
                  onClick={saveRecipe}
                  label="Save BOM"
                  icon={CheckCircle2}
                  primary
                />
                <ActionButton
                  onClick={removeRecipe}
                  label="Delete"
                  icon={Trash2}
                  danger
                  disabled={!selectedRecipeId}
                />
              </div>
            </div>

            <div className="grid grid-cols-[minmax(260px,1fr)_repeat(4,160px)] gap-2 px-2 py-2">
              <div className="relative">
                <Search className="absolute left-2 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-[#627474]" />
                <input
                  value={search}
                  onChange={(event) => setSearch(event.target.value)}
                  placeholder="Search BOM, code, product..."
                  className="h-8 w-full border border-[#AAB5B5] bg-white pl-8 pr-2 text-[11px] outline-none"
                />
              </div>

              <SmallMetric label="BOMs" value={recipes.length} />
              <SmallMetric label="Materials" value={materials.length} />
              <SmallMetric
                label="Batch Cost"
                value={formatMoneyWithCurrency(defaultCurrency, totals.totalCost)}
              />
              <SmallMetric
                label="Unit Cost"
                value={formatMoneyWithCurrency(defaultCurrency, totals.costPerUnit)}
              />
            </div>
          </div>

          <div className="grid min-h-[265px] grid-cols-[minmax(430px,45%)_minmax(520px,55%)] border-b border-[#AAB5B5]">
            <section className="min-w-0 border-r border-[#AAB5B5]">
              <SectionTitle title="BOM Register" subtitle="Recipes" />

              <div className="h-[235px] overflow-auto">
                <table className="w-full min-w-[650px] border-collapse text-left text-[11px]">
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
                          No BOM recipes found.
                        </td>
                      </tr>
                    ) : (
                      filteredRecipes.map((recipe, index) => {
                        const selected = selectedRecipeId === recipe.id;

                        const finishedName =
                          recipe.finished_product?.name ||
                          recipe.finishedProduct?.name ||
                          recipe.metadata?.finished_product_name ||
                          "-";

                        return (
                          <tr
                            key={recipe.id}
                            onClick={() => selectRecipe(recipe)}
                            className={`cursor-pointer border-b border-[#CFD9D8] ${
                              selected
                                ? "bg-[#49A942] text-white"
                                : "bg-white hover:bg-[#F8FAFC]"
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

              <div className="h-[235px] overflow-auto p-2">
                <div className="grid grid-cols-4 gap-2">
                  <FormInput
                    label="BOM Name"
                    value={form.name}
                    onChange={(value) =>
                      setForm((prev) => ({ ...prev, name: value }))
                    }
                  />

                  <FormInput
                    label="Code"
                    value={form.code}
                    onChange={(value) =>
                      setForm((prev) => ({ ...prev, code: value }))
                    }
                  />

                  <label className="block">
                    <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">
                      Finished Product
                    </div>
                    <button
                      type="button"
                      onClick={() => openLookup("finished")}
                      className="h-8 w-full border border-[#AAB5B5] bg-white px-2 text-left text-[11px] font-semibold text-[#004343]"
                    >
                      <span className="block truncate">
                        {form.finished_product_name || "Select finished good"}
                      </span>
                    </button>
                  </label>

                  <FormSelect
                    label="Currency"
                    value={form.currency || defaultCurrency}
                    onChange={(value) =>
                      setForm((prev) => ({ ...prev, currency: value }))
                    }
                    options={(baseCurrencies.length
                      ? baseCurrencies
                      : [{ code: "USD", name: "US Dollar" }]
                    ).map((currency) => [
                      currency.code || currency.currency || "USD",
                      `${currency.code || currency.currency || "USD"}${
                        currency.name ? ` - ${currency.name}` : ""
                      }`,
                    ])}
                  />

                  <FormSelect
                    label="Costing Basis"
                    value={form.costing_basis}
                    onChange={(value) =>
                      setForm((prev) => ({ ...prev, costing_basis: value }))
                    }
                    options={[
                      ["per_batch", "Material qty is for full batch"],
                      ["per_unit", "Material qty is per 1 unit"],
                    ]}
                  />

                  <FormInput
                    label="Batch Output Qty"
                    type="number"
                    value={form.batch_output_quantity}
                    onChange={(value) =>
                      setForm((prev) => ({
                        ...prev,
                        batch_output_quantity: value,
                      }))
                    }
                  />

                  <FormInput
                    label="Standard Wastage %"
                    type="number"
                    value={form.standard_wastage_percent}
                    onChange={(value) =>
                      setForm((prev) => ({
                        ...prev,
                        standard_wastage_percent: value,
                      }))
                    }
                  />

                  <FormSelect
                    label="Status"
                    value={form.is_active ? "1" : "0"}
                    onChange={(value) =>
                      setForm((prev) => ({
                        ...prev,
                        is_active: value === "1",
                      }))
                    }
                    options={[
                      ["1", "Active"],
                      ["0", "Inactive"],
                    ]}
                  />

                  <SmallMetric
                    label="Cost / Unit"
                    value={formatMoneyWithCurrency(
                      defaultCurrency,
                      totals.costPerUnit
                    )}
                  />
                </div>

                <div className="mt-2 border border-[#A4E5E4] bg-[#F1F8F8] p-2 text-[11px] font-medium text-[#057B7B]">
                  Example: If costing basis is <b>full batch</b>, enter
                  cement/sand/water needed for the whole batch output. If basis is{" "}
                  <b>per unit</b>, enter what one finished unit consumes.
                  Material currency is stored per material line.
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
                defaultCurrency={defaultCurrency}
              />
            )}

            {activeTab === "readiness" && (
              <ReadinessPanel
                form={form}
                materials={materials}
                totals={totals}
                defaultCurrency={defaultCurrency}
              />
            )}
          </div>
        </div>
      </div>

      <ManufacturingProductLookupModal
        open={lookupOpen}
        mode={lookupMode === "finished" ? "finished" : "material"}
        title={
          lookupMode === "finished"
            ? "Select Finished Product"
            : "Select Material"
        }
        search={productSearch}
        setSearch={setProductSearch}
        products={products}
        searchProducts={searchProducts}
        onSelect={selectProduct}
        onClose={() => setLookupOpen(false)}
      />

      <NoRadiusStyles />
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
          Add raw materials, semi-finished goods, packaging and consumables used
          by the BOM. Currency and unit cost are controlled from product costing.
        </div>

        <SmallButton onClick={openLookup}>Add Material</SmallButton>
      </div>

      <table className="w-full min-w-[1320px] border-collapse text-left text-[11px]">
        <thead className="sticky top-0 z-10 bg-[#0E4D4D] text-white">
          <tr>
            <GridTh>Line</GridTh>
            <GridTh>Code</GridTh>
            <GridTh>Material</GridTh>
            <GridTh>Type</GridTh>
            <GridTh>Unit</GridTh>
            <GridTh align="right">Qty Required</GridTh>
            <GridTh align="right">Wastage %</GridTh>
            <GridTh>Currency</GridTh>
            <GridTh align="right">Unit Cost</GridTh>
            <GridTh align="right">Available</GridTh>
            <GridTh align="right">Line Cost</GridTh>
            <GridTh>Action</GridTh>
          </tr>
        </thead>

        <tbody>
          {materials.length === 0 ? (
            <tr>
              <td colSpan={12} className="px-3 py-10 text-center text-[#627474]">
                No materials added.
              </td>
            </tr>
          ) : (
            materials.map((line, index) => {
              const required = numberValue(line.quantity_required);
              const totalQty =
                required + required * (numberValue(line.wastage_percent) / 100);
              const lineCost = totalQty * numberValue(line.unit_cost);
              const currency = line.currency || "USD";

              return (
                <tr
                  key={line.line_id}
                  className="border-b border-[#CFD9D8] bg-white hover:bg-[#F8FAFC]"
                >
                  <GridTd>{index + 1}</GridTd>
                  <GridTd>{line.product_code || "-"}</GridTd>
                  <GridTd strong>{line.product_name || "-"}</GridTd>
                  <GridTd>{titleCase(line.product_type)}</GridTd>
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

                  <GridTd>
                    <input
                      value={currency}
                      readOnly
                      disabled
                      title="Read-only currency from product cost, falling back to BOM currency."
                      className="h-8 w-full cursor-not-allowed border border-[#B9C3C3] bg-[#EEF2F2] px-2 text-[11px] font-semibold text-[#004343]"
                    />
                  </GridTd>

                  <GridTd right>
                    <input
                      value={formatMoneyWithCurrency(currency, line.unit_cost)}
                      readOnly
                      disabled
                      title="Read-only average cost from product costing."
                      className="h-8 w-full cursor-not-allowed border border-[#B9C3C3] bg-[#EEF2F2] px-2 text-right text-[11px] font-semibold text-[#004343]"
                    />
                  </GridTd>

                  <GridTd right>{qtyNumber(line.available_stock)}</GridTd>

                  <GridTd right>
                    {formatMoneyWithCurrency(currency, lineCost)}
                  </GridTd>

                  <GridTd>
                    <IconButton
                      icon={Trash2}
                      danger
                      onClick={() => removeMaterial(line.line_id)}
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
          title="Batch Cost"
          value={formatMoneyWithCurrency(defaultCurrency, totals.totalCost)}
          icon={FileText}
        />
        <MetricCard
          title="Cost / Unit"
          value={formatMoneyWithCurrency(defaultCurrency, totals.costPerUnit)}
          icon={ClipboardList}
        />
      </div>

      <div className="mt-2 grid gap-2 xl:grid-cols-2">
        <Panel title="Cost Composition" subtitle="BOM material estimate">
          <CostBar
            label="Direct Materials"
            value={totals.materialCost}
            total={totals.totalCost}
            currency={defaultCurrency}
          />
        </Panel>

        <Panel title="BOM Notes" subtitle="Enterprise costing rule">
          <ReadinessLine
            label="Direct Material"
            ok={materials.length > 0}
            value={`${materials.length} line(s)`}
          />
          <ReadinessLine
            label="Direct Labour"
            ok={false}
            value="Captured in production"
          />
          <ReadinessLine
            label="Factory Overhead"
            ok={false}
            value="Captured in production"
          />
          <ReadinessLine
            label="Finished Product"
            ok={!!form.finished_product_id}
            value={form.finished_product_name || "Not selected"}
          />
        </Panel>
      </div>

      <div className="mt-2">
        <Panel title="Material Currency Lines" subtitle="Currency is shown per material">
          {materials.length === 0 ? (
            <EmptyBlock text="No material currency lines available." />
          ) : (
            <div className="grid gap-2 md:grid-cols-2 xl:grid-cols-3">
              {materials.map((line) => {
                const required = numberValue(line.quantity_required);
                const totalQty =
                  required +
                  required * (numberValue(line.wastage_percent) / 100);
                const lineCost = totalQty * numberValue(line.unit_cost);
                const currency = line.currency || defaultCurrency || "USD";

                return (
                  <div
                    key={line.line_id}
                    className="border border-[#D8DFDF] bg-[#F8FAFC] p-2"
                  >
                    <div className="truncate text-[11px] font-semibold text-[#0C1B1B]">
                      {line.product_name || "-"}
                    </div>
                    <div className="mt-1 text-[10px] font-medium uppercase text-[#627474]">
                      {titleCase(line.product_type)} • {line.unit || "-"}
                    </div>
                    <div className="mt-2 text-[13px] font-semibold text-[#004343]">
                      {formatMoneyWithCurrency(currency, lineCost)}
                    </div>
                    <div className="mt-1 text-[10px] text-[#627474]">
                      Unit cost: {formatMoneyWithCurrency(currency, line.unit_cost)}
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

function ReadinessPanel({ form, materials, totals, defaultCurrency }) {
  return (
    <div className="h-full overflow-auto bg-[#F7F8FA] p-2">
      <div className="grid gap-2 xl:grid-cols-3">
        <Panel title="BOM Readiness" subtitle="Validation before planning">
          <ReadinessLine
            label="Recipe Name"
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
            ok={numberValue(form.batch_output_quantity) > 0}
            value={qtyNumber(form.batch_output_quantity)}
          />
          <ReadinessLine
            label="Material Lines"
            ok={materials.length > 0}
            value={`${materials.length} line(s)`}
          />
        </Panel>

        <Panel title="Currency Check" subtitle="Per material costing currency">
          <ReadinessLine
            label="BOM Currency"
            ok={!!defaultCurrency}
            value={defaultCurrency || "Missing"}
          />
          <ReadinessLine
            label="Material Currency"
            ok={materials.every((line) => !!line.currency)}
            value={
              materials.every((line) => !!line.currency)
                ? "Available per line"
                : "Missing on some lines"
            }
          />
          <ReadinessLine
            label="Batch Cost"
            ok
            value={formatMoneyWithCurrency(defaultCurrency, totals.totalCost)}
          />
          <ReadinessLine
            label="Unit Cost"
            ok
            value={formatMoneyWithCurrency(defaultCurrency, totals.costPerUnit)}
          />
        </Panel>

        <Panel title="Material Type Rule" subtitle="Product lookup filtering">
          <ReadinessLine
            label="Finished lookup"
            ok
            value="Finished goods, semi-finished"
          />
          <ReadinessLine
            label="Material lookup"
            ok
            value="Raw, semi-finished, packaging, consumable"
          />
          <ReadinessLine
            label="Manual cost editing"
            ok={false}
            value="Disabled"
          />
          <ReadinessLine
            label="Cost source"
            ok
            value="Product average cost"
          />
        </Panel>
      </div>
    </div>
  );
}

function CostBar({ label, value, total, currency }) {
  const percent = total > 0 ? (numberValue(value) / numberValue(total)) * 100 : 0;

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

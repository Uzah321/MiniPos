import React, { useEffect, useMemo, useState } from "react";
import {
  Edit3,
  Plus,
  RefreshCw,
  Search,
  Trash2,
  X,
} from "lucide-react";

import FullScreenModal from "../../../components/FullScreenModal";

import {
  getProductionRecipes,
  saveProductionRecipe,
  deleteProductionRecipe,
} from "../../../services/ManufacturingService";

import { getProducts } from "../../../services/ProductService";

import {
  ActionButton,
  DataTable,
  LoadingPill,
  Notice,
  PageShell,
  SmallButton,
  StatusBadge,
  Td,
  getBranchId,
  getErrorMessage,
  normalizeRows,
  qtyNumber,
  useNotice,
} from "./ManufacturingShared";

const MANUFACTURING_PRODUCT_TYPES = [
  "raw_material",
  "finished_good",
  "semi_finished",
  "packaging",
  "consumable",
];

const FINISHED_PRODUCT_TYPES = ["finished_good", "semi_finished"];

const MATERIAL_PRODUCT_TYPES = [
  "raw_material",
  "semi_finished",
  "packaging",
  "consumable",
];

const PRODUCT_TYPE_LABELS = {
  raw_material: "Raw Material",
  finished_good: "Finished Good",
  semi_finished: "Semi Finished",
  packaging: "Packaging",
  consumable: "Consumable",
};

const emptyBomForm = {
  id: null,
  branch_id: "",
  finished_product_id: "",
  finished_product_name: "",
  finished_product_code: "",
  name: "",
  code: "",
  costing_basis: "per_batch",
  batch_output_quantity: "1",
  standard_wastage_percent: "0",
  is_active: true,
};

const emptyStaffLine = () => ({
  line_id: `staff-${Date.now()}-${Math.random()}`,
  role: "",
  staff_count: "1",
  hours: "1",
  rate: "0",
  notes: "",
});

const numberValue = (value) => Number(value || 0);

const moneyNumber = (value) => Number(value || 0).toFixed(4);

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

const uniqueById = (rows = []) => {
  const map = new Map();

  rows.forEach((row) => {
    if (row?.id) map.set(row.id, row);
  });

  return Array.from(map.values());
};

export default function ManufacturingRecipeRegisterModal({
  open,
  onClose,
  currentUser = null,
}) {
  const branchId = getBranchId(currentUser);

  const [recipes, setRecipes] = useState([]);
  const [manufacturingProducts, setManufacturingProducts] = useState([]);
  const [finishedProducts, setFinishedProducts] = useState([]);
  const [materialProducts, setMaterialProducts] = useState([]);

  const [loading, setLoading] = useState(false);
  const [savingBom, setSavingBom] = useState(false);

  const [search, setSearch] = useState("");
  const [basisFilter, setBasisFilter] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [productTypeFilter, setProductTypeFilter] = useState("");

  const [bomModalOpen, setBomModalOpen] = useState(false);
  const [productLookupOpen, setProductLookupOpen] = useState(false);
  const [lookupMode, setLookupMode] = useState("");
  const [lookupSearch, setLookupSearch] = useState("");

  const [bomForm, setBomForm] = useState(emptyBomForm);
  const [materialLines, setMaterialLines] = useState([]);
  const [staffLines, setStaffLines] = useState([]);

  const { notice, showNotice } = useNotice();

  const filteredRecipes = useMemo(() => {
    const q = search.trim().toLowerCase();

    return recipes.filter((recipe) => {
      const recipeName = String(recipe?.name || "").toLowerCase();
      const recipeCode = String(recipe?.code || "").toLowerCase();
      const finishedName = String(
        recipe?.finished_product?.name || recipe?.finished_product_name || ""
      ).toLowerCase();

      const matchesSearch =
        !q ||
        recipeName.includes(q) ||
        recipeCode.includes(q) ||
        finishedName.includes(q);

      const matchesBasis =
        !basisFilter || recipe?.costing_basis === basisFilter;

      const matchesStatus =
        statusFilter === ""
          ? true
          : Boolean(recipe?.is_active) === (statusFilter === "active");

      const finishedType =
        recipe?.finished_product?.product_type ||
        recipe?.finished_product_type ||
        "";

      const matchesProductType =
        !productTypeFilter || finishedType === productTypeFilter;

      return (
        matchesSearch && matchesBasis && matchesStatus && matchesProductType
      );
    });
  }, [recipes, search, basisFilter, statusFilter, productTypeFilter]);

  const bomStats = useMemo(() => {
    return {
      total: recipes.length,
      active: recipes.filter((item) => item.is_active).length,
      inactive: recipes.filter((item) => !item.is_active).length,
      batch: recipes.filter((item) => item.costing_basis === "per_batch").length,
      unit: recipes.filter((item) => item.costing_basis === "per_unit").length,
      manufacturingProducts: manufacturingProducts.length,
    };
  }, [recipes, manufacturingProducts]);

  const visibleLookupProducts = useMemo(() => {
    const q = lookupSearch.trim().toLowerCase();

    const source =
      lookupMode === "finished_product" ? finishedProducts : materialProducts;

    if (!q) return source;

    return source.filter((product) => {
      return (
        String(getProductName(product)).toLowerCase().includes(q) ||
        String(getProductCode(product)).toLowerCase().includes(q) ||
        String(product?.sku || "").toLowerCase().includes(q) ||
        String(product?.barcode || "").toLowerCase().includes(q) ||
        String(product?.product_type || "").toLowerCase().includes(q)
      );
    });
  }, [lookupMode, lookupSearch, finishedProducts, materialProducts]);

  const bomTotals = useMemo(() => {
    const batchQty = Math.max(numberValue(bomForm.batch_output_quantity), 1);

    const materials = materialLines.map((line) => {
      const enteredQty = numberValue(line.quantity_required);
      const wastePercent = numberValue(line.wastage_percent);
      const unitCost = numberValue(line.unit_cost);

      const backendQty =
        bomForm.costing_basis === "per_unit"
          ? enteredQty * batchQty
          : enteredQty;

      const wasteQty = backendQty * (wastePercent / 100);
      const totalQty = backendQty + wasteQty;
      const lineCost = totalQty * unitCost;

      return {
        ...line,
        backend_quantity: backendQty,
        waste_quantity: wasteQty,
        total_quantity: totalQty,
        line_cost: lineCost,
      };
    });

    const materialCost = materials.reduce(
      (sum, line) => sum + numberValue(line.line_cost),
      0
    );

    const staffCost = staffLines.reduce((sum, line) => {
      return (
        sum +
        numberValue(line.staff_count) *
          numberValue(line.hours) *
          numberValue(line.rate)
      );
    }, 0);

    const totalCost = materialCost + staffCost;

    return {
      materials,
      material_cost: materialCost,
      staff_cost: staffCost,
      total_cost: totalCost,
      cost_per_unit: batchQty > 0 ? totalCost / batchQty : 0,
    };
  }, [
    materialLines,
    staffLines,
    bomForm.costing_basis,
    bomForm.batch_output_quantity,
  ]);

  useEffect(() => {
    if (!open) return;

    loadAll();
  }, [open]);

  const loadAll = async () => {
    try {
      setLoading(true);

      await Promise.all([loadRecipes(), loadManufacturingProducts()]);
    } catch (error) {
      showNotice(getErrorMessage(error, "Failed to load BOM management data."));
    } finally {
      setLoading(false);
    }
  };

  const loadRecipes = async () => {
    const res = await getProductionRecipes({
      branch_id: branchId || undefined,
      per_page: 100,
    });

    setRecipes(normalizeRows(res));
  };

  const loadManufacturingProducts = async () => {
    const requests = MANUFACTURING_PRODUCT_TYPES.map((type) =>
      getProducts({
        product_type: type,
        is_active: true,
        per_page: 100,
      })
    );

    const responses = await Promise.all(requests);

    const allProducts = uniqueById(
      responses.flatMap((response) => normalizeRows(response))
    );

    setManufacturingProducts(allProducts);

    setFinishedProducts(
      allProducts.filter((product) =>
        FINISHED_PRODUCT_TYPES.includes(product?.product_type)
      )
    );

    setMaterialProducts(
      allProducts.filter((product) =>
        MATERIAL_PRODUCT_TYPES.includes(product?.product_type)
      )
    );
  };

  const openCreateBom = () => {
    setBomForm({
      ...emptyBomForm,
      branch_id: branchId || "",
    });

    setMaterialLines([]);
    setStaffLines([]);
    setBomModalOpen(true);
  };

  const openEditBom = (recipe) => {
    setBomForm({
      ...emptyBomForm,
      id: recipe.id,
      branch_id: recipe.branch_id || branchId || "",
      finished_product_id: recipe.finished_product_id || "",
      finished_product_name:
        recipe.finished_product?.name || recipe.finished_product_name || "",
      finished_product_code:
        recipe.finished_product?.code || recipe.finished_product_code || "",
      name: recipe.name || "",
      code: recipe.code || "",
      costing_basis: recipe.costing_basis || "per_batch",
      batch_output_quantity: recipe.batch_output_quantity || "1",
      standard_wastage_percent: recipe.standard_wastage_percent || "0",
      is_active: recipe.is_active ?? true,
    });

    setMaterialLines(
      (recipe.items || []).map((item) => ({
        line_id: item.id || `mat-${Date.now()}-${Math.random()}`,
        product_id: item.product_id,
        product_name: item.product?.name || item.metadata?.product_name || "",
        product_code: item.product?.code || item.metadata?.product_code || "",
        product_type:
          item.product?.product_type || item.metadata?.product_type || "",
        uom_id: item.uom_id || null,
        unit:
          item.uom?.code ||
          item.product?.uom?.code ||
          item.metadata?.unit ||
          "EACH",
        quantity_required: item.metadata?.entered_quantity_required
          ? item.metadata.entered_quantity_required
          : item.quantity_required || 0,
        backend_quantity_required: item.quantity_required || 0,
        wastage_percent: item.wastage_percent || 0,
        unit_cost: item.metadata?.unit_cost || getProductCost(item.product),
        available_stock:
          item.metadata?.available_stock || getProductStock(item.product),
      }))
    );

    setStaffLines(
      Array.isArray(recipe.metadata?.staff_lines)
        ? recipe.metadata.staff_lines.map((line) => ({
            ...emptyStaffLine(),
            ...line,
            line_id: line.line_id || `staff-${Date.now()}-${Math.random()}`,
          }))
        : []
    );

    setBomModalOpen(true);
  };

  const closeBomModal = () => {
    if (savingBom) return;

    setBomModalOpen(false);
    setProductLookupOpen(false);
    setLookupMode("");
    setLookupSearch("");
  };

  const deleteRecipe = async (recipe) => {
    if (!window.confirm(`Delete BOM / recipe ${recipe.name}?`)) return;

    try {
      setLoading(true);

      const res = await deleteProductionRecipe(recipe.id);

      showNotice(res?.message || "BOM deleted successfully.");
      await loadRecipes();
    } catch (error) {
      showNotice(getErrorMessage(error, "Failed to delete BOM."));
    } finally {
      setLoading(false);
    }
  };

  const selectLookupProduct = (product) => {
    if (lookupMode === "finished_product") {
      setBomForm((prev) => ({
        ...prev,
        finished_product_id: product.id,
        finished_product_name: getProductName(product),
        finished_product_code: getProductCode(product),
        name:
          prev.name ||
          `${getProductName(product)} BOM${
            prev.costing_basis === "per_unit" ? " - Per Unit" : " - Per Batch"
          }`,
      }));

      setProductLookupOpen(false);
      return;
    }

    if (lookupMode === "material") {
      const exists = materialLines.some((line) => line.product_id === product.id);

      if (exists) {
        showNotice("This material is already on the BOM.");
        setProductLookupOpen(false);
        return;
      }

      setMaterialLines((prev) => [
        ...prev,
        {
          line_id: `mat-${product.id}-${Date.now()}`,
          product_id: product.id,
          product_name: getProductName(product),
          product_code: getProductCode(product),
          product_type: product.product_type,
          uom_id: product?.base_uom_id || product?.uom_id || null,
          unit: getProductUnit(product),
          quantity_required: "1",
          wastage_percent: "0",
          unit_cost: getProductCost(product),
          available_stock: getProductStock(product),
        },
      ]);

      setProductLookupOpen(false);
    }
  };

  const updateMaterialLine = (lineId, patch) => {
    setMaterialLines((prev) =>
      prev.map((line) => (line.line_id === lineId ? { ...line, ...patch } : line))
    );
  };

  const removeMaterialLine = (lineId) => {
    setMaterialLines((prev) => prev.filter((line) => line.line_id !== lineId));
  };

  const addStaffLine = () => {
    setStaffLines((prev) => [
      ...prev,
      {
        ...emptyStaffLine(),
        role: "Production Staff",
      },
    ]);
  };

  const updateStaffLine = (lineId, patch) => {
    setStaffLines((prev) =>
      prev.map((line) => (line.line_id === lineId ? { ...line, ...patch } : line))
    );
  };

  const removeStaffLine = (lineId) => {
    setStaffLines((prev) => prev.filter((line) => line.line_id !== lineId));
  };

  const validateBom = () => {
    if (!bomForm.name.trim()) {
      showNotice("BOM name is required.");
      return false;
    }

    if (!bomForm.finished_product_id) {
      showNotice("Select the finished product this BOM produces.");
      return false;
    }

    if (!numberValue(bomForm.batch_output_quantity)) {
      showNotice("Batch output quantity must be greater than zero.");
      return false;
    }

    if (!materialLines.length) {
      showNotice("Add at least one manufacturing material.");
      return false;
    }

    const invalidMaterial = materialLines.find(
      (line) =>
        !line.product_id ||
        !numberValue(line.quantity_required) ||
        numberValue(line.quantity_required) <= 0
    );

    if (invalidMaterial) {
      showNotice("Every material must have a quantity greater than zero.");
      return false;
    }

    const invalidType = materialLines.find(
      (line) => !MATERIAL_PRODUCT_TYPES.includes(line.product_type)
    );

    if (invalidType) {
      showNotice(
        "Only raw materials, semi-finished goods, packaging and consumables are allowed as BOM materials."
      );
      return false;
    }

    return true;
  };

  const saveBom = async () => {
    if (!validateBom()) return;

    try {
      setSavingBom(true);

      const payload = {
        id: bomForm.id || undefined,
        branch_id: bomForm.branch_id || branchId || null,
        finished_product_id: bomForm.finished_product_id,
        name: bomForm.name.trim(),
        code: bomForm.code || null,
        costing_basis: bomForm.costing_basis,
        batch_output_quantity: numberValue(bomForm.batch_output_quantity),
        standard_wastage_percent: numberValue(
          bomForm.standard_wastage_percent
        ),
        is_active: Boolean(bomForm.is_active),

        metadata: {
          bom_management_version: "enterprise_compact_v1",
          finished_product_name: bomForm.finished_product_name,
          finished_product_code: bomForm.finished_product_code,
          basis_explanation:
            bomForm.costing_basis === "per_unit"
              ? "User entered material quantities per 1 finished unit. Frontend multiplied quantities by batch output before sending to backend."
              : "User entered material quantities for the full batch output.",
          material_cost: bomTotals.material_cost,
          staff_cost: bomTotals.staff_cost,
          total_cost: bomTotals.total_cost,
          cost_per_unit: bomTotals.cost_per_unit,
          staff_lines: staffLines.map((line) => ({
            line_id: line.line_id,
            role: line.role,
            staff_count: numberValue(line.staff_count),
            hours: numberValue(line.hours),
            rate: numberValue(line.rate),
            notes: line.notes || "",
            total:
              numberValue(line.staff_count) *
              numberValue(line.hours) *
              numberValue(line.rate),
          })),
        },

        items: bomTotals.materials.map((line) => ({
          product_id: line.product_id,
          uom_id: line.uom_id || null,

          /*
            Important:
            The backend services currently multiply recipe quantities by batches.
            For clarity:
            - Per Batch: quantity_required is already the batch quantity.
            - Per Unit: quantity_required is converted to batch quantity here.
          */
          quantity_required: numberValue(line.backend_quantity),
          wastage_percent: numberValue(line.wastage_percent),

          metadata: {
            product_code: line.product_code,
            product_name: line.product_name,
            product_type: line.product_type,
            unit: line.unit,
            entered_quantity_required: numberValue(line.quantity_required),
            backend_quantity_required: numberValue(line.backend_quantity),
            quantity_basis: bomForm.costing_basis,
            waste_quantity: numberValue(line.waste_quantity),
            total_quantity: numberValue(line.total_quantity),
            unit_cost: numberValue(line.unit_cost),
            available_stock: numberValue(line.available_stock),
            line_cost: numberValue(line.line_cost),
          },
        })),
      };

      const res = await saveProductionRecipe(payload);

      showNotice(res?.message || "BOM saved successfully.");

      await loadRecipes();
      closeBomModal();
    } catch (error) {
      showNotice(getErrorMessage(error, "Failed to save BOM."));
    } finally {
      setSavingBom(false);
    }
  };

  if (!open) return null;

  return (
    <>
      <FullScreenModal
        open={open}
        onClose={onClose}
        title="BOM MANAGEMENT"
        subtitle="Manufacturing recipes, raw materials, packaging, consumables and costing"
      >
        <Notice notice={notice} />
        <LoadingPill loading={loading} />

        <div className="h-[calc(100vh-86px)] min-h-0 bg-[#D8DFDF] overflow-hidden text-[#1C2C2B] font-normal">
          <div className="grid h-full min-h-0 grid-rows-[auto_auto_minmax(0,1fr)] gap-2 p-2">
            <div className="grid shrink-0 grid-cols-1 gap-2 xl:grid-cols-[300px_minmax(0,1fr)_300px]">
              <BomDeskPanel title="BOM Card">
                <div className="grid grid-cols-[110px_1fr] items-center gap-1 text-[11px]">
                  <BomDeskLabel>Total BOMs</BomDeskLabel>
                  <BomDeskField value={bomStats.total} disabled />

                  <BomDeskLabel>Active</BomDeskLabel>
                  <BomDeskField value={bomStats.active} disabled />

                  <BomDeskLabel>Inactive</BomDeskLabel>
                  <BomDeskField value={bomStats.inactive} disabled />

                  <BomDeskLabel>Per Batch</BomDeskLabel>
                  <BomDeskField value={bomStats.batch} disabled />

                  <BomDeskLabel>Per Unit</BomDeskLabel>
                  <BomDeskField value={bomStats.unit} disabled />
                </div>
              </BomDeskPanel>

              <BomDeskPanel title="BOM Search">
                <div className="grid grid-cols-[120px_1fr] items-center gap-1 text-[11px]">
                  <BomDeskLabel>Search</BomDeskLabel>
                  <input
                    type="text"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder="Search BOM, code or finished product..."
                    className="h-7 border border-[#B9C3C3] bg-white px-2 text-[11px] outline-none focus:border-[#004343]"
                  />

                  <BomDeskLabel>Costing Basis</BomDeskLabel>
                  <select
                    value={basisFilter}
                    onChange={(event) => setBasisFilter(event.target.value)}
                    className="h-7 border border-[#B9C3C3] bg-white px-2 text-[11px] outline-none focus:border-[#004343]"
                  >
                    <option value="">All Basis</option>
                    <option value="per_batch">Per Batch</option>
                    <option value="per_unit">Per Unit</option>
                  </select>

                  <BomDeskLabel>Status</BomDeskLabel>
                  <select
                    value={statusFilter}
                    onChange={(event) => setStatusFilter(event.target.value)}
                    className="h-7 border border-[#B9C3C3] bg-white px-2 text-[11px] outline-none focus:border-[#004343]"
                  >
                    <option value="">All Status</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                  </select>
                </div>
              </BomDeskPanel>

              <BomDeskPanel title="Manufacturing Products">
                <div className="grid grid-cols-[110px_1fr] items-center gap-1 text-[11px]">
                  <BomDeskLabel>Allowed Items</BomDeskLabel>
                  <BomDeskField value={bomStats.manufacturingProducts} disabled />

                  <BomDeskLabel>Finished Goods</BomDeskLabel>
                  <BomDeskField value={finishedProducts.length} disabled />

                  <BomDeskLabel>Materials</BomDeskLabel>
                  <BomDeskField value={materialProducts.length} disabled />

                  <BomDeskLabel>Product Type</BomDeskLabel>
                  <select
                    value={productTypeFilter}
                    onChange={(event) =>
                      setProductTypeFilter(event.target.value)
                    }
                    className="h-7 border border-[#B9C3C3] bg-white px-2 text-[11px] outline-none focus:border-[#004343]"
                  >
                    <option value="">All Finished Types</option>
                    {FINISHED_PRODUCT_TYPES.map((type) => (
                      <option key={type} value={type}>
                        {PRODUCT_TYPE_LABELS[type]}
                      </option>
                    ))}
                  </select>
                </div>
              </BomDeskPanel>
            </div>

            <div className="flex shrink-0 flex-wrap items-center justify-between gap-2 border border-[#B9C3C3] bg-[#EEF2F2] px-2 py-1">
              <div className="flex flex-wrap gap-1">
                <BomDeskTab active>BOM Register</BomDeskTab>
              </div>

              <div className="flex flex-wrap items-center gap-1">
                <BomDeskCompactTool onClick={openCreateBom} green>
                  Add BOM
                </BomDeskCompactTool>

                <BomDeskCompactTool onClick={loadAll} disabled={loading}>
                  {loading ? "Loading..." : "Refresh"}
                </BomDeskCompactTool>

                <BomDeskCompactTool
                  onClick={() => {
                    setSearch("");
                    setBasisFilter("");
                    setStatusFilter("");
                    setProductTypeFilter("");
                  }}
                >
                  Clear
                </BomDeskCompactTool>
              </div>
            </div>

            <div className="min-h-0 border border-[#AFB9B9] bg-white">
              <div className="flex h-full min-h-0 flex-col">
                <BomDeskGridHeader columns="grid-cols-[48px_120px_minmax(260px,1.2fr)_220px_130px_120px_120px_120px_160px]">
                  <BomDeskHead>Line</BomDeskHead>
                  <BomDeskHead>Code</BomDeskHead>
                  <BomDeskHead>BOM / Recipe</BomDeskHead>
                  <BomDeskHead>Finished Product</BomDeskHead>
                  <BomDeskHead>Basis</BomDeskHead>
                  <BomDeskHead>Batch Qty</BomDeskHead>
                  <BomDeskHead>Waste %</BomDeskHead>
                  <BomDeskHead>Status</BomDeskHead>
                  <BomDeskHead>Actions</BomDeskHead>
                </BomDeskGridHeader>

                <div className="min-h-0 flex-1 overflow-auto">
                  {loading ? (
                    <BomDeskEmpty message="Loading BOM records..." />
                  ) : filteredRecipes.length === 0 ? (
                    <BomDeskEmpty message="No BOM records found." />
                  ) : (
                    filteredRecipes.map((recipe, index) => (
                      <div
                        key={recipe.id}
                        className="grid min-w-[1300px] grid-cols-[48px_120px_minmax(260px,1.2fr)_220px_130px_120px_120px_120px_160px] border-b border-[#D8DFDF] bg-white text-[11px] hover:bg-[#F8FAFC]"
                      >
                        <BomDeskCell muted>{index + 1}</BomDeskCell>

                        <BomDeskCell>{recipe.code || "-"}</BomDeskCell>

                        <BomDeskCell>
                          <div className="font-semibold text-[#0C1B1B]">
                            {recipe.name}
                          </div>
                          <div className="mt-[2px] text-[#627474]">
                            {recipe.items?.length || 0} material line(s)
                          </div>
                        </BomDeskCell>

                        <BomDeskCell>
                          <div className="font-semibold text-[#0C1B1B]">
                            {recipe.finished_product?.name || "-"}
                          </div>
                          <div className="text-[10px] text-[#627474]">
                            {PRODUCT_TYPE_LABELS[
                              recipe.finished_product?.product_type
                            ] ||
                              recipe.finished_product?.product_type ||
                              ""}
                          </div>
                        </BomDeskCell>

                        <BomDeskCell>
                          <BomDeskPill tone="info">
                            {recipe.costing_basis === "per_unit"
                              ? "Per Unit"
                              : "Per Batch"}
                          </BomDeskPill>
                        </BomDeskCell>

                        <BomDeskCell right>
                          {qtyNumber(recipe.batch_output_quantity)}
                        </BomDeskCell>

                        <BomDeskCell right>
                          {qtyNumber(recipe.standard_wastage_percent)}
                        </BomDeskCell>

                        <BomDeskCell>
                          <StatusBadge
                            status={recipe.is_active ? "active" : "inactive"}
                          />
                        </BomDeskCell>

                        <BomDeskCell>
                          <div className="flex flex-wrap gap-1">
                            <button
                              type="button"
                              onClick={() => openEditBom(recipe)}
                              className="inline-flex h-7 items-center gap-1 bg-[#004142] px-2 text-[10px] font-medium uppercase text-white"
                            >
                              <Edit3 className="h-3 w-3" />
                              Edit
                            </button>

                            <button
                              type="button"
                              onClick={() => deleteRecipe(recipe)}
                              className="inline-flex h-7 items-center gap-1 bg-red-700 px-2 text-[10px] font-medium uppercase text-white"
                            >
                              <Trash2 className="h-3 w-3" />
                              Delete
                            </button>
                          </div>
                        </BomDeskCell>
                      </div>
                    ))
                  )}
                </div>
              </div>
            </div>
          </div>
        </div>
      </FullScreenModal>

      {bomModalOpen && (
        <div className="bom-modal-backdrop">
          <div className="bom-product-modal">
            <div className="bom-modal-header">
              <div>
                <h2>{bomForm.id ? "Edit BOM" : "Add BOM"}</h2>
                <p>
                  Define finished product, material usage, wastage, staff cost
                  and live production cost.
                </p>
              </div>

              <button
                type="button"
                onClick={closeBomModal}
                disabled={savingBom}
                className="bom-close-button"
              >
                ×
              </button>
            </div>

            <div className="bom-modal-body">
              <BomCleanSection title="BOM Identity">
                <BomCleanGrid>
                  <BomCleanField label="BOM Name" span="3">
                    <input
                      value={bomForm.name}
                      onChange={(event) =>
                        setBomForm((prev) => ({
                          ...prev,
                          name: event.target.value,
                        }))
                      }
                      className="bom-input"
                    />
                  </BomCleanField>

                  <BomCleanField label="BOM Code" span="2">
                    <input
                      value={bomForm.code}
                      onChange={(event) =>
                        setBomForm((prev) => ({
                          ...prev,
                          code: event.target.value,
                        }))
                      }
                      placeholder="Optional"
                      className="bom-input"
                    />
                  </BomCleanField>

                  <BomCleanField label="Costing Basis" span="2">
                    <select
                      value={bomForm.costing_basis}
                      onChange={(event) =>
                        setBomForm((prev) => ({
                          ...prev,
                          costing_basis: event.target.value,
                        }))
                      }
                      className="bom-input"
                    >
                      <option value="per_batch">Per Batch</option>
                      <option value="per_unit">Per Unit</option>
                    </select>
                  </BomCleanField>

                  <BomCleanField label="Batch Output Qty" span="2">
                    <input
                      type="number"
                      step="0.0001"
                      value={bomForm.batch_output_quantity}
                      onChange={(event) =>
                        setBomForm((prev) => ({
                          ...prev,
                          batch_output_quantity: event.target.value,
                        }))
                      }
                      className="bom-input"
                    />
                  </BomCleanField>

                  <BomCleanField label="Standard Waste %" span="2">
                    <input
                      type="number"
                      step="0.0001"
                      value={bomForm.standard_wastage_percent}
                      onChange={(event) =>
                        setBomForm((prev) => ({
                          ...prev,
                          standard_wastage_percent: event.target.value,
                        }))
                      }
                      className="bom-input"
                    />
                  </BomCleanField>

                  <label className="bom-check-card bom-span-1">
                    <input
                      type="checkbox"
                      checked={Boolean(bomForm.is_active)}
                      onChange={(event) =>
                        setBomForm((prev) => ({
                          ...prev,
                          is_active: event.target.checked,
                        }))
                      }
                    />
                    Active
                  </label>

                  <BomCleanField label="Finished Product" span="4">
                    <button
                      type="button"
                      onClick={() => {
                        setLookupMode("finished_product");
                        setLookupSearch("");
                        setProductLookupOpen(true);
                      }}
                      className="bom-select-button"
                    >
                      <span>
                        {bomForm.finished_product_name || "Select Finished Good"}
                      </span>
                      <Search className="h-4 w-4" />
                    </button>
                  </BomCleanField>

                  <div className="bom-note bom-span-8">
                    {bomForm.costing_basis === "per_batch" ? (
                      <>
                        <strong>Per Batch Example:</strong> If this BOM produces{" "}
                        <strong>100 burgers</strong>, enter flour, beef,
                        packaging and consumables needed for the full{" "}
                        <strong>100 units</strong>.
                      </>
                    ) : (
                      <>
                        <strong>Per Unit Example:</strong> If 1 burger uses{" "}
                        <strong>0.1500 kg beef</strong> and batch output is{" "}
                        <strong>100</strong>, the system calculates{" "}
                        <strong>15.0000 kg</strong> for the batch before saving.
                      </>
                    )}
                  </div>
                </BomCleanGrid>
              </BomCleanSection>

              <div className="bom-compact-grid">
                <BomCleanSection
                  title="Manufacturing Materials"
                  right={
                    <button
                      type="button"
                      onClick={() => {
                        setLookupMode("material");
                        setLookupSearch("");
                        setProductLookupOpen(true);
                      }}
                      className="bom-small-button"
                    >
                      Add Material
                    </button>
                  }
                >
                  <div className="bom-table-wrap">
                    <table className="bom-table">
                      <thead>
                        <tr>
                          <th>Material</th>
                          <th>Type</th>
                          <th>Qty</th>
                          <th>Waste %</th>
                          <th>Unit Cost</th>
                          <th>Total Qty</th>
                          <th>Cost</th>
                          <th></th>
                        </tr>
                      </thead>

                      <tbody>
                        {bomTotals.materials.length === 0 ? (
                          <tr>
                            <td colSpan="8" className="bom-empty-cell">
                              No materials added. Add raw material, packaging,
                              consumable or semi-finished item.
                            </td>
                          </tr>
                        ) : (
                          bomTotals.materials.map((line) => (
                            <tr key={line.line_id}>
                              <td>
                                <div className="bom-strong">
                                  {line.product_name}
                                </div>
                                <div className="bom-muted">
                                  {line.product_code || "-"} · {line.unit}
                                </div>
                              </td>

                              <td>
                                {PRODUCT_TYPE_LABELS[line.product_type] ||
                                  line.product_type ||
                                  "-"}
                              </td>

                              <td>
                                <input
                                  type="number"
                                  step="0.0001"
                                  value={line.quantity_required}
                                  onChange={(event) =>
                                    updateMaterialLine(line.line_id, {
                                      quantity_required: event.target.value,
                                    })
                                  }
                                  className="bom-mini-input"
                                />
                              </td>

                              <td>
                                <input
                                  type="number"
                                  step="0.0001"
                                  value={line.wastage_percent}
                                  onChange={(event) =>
                                    updateMaterialLine(line.line_id, {
                                      wastage_percent: event.target.value,
                                    })
                                  }
                                  className="bom-mini-input"
                                />
                              </td>

                              <td>
                                <input
                                  type="number"
                                  step="0.0001"
                                  value={line.unit_cost}
                                  onChange={(event) =>
                                    updateMaterialLine(line.line_id, {
                                      unit_cost: event.target.value,
                                    })
                                  }
                                  className="bom-mini-input"
                                />
                              </td>

                              <td className="bom-right">
                                {qtyNumber(line.total_quantity)}
                              </td>

                              <td className="bom-right">
                                {moneyNumber(line.line_cost)}
                              </td>

                              <td className="bom-right">
                                <button
                                  type="button"
                                  onClick={() =>
                                    removeMaterialLine(line.line_id)
                                  }
                                  className="bom-icon-danger"
                                >
                                  <Trash2 className="h-3.5 w-3.5" />
                                </button>
                              </td>
                            </tr>
                          ))
                        )}
                      </tbody>
                    </table>
                  </div>
                </BomCleanSection>

                <BomCleanSection
                  title="Staff / Labour Cost"
                  right={
                    <button
                      type="button"
                      onClick={addStaffLine}
                      className="bom-small-button"
                    >
                      Add Staff
                    </button>
                  }
                >
                  <div className="bom-table-wrap">
                    <table className="bom-table">
                      <thead>
                        <tr>
                          <th>Role</th>
                          <th>Staff</th>
                          <th>Hours</th>
                          <th>Rate</th>
                          <th>Total</th>
                          <th></th>
                        </tr>
                      </thead>

                      <tbody>
                        {staffLines.length === 0 ? (
                          <tr>
                            <td colSpan="6" className="bom-empty-cell">
                              No staff cost added. Add labour if production has
                              direct staff cost.
                            </td>
                          </tr>
                        ) : (
                          staffLines.map((line) => {
                            const total =
                              numberValue(line.staff_count) *
                              numberValue(line.hours) *
                              numberValue(line.rate);

                            return (
                              <tr key={line.line_id}>
                                <td>
                                  <input
                                    value={line.role}
                                    onChange={(event) =>
                                      updateStaffLine(line.line_id, {
                                        role: event.target.value,
                                      })
                                    }
                                    placeholder="Role"
                                    className="bom-mini-input"
                                  />
                                </td>

                                <td>
                                  <input
                                    type="number"
                                    step="1"
                                    value={line.staff_count}
                                    onChange={(event) =>
                                      updateStaffLine(line.line_id, {
                                        staff_count: event.target.value,
                                      })
                                    }
                                    className="bom-mini-input"
                                  />
                                </td>

                                <td>
                                  <input
                                    type="number"
                                    step="0.0001"
                                    value={line.hours}
                                    onChange={(event) =>
                                      updateStaffLine(line.line_id, {
                                        hours: event.target.value,
                                      })
                                    }
                                    className="bom-mini-input"
                                  />
                                </td>

                                <td>
                                  <input
                                    type="number"
                                    step="0.0001"
                                    value={line.rate}
                                    onChange={(event) =>
                                      updateStaffLine(line.line_id, {
                                        rate: event.target.value,
                                      })
                                    }
                                    className="bom-mini-input"
                                  />
                                </td>

                                <td className="bom-right">
                                  {moneyNumber(total)}
                                </td>

                                <td className="bom-right">
                                  <button
                                    type="button"
                                    onClick={() =>
                                      removeStaffLine(line.line_id)
                                    }
                                    className="bom-icon-danger"
                                  >
                                    <Trash2 className="h-3.5 w-3.5" />
                                  </button>
                                </td>
                              </tr>
                            );
                          })
                        )}
                      </tbody>
                    </table>
                  </div>
                </BomCleanSection>
              </div>

              <BomCleanSection title="Live BOM Costing">
                <div className="bom-summary-grid">
                  <BomSummaryCard
                    label="Material Cost"
                    value={moneyNumber(bomTotals.material_cost)}
                  />
                  <BomSummaryCard
                    label="Staff Cost"
                    value={moneyNumber(bomTotals.staff_cost)}
                  />
                  <BomSummaryCard
                    label="Total BOM Cost"
                    value={moneyNumber(bomTotals.total_cost)}
                  />
                  <BomSummaryCard
                    label="Cost Per Finished Unit"
                    value={moneyNumber(bomTotals.cost_per_unit)}
                    green
                  />
                </div>
              </BomCleanSection>
            </div>

            <div className="bom-modal-footer">
              <div className="bom-modal-status">
                {bomForm.finished_product_name || "No finished product selected"}
              </div>

              <div className="bom-footer-actions">
                <button
                  type="button"
                  onClick={closeBomModal}
                  disabled={savingBom}
                  className="bom-secondary-button"
                >
                  Cancel
                </button>

                <button
                  type="button"
                  onClick={saveBom}
                  disabled={savingBom}
                  className="bom-primary-button"
                >
                  {savingBom
                    ? "Saving..."
                    : bomForm.id
                      ? "Update BOM"
                      : "Save BOM"}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {productLookupOpen && (
        <div className="bom-lookup-backdrop">
          <div className="bom-lookup-modal">
            <div className="bom-lookup-header">
              <div>
                <h3>
                  {lookupMode === "finished_product"
                    ? "Select Finished Product"
                    : "Select Manufacturing Material"}
                </h3>
                <p>
                  {lookupMode === "finished_product"
                    ? "Only finished goods and semi-finished goods are shown."
                    : "Only raw materials, semi-finished goods, packaging and consumables are shown."}
                </p>
              </div>

              <button
                type="button"
                onClick={() => setProductLookupOpen(false)}
                className="bom-close-button"
              >
                ×
              </button>
            </div>

            <div className="bom-lookup-search">
              <Search className="h-4 w-4 text-[#627474]" />
              <input
                autoFocus
                value={lookupSearch}
                onChange={(event) => setLookupSearch(event.target.value)}
                placeholder="Search code, SKU, barcode or product name..."
              />
            </div>

            <div className="bom-lookup-body">
              <table className="bom-table">
                <thead>
                  <tr>
                    <th>Code</th>
                    <th>Product</th>
                    <th>Type</th>
                    <th>Stock</th>
                    <th>Cost</th>
                    <th></th>
                  </tr>
                </thead>

                <tbody>
                  {visibleLookupProducts.length === 0 ? (
                    <tr>
                      <td colSpan="6" className="bom-empty-cell">
                        No manufacturing products found.
                      </td>
                    </tr>
                  ) : (
                    visibleLookupProducts.map((product) => (
                      <tr key={product.id}>
                        <td>{getProductCode(product) || "-"}</td>
                        <td>
                          <div className="bom-strong">
                            {getProductName(product)}
                          </div>
                          <div className="bom-muted">
                            {product?.category?.name || "Uncategorised"}
                          </div>
                        </td>
                        <td>
                          {PRODUCT_TYPE_LABELS[product.product_type] ||
                            product.product_type ||
                            "-"}
                        </td>
                        <td className="bom-right">
                          {qtyNumber(getProductStock(product))}
                        </td>
                        <td className="bom-right">
                          {moneyNumber(getProductCost(product))}
                        </td>
                        <td className="bom-right">
                          <button
                            type="button"
                            onClick={() => selectLookupProduct(product)}
                            className="bom-small-dark-button"
                          >
                            Select
                          </button>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}

      <BomInlineStyles />
    </>
  );
}

const BomDeskPanel = ({ title, children }) => (
  <div className="border border-[#AFB9B9] bg-white">
    <div className="border-b border-[#D8DFDF] bg-[#EEF2F2] px-2 py-1 text-[11px] font-semibold text-[#324443]">
      {title}
    </div>
    <div className="p-2">{children}</div>
  </div>
);

const BomDeskLabel = ({ children }) => (
  <div className="text-[11px] text-[#465757]">{children}</div>
);

const BomDeskField = ({ value, disabled = false }) => (
  <input
    value={value ?? ""}
    disabled={disabled}
    readOnly
    className="h-7 border border-[#B9C3C3] bg-white px-2 text-[11px] outline-none disabled:bg-[#EEF2F2]"
  />
);

const BomDeskTab = ({ active, children }) => (
  <button
    type="button"
    className={`h-7 border px-4 text-[11px] font-semibold ${
      active
        ? "border-[#7C8888] bg-white text-[#0C1B1B]"
        : "border-transparent bg-transparent text-[#465757]"
    }`}
  >
    {children}
  </button>
);

const BomDeskCompactTool = ({
  children,
  onClick,
  disabled = false,
  green = false,
  danger = false,
}) => {
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
};

const BomDeskGridHeader = ({ columns, children }) => (
  <div
    className={`grid shrink-0 ${columns} border-b border-[#AFB9B9] bg-[#EEF2F2] text-[10px] font-semibold uppercase text-[#465757]`}
  >
    {children}
  </div>
);

const BomDeskHead = ({ children }) => (
  <div className="border-r border-[#D8DFDF] px-2 py-2 text-[10px]">
    {children}
  </div>
);

const BomDeskCell = ({ children, right = false, muted = false }) => (
  <div
    className={`min-h-[36px] border-r border-[#E4EAEA] px-2 py-2 ${
      right ? "text-right" : ""
    } ${muted ? "text-[#627474]" : "text-[#1C2C2B]"}`}
  >
    {children}
  </div>
);

const BomDeskPill = ({ children, tone = "default" }) => {
  const tones = {
    default: "border-[#CFD6D6] bg-[#F9FAFB] text-[#465757]",
    info: "border-[#A4E5E4] bg-[#F1F8F8] text-[#057B7B]",
    success: "border-[#ABEFC6] bg-[#ECFDF3] text-[#067647]",
  };

  return (
    <span
      className={`inline-flex border px-2 py-[1px] text-[9px] font-semibold uppercase ${tones[tone]}`}
    >
      {children}
    </span>
  );
};

const BomDeskEmpty = ({ message }) => (
  <div className="flex h-[220px] items-center justify-center text-[12px] text-[#627474]">
    {message}
  </div>
);

const BomCleanSection = ({ title, right, children }) => (
  <section className="bom-section">
    <div className="bom-section-header">
      <h3>{title}</h3>
      {right}
    </div>

    <div className="bom-section-body">{children}</div>
  </section>
);

const BomCleanGrid = ({ children }) => (
  <div className="bom-clean-grid">{children}</div>
);

const BomCleanField = ({ label, children, span = "12" }) => (
  <div className={`bom-field bom-span-${span}`}>
    <label>{label}</label>
    {children}
  </div>
);

const BomSummaryCard = ({ label, value, green = false }) => (
  <div className={`bom-summary-card ${green ? "bom-summary-green" : ""}`}>
    <div>{label}</div>
    <strong>{value}</strong>
  </div>
);

const BomInlineStyles = () => (
  <style>
    {`
      button,
      input,
      select,
      textarea {
        border-radius: 0 !important;
      }

      .bom-modal-backdrop,
      .bom-lookup-backdrop {
        position: fixed;
        inset: 0;
        z-index: 99999;
        background: rgba(0, 52, 52, 0.62);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 14px;
      }

      .bom-product-modal {
        width: min(1380px, 98vw);
        height: min(94vh, 900px);
        background: #ffffff;
        border: 1px solid #9BA5A5;
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.32);
        display: flex;
        flex-direction: column;
        color: #0D1B1B;
      }

      .bom-modal-header,
      .bom-lookup-header {
        min-height: 58px;
        flex-shrink: 0;
        background: #F8FAFC;
        border-bottom: 1px solid #CBD6D6;
        padding: 10px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
      }

      .bom-modal-header h2,
      .bom-lookup-header h3 {
        margin: 0;
        font-size: 15px;
        font-weight: 500;
        color: #091B1B;
      }

      .bom-modal-header p,
      .bom-lookup-header p {
        margin: 3px 0 0;
        font-size: 12px;
        color: #637877;
      }

      .bom-close-button {
        width: 34px;
        height: 34px;
        border: 1px solid #CBD6D6;
        background: #ffffff;
        font-size: 22px;
        line-height: 1;
        color: #324444;
      }

      .bom-close-button:hover:not(:disabled) {
        background: #FEE2E2;
        border-color: #EF4444;
        color: #991B1B;
      }

      .bom-modal-body {
        flex: 1;
        min-height: 0;
        overflow-y: auto;
        padding: 12px;
        background: #F1F5F5;
      }

      .bom-section {
        background: #ffffff;
        border: 1px solid #CBD6D6;
        margin-bottom: 10px;
      }

      .bom-section-header {
        min-height: 34px;
        border-bottom: 1px solid #E2E9E9;
        background: #F8FAFC;
        padding: 7px 10px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
      }

      .bom-section-header h3 {
        margin: 0;
        font-size: 12px;
        font-weight: 500;
        color: #091B1B;
      }

      .bom-section-body {
        padding: 10px;
      }

      .bom-clean-grid {
        display: grid;
        grid-template-columns: repeat(12, minmax(0, 1fr));
        gap: 8px;
      }

      .bom-compact-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.35fr) minmax(380px, 0.65fr);
        gap: 10px;
      }

      .bom-field label {
        display: block;
        margin-bottom: 4px;
        font-size: 11px;
        font-weight: 400;
        color: #465858;
      }

      .bom-span-1 { grid-column: span 1 / span 1; }
      .bom-span-2 { grid-column: span 2 / span 2; }
      .bom-span-3 { grid-column: span 3 / span 3; }
      .bom-span-4 { grid-column: span 4 / span 4; }
      .bom-span-8 { grid-column: span 8 / span 8; }
      .bom-span-12 { grid-column: span 12 / span 12; }

      .bom-input,
      .bom-mini-input {
        width: 100%;
        border: 1px solid #93A6A6;
        background: #ffffff;
        color: #091B1B;
        font-size: 12px;
        outline: none;
      }

      .bom-input {
        height: 32px;
        padding: 0 8px;
      }

      .bom-mini-input {
        height: 28px;
        padding: 0 6px;
        font-size: 11px;
      }

      .bom-input:focus,
      .bom-mini-input:focus {
        border-color: #069495;
        box-shadow: inset 0 0 0 1px #93F5F4;
      }

      .bom-select-button {
        width: 100%;
        height: 32px;
        border: 1px solid #004343;
        background: white;
        color: #004343;
        padding: 0 8px;
        font-size: 12px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
      }

      .bom-check-card {
        min-height: 32px;
        border: 1px solid #CBD6D6;
        background: #F8FAFC;
        padding: 0 9px;
        display: flex;
        align-items: center;
        gap: 7px;
        font-size: 12px;
        font-weight: 400;
        color: #324444;
      }

      .bom-note {
        border: 1px solid #F59E0B;
        background: #FFFBEB;
        color: #92400E;
        padding: 8px 10px;
        font-size: 12px;
        line-height: 1.35;
      }

      .bom-small-button,
      .bom-small-dark-button,
      .bom-secondary-button,
      .bom-primary-button {
        height: 30px;
        border: 1px solid #93A6A6;
        padding: 0 12px;
        font-size: 11px;
        font-weight: 400;
        white-space: nowrap;
      }

      .bom-small-button {
        background: #F8FAFC;
        color: #091B1B;
      }

      .bom-small-dark-button {
        background: #004142;
        border-color: #004141;
        color: white;
      }

      .bom-primary-button {
        background: #069495;
        border-color: #057B7C;
        color: #ffffff;
      }

      .bom-primary-button:hover:not(:disabled) {
        background: #057B7C;
      }

      .bom-secondary-button {
        background: #ffffff;
        color: #091B1B;
      }

      .bom-table-wrap {
        overflow: auto;
        border: 1px solid #D8DFDF;
      }

      .bom-table {
        width: 100%;
        min-width: 760px;
        border-collapse: collapse;
        font-size: 11px;
      }

      .bom-table th {
        background: #F4F6F8;
        color: #465757;
        text-transform: uppercase;
        font-size: 10px;
        font-weight: 600;
        border-bottom: 1px solid #D8DFDF;
        padding: 7px 6px;
        text-align: left;
      }

      .bom-table td {
        border-bottom: 1px solid #E9EDED;
        padding: 6px;
        vertical-align: middle;
        color: #324443;
      }

      .bom-empty-cell {
        height: 90px;
        text-align: center;
        color: #627474;
      }

      .bom-right {
        text-align: right;
      }

      .bom-strong {
        font-weight: 600;
        color: #0C1B1B;
      }

      .bom-muted {
        margin-top: 2px;
        font-size: 10px;
        color: #627474;
      }

      .bom-icon-danger {
        display: inline-flex;
        height: 26px;
        width: 26px;
        align-items: center;
        justify-content: center;
        border: 1px solid #FECDCA;
        background: #ffffff;
        color: #B42318;
      }

      .bom-summary-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 8px;
      }

      .bom-summary-card {
        border: 1px solid #CBD6D6;
        background: #F8FAFC;
        padding: 10px;
      }

      .bom-summary-card div {
        font-size: 10px;
        text-transform: uppercase;
        color: #637877;
      }

      .bom-summary-card strong {
        display: block;
        margin-top: 6px;
        font-size: 20px;
        color: #004343;
      }

      .bom-summary-green {
        border-color: #ABEFC6;
        background: #ECFDF3;
      }

      .bom-summary-green strong {
        color: #067647;
      }

      .bom-modal-footer {
        min-height: 54px;
        flex-shrink: 0;
        border-top: 1px solid #CBD6D6;
        background: #F8FAFC;
        padding: 10px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
      }

      .bom-modal-status {
        font-size: 12px;
        color: #637877;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      .bom-footer-actions {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 8px;
      }

      .bom-lookup-modal {
        width: min(980px, 96vw);
        height: min(78vh, 760px);
        background: white;
        border: 1px solid #9BA5A5;
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.32);
        display: flex;
        flex-direction: column;
      }

      .bom-lookup-search {
        height: 44px;
        flex-shrink: 0;
        border-bottom: 1px solid #CBD6D6;
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 0 10px;
      }

      .bom-lookup-search input {
        flex: 1;
        height: 30px;
        border: 1px solid #93A6A6;
        padding: 0 8px;
        font-size: 12px;
        outline: none;
      }

      .bom-lookup-body {
        flex: 1;
        min-height: 0;
        overflow: auto;
        padding: 10px;
        background: #F1F5F5;
      }

      button:disabled,
      input:disabled,
      select:disabled,
      textarea:disabled {
        opacity: 0.55;
        cursor: not-allowed;
      }

      @media (max-width: 1200px) {
        .bom-compact-grid {
          grid-template-columns: 1fr;
        }

        .bom-summary-grid {
          grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .bom-span-1,
        .bom-span-2,
        .bom-span-3,
        .bom-span-4 {
          grid-column: span 6 / span 6;
        }

        .bom-span-8 {
          grid-column: span 12 / span 12;
        }
      }

      @media (max-width: 700px) {
        .bom-product-modal {
          width: 99vw;
          height: 98vh;
        }

        .bom-clean-grid {
          grid-template-columns: 1fr;
        }

        .bom-span-1,
        .bom-span-2,
        .bom-span-3,
        .bom-span-4,
        .bom-span-8,
        .bom-span-12 {
          grid-column: span 1 / span 1;
        }

        .bom-summary-grid {
          grid-template-columns: 1fr;
        }

        .bom-modal-footer {
          align-items: stretch;
          flex-direction: column;
        }

        .bom-footer-actions {
          width: 100%;
        }

        .bom-footer-actions button {
          flex: 1;
        }
      }
    `}
  </style>
);
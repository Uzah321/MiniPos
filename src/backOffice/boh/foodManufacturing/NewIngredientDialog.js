import React, { useEffect, useState } from "react";

import EnterpriseFormModal from "../../../components/EnterpriseFormModal";
import {
  createProduct,
  generateProductIdentity,
  getProductCategories,
  getUoms,
} from "../../../services/ProductService";

export const INGREDIENT_TYPES = [
  ["raw_material", "Raw material"],
  ["semi_finished", "Prepared item"],
  ["packaging", "Packaging"],
  ["consumable", "Consumable"],
];

const rowsOf = (response) => {
  const payload = response?.data ?? response;
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data?.data)) return payload.data.data;
  if (Array.isArray(payload?.data)) return payload.data;
  return [];
};

const errorText = (error) => {
  const data = error?.response?.data;
  if (data?.errors) return Object.values(data.errors).flat().join("\n");
  return data?.message || data?.error || error?.message || "The ingredient could not be created.";
};

const generatedValue = async (type, branchId) => {
  const response = await generateProductIdentity({ type, branch_id: branchId || null });
  const value = response?.data?.value || response?.value || response?.data?.data?.value || "";
  if (!value) throw new Error("The server did not return a generated code.");
  return value;
};

// Same stock defaults as Stock Item Management, so a recipe ingredient can be
// received, issued to front stock and used by the recipe at the till.
export const buildIngredientPayload = (form, code, sku) => ({
  is_global: true,
  branch_id: null,
  category_id: form.category_id || null,
  sub_category_id: null,
  base_uom_id: form.base_uom_id,
  tax_id: null,
  name: form.name.trim(),
  code,
  sku,
  barcode: null,
  description: null,
  product_type: form.product_type,
  stock_usage_type: "turnover",
  default_issue_destination: form.product_type === "consumable" ? "expense" : "front",
  stock_tracking_enabled: true,
  batch_tracking_enabled: false,
  expiry_tracking_enabled: false,
  default_shelf_life_days: null,
  allow_negative_stock: false,
  allow_discount: false,
  taxable: false,
  is_active: true,
  reorder_level: 0,
  minimum_stock: 0,
  maximum_stock: 0,
  barcodes: [],
});

const emptyForm = (name = "") => ({
  name,
  product_type: "raw_material",
  base_uom_id: "",
  category_id: "",
  code: "",
});

export default function NewIngredientDialog({ open, initialName = "", branchId = "", onClose, onCreated }) {
  const [form, setForm] = useState(emptyForm(initialName));
  const [uoms, setUoms] = useState([]);
  const [categories, setCategories] = useState([]);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    if (!open) return;
    setForm(emptyForm(initialName));
    setError("");

    let active = true;
    Promise.all([getUoms(), getProductCategories({ per_page: 500 })])
      .then(([uomResponse, categoryResponse]) => {
        if (!active) return;
        setUoms(rowsOf(uomResponse));
        setCategories(rowsOf(categoryResponse));
      })
      .catch((loadError) => active && setError(errorText(loadError)));

    return () => {
      active = false;
    };
  }, [open, initialName]);

  const change = (event) => {
    const { name, value } = event.target;
    setForm((current) => ({ ...current, [name]: value }));
  };

  const save = async (event) => {
    event.preventDefault();
    if (!form.name.trim()) return setError("Enter the ingredient name.");
    if (!form.base_uom_id) return setError("Choose the unit the recipe measures it in.");

    try {
      setSaving(true);
      setError("");
      const ownCode = form.code.trim();
      const code = ownCode || await generatedValue("product_code", branchId);
      const sku = ownCode || await generatedValue("sku", branchId);
      const response = await createProduct(buildIngredientPayload(form, code, sku));
      const product = response?.data?.data || response?.data || response;
      const uom = uoms.find((item) => String(item.id) === String(form.base_uom_id)) || null;

      onCreated?.({
        ...product,
        product_type: product?.product_type || form.product_type,
        base_uom_id: product?.base_uom_id || form.base_uom_id,
        uom: product?.uom || uom,
      });
    } catch (saveError) {
      setError(errorText(saveError));
    } finally {
      setSaving(false);
    }
  };

  return (
    <EnterpriseFormModal
      open={open}
      title="New Ingredient"
      subtitle="Creates a stock item and adds it to this recipe"
      onClose={onClose}
      busy={saving}
      width="520px"
      zIndex={100015}
    >
      <form onSubmit={save} className="food-bom-page grid gap-3 p-4 text-[11px] text-[#1C2C2B]">
        <IngredientField label="Ingredient name">
          <input name="name" value={form.name} onChange={change} className="food-input" autoFocus maxLength={255} />
        </IngredientField>
        <div className="grid grid-cols-2 gap-3">
          <IngredientField label="Type">
            <select name="product_type" value={form.product_type} onChange={change} className="food-input">
              {INGREDIENT_TYPES.map(([value, label]) => (
                <option key={value} value={value}>{label}</option>
              ))}
            </select>
          </IngredientField>
          <IngredientField label="Unit of measure">
            <select name="base_uom_id" value={form.base_uom_id} onChange={change} className="food-input">
              <option value="">Select unit</option>
              {uoms.map((uom) => (
                <option key={uom.id} value={uom.id}>
                  {uom.name}{uom.code ? ` (${uom.code})` : ""}
                </option>
              ))}
            </select>
          </IngredientField>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <IngredientField label="Category (optional)">
            <select name="category_id" value={form.category_id} onChange={change} className="food-input">
              <option value="">No category</option>
              {categories.map((category) => (
                <option key={category.id} value={category.id}>{category.name}</option>
              ))}
            </select>
          </IngredientField>
          <IngredientField label="Code (optional)">
            <input name="code" value={form.code} onChange={change} className="food-input" placeholder="Generated if blank" maxLength={100} />
          </IngredientField>
        </div>
        {error && (
          <div role="alert" className="whitespace-pre-line border border-[#F0B4B4] bg-[#FDF1F1] px-3 py-2 text-[11px] text-[#B42318]">
            {error}
          </div>
        )}
        <div className="flex justify-end gap-1 border-t border-[#D5DDDD] pt-3">
          <button type="button" onClick={onClose} disabled={saving} className="h-8 border border-[#AAB5B5] bg-white px-3 font-semibold text-[#324443] disabled:opacity-50">
            Cancel
          </button>
          <button type="submit" disabled={saving} className="h-8 border border-[#067C7C] bg-[#069494] px-3 font-semibold text-white hover:bg-[#067C7C] disabled:opacity-50">
            {saving ? "Saving..." : "Create and add"}
          </button>
        </div>
      </form>
    </EnterpriseFormModal>
  );
}

function IngredientField({ label, children }) {
  return (
    <label className="block">
      <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">{label}</div>
      {children}
    </label>
  );
}

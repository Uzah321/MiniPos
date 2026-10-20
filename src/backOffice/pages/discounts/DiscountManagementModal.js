import React, { useEffect, useMemo, useState } from "react";
import {
  Plus,
  RefreshCw,
  X,
} from "lucide-react";
import FullScreenModal from "../../../components/FullScreenModal";
import EnterpriseFilterBar from "../../../components/EnterpriseFilterBar";
import EnterpriseRowActionMenu from "../../../components/EnterpriseRowActionMenu";
import EnterpriseConfirmModal from "../../../components/EnterpriseConfirmModal";
import {
  deleteDiscount,
  getDiscounts,
  getDiscountTypes,
  saveDiscount,
} from "../../../services/DiscountService";
import {
  getProducts,
  getProductCategories,
} from "../../../services/ProductService";
import { getRoles } from "../../../services/AuthenticationService";

const emptyForm = {
  code: "",
  name: "",
  description: "",
  discount_type_id: "",
  discount_basis: "percentage",
  value: "",
  max_discount_amount: "",
  min_sale_amount: "",
  min_quantity: "",
  applies_to_all_products: true,
  applies_to_all_categories: true,
  requires_customer: false,
  requires_coupon_code: false,
  coupon_code: "",
  usage_limit: "",
  per_customer_limit: "",
  starts_at: "",
  ends_at: "",
  requires_manager_approval: false,
  requires_reason: false,
  allow_stacking: false,
  priority: 100,
  is_active: true,
};

const getErrorMessage = (error, fallback) => {
  const data = error?.response?.data;

  if (data?.errors && typeof data.errors === "object") {
    return Object.values(data.errors).flat().join("\n");
  }

  return data?.message || data?.error || error?.message || fallback;
};

const normalizeRows = (response) => {
  const payload = response?.data ?? response;

  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data)) return payload.data;
  if (Array.isArray(payload?.data?.data)) return payload.data.data;
  if (Array.isArray(payload?.rows)) return payload.rows;
  if (Array.isArray(payload?.items)) return payload.items;

  return [];
};

const isActive = (row) =>
  row?.is_active !== false && row?.is_active !== 0 && row?.is_active !== "0";

const boolValue = (value) =>
  value === true ||
  value === 1 ||
  value === "1" ||
  String(value || "").toLowerCase() === "true";

const formatDateTimeForInput = (value) => (value ? String(value).slice(0, 16) : "");

const numberOrNull = (value) => {
  if (value === "" || value === null || value === undefined) return null;
  return Number(value || 0);
};

const discountValueLabel = (row) => {
  const value = Number(row?.value || 0);
  const basis = row?.discount_basis || "percentage";

  if (basis === "percentage") return `${value.toFixed(2)}%`;
  if (basis === "fixed_price") return `Fixed Price ${value.toFixed(2)}`;
  return value.toFixed(2);
};

const getTargetProduct = (row) => row?.product_display || row?.product || {};
const getTargetCategory = (row) => row?.category_display || row?.category || {};
const getTargetRole = (row) => row?.role_display || row?.role || {};

const getLabel = (row, type) => {
  if (!row) return "-";

  if (type === "product") {
    const product = getTargetProduct(row);
    return (
      row.display_name ||
      product.name ||
      row.product_name ||
      row.name ||
      row.description ||
      row.code ||
      row.sku ||
      row.product_id ||
      row.id ||
      "-"
    );
  }

  if (type === "category") {
    const category = getTargetCategory(row);
    return (
      row.display_name ||
      category.name ||
      row.category_name ||
      row.name ||
      row.description ||
      row.code ||
      row.category_id ||
      row.id ||
      "-"
    );
  }

  if (type === "role") {
    const role = getTargetRole(row);
    return (
      row.display_name ||
      role.name ||
      row.role_name ||
      row.name ||
      row.group ||
      row.description ||
      row.role_id ||
      row.id ||
      "-"
    );
  }

  return row.display_name || row.name || row.code || row.id || "-";
};

const getSubLabel = (row, type) => {
  if (!row) return "";

  if (type === "product") {
    const product = getTargetProduct(row);
    return [product.code || row.code, product.sku || row.sku, product.barcode || row.barcode]
      .filter(Boolean)
      .join(" / ");
  }

  if (type === "category") {
    const category = getTargetCategory(row);
    return [category.code || row.code, row.parent_name].filter(Boolean).join(" / ");
  }

  if (type === "role") {
    const role = getTargetRole(row);
    return [role.group || row.group, role.description || row.description]
      .filter(Boolean)
      .join(" / ");
  }

  return row.code || "";
};

const dedupeById = (rows = []) => {
  const map = new Map();

  rows.forEach((row) => {
    const id = row?.id || row?.product_id || row?.category_id || row?.role_id;
    if (id && !map.has(String(id))) map.set(String(id), row);
  });

  return Array.from(map.values());
};

const targetRowsFromApi = (rows = [], idKey, type) =>
  (Array.isArray(rows) ? rows : [])
    .map((row) => {
      const entity =
        type === "product"
          ? row.product_display || row.product
          : row.category_display || row.category;
      const id = row[idKey] || entity?.id || row.id;

      return {
        ...row,
        id,
        [idKey]: id,
        display_name: entity?.name || row.display_name || row.name || "",
        name: entity?.name || row.name || "",
        code: entity?.code || row.code || "",
        sku: entity?.sku || row.sku || "",
        barcode: entity?.barcode || row.barcode || "",
        type,
      };
    })
    .filter((row) => row.id);

const roleRowsFromApi = (rows = []) =>
  (Array.isArray(rows) ? rows : [])
    .map((row) => {
      const role = row.role_display || row.role || {};
      return {
        ...row,
        id: row.role_id || role.id || row.id,
        role_id: row.role_id || role.id || row.id,
        display_name: role.name || row.role_name || row.name || "",
        name: role.name || row.role_name || row.name || "",
        group: role.group || row.group || "",
        description: role.description || row.description || "",
        max_percentage: row.max_percentage ?? "",
        max_amount: row.max_amount ?? "",
        can_apply_without_manager: boolValue(row.can_apply_without_manager),
      };
    })
    .filter((row) => row.role_id);

export default function DiscountManagementModal({ open, onClose }) {
  const [rows, setRows] = useState([]);
  const [types, setTypes] = useState([]);
  const [loading, setLoading] = useState(false);
  const [notice, setNotice] = useState("");
  const [query, setQuery] = useState("");
  const [statusFilter, setStatusFilter] = useState("all");
  const [targetFilter, setTargetFilter] = useState("all");
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [deleteTarget, setDeleteTarget] = useState(null);

  const filteredRows = useMemo(() => {
    const search = query.trim().toLowerCase();

    return rows.filter((row) => {
      const active = isActive(row);
      const productTargeted = Array.isArray(row.product_targets) && row.product_targets.length > 0;
      const categoryTargeted = Array.isArray(row.category_targets) && row.category_targets.length > 0;
      const roleControlled = Array.isArray(row.role_permissions) && row.role_permissions.length > 0;

      const matchesSearch =
        !search ||
        String(row.code || "").toLowerCase().includes(search) ||
        String(row.name || "").toLowerCase().includes(search) ||
        String(row.coupon_code || "").toLowerCase().includes(search) ||
        String(row.type?.name || row.discount_type_name || "").toLowerCase().includes(search);

      const matchesStatus =
        statusFilter === "all" ||
        (statusFilter === "active" && active) ||
        (statusFilter === "inactive" && !active);

      const matchesTarget =
        targetFilter === "all" ||
        (targetFilter === "products" && productTargeted) ||
        (targetFilter === "categories" && categoryTargeted) ||
        (targetFilter === "roles" && roleControlled) ||
        (targetFilter === "global" && !productTargeted && !categoryTargeted);

      return matchesSearch && matchesStatus && matchesTarget;
    });
  }, [rows, query, statusFilter, targetFilter]);

  const stats = useMemo(() => {
    const active = rows.filter(isActive).length;
    const manager = rows.filter((row) => boolValue(row.requires_manager_approval)).length;
    const coupons = rows.filter((row) => row.coupon_code || boolValue(row.requires_coupon_code)).length;
    const roles = rows.filter((row) => Array.isArray(row.role_permissions) && row.role_permissions.length > 0).length;

    return {
      total: rows.length,
      active,
      inactive: rows.length - active,
      manager,
      coupons,
      roles,
    };
  }, [rows]);

  const load = async () => {
    try {
      setLoading(true);
      setNotice("");

      const [discountsRes, typesRes] = await Promise.all([
        getDiscounts({ per_page: 250 }),
        getDiscountTypes(),
      ]);

      setRows(normalizeRows(discountsRes));
      setTypes(Array.isArray(typesRes?.data) ? typesRes.data : normalizeRows(typesRes));
    } catch (error) {
      setNotice(getErrorMessage(error, "Failed to load discounts."));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (open) load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const openCreate = () => {
    setEditing(null);
    setFormOpen(true);
  };

  const openEdit = (row) => {
    setEditing(row);
    setFormOpen(true);
  };

  const remove = async (row) => {
    try {
      setLoading(true);
      setNotice("");

      const response = await deleteDiscount(row.id);
      setNotice(response?.message || "Discount deleted.");
      await load();
    } catch (error) {
      setNotice(getErrorMessage(error, "Failed to delete discount."));
    } finally {
      setLoading(false);
      setDeleteTarget(null);
    }
  };

  const onSaved = async (message) => {
    setFormOpen(false);
    setEditing(null);
    setNotice(message || "Discount saved successfully.");
    await load();
  };

  if (!open) return null;

  return (
    <FullScreenModal
      open={open}
      onClose={onClose}
      title="DISCOUNT SETTINGS"
      subtitle="Discount rules"
    >
      <div className="h-[calc(100vh-86px)] min-h-0 overflow-auto bg-[#F3F6F8] p-4 text-[#1C2C2B]">
        <div className="mx-auto flex max-w-[1500px] flex-col gap-4">
          <section className="border border-[#CBD6D6] bg-white shadow-sm">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-[#CFD9D8] px-4 py-3">
              <div>
                <div className="text-[15px] font-bold text-[#034040]">Discount rules</div>
              </div>
              <div className="flex items-center gap-2">
                <SmallAction onClick={load} disabled={loading} icon={RefreshCw} label={loading ? "Loading" : "Refresh"} />
                <SmallAction onClick={openCreate} icon={Plus} label="New Discount" primary />
              </div>
            </div>

            {notice && (
              <div className="whitespace-pre-line border-b border-[#FEDF89] bg-[#FFFAEB] px-4 py-2.5 text-[11px] font-semibold text-[#B54708]">
                {notice}
              </div>
            )}

            <EnterpriseFilterBar
              searchValue={query}
              onSearchChange={setQuery}
              searchLabel="Search discount rules"
              searchPlaceholder="Search name, code, coupon or type"
            >
              <Select label="Status" value={statusFilter} onChange={setStatusFilter}>
                <option value="all">All statuses</option>
                <option value="active">Active only</option>
                <option value="inactive">Inactive only</option>
              </Select>
              <Select label="Applies To" value={targetFilter} onChange={setTargetFilter}>
                <option value="all">Every target</option>
                <option value="global">All products</option>
                <option value="products">Selected products</option>
                <option value="categories">Selected categories</option>
                <option value="roles">Role controlled</option>
              </Select>
            </EnterpriseFilterBar>
          </section>

          <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
            <Metric label="All Rules" value={stats.total} />
            <Metric label="Active Now" value={stats.active} good />
            <Metric label="Inactive" value={stats.inactive} />
            <Metric label="Need Manager" value={stats.manager} warning />
            <Metric label="Coupon Rules" value={stats.coupons} warning />
            <Metric label="Role Limits" value={stats.roles} warning />
          </div>

          <section className="overflow-hidden border border-[#CBD6D6] bg-white shadow-sm">
            <div className="overflow-auto">
              <table className="w-full min-w-[980px] border-collapse text-left text-[11px]">
                <thead className="bg-[#004141] text-white">
                  <tr>
                    <GridTh>Discount</GridTh>
                    <GridTh>Value</GridTh>
                    <GridTh>Applies To</GridTh>
                    <GridTh>Controls</GridTh>
                    <GridTh>Availability</GridTh>
                    <GridTh>Status</GridTh>
                    <GridTh>More</GridTh>
                  </tr>
                </thead>
                <tbody>
                  {filteredRows.length === 0 ? (
                    <tr><td colSpan={7} className="px-4 py-16 text-center text-[12px] font-semibold text-[#627474]">{loading ? "Loading discounts..." : "No discount rules match these filters."}</td></tr>
                  ) : filteredRows.map((row, index) => {
                    const productCount = row.product_targets?.length || 0;
                    const categoryCount = row.category_targets?.length || 0;
                    const roleCount = row.role_permissions?.length || 0;
                    const global = productCount === 0 && categoryCount === 0;
                    const starts = row.starts_at ? new Date(row.starts_at).toLocaleDateString() : "Now";
                    const ends = row.ends_at ? new Date(row.ends_at).toLocaleDateString() : "No end date";

                    return (
                      <tr key={row.id || `${row.code}-${index}`} className="border-b border-[#E2E9E9] align-top hover:bg-[#F8FAFC]">
                        <GridTd><div className="font-bold text-[#034040]">{row.name || "Unnamed discount"}</div><div className="mt-1 text-[10px] text-[#627474]">{row.code || "NO CODE"} · {row.type?.name || row.discount_type_name || "Standard"}</div></GridTd>
                        <GridTd><div className="text-[14px] font-black text-[#057B7C]">{discountValueLabel(row)}</div><div className="mt-1 text-[10px] uppercase text-[#627474]">{String(row.discount_basis || "percentage").replaceAll("_", " ")}</div></GridTd>
                        <GridTd><div className="flex max-w-[260px] flex-wrap gap-1">{global && <Pill tone="good">All products</Pill>}{productCount > 0 && <Pill>{productCount} product{productCount === 1 ? "" : "s"}</Pill>}{categoryCount > 0 && <Pill>{categoryCount} categor{categoryCount === 1 ? "y" : "ies"}</Pill>}{roleCount > 0 && <Pill tone="warning">{roleCount} role limit{roleCount === 1 ? "" : "s"}</Pill>}</div></GridTd>
                        <GridTd><div className="flex max-w-[240px] flex-wrap gap-1">{row.requires_manager_approval && <Pill tone="danger">Manager approval</Pill>}{row.requires_reason && <Pill tone="warning">Reason required</Pill>}{row.requires_coupon_code && <Pill>Coupon: {row.coupon_code || "required"}</Pill>}{!row.requires_manager_approval && !row.requires_reason && !row.requires_coupon_code && <span className="text-[#627474]">Standard cashier flow</span>}</div></GridTd>
                        <GridTd><div className="font-semibold text-[#324443]">{starts} → {ends}</div><div className="mt-1 text-[10px] text-[#627474]">{row.usage_limit ? `${row.usage_count || 0} of ${row.usage_limit} uses` : "Unlimited uses"}</div></GridTd>
                        <GridTd>{isActive(row) ? <Pill tone="good">Active</Pill> : <Pill tone="danger">Inactive</Pill>}</GridTd>
                        <GridTd><EnterpriseRowActionMenu label={`More actions for ${row.name || row.code || "discount"}`} actions={[{ label: "Edit discount", onClick: () => openEdit(row) }, { label: "Delete discount", danger: true, onClick: () => setDeleteTarget(row) }]} /></GridTd>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </section>
        </div>

        <DiscountFormModal
          open={formOpen}
          editing={editing}
          types={types}
          onClose={() => {
            setFormOpen(false);
            setEditing(null);
          }}
          onSaved={onSaved}
        />
        <EnterpriseConfirmModal
          open={Boolean(deleteTarget)}
          title="Delete Discount"
          message={deleteTarget ? `Delete ${deleteTarget.name || deleteTarget.code || "this discount"}?` : ""}
          confirmLabel="Delete discount"
          busy={loading}
          onClose={() => !loading && setDeleteTarget(null)}
          onConfirm={() => deleteTarget && remove(deleteTarget)}
        />
      </div>
    </FullScreenModal>
  );
}

function DiscountFormModal({ open, editing, types, onClose, onSaved }) {
  const [form, setForm] = useState(emptyForm);
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState("");
  const [selectedProducts, setSelectedProducts] = useState([]);
  const [selectedCategories, setSelectedCategories] = useState([]);
  const [rolePermissions, setRolePermissions] = useState([]);
  const [picker, setPicker] = useState(null);

  useEffect(() => {
    if (!open) return;

    if (editing) {
      setForm({
        ...emptyForm,
        ...editing,
        discount_type_id: editing.discount_type_id || editing.type?.id || "",
        max_discount_amount: editing.max_discount_amount ?? "",
        min_sale_amount: editing.min_sale_amount ?? "",
        min_quantity: editing.min_quantity ?? "",
        usage_limit: editing.usage_limit ?? "",
        per_customer_limit: editing.per_customer_limit ?? "",
        starts_at: formatDateTimeForInput(editing.starts_at),
        ends_at: formatDateTimeForInput(editing.ends_at),
        is_active: isActive(editing),
      });
      setSelectedProducts(targetRowsFromApi(editing.product_targets, "product_id", "product"));
      setSelectedCategories(targetRowsFromApi(editing.category_targets, "category_id", "category"));
      setRolePermissions(roleRowsFromApi(editing.role_permissions));
    } else {
      setForm({ ...emptyForm, discount_type_id: types[0]?.id || "" });
      setSelectedProducts([]);
      setSelectedCategories([]);
      setRolePermissions([]);
    }

    setNotice("");
  }, [open, editing, types]);

  if (!open) return null;

  const set = (key, value) => setForm((prev) => ({ ...prev, [key]: value }));

  const addSelectedProduct = (item) => {
    const next = {
      ...item,
      display_name: getLabel(item, "product"),
      name: getLabel(item, "product"),
    };
    setSelectedProducts((prev) => dedupeById([...prev, next]));
  };

  const addSelectedCategory = (item) => {
    const next = {
      ...item,
      display_name: getLabel(item, "category"),
      name: getLabel(item, "category"),
    };
    setSelectedCategories((prev) => dedupeById([...prev, next]));
  };

  const addSelectedRole = (item) => {
    setRolePermissions((prev) =>
      dedupeById([
        ...prev,
        {
          ...item,
          id: item.id,
          role_id: item.id,
          display_name: getLabel(item, "role"),
          name: getLabel(item, "role"),
          max_percentage: "",
          max_amount: "",
          can_apply_without_manager: false,
        },
      ])
    );
  };

  const updateRolePermission = (roleId, key, value) => {
    setRolePermissions((prev) =>
      prev.map((row) => ((row.role_id || row.id) === roleId ? { ...row, [key]: value } : row))
    );
  };

  const buildPayload = () => {
    const productIds = selectedProducts.map((row) => row.id).filter(Boolean);
    const categoryIds = selectedCategories.map((row) => row.id).filter(Boolean);

    return {
      ...form,
      id: editing?.id,
      value: Number(form.value || 0),
      max_discount_amount: numberOrNull(form.max_discount_amount),
      min_sale_amount: numberOrNull(form.min_sale_amount),
      min_quantity: numberOrNull(form.min_quantity),
      usage_limit: numberOrNull(form.usage_limit),
      per_customer_limit: numberOrNull(form.per_customer_limit),
      priority: Number(form.priority || 100),
      applies_to_all_products: productIds.length === 0,
      applies_to_all_categories: categoryIds.length === 0,
      product_ids: productIds,
      category_ids: categoryIds,
      role_permissions: rolePermissions
        .filter((row) => row.role_id || row.id)
        .map((row) => ({
          role_id: row.role_id || row.id,
          max_percentage: numberOrNull(row.max_percentage),
          max_amount: numberOrNull(row.max_amount),
          can_apply_without_manager: Boolean(row.can_apply_without_manager),
        })),
    };
  };

  const submit = async (event) => {
    event.preventDefault();

    if (!form.code?.trim()) {
      setNotice("Code is required.");
      return;
    }

    if (!form.name?.trim()) {
      setNotice("Name is required.");
      return;
    }

    if (!form.discount_type_id) {
      setNotice("Discount type is required.");
      return;
    }

    if (form.discount_basis === "percentage" && Number(form.value || 0) > 100) {
      setNotice("A percentage discount cannot be greater than 100%.");
      return;
    }

    if (form.requires_coupon_code && !form.coupon_code?.trim()) {
      setNotice("Enter the coupon code that the cashier or customer must provide.");
      return;
    }

    try {
      setSaving(true);
      setNotice("");

      const response = await saveDiscount(buildPayload());
      onSaved?.(response?.message || (editing ? "Discount updated." : "Discount created."));
    } catch (error) {
      setNotice(getErrorMessage(error, "Failed to save discount."));
    } finally {
      setSaving(false);
    }
  };

  const targetSummary = selectedProducts.length || selectedCategories.length
    ? [selectedProducts.length ? `${selectedProducts.length} selected product${selectedProducts.length === 1 ? "" : "s"}` : null, selectedCategories.length ? `${selectedCategories.length} selected categor${selectedCategories.length === 1 ? "y" : "ies"}` : null].filter(Boolean).join(" and ")
    : "All products";

  return (
    <div className="fixed inset-0 z-[240] bg-black/60 p-2 sm:p-4">
      <form onSubmit={submit} className="mx-auto flex h-full max-w-[1500px] min-h-0 flex-col overflow-hidden border border-[#93A6A6] bg-white text-[#1C2C2B] shadow-2xl">
        <div className="flex min-h-14 shrink-0 items-center justify-between border-b-2 border-[#00BCBC] bg-[#004142] px-4 text-white">
          <div>
            <div className="text-[15px] font-bold">{editing ? "Edit discount rule" : "Create discount rule"}</div>
            <div className="mt-0.5 text-[10px] text-white/70">Complete the essentials first. Limits and role overrides are optional.</div>
          </div>
          <button type="button" onClick={onClose} aria-label="Close discount editor" className="flex h-9 w-9 items-center justify-center border border-white/25 bg-white/10 hover:bg-white/20"><X className="h-4 w-4" /></button>
        </div>

        {notice && <div className="shrink-0 whitespace-pre-line border-b border-[#FEDF89] bg-[#FFFAEB] px-4 py-2.5 text-[11px] font-semibold text-[#B54708]">{notice}</div>}

        <main className="min-h-0 flex-1 overflow-y-auto bg-[#F3F6F8] p-4">
          <div className="mx-auto grid max-w-[1420px] items-start gap-4 xl:grid-cols-[minmax(0,1fr)_310px]">
            <div className="grid gap-4">
              <Panel title="1. Name the discount">
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                  <Input label="Discount Name *" value={form.name} onChange={(value) => set("name", value)} className="xl:col-span-2" />
                  <Input label="Short Code *" value={form.code} onChange={(value) => set("code", value.toUpperCase())} />
                  <Select label="Discount Type *" value={form.discount_type_id} onChange={(value) => set("discount_type_id", value)}>
                    <option value="">Select a type</option>
                    {types.map((type) => <option key={type.id} value={type.id}>{type.name}</option>)}
                  </Select>
                  <label className="block md:col-span-2 xl:col-span-4">
                    <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">Description</div>
                    <textarea value={form.description || ""} onChange={(event) => set("description", event.target.value)} rows={2} className="w-full border border-[#AAB5B5] bg-white px-3 py-2 text-[11px] outline-none focus:border-[#069495]" placeholder="Optional note explaining when staff should use this discount" />
                  </label>
                </div>
              </Panel>

              <Panel title="2. Set the value and qualifying sale">
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                  <Select label="How to Calculate *" value={form.discount_basis} onChange={(value) => set("discount_basis", value)}>
                    <option value="percentage">Percentage off</option>
                    <option value="fixed_amount">Fixed amount off</option>
                    <option value="fixed_price">Set a fixed selling price</option>
                  </Select>
                  <Input label={form.discount_basis === "percentage" ? "Percentage *" : form.discount_basis === "fixed_price" ? "New Selling Price *" : "Amount Off *"} value={form.value} onChange={(value) => set("value", value)} type="number" />
                  <Input label="Maximum Discount" value={form.max_discount_amount} onChange={(value) => set("max_discount_amount", value)} type="number" />
                  <Input label="Minimum Sale Amount" value={form.min_sale_amount} onChange={(value) => set("min_sale_amount", value)} type="number" />
                  <Input label="Minimum Quantity" value={form.min_quantity} onChange={(value) => set("min_quantity", value)} type="number" />
                </div>
                <div className="mt-3 border-l-4 border-[#069495] bg-[#E8F7F5] px-3 py-2 text-[11px] text-[#005A5A]">
                  {form.discount_basis === "percentage" ? "Percentage is limited to 100%. Use Maximum Discount to cap the money saved." : form.discount_basis === "fixed_price" ? "The selected item will be sold at this fixed price when the rule qualifies." : "This exact amount is deducted from the qualifying line."}
                </div>
              </Panel>

              <Panel title="3. Choose where it applies">
                <div className="mb-3 text-[11px] text-[#627474]">Leave both lists empty to make the discount available for all products.</div>
                <div className="grid gap-3 lg:grid-cols-2">
                  <div className="border border-[#CFD9D8] bg-[#F8FAFC] p-3">
                    <TargetToolbar title="Products" count={selectedProducts.length} allText="All products" onClear={() => setSelectedProducts([])} onAdd={() => setPicker({ type: "product", title: "Select Products" })} />
                    <SelectedTable rows={selectedProducts} type="product" empty="No product restriction" onRemove={(id) => setSelectedProducts((prev) => prev.filter((row) => row.id !== id))} />
                  </div>
                  <div className="border border-[#CFD9D8] bg-[#F8FAFC] p-3">
                    <TargetToolbar title="Categories" count={selectedCategories.length} allText="All categories" onClear={() => setSelectedCategories([])} onAdd={() => setPicker({ type: "category", title: "Select Categories" })} />
                    <SelectedTable rows={selectedCategories} type="category" empty="No category restriction" onRemove={(id) => setSelectedCategories((prev) => prev.filter((row) => row.id !== id))} />
                  </div>
                </div>
              </Panel>

              <Panel title="4. Approval and checkout controls">
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                  <ControlCard title="Manager approval" description="A manager must approve before the discount is applied." checked={form.requires_manager_approval} onChange={(value) => set("requires_manager_approval", value)} />
                  <ControlCard title="Reason required" description="The cashier must record why the discount was given." checked={form.requires_reason} onChange={(value) => set("requires_reason", value)} />
                  <ControlCard title="Customer required" description="Only allow this rule when a customer is attached to the sale." checked={form.requires_customer} onChange={(value) => set("requires_customer", value)} />
                  <ControlCard title="Coupon required" description="Ask for a matching coupon code at checkout." checked={form.requires_coupon_code} onChange={(value) => set("requires_coupon_code", value)} />
                  <ControlCard title="Allow stacking" description="Permit this rule alongside another discount on the same sale." checked={form.allow_stacking} onChange={(value) => set("allow_stacking", value)} />
                  <ControlCard title="Rule active" description="Turn this off to keep the setup without offering it at checkout." checked={form.is_active} onChange={(value) => set("is_active", value)} />
                </div>
                {form.requires_coupon_code && <div className="mt-3 max-w-sm"><Input label="Required Coupon Code *" value={form.coupon_code} onChange={(value) => set("coupon_code", value.toUpperCase())} /></div>}
              </Panel>

              <details className="border border-[#CBD6D6] bg-white" open={Boolean(form.starts_at || form.ends_at || form.usage_limit || form.per_customer_limit)}>
                <summary className="cursor-pointer select-none border-b border-[#E2E9E9] bg-[#F8FAFC] px-4 py-3 text-[12px] font-bold text-[#034040]">Optional schedule and usage limits</summary>
                <div className="grid gap-3 p-4 md:grid-cols-2 xl:grid-cols-5">
                  <Input label="Starts At" value={form.starts_at} onChange={(value) => set("starts_at", value)} type="datetime-local" />
                  <Input label="Ends At" value={form.ends_at} onChange={(value) => set("ends_at", value)} type="datetime-local" />
                  <Input label="Total Usage Limit" value={form.usage_limit} onChange={(value) => set("usage_limit", value)} type="number" />
                  <Input label="Per Customer Limit" value={form.per_customer_limit} onChange={(value) => set("per_customer_limit", value)} type="number" />
                  <Input label="Priority" value={form.priority} onChange={(value) => set("priority", value)} type="number" />
                </div>
              </details>

              <details className="border border-[#CBD6D6] bg-white" open={rolePermissions.length > 0}>
                <summary className="cursor-pointer select-none border-b border-[#E2E9E9] bg-[#F8FAFC] px-4 py-3 text-[12px] font-bold text-[#034040]">Optional role limits ({rolePermissions.length})</summary>
                <div className="p-4">
                  <div className="mb-3 flex flex-wrap items-center justify-between gap-2"><div className="text-[11px] text-[#627474]">Add a role only when it needs its own percentage, amount, or approval exception.</div><SmallAction type="button" onClick={() => setPicker({ type: "role", title: "Select Roles" })} icon={Plus} label="Add Role Limit" primary /></div>
                  <div className="overflow-auto"><table className="w-full min-w-[900px] border-collapse text-left text-[11px]"><thead className="bg-[#0E4D4D] text-white"><tr><GridTh>Role</GridTh><GridTh>Role Details</GridTh><GridTh align="right">Maximum %</GridTh><GridTh align="right">Maximum Amount</GridTh><GridTh>Can Apply Without Manager</GridTh><GridTh aria-label="Actions">⋮</GridTh></tr></thead><tbody>{rolePermissions.length === 0 ? <tr><td colSpan={6} className="px-3 py-8 text-center text-[#627474]">No role-specific limits. Normal rule controls apply.</td></tr> : rolePermissions.map((row) => { const roleId = row.role_id || row.id; return <tr key={roleId} className="border-b border-[#CFD9D8]"><GridTd strong>{getLabel(row, "role")}</GridTd><GridTd>{getSubLabel(row, "role") || "-"}</GridTd><GridTd right><MiniInput type="number" value={row.max_percentage} onChange={(value) => updateRolePermission(roleId, "max_percentage", value)} /></GridTd><GridTd right><MiniInput type="number" value={row.max_amount} onChange={(value) => updateRolePermission(roleId, "max_amount", value)} /></GridTd><GridTd><input type="checkbox" checked={Boolean(row.can_apply_without_manager)} onChange={(event) => updateRolePermission(roleId, "can_apply_without_manager", event.target.checked)} /></GridTd><GridTd><button type="button" onClick={() => setRolePermissions((prev) => prev.filter((item) => (item.role_id || item.id) !== roleId))} className="border border-[#FDA29B] px-2 py-1 text-[9px] font-semibold uppercase text-[#B42318]">Remove</button></GridTd></tr>; })}</tbody></table></div>
                </div>
              </details>
            </div>

            <aside className="grid gap-3 xl:sticky xl:top-0">
              <div className="border border-[#CBD6D6] bg-white shadow-sm">
                <div className="border-b border-[#CFD9D8] bg-[#004141] px-4 py-3 text-[12px] font-bold text-white">Rule summary</div>
                <div className="grid gap-3 p-4 text-[11px]">
                  <div><div className="text-[10px] font-bold uppercase text-[#627474]">Discount</div><div className="mt-1 text-[16px] font-black text-[#034040]">{form.name || "Unnamed discount"}</div><div className="text-[#627474]">{form.code || "Code not set"}</div></div>
                  <div className="border-y border-[#E2E9E9] py-3"><div className="text-[10px] font-bold uppercase text-[#627474]">Customer receives</div><div className="mt-1 text-[22px] font-black text-[#057B7C]">{form.value === "" ? "—" : form.discount_basis === "percentage" ? `${Number(form.value || 0).toFixed(2)}% off` : form.discount_basis === "fixed_price" ? `Fixed price ${Number(form.value || 0).toFixed(2)}` : `${Number(form.value || 0).toFixed(2)} off`}</div></div>
                  <SummaryLine label="Applies to" value={targetSummary} />
                  <SummaryLine label="Approval" value={form.requires_manager_approval ? "Manager required" : "Normal cashier flow"} />
                  <SummaryLine label="Reason" value={form.requires_reason ? "Required" : "Optional"} />
                  <SummaryLine label="Coupon" value={form.requires_coupon_code ? (form.coupon_code || "Not entered") : "Not required"} />
                  <SummaryLine label="Status" value={form.is_active ? "Active" : "Inactive"} />
                </div>
              </div>
              <div className="border border-[#ABEFC6] bg-[#ECFDF3] p-4 text-[11px] leading-5 text-[#067647]">A simple discount only needs a name, code, type, value, and active status. Add restrictions only when the business rule requires them.</div>
            </aside>
          </div>
        </main>

        <div className="flex min-h-14 shrink-0 flex-wrap items-center justify-between gap-2 border-t border-[#CFD9D8] bg-white px-4 py-2">
          <div className="text-[10px] font-semibold uppercase text-[#627474]">{editing ? `Editing ${editing.code || editing.name}` : "New discount rule"}</div>
          <div className="flex gap-2"><button type="button" onClick={onClose} className="h-9 border border-[#AAB5B5] bg-white px-5 text-[11px] font-bold text-[#324443]">Cancel</button><button type="submit" disabled={saving} className="h-9 border border-[#069495] bg-[#069495] px-6 text-[11px] font-bold text-white disabled:opacity-60">{saving ? "Saving..." : editing ? "Save Changes" : "Create Discount"}</button></div>
        </div>
      </form>

      {picker && (
        <SearchPickerModal
          type={picker.type}
          title={picker.title}
          onClose={() => setPicker(null)}
          onSelect={(item) => {
            if (picker.type === "product") addSelectedProduct(item);
            if (picker.type === "category") addSelectedCategory(item);
            if (picker.type === "role") addSelectedRole(item);
          }}
        />
      )}
    </div>
  );
}

function SearchPickerModal({ type, title, onClose, onSelect }) {
  const [query, setQuery] = useState("");
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(false);
  const [notice, setNotice] = useState("");

  const search = async () => {
    try {
      setLoading(true);
      setNotice("");

      let response;

      if (type === "product") response = await getProducts({ q: query, search: query, per_page: 50, is_active: true });
      if (type === "category") response = await getProductCategories({ q: query, search: query, per_page: 50, is_active: true });
      if (type === "role") response = await getRoles({ q: query, search: query, per_page: 50 });

      setRows(normalizeRows(response));
    } catch (error) {
      setNotice(getErrorMessage(error, `Failed to search ${type}s.`));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    search();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [type]);

  return (
    <div className="fixed inset-0 z-[280] flex items-center justify-center bg-black/55 p-4">
      <div className="sx-large-modal-window border border-[#AAB5B5] bg-white shadow-2xl" role="dialog" aria-modal="true" aria-label={title}>
        <div className="flex h-10 shrink-0 items-center justify-between bg-[#004142] px-3 text-white">
          <div className="text-[12px] font-semibold uppercase">{title}</div>
          <button type="button" onClick={onClose} className="text-[20px] leading-none">×</button>
        </div>

        <div className="shrink-0 border-b border-[#CFD9D8] bg-[#F5F7FA] p-2">
          <div className="flex gap-2">
            <input
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              onKeyDown={(event) => {
                if (event.key === "Enter") search();
              }}
              className="h-8 flex-1 border border-[#AAB5B5] px-2 text-[11px] outline-none focus:border-[#069495]"
              placeholder={`Search ${type}s...`}
            />
            <button type="button" onClick={search} disabled={loading} className="h-8 border border-[#069495] bg-[#069495] px-4 text-[10px] font-semibold uppercase text-white disabled:opacity-60">
              {loading ? "Loading" : "Search"}
            </button>
          </div>
        </div>

        {notice && <div className="shrink-0 border-b border-[#FEDF89] bg-[#FFFAEB] px-3 py-2 text-[11px] font-semibold text-[#B54708]">{notice}</div>}

        <div className="min-h-0 flex-1 overflow-auto">
          <table className="w-full min-w-[720px] border-collapse text-left text-[11px]">
            <thead className="sticky top-0 bg-[#0E4D4D] text-white">
              <tr>
                <GridTh>Name</GridTh>
                <GridTh>Code / Details</GridTh>
                <GridTh>Action</GridTh>
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 ? (
                <tr><td colSpan={3} className="px-3 py-10 text-center text-[#627474]">{loading ? "Loading..." : "No records found."}</td></tr>
              ) : rows.map((row) => (
                <tr key={row.id} className="border-b border-[#CFD9D8] hover:bg-[#F8FAFC]">
                  <GridTd strong>{getLabel(row, type)}</GridTd>
                  <GridTd>{getSubLabel(row, type) || row.id}</GridTd>
                  <GridTd><button type="button" onClick={() => { onSelect(row); onClose(); }} className="border border-[#069495] bg-[#069495] px-3 py-1 text-[10px] font-semibold uppercase text-white">Select</button></GridTd>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}

function Panel({ title, children }) {
  return (
    <div className="border border-[#CFD9D8] bg-white p-3 shadow-sm">
      <div className="mb-3 border-b border-[#E4E8E8] pb-2 text-[12px] font-semibold uppercase text-[#324443]">
        {title}
      </div>
      {children}
    </div>
  );
}

function TargetToolbar({ title, count, allText, onClear, onAdd }) {
  return (
    <div className="mb-2 flex items-center justify-between gap-2">
      <div>
        <div className="text-[11px] font-semibold uppercase text-[#324443]">{title}</div>
        <div className="text-[10px] text-[#627474]">{count ? `${count} selected` : allText}</div>
      </div>
      <div className="flex gap-1">
        <button type="button" onClick={onClear} className="h-8 border border-[#AAB5B5] bg-white px-3 text-[10px] font-semibold uppercase text-[#324443]">All</button>
        <button type="button" onClick={onAdd} className="h-8 border border-[#069495] bg-[#069495] px-3 text-[10px] font-semibold uppercase text-white">Add</button>
      </div>
    </div>
  );
}

function SelectedTable({ rows, type, empty, onRemove }) {
  return (
    <table className="w-full border-collapse text-left text-[11px]">
      <thead className="bg-[#0E4D4D] text-white">
        <tr>
          <GridTh>Name</GridTh>
          <GridTh>Details</GridTh>
          <GridTh aria-label="Actions">⋮</GridTh>
        </tr>
      </thead>
      <tbody>
        {rows.length === 0 ? (
          <tr><td colSpan={3} className="px-3 py-8 text-center text-[#627474]">{empty}</td></tr>
        ) : rows.map((row) => (
          <tr key={row.id} className="border-b border-[#CFD9D8]">
            <GridTd strong>{getLabel(row, type)}</GridTd>
            <GridTd>{getSubLabel(row, type) || row.id}</GridTd>
            <GridTd><button type="button" onClick={() => onRemove(row.id)} className="border border-[#FDA29B] px-2 py-1 text-[9px] font-semibold uppercase text-[#B42318]">Remove</button></GridTd>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

function Metric({ label, value, good = false, warning = false }) {
  const color = good ? "text-[#067C7C]" : warning ? "text-[#B54708]" : "text-[#1C2C2B]";
  return (
    <div className="border border-[#CFD9D8] bg-white px-2 py-2">
      <div className="text-[9px] font-semibold uppercase text-[#627474]">{label}</div>
      <div className={`mt-[1px] text-[15px] font-bold ${color}`}>{value}</div>
    </div>
  );
}

function SummaryLine({ label, value }) {
  return (
    <div className="flex items-center justify-between gap-2 border-b border-[#EEF2F2] py-1 last:border-b-0">
      <span>{label}</span>
      <span className="font-semibold text-[#1C2C2B]">{value}</span>
    </div>
  );
}

function GridTh({ children, align = "left", ...props }) {
  return (
    <th {...props} className={`border-r border-white/10 px-2 py-2 text-${align} text-[10px] font-semibold uppercase tracking-wide last:border-r-0`}>
      {children}
    </th>
  );
}

function GridTd({ children, right = false, strong = false }) {
  return (
    <td className={`border-r border-[#CFD9D8] px-2 py-2 align-top last:border-r-0 ${right ? "text-right" : "text-left"} ${strong ? "font-semibold text-[#0C1B1B]" : "text-[#324443]"}`}>
      {children}
    </td>
  );
}

function Pill({ children, tone = "default" }) {
  const style =
    tone === "good"
      ? "border-[#ABEFC6] bg-[#ECFDF3] text-[#067647]"
      : tone === "danger"
        ? "border-[#FDA29B] bg-[#FEF3F2] text-[#B42318]"
        : tone === "warning"
          ? "border-[#FEDF89] bg-[#FFFAEB] text-[#B54708]"
          : "border-[#CFD9D8] bg-[#F8FAFC] text-[#324443]";

  return <span className={`inline-flex border px-2 py-[2px] text-[9px] font-semibold uppercase ${style}`}>{children}</span>;
}

function SmallAction({ label, icon: Icon, onClick, disabled = false, primary = false, type = "button" }) {
  return (
    <button
      type={type}
      onClick={onClick}
      disabled={disabled}
      className={`inline-flex h-8 items-center gap-1 border px-3 text-[10px] font-semibold uppercase disabled:opacity-60 ${
        primary
          ? "border-[#069495] bg-[#069495] text-white"
          : "border-[#AAB5B5] bg-white text-[#324443] hover:bg-[#F8FAFC]"
      }`}
    >
      {Icon && <Icon className="h-3.5 w-3.5" />}
      {label}
    </button>
  );
}

function Input({ label, value, onChange, type = "text", className = "" }) {
  return (
    <label className={`block ${className}`}>
      <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">{label}</div>
      <input
        type={type}
        value={value ?? ""}
        onChange={(event) => onChange(event.target.value)}
        className="h-8 w-full border border-[#AAB5B5] bg-white px-2 text-[11px] outline-none focus:border-[#069495]"
      />
    </label>
  );
}

function MiniInput({ value, onChange, type = "text" }) {
  return (
    <input
      type={type}
      value={value ?? ""}
      onChange={(event) => onChange(event.target.value)}
      className="h-7 w-full border border-[#AAB5B5] bg-white px-2 text-right text-[11px] outline-none focus:border-[#069495]"
    />
  );
}

function Select({ label, value, onChange, children }) {
  return (
    <label className="block">
      <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">{label}</div>
      <select
        value={value ?? ""}
        onChange={(event) => onChange(event.target.value)}
        className="h-8 w-full border border-[#AAB5B5] bg-white px-2 text-[11px] outline-none focus:border-[#069495]"
      >
        {children}
      </select>
    </label>
  );
}

function ControlCard({ title, description, checked, onChange }) {
  return (
    <label className={`flex cursor-pointer items-start gap-3 border p-3 transition-colors ${checked ? "border-[#069495] bg-[#E8F7F5]" : "border-[#CFD9D8] bg-white hover:border-[#93A6A6]"}`}>
      <input className="mt-0.5 h-4 w-4 accent-[#069495]" type="checkbox" checked={Boolean(checked)} onChange={(event) => onChange(event.target.checked)} />
      <span>
        <span className="block text-[11px] font-bold text-[#324443]">{title}</span>
        <span className="mt-1 block text-[10px] leading-4 text-[#627474]">{description}</span>
      </span>
    </label>
  );
}

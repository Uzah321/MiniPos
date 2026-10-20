import React, { useCallback, useEffect, useMemo, useState } from "react";
import {
  Building2,
  Image as ImageIcon,
  MapPin,
  Monitor,
  Plus,
  RefreshCw,
  Store,
  Tags,
  Upload,
} from "lucide-react";
import FullScreenModal from "../../../components/FullScreenModal";
import EnterpriseFormModal from "../../../components/EnterpriseFormModal";
import EnterpriseFilterBar from "../../../components/EnterpriseFilterBar";
import EnterpriseRowActionMenu from "../../../components/EnterpriseRowActionMenu";
import EnterpriseConfirmModal from "../../../components/EnterpriseConfirmModal";
import {
  createManagedHierarchyRecord,
  deleteManagedHierarchyRecord,
  getManagedHierarchyRecords,
  updateManagedHierarchyRecord,
} from "../../../services/EnterpriseHierarchyService";
import { publicAssetUrl } from "../../../utils/publicAssetUrl";

const inputClass = "h-9 w-full border border-[#ACB8B8] bg-white px-2 text-[12px] outline-none focus:border-[#069494]";
const primaryButton = "inline-flex h-9 items-center justify-center gap-1.5 bg-[#069494] px-4 text-[10px] font-black uppercase tracking-wide text-white hover:bg-[#057B7B] disabled:cursor-not-allowed disabled:opacity-40";
const secondaryButton = "inline-flex h-9 items-center justify-center gap-1.5 border border-[#ACB8B8] bg-white px-3 text-[10px] font-black uppercase tracking-wide text-[#324444] hover:border-[#069494] hover:text-[#006B6B] disabled:cursor-not-allowed disabled:opacity-40";

const sectionConfiguration = {
  countries: {
    singular: "Country",
    title: "Country Management",
    icon: MapPin,
    fileField: "image",
  },
  brands: {
    singular: "Brand",
    title: "Brand Management",
    icon: Tags,
    fileField: "logo",
  },
  complexes: {
    singular: "Complex",
    title: "Complex Management",
    icon: Building2,
  },
  shops: {
    singular: "Shop",
    title: "Shop Management",
    icon: Store,
  },
  tills: {
    singular: "Till",
    title: "Till Management",
    icon: Monitor,
  },
};

const valueId = (value) => String(value?.id || value?.uuid || "");
const entityName = (value, fallback = "—") => (
  value?.name
  || value?.company_name
  || value?.brand_name
  || value?.complex_name
  || value?.shop_name
  || value?.branch_name
  || value?.till_name
  || fallback
);
const entityCode = (value, fallback = "—") => (
  value?.code
  || value?.company_code
  || value?.brand_code
  || value?.complex_code
  || value?.shop_code
  || value?.branch_code
  || value?.till_code
  || fallback
);

export const extractManagementRows = (response) => {
  const candidates = [
    response?.data?.data,
    response?.data,
    response?.items?.data,
    response?.items,
    response,
  ];
  return candidates.find(Array.isArray) || [];
};

export const extractManagementPagination = (response) => {
  const paginator = response?.data && !Array.isArray(response.data)
    ? response.data
    : response;
  return {
    page: Number(paginator?.current_page || 1),
    lastPage: Number(paginator?.last_page || 1),
    total: Number(paginator?.total || extractManagementRows(response).length),
  };
};

const apiMessage = (error, fallback) => (
  error?.response?.data?.message
  || error?.response?.data?.error
  || (error?.response?.data?.errors
    ? Object.values(error.response.data.errors).flat().join(" ")
    : "")
  || error?.displayMessage
  || error?.message
  || fallback
);

const relationId = (entity, relation) => String(
  entity?.[`${relation}_id`]
  || entity?.[relation]?.id
  || entity?.shop?.[`${relation}_id`]
  || entity?.shop?.[relation]?.id
  || "",
);

const emptyForm = (section, defaults = {}) => {
  const shared = { code: "", name: "", status: "active" };
  if (section === "countries") {
    return {
      ...shared,
      iso_alpha_2: "",
      iso_alpha_3: "",
      currency_code: "",
      timezone: "Africa/Harare",
      locale: "",
      is_active: true,
    };
  }
  if (section === "brands") return { ...shared, company_id: defaults.company_id || "", description: "" };
  if (section === "complexes") {
    return {
      ...shared,
      company_id: defaults.company_id || "",
      address: "",
      city: "",
      timezone: "Africa/Harare",
    };
  }
  if (section === "shops") {
    return {
      ...shared,
      company_id: defaults.company_id || "",
      brand_id: defaults.brand_id || "",
      complex_id: defaults.complex_id || "",
      address: "",
      city: "",
      timezone: "Africa/Harare",
      pos_login_mode: "code",
    };
  }
  return {
    company_id: defaults.company_id || "",
    brand_id: defaults.brand_id || "",
    complex_id: defaults.complex_id || "",
    shop_id: defaults.shop_id || "",
    till_code: "",
    till_name: "",
    device_label: "",
    is_active: true,
  };
};

const formFromEntity = (section, entity) => {
  if (section === "countries") {
    return {
      ...emptyForm(section),
      code: entityCode(entity, ""),
      name: entityName(entity, ""),
      iso_alpha_2: entity?.iso_alpha_2 || "",
      iso_alpha_3: entity?.iso_alpha_3 || "",
      currency_code: entity?.currency_code || "",
      timezone: entity?.timezone || "Africa/Harare",
      locale: entity?.locale || "",
      is_active: entity?.is_active !== false && entity?.is_active !== 0,
    };
  }

  if (section === "tills") {
    return {
      ...emptyForm(section),
      company_id: relationId(entity, "company"),
      brand_id: relationId(entity, "brand"),
      complex_id: relationId(entity, "complex"),
      shop_id: String(entity?.shop_id || entity?.shop?.id || ""),
      till_code: entityCode(entity, ""),
      till_name: entityName(entity, ""),
      device_label: entity?.device_label || "",
      is_active: entity?.is_active !== false && entity?.is_active !== 0,
    };
  }

  return {
    ...emptyForm(section),
    company_id: relationId(entity, "company"),
    brand_id: relationId(entity, "brand"),
    complex_id: relationId(entity, "complex"),
    code: entityCode(entity, ""),
    name: entityName(entity, ""),
    description: entity?.description || "",
    address: entity?.address || entity?.location_address || "",
    city: entity?.city || "",
    timezone: entity?.timezone || "Africa/Harare",
    pos_login_mode: entity?.pos_login_mode || "code",
    status: entity?.status || "active",
  };
};

const imageSource = (entity, section) => {
  if (!entity) return "";
  if (section === "countries") return publicAssetUrl(entity.image_path || entity.image_url || entity.image);
  return publicAssetUrl(entity.logo_path || entity.logo_url || entity.logo);
};

const selectedOption = (items, id) => (
  items.find((item) => valueId(item) === String(id || "")) || null
);

const Field = ({ label, required = false, children, span = "" }) => (
  <label className={`block ${span}`}>
    <span className="mb-1 block text-[10px] font-black uppercase text-[#465858]">
      {label}{required ? " *" : ""}
    </span>
    {children}
  </label>
);

function ImageFileField({ label, preview, existing, onSelect, onRemove }) {
  const source = preview || existing;
  const inputId = `structure-image-${label.toLowerCase().replace(/\s+/g, "-")}`;
  return (
    <div className="md:col-span-2">
      <span className="mb-1 block text-[10px] font-black uppercase text-[#465858]">{label}</span>
      <div className="flex min-h-[110px] items-center gap-3 border border-[#CBD6D6] bg-[#F8FAFC] p-3">
        <div className="flex h-20 w-24 shrink-0 items-center justify-center overflow-hidden border border-[#CBD6D6] bg-white">
          {source
            ? <img src={source} alt={`${label} preview`} className="h-full w-full object-contain" />
            : <ImageIcon size={24} className="text-[#93A6A6]" />}
        </div>
        <div className="flex flex-wrap gap-2">
          <input
            id={inputId}
            type="file"
            accept="image/png,image/jpeg,image/jpg,image/webp"
            className="hidden"
            onChange={onSelect}
          />
          <label htmlFor={inputId} className={`${secondaryButton} cursor-pointer`}><Upload size={13} /> Select Image</label>
          {preview && <button type="button" onClick={onRemove} className={`${secondaryButton} text-red-700 hover:border-red-500 hover:text-red-700`}>Clear Selection</button>}
        </div>
      </div>
    </div>
  );
}

function ParentFilters({ section, filters, setFilters, lookups, loadingLookups }) {
  if (section === "countries") return null;

  const updateCompany = (companyId) => {
    setFilters({ company_id: companyId, brand_id: "", complex_id: "", shop_id: "" });
  };

  return (
    <>
      <Field label="Company">
        <select aria-label="Filter by company" className={inputClass} value={filters.company_id} onChange={(event) => updateCompany(event.target.value)} disabled={loadingLookups}>
          <option value="">All companies</option>
          {lookups.companies.map((company) => <option key={valueId(company)} value={valueId(company)}>{entityName(company)}</option>)}
        </select>
      </Field>

      {section === "shops" || section === "tills" ? <Field label="Brand">
        <select aria-label="Filter by brand" className={inputClass} value={filters.brand_id} onChange={(event) => setFilters((current) => ({ ...current, brand_id: event.target.value, shop_id: "" }))} disabled={!filters.company_id || loadingLookups}>
          <option value="">All brands</option>
          {lookups.brands.map((brand) => <option key={valueId(brand)} value={valueId(brand)}>{entityName(brand)}</option>)}
        </select>
      </Field> : null}

      {section === "shops" || section === "tills" ? <Field label="Complex">
        <select aria-label="Filter by complex" className={inputClass} value={filters.complex_id} onChange={(event) => setFilters((current) => ({ ...current, complex_id: event.target.value, shop_id: "" }))} disabled={!filters.company_id || loadingLookups}>
          <option value="">All complexes</option>
          {lookups.complexes.map((complex) => <option key={valueId(complex)} value={valueId(complex)}>{entityName(complex)}</option>)}
        </select>
      </Field> : null}

      {section === "tills" ? <Field label="Shop">
        <select aria-label="Filter by shop" className={inputClass} value={filters.shop_id} onChange={(event) => setFilters((current) => ({ ...current, shop_id: event.target.value }))} disabled={!filters.company_id || loadingLookups}>
          <option value="">All shops</option>
          {lookups.shops.map((shop) => <option key={valueId(shop)} value={valueId(shop)}>{entityName(shop)}</option>)}
        </select>
      </Field> : null}
    </>
  );
}

function EntityTable({ section, rows, loading, onEdit, onDelete, onOpenSettings }) {
  const countValue = (entity, name) => entity?.[`${name}_count`] ?? entity?.[`total_${name}`] ?? "—";
  const statusValue = (entity) => {
    if (section === "countries") return entity?.is_active === false || entity?.is_active === 0 ? "Inactive" : "Active";
    return String(entity?.status || (entity?.is_active === false ? "inactive" : "active"));
  };
  const relationName = (entity, relation, fallback = "—") => entityName(entity?.[relation], entity?.[`${relation}_name`] || fallback);
  const columns = {
    countries: ["Image", "Country", "Code", "Currency", "Timezone", "Status"],
    brands: ["Logo", "Brand", "Code", "Company", "Shops", "Status"],
    complexes: ["Complex", "Code", "Company", "City", "Shops", "Status"],
    shops: ["Shop", "Code", "Brand", "Complex", "City", "Tills", "Status"],
    tills: ["Till", "Code", "Shop", "Brand", "Complex", "Status"],
  }[section];

  const cellsFor = (entity) => {
    if (section === "countries") return [
      imageSource(entity, section) ? <img src={imageSource(entity, section)} alt="" className="h-8 w-11 object-contain" /> : <ImageIcon size={17} className="text-[#93A6A6]" />,
      entityName(entity), entityCode(entity), entity.currency_code || "—", entity.timezone || "—", statusValue(entity),
    ];
    if (section === "brands") return [
      imageSource(entity, section) ? <img src={imageSource(entity, section)} alt="" className="h-8 w-11 object-contain" /> : <ImageIcon size={17} className="text-[#93A6A6]" />,
      entityName(entity), entityCode(entity), relationName(entity, "company"), countValue(entity, "shops"), statusValue(entity),
    ];
    if (section === "complexes") return [entityName(entity), entityCode(entity), relationName(entity, "company"), entity.city || "—", countValue(entity, "shops"), statusValue(entity)];
    if (section === "shops") return [entityName(entity), entityCode(entity), relationName(entity, "brand"), relationName(entity, "complex"), entity.city || "—", countValue(entity, "tills"), statusValue(entity)];
    return [
      entityName(entity),
      entityCode(entity),
      relationName(entity, "shop"),
      relationName(entity, "brand", relationName(entity?.shop, "brand")),
      relationName(entity, "complex", relationName(entity?.shop, "complex")),
      statusValue(entity),
    ];
  };

  return (
    <div className="min-h-0 flex-1 overflow-auto bg-white">
      <table className="w-full min-w-[820px] border-collapse text-left text-[11px]">
        <thead className="sticky top-0 z-10 bg-[#004142] text-[9px] uppercase tracking-wide text-white">
          <tr>{columns.map((column) => <th key={column} className="px-3 py-2.5 font-black">{column}</th>)}<th className="w-[54px] px-3 py-2.5 text-center font-black">More</th></tr>
        </thead>
        <tbody>
          {rows.map((entity) => (
            <tr key={valueId(entity)} className="border-b border-[#E2E9E9] hover:bg-[#F8FAFC]">
              {cellsFor(entity).map((cell, index) => <td key={`${valueId(entity)}-${columns[index]}`} className={`px-3 py-2 ${index === 1 || (index === 0 && !["countries", "brands"].includes(section)) ? "font-semibold text-[#1C2C2C]" : "text-[#637877]"}`}>{cell}</td>)}
              <td className="px-3 py-2 text-center">
                <EnterpriseRowActionMenu
                  label={`More actions for ${entityName(entity)}`}
                  actions={[
                    { label: `Edit ${configurationLabel(section)}`, onClick: () => onEdit(entity) },
                    section === "shops" && onOpenSettings
                      ? { label: "Open settings", onClick: () => onOpenSettings(entity) }
                      : null,
                    { label: `Delete ${configurationLabel(section)}`, danger: true, onClick: () => onDelete(entity) },
                  ]}
                />
              </td>
            </tr>
          ))}
          {!loading && rows.length === 0 && <tr><td colSpan={columns.length + 1} className="p-12 text-center text-[12px] text-[#637877]">No records found.</td></tr>}
          {loading && <tr><td colSpan={columns.length + 1} className="p-12 text-center text-[12px] text-[#637877]">Loading…</td></tr>}
        </tbody>
      </table>
    </div>
  );
}

const configurationLabel = (section) => sectionConfiguration[section]?.singular || "record";

export default function HospitalityStructureManagementModal({
  open,
  onClose,
  section = "countries",
  isPlatformAdmin = false,
  onOpenShopSettings,
}) {
  const configuration = sectionConfiguration[section] || sectionConfiguration.countries;
  const SectionIcon = configuration.icon;
  const [rows, setRows] = useState([]);
  const [pagination, setPagination] = useState({ page: 1, lastPage: 1, total: 0 });
  const [filters, setFilters] = useState({ company_id: "", brand_id: "", complex_id: "", shop_id: "" });
  const [lookups, setLookups] = useState({ companies: [], brands: [], complexes: [], shops: [] });
  const [query, setQuery] = useState("");
  const [loading, setLoading] = useState(false);
  const [loadingLookups, setLoadingLookups] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [editor, setEditor] = useState(null);
  const [form, setForm] = useState(emptyForm(section));
  const [imageFile, setImageFile] = useState(null);
  const [imagePreview, setImagePreview] = useState("");
  const [deleteTarget, setDeleteTarget] = useState(null);

  const lookup = useCallback(async (resource, params = {}) => {
    const first = await getManagedHierarchyRecords(resource, { per_page: 100, page: 1, ...params });
    const rows = [...extractManagementRows(first)];
    const { lastPage } = extractManagementPagination(first);
    for (let start = 2; start <= lastPage; start += 5) {
      const pages = Array.from(
        { length: Math.min(5, lastPage - start + 1) },
        (_, index) => start + index,
      );
      const responses = await Promise.all(pages.map((page) => (
        getManagedHierarchyRecords(resource, { per_page: 100, page, ...params })
      )));
      responses.forEach((response) => rows.push(...extractManagementRows(response)));
    }
    return rows;
  }, []);

  const loadRows = useCallback(async (page = 1) => {
    if (!open || !isPlatformAdmin) return;
    setLoading(true);
    setError("");
    try {
      const params = { page, per_page: 100 };
      if (query.trim()) params.q = query.trim();
      if (section !== "countries" && filters.company_id) params.company_id = filters.company_id;
      if (["shops", "tills"].includes(section) && filters.brand_id) params.brand_id = filters.brand_id;
      if (["shops", "tills"].includes(section) && filters.complex_id) params.complex_id = filters.complex_id;
      if (section === "tills" && filters.shop_id) params.shop_id = filters.shop_id;
      const response = await getManagedHierarchyRecords(section, params);
      setRows(extractManagementRows(response));
      setPagination(extractManagementPagination(response));
    } catch (loadError) {
      setRows([]);
      setError(apiMessage(loadError, `Unable to load ${configuration.title.toLowerCase()}.`));
    } finally {
      setLoading(false);
    }
  }, [configuration.title, filters, isPlatformAdmin, open, query, section]);

  useEffect(() => {
    if (!open || !isPlatformAdmin) return;
    setRows([]);
    setQuery("");
    setFilters({ company_id: "", brand_id: "", complex_id: "", shop_id: "" });
    setLookups({ companies: [], brands: [], complexes: [], shops: [] });
    setEditor(null);
    setError("");
    setNotice("");
    setImageFile(null);
    setImagePreview("");
    setDeleteTarget(null);
    if (section !== "countries") {
      setLoadingLookups(true);
      lookup("companies")
        .then((companies) => setLookups((current) => ({ ...current, companies })))
        .catch((lookupError) => setError(apiMessage(lookupError, "Unable to load companies.")))
        .finally(() => setLoadingLookups(false));
    }
  }, [isPlatformAdmin, lookup, open, section]);

  useEffect(() => {
    if (open && isPlatformAdmin) loadRows(1);
    // Explicit dependencies are represented by loadRows.
  }, [isPlatformAdmin, loadRows, open]);

  useEffect(() => {
    if (!open || !filters.company_id || !["shops", "tills"].includes(section)) {
      setLookups((current) => ({ ...current, brands: [], complexes: [], shops: [] }));
      return;
    }
    let active = true;
    setLoadingLookups(true);
    Promise.all([
      lookup("brands", { company_id: filters.company_id }),
      lookup("complexes", { company_id: filters.company_id }),
    ]).then(([brands, complexes]) => {
      if (!active) return;
      setLookups((current) => ({ ...current, brands, complexes }));
    }).catch((lookupError) => {
      if (active) setError(apiMessage(lookupError, "Unable to load company structure."));
    }).finally(() => {
      if (active) setLoadingLookups(false);
    });
    return () => { active = false; };
  }, [filters.company_id, lookup, open, section]);

  useEffect(() => {
    if (!open || section !== "tills" || !filters.company_id) return;
    let active = true;
    setLoadingLookups(true);
    lookup("shops", {
      company_id: filters.company_id,
      ...(filters.brand_id ? { brand_id: filters.brand_id } : {}),
      ...(filters.complex_id ? { complex_id: filters.complex_id } : {}),
    }).then((shops) => {
      if (active) setLookups((current) => ({ ...current, shops }));
    }).catch((lookupError) => {
      if (active) setError(apiMessage(lookupError, "Unable to load shops."));
    }).finally(() => {
      if (active) setLoadingLookups(false);
    });
    return () => { active = false; };
  }, [filters.brand_id, filters.company_id, filters.complex_id, lookup, open, section]);

  useEffect(() => () => {
    if (imagePreview.startsWith("blob:")) URL.revokeObjectURL(imagePreview);
  }, [imagePreview]);

  const loadEditorRelations = async (companyId, sourceSection = section) => {
    if (!companyId || !["shops", "tills"].includes(sourceSection)) return;
    setLoadingLookups(true);
    try {
      const [brands, complexes, shops] = await Promise.all([
        lookup("brands", { company_id: companyId }),
        lookup("complexes", { company_id: companyId }),
        sourceSection === "tills" ? lookup("shops", { company_id: companyId }) : Promise.resolve([]),
      ]);
      setLookups((current) => ({ ...current, brands, complexes, shops }));
    } catch (lookupError) {
      setError(apiMessage(lookupError, "Unable to load parent records."));
    } finally {
      setLoadingLookups(false);
    }
  };

  const openCreate = async () => {
    const next = emptyForm(section, filters);
    setEditor({ entity: null });
    setForm(next);
    setImageFile(null);
    setImagePreview("");
    setError("");
    if (next.company_id) await loadEditorRelations(next.company_id);
  };

  const openEdit = async (entity) => {
    const next = formFromEntity(section, entity);
    setEditor({ entity });
    setForm(next);
    setImageFile(null);
    setImagePreview("");
    setError("");
    if (next.company_id) await loadEditorRelations(next.company_id);
  };

  const selectImage = (event) => {
    const file = event.target.files?.[0];
    event.target.value = "";
    if (!file) return;
    if (!["image/png", "image/jpeg", "image/webp"].includes(file.type)) {
      setError("Select a PNG, JPG or WEBP image.");
      return;
    }
    if (file.size > 4 * 1024 * 1024) {
      setError("The image must be 4 MB or smaller.");
      return;
    }
    setImageFile(file);
    setImagePreview(URL.createObjectURL(file));
    setError("");
  };

  const onFormCompanyChange = async (companyId) => {
    setForm((current) => ({ ...current, company_id: companyId, brand_id: "", complex_id: "", shop_id: "" }));
    if (companyId) await loadEditorRelations(companyId);
  };

  const onTillShopChange = (shopId) => {
    const shop = selectedOption(lookups.shops, shopId);
    setForm((current) => ({
      ...current,
      shop_id: shopId,
      brand_id: relationId(shop, "brand"),
      complex_id: relationId(shop, "complex"),
      company_id: relationId(shop, "company") || current.company_id,
    }));
  };

  const selectedTillShop = useMemo(() => selectedOption(lookups.shops, form.shop_id), [form.shop_id, lookups.shops]);
  const selectedTillBrand = selectedOption(lookups.brands, form.brand_id) || selectedTillShop?.brand;
  const selectedTillComplex = selectedOption(lookups.complexes, form.complex_id) || selectedTillShop?.complex;

  const buildPayload = () => {
    let payload;
    if (section === "countries") {
      payload = {
        code: form.code.trim().toUpperCase(),
        name: form.name.trim(),
        iso_alpha_2: form.iso_alpha_2.trim().toUpperCase(),
        iso_alpha_3: form.iso_alpha_3.trim().toUpperCase(),
        currency_code: form.currency_code.trim().toUpperCase(),
        timezone: form.timezone,
        locale: form.locale.trim(),
        is_active: form.is_active,
      };
    } else if (section === "brands") {
      payload = { company_id: form.company_id, code: form.code.trim().toUpperCase(), name: form.name.trim(), description: form.description.trim(), status: form.status };
    } else if (section === "complexes") {
      payload = { company_id: form.company_id, code: form.code.trim().toUpperCase(), name: form.name.trim(), address: form.address.trim(), city: form.city.trim(), timezone: form.timezone, status: form.status };
    } else if (section === "shops") {
      payload = { brand_id: form.brand_id, complex_id: form.complex_id, code: form.code.trim().toUpperCase(), name: form.name.trim(), address: form.address.trim(), city: form.city.trim(), timezone: form.timezone, pos_login_mode: form.pos_login_mode, status: form.status };
    } else {
      payload = { shop_id: form.shop_id, brand_id: form.brand_id, complex_id: form.complex_id, till_code: form.till_code.trim().toUpperCase(), till_name: form.till_name.trim(), device_label: form.device_label.trim(), is_active: form.is_active };
    }

    if (!configuration.fileField || !imageFile) return payload;
    const data = new FormData();
    Object.entries(payload).forEach(([key, value]) => {
      if (value !== "" && value !== null && value !== undefined) data.append(key, typeof value === "boolean" ? (value ? "1" : "0") : value);
    });
    data.append(configuration.fileField, imageFile);
    return data;
  };

  const validate = () => {
    if (section === "tills") {
      if (!form.shop_id) return "Select a shop.";
      if (!form.brand_id || !form.complex_id) return "The selected shop must have a brand and complex.";
      if (!form.till_code.trim() || !form.till_name.trim()) return "Till code and till name are required.";
      return "";
    }
    if (!form.code.trim() || !form.name.trim()) return `${configuration.singular} code and name are required.`;
    if (["brands", "complexes"].includes(section) && !form.company_id) return "Select a company.";
    if (section === "shops" && (!form.brand_id || !form.complex_id)) return "Select both a brand and a complex.";
    return "";
  };

  const save = async (event) => {
    event.preventDefault();
    if (saving) return;
    const validation = validate();
    if (validation) {
      setError(validation);
      return;
    }
    setSaving(true);
    setError("");
    try {
      const id = valueId(editor?.entity);
      if (id) await updateManagedHierarchyRecord(section, id, buildPayload());
      else await createManagedHierarchyRecord(section, buildPayload());
      setEditor(null);
      setNotice(`${configuration.singular} ${id ? "updated" : "created"}.`);
      await loadRows(pagination.page);
    } catch (saveError) {
      setError(apiMessage(saveError, `Unable to save ${configuration.singular.toLowerCase()}.`));
    } finally {
      setSaving(false);
    }
  };

  const remove = async (entity) => {
    setSaving(true);
    setError("");
    try {
      await deleteManagedHierarchyRecord(section, valueId(entity));
      setNotice(`${configuration.singular} deleted.`);
      await loadRows(pagination.page);
    } catch (deleteError) {
      setError(apiMessage(deleteError, `Unable to delete ${configuration.singular.toLowerCase()}.`));
    } finally {
      setSaving(false);
      setDeleteTarget(null);
    }
  };

  if (!open) return null;

  return (
    <>
      <FullScreenModal open={open} onClose={onClose} title={configuration.title} subtitle="">
        {!isPlatformAdmin ? <div className="grid h-full place-items-center text-[12px] font-bold text-red-700">Platform administrator access is required.</div> : (
          <div className="flex h-full min-h-0 flex-col bg-[#EFF2F2]">
            <EnterpriseFilterBar
              searchValue={query}
              onSearchChange={setQuery}
              onSearch={() => loadRows(1)}
              searchLabel={`Search ${section}`}
              leading={<SectionIcon size={17} className="text-[#069494]" aria-hidden="true" />}
              actions={<><button type="button" className={secondaryButton} onClick={() => loadRows(pagination.page)} disabled={loading}><RefreshCw size={13} className={loading ? "animate-spin" : ""} /> Refresh</button><button type="button" className={primaryButton} onClick={openCreate}><Plus size={14} /> Add {configuration.singular}</button></>}
            >
              <ParentFilters section={section} filters={filters} setFilters={setFilters} lookups={lookups} loadingLookups={loadingLookups} />
            </EnterpriseFilterBar>

            {(error || notice) && <div className={`border-b px-3 py-2 text-[11px] font-bold ${error ? "border-red-200 bg-red-50 text-red-700" : "border-emerald-200 bg-emerald-50 text-emerald-700"}`}>{error || notice}</div>}

            <EntityTable
              section={section}
              rows={rows}
              loading={loading}
              onEdit={openEdit}
              onDelete={setDeleteTarget}
              onOpenSettings={section === "shops" ? onOpenShopSettings : null}
            />

            <footer className="flex min-h-11 shrink-0 items-center justify-between border-t border-[#CBD6D6] bg-[#F8FAFC] px-3 text-[9px] font-black uppercase text-[#637877]">
              <span>{pagination.total} records</span>
              <div className="flex items-center gap-2"><button type="button" className={secondaryButton} disabled={loading || pagination.page <= 1} onClick={() => loadRows(pagination.page - 1)}>Previous</button><span>Page {pagination.page} / {pagination.lastPage}</span><button type="button" className={secondaryButton} disabled={loading || pagination.page >= pagination.lastPage} onClick={() => loadRows(pagination.page + 1)}>Next</button></div>
            </footer>
          </div>
        )}
      </FullScreenModal>

      <EnterpriseFormModal open={Boolean(editor)} title={`${editor?.entity ? "Edit" : "Add"} ${configuration.singular}`} subtitle="" onClose={() => !saving && setEditor(null)} busy={saving} width="780px">
        <form onSubmit={save} className="space-y-4">
          <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
            {section !== "countries" && <Field label="Company" required>
              <select className={inputClass} value={form.company_id || ""} onChange={(event) => onFormCompanyChange(event.target.value)} disabled={Boolean(editor?.entity) && ["brands", "complexes", "shops", "tills"].includes(section)} required>
                <option value="">Select company</option>
                {lookups.companies.map((company) => <option key={valueId(company)} value={valueId(company)}>{entityName(company)}</option>)}
              </select>
            </Field>}

            {section === "shops" && <><Field label="Brand" required><select className={inputClass} value={form.brand_id || ""} onChange={(event) => setForm((current) => ({ ...current, brand_id: event.target.value }))} disabled={!form.company_id || loadingLookups} required><option value="">Select brand</option>{lookups.brands.map((brand) => <option key={valueId(brand)} value={valueId(brand)}>{entityName(brand)}</option>)}</select></Field><Field label="Complex" required><select className={inputClass} value={form.complex_id || ""} onChange={(event) => setForm((current) => ({ ...current, complex_id: event.target.value }))} disabled={!form.company_id || loadingLookups} required><option value="">Select complex</option>{lookups.complexes.map((complex) => <option key={valueId(complex)} value={valueId(complex)}>{entityName(complex)}</option>)}</select></Field></>}

            {section === "tills" && <><Field label="Shop" required><select className={inputClass} value={form.shop_id || ""} onChange={(event) => onTillShopChange(event.target.value)} disabled={!form.company_id || loadingLookups} required><option value="">Select shop</option>{lookups.shops.map((shop) => <option key={valueId(shop)} value={valueId(shop)}>{entityName(shop)} ({entityCode(shop)})</option>)}</select></Field><div className="md:col-span-2 grid grid-cols-1 gap-2 border border-[#CBD6D6] bg-[#F8FAFC] p-3 sm:grid-cols-2"><div><span className="block text-[9px] font-black uppercase text-[#637877]">Inherited Brand</span><strong className="mt-1 block text-[12px] text-[#1C2C2C]">{entityName(selectedTillBrand, "Select a shop")}</strong></div><div><span className="block text-[9px] font-black uppercase text-[#637877]">Inherited Complex</span><strong className="mt-1 block text-[12px] text-[#1C2C2C]">{entityName(selectedTillComplex, "Select a shop")}</strong></div></div></>}

            {section === "tills" ? <><Field label="Till Code" required><input className={inputClass} value={form.till_code || ""} onChange={(event) => setForm((current) => ({ ...current, till_code: event.target.value.toUpperCase() }))} required /></Field><Field label="Till Name" required><input className={inputClass} value={form.till_name || ""} onChange={(event) => setForm((current) => ({ ...current, till_name: event.target.value }))} required /></Field><Field label="Device Label"><input className={inputClass} value={form.device_label || ""} onChange={(event) => setForm((current) => ({ ...current, device_label: event.target.value }))} /></Field><Field label="Status"><select className={inputClass} value={form.is_active ? "active" : "inactive"} onChange={(event) => setForm((current) => ({ ...current, is_active: event.target.value === "active" }))}><option value="active">Active</option><option value="inactive">Inactive</option></select></Field></> : <>
              <Field label={`${configuration.singular} Code`} required><input className={inputClass} value={form.code || ""} onChange={(event) => setForm((current) => ({ ...current, code: event.target.value.toUpperCase() }))} required /></Field>
              <Field label={`${configuration.singular} Name`} required><input className={inputClass} value={form.name || ""} onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))} required /></Field>
            </>}

            {section === "countries" && <><Field label="ISO Alpha-2"><input maxLength="2" className={inputClass} value={form.iso_alpha_2 || ""} onChange={(event) => setForm((current) => ({ ...current, iso_alpha_2: event.target.value.toUpperCase() }))} /></Field><Field label="ISO Alpha-3"><input maxLength="3" className={inputClass} value={form.iso_alpha_3 || ""} onChange={(event) => setForm((current) => ({ ...current, iso_alpha_3: event.target.value.toUpperCase() }))} /></Field><Field label="Currency"><input maxLength="3" className={inputClass} value={form.currency_code || ""} onChange={(event) => setForm((current) => ({ ...current, currency_code: event.target.value.toUpperCase() }))} /></Field><Field label="Timezone"><input className={inputClass} value={form.timezone || ""} onChange={(event) => setForm((current) => ({ ...current, timezone: event.target.value }))} /></Field><Field label="Locale"><input className={inputClass} value={form.locale || ""} onChange={(event) => setForm((current) => ({ ...current, locale: event.target.value }))} /></Field><Field label="Status"><select className={inputClass} value={form.is_active ? "active" : "inactive"} onChange={(event) => setForm((current) => ({ ...current, is_active: event.target.value === "active" }))}><option value="active">Active</option><option value="inactive">Inactive</option></select></Field><ImageFileField label="Country Image" preview={imagePreview} existing={imageSource(editor?.entity, section)} onSelect={selectImage} onRemove={() => { setImageFile(null); setImagePreview(""); }} /></>}

            {section === "brands" && <><Field label="Description" span="md:col-span-2"><textarea className="min-h-[74px] w-full border border-[#ACB8B8] bg-white p-2 text-[12px] outline-none focus:border-[#069494]" value={form.description || ""} onChange={(event) => setForm((current) => ({ ...current, description: event.target.value }))} /></Field><Field label="Status"><select className={inputClass} value={form.status || "active"} onChange={(event) => setForm((current) => ({ ...current, status: event.target.value }))}><option value="active">Active</option><option value="inactive">Inactive</option><option value="suspended">Suspended</option></select></Field><ImageFileField label="Brand Logo" preview={imagePreview} existing={imageSource(editor?.entity, section)} onSelect={selectImage} onRemove={() => { setImageFile(null); setImagePreview(""); }} /></>}

            {["complexes", "shops"].includes(section) && <><Field label="Address" span="md:col-span-2"><input className={inputClass} value={form.address || ""} onChange={(event) => setForm((current) => ({ ...current, address: event.target.value }))} /></Field><Field label="City"><input className={inputClass} value={form.city || ""} onChange={(event) => setForm((current) => ({ ...current, city: event.target.value }))} /></Field><Field label="Timezone"><input className={inputClass} value={form.timezone || ""} onChange={(event) => setForm((current) => ({ ...current, timezone: event.target.value }))} /></Field>{section === "shops" && <Field label="POS Login"><select className={inputClass} value={form.pos_login_mode || "code"} onChange={(event) => setForm((current) => ({ ...current, pos_login_mode: event.target.value }))}><option value="code">Operator code / PIN</option><option value="username_password">Username + password</option><option value="both">Both methods</option></select></Field>}<Field label="Status"><select className={inputClass} value={form.status || "active"} onChange={(event) => setForm((current) => ({ ...current, status: event.target.value }))}><option value="active">Active</option><option value="inactive">Inactive</option><option value="suspended">Suspended</option></select></Field></>}
          </div>

          {error && <div className="border border-red-200 bg-red-50 p-2 text-[11px] font-bold text-red-700">{error}</div>}
          <div className="flex justify-end gap-2 border-t border-[#D7DFDE] pt-3"><button type="button" onClick={() => setEditor(null)} disabled={saving} className={secondaryButton}>Cancel</button><button type="submit" disabled={saving} className={primaryButton}>{saving ? "Saving…" : "Save"}</button></div>
        </form>
      </EnterpriseFormModal>

      <EnterpriseConfirmModal
        open={Boolean(deleteTarget)}
        title={`Delete ${configuration.singular}`}
        message={deleteTarget ? `Delete ${entityName(deleteTarget)}?` : ""}
        confirmLabel={`Delete ${configuration.singular.toLowerCase()}`}
        busy={saving}
        onClose={() => !saving && setDeleteTarget(null)}
        onConfirm={() => deleteTarget && remove(deleteTarget)}
      />
    </>
  );
}


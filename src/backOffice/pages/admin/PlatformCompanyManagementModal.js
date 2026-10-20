import React, { useEffect, useMemo, useState } from "react";
import { Building2, Clipboard, Image as ImageIcon, Plus, RefreshCw, Search, Upload } from "lucide-react";
import FullScreenModal from "../../../components/FullScreenModal";
import EnterpriseFormModal from "../../../components/EnterpriseFormModal";
import EnterpriseConfirmModal from "../../../components/EnterpriseConfirmModal";
import ReusableMessageModal from "../../../components/ReusableMessageModal";
import { formatLicenseKey } from "../../../utils/licenseKey";
import {
  createPlatformCompany,
  createPlatformCompanyBranch,
  deletePlatformCompany,
  deletePlatformCompanyBranch,
  getPlatformCompanies,
  getPlatformCompany,
  getPlatformCompanyLicenses,
  renewPlatformCompanyLicense,
  updatePlatformCompany,
  updatePlatformCompanyBranch,
} from "../../../services/CompanyService";
import { getEnterpriseCountries } from "../../../services/EnterpriseHierarchyService";
import { publicAssetUrl } from "../../../utils/publicAssetUrl";

const today = () => new Date().toISOString().slice(0, 10);
const oneYearFromToday = () => {
  const date = new Date();
  date.setFullYear(date.getFullYear() + 1);
  return date.toISOString().slice(0, 10);
};

const emptyCompany = {
  company_name: "",
  country_id: "",
  location_address: "",
  city: "",
  country: "",
  domain: "",
  host_ip: "",
  logo: "",
  license_support_phone: "",
  status: "active",
};

const emptyBranch = {
  branch_name: "",
  branch_code: "",
  branch_address: "",
  branch_city: "",
  branch_country: "",
  level: "",
  is_main_branch: false,
  status: "active",
};

const emptyLicense = () => ({
  license_type: "standard",
  max_branches: 1,
  max_users: 10,
  max_devices: 3,
  starts_at: today(),
  expires_at: oneYearFromToday(),
});

export const PLATFORM_LICENSE_TYPES = ["trial", "standard", "enterprise"];

export const formatCompactLicenseKey = formatLicenseKey;

export const extractPlatformCompanies = (response) => {
  const candidates = [
    response?.companies?.data,
    response?.data?.companies?.data,
    response?.companies,
    response?.data?.companies,
    response?.data?.data,
    response?.data,
  ];

  return candidates.find(Array.isArray) || [];
};

export const extractPlatformLicenses = (response) => {
  const candidates = [
    response?.licenses?.data,
    response?.data?.licenses?.data,
    response?.company?.licenses,
    response?.data?.company?.licenses,
    response?.licenses,
    response?.data?.licenses,
    response?.data?.data,
    response?.data,
  ];

  return candidates.find(Array.isArray) || [];
};

const responseCompany = (response) =>
  response?.company || response?.data?.company || response?.data?.data || null;

const responseLicense = (response) =>
  response?.license || response?.data?.license || response?.data?.data || null;

const apiMessage = (error, fallback) => {
  const payload = error?.response?.data;
  if (payload?.message) return payload.message;
  if (payload?.error) return payload.error;
  if (payload?.errors) return Object.values(payload.errors).flat().join("\n");
  return error?.displayMessage || error?.message || fallback;
};

export const companyCountryForDisplay = (company) => {
  const country = company?.country;
  if (country && typeof country === "object") {
    return country.name || country.iso_alpha_3 || country.iso_alpha_2 || country.code || "";
  }

  return country || "";
};

const normaliseCompanyForm = (company = {}) => ({
  company_name: company.company_name || company.name || "",
  country_id: String(company.country_id || company.country?.id || ""),
  location_address: company.location_address || company.address || "",
  city: company.city || "",
  country: companyCountryForDisplay(company),
  domain: company.domain || "",
  host_ip: company.host_ip || "",
  logo: publicAssetUrl(company.logo_path || company.logo || company.logo_url),
  license_support_phone: company.license_support_phone || "",
  status: company.status || "active",
});

export const extractCompanyCountries = (response) => {
  const candidates = [
    response?.data?.data,
    response?.data,
    response?.countries?.data,
    response?.countries,
    response,
  ];
  return candidates.find(Array.isArray) || [];
};

const responseLastPage = (response) => Number(
  response?.data?.last_page
  || response?.companies?.last_page
  || response?.last_page
  || 1,
);

const appendFormValue = (data, key, value) => {
  if (value === undefined || value === null || value === "") return;
  data.append(key, typeof value === "boolean" ? (value ? "1" : "0") : value);
};

export const buildCompanyRequestPayload = ({ company, logoFile = null, initialBranch = null, initialLicense = null }) => {
  const payload = {
    ...company,
    ...(initialBranch ? { initial_branch: initialBranch } : {}),
    ...(initialLicense ? { initial_license: initialLicense } : {}),
  };
  if (!logoFile) return payload;

  const data = new FormData();
  Object.entries(company).forEach(([key, value]) => {
    if (key !== "logo") appendFormValue(data, key, value);
  });
  data.append("logo", logoFile);
  if (initialBranch) Object.entries(initialBranch).forEach(([key, value]) => appendFormValue(data, `initial_branch[${key}]`, value));
  if (initialLicense) Object.entries(initialLicense).forEach(([key, value]) => appendFormValue(data, `initial_license[${key}]`, value));
  return data;
};

const normaliseBranchForm = (branch = {}) => ({
  branch_name: branch.branch_name || branch.name || "",
  branch_code: branch.branch_code || branch.code || "",
  branch_address: branch.branch_address || branch.address || "",
  branch_city: branch.branch_city || branch.city || "",
  branch_country: branch.branch_country || branch.country || "",
  level: branch.level || "",
  is_main_branch: Boolean(branch.is_main_branch),
  status: branch.status || "active",
});

const companyBranches = (company) =>
  company?.branches || company?.company_branches || [];

export const companyCodeForDisplay = (company) =>
  company?.company_code || company?.tenant_code || company?.code || "";

const formatDate = (value) => {
  if (!value) return "-";
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString("en-GB");
};

const licenseKeyForDisplay = (license) => {
  const key = String(license?.license_key || license?.key || "");
  const compact = key.replace(/[^A-Z0-9]/gi, "");
  return compact.length === 16 ? formatCompactLicenseKey(key) : key || "-";
};

const statusClass = (status) => {
  const value = String(status || "").toLowerCase();
  if (value === "active") return "border-[#86EFAC] bg-[#DCFCE7] text-[#166534]";
  if (value === "expired" || value === "inactive") return "border-red-200 bg-red-50 text-red-700";
  return "border-[#FCD34D] bg-[#FEF3C7] text-[#92400E]";
};

const Field = ({ label, required = false, children, span = "" }) => (
  <label className={`block ${span}`}>
    <span className="mb-1 block text-[10px] font-black uppercase text-[#465858]">
      {label}{required ? " *" : ""}
    </span>
    {children}
  </label>
);

const inputClass = "h-9 w-full border border-[#ACB8B8] bg-white px-2 text-[12px] outline-none focus:border-[#069495]";

const ConfirmDialog = ({ confirmation, busy, onCancel, onConfirm }) => {
  return <EnterpriseConfirmModal open={Boolean(confirmation)} title="Confirm Delete" message={confirmation?.message} confirmLabel="Delete" busy={busy} onClose={onCancel} onConfirm={onConfirm} />;
};

export default function PlatformCompanyManagementModal({ open, onClose, isPlatformAdmin = false }) {
  const [companies, setCompanies] = useState([]);
  const [countries, setCountries] = useState([]);
  const [selectedCompany, setSelectedCompany] = useState(null);
  const [licenses, setLicenses] = useState([]);
  const [search, setSearch] = useState("");
  const [activePanel, setActivePanel] = useState("overview");
  const [loading, setLoading] = useState(false);
  const [detailLoading, setDetailLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [companyFormOpen, setCompanyFormOpen] = useState(false);
  const [editingCompany, setEditingCompany] = useState(null);
  const [companyForm, setCompanyForm] = useState(emptyCompany);
  const [companyLogoFile, setCompanyLogoFile] = useState(null);
  const [companyLogoPreview, setCompanyLogoPreview] = useState("");
  const [initialBranch, setInitialBranch] = useState({ ...emptyBranch, is_main_branch: true });
  const [initialLicense, setInitialLicense] = useState(emptyLicense());
  const [branchFormOpen, setBranchFormOpen] = useState(false);
  const [editingBranch, setEditingBranch] = useState(null);
  const [branchForm, setBranchForm] = useState(emptyBranch);
  const [licenseFormOpen, setLicenseFormOpen] = useState(false);
  const [licenseForm, setLicenseForm] = useState(emptyLicense());
  const [lastIssuedKey, setLastIssuedKey] = useState("");
  const [confirmation, setConfirmation] = useState(null);
  const [message, setMessage] = useState({ open: false, type: "success", title: "", message: "" });

  const showMessage = (type, title, text) => setMessage({ open: true, type, title, message: text });

  const loadCountries = async () => {
    try {
      const first = await getEnterpriseCountries({ per_page: 100, page: 1 });
      const rows = [...extractCompanyCountries(first)];
      for (let page = 2; page <= responseLastPage(first); page += 1) {
        const response = await getEnterpriseCountries({ per_page: 100, page });
        rows.push(...extractCompanyCountries(response));
      }
      setCountries(rows);
    } catch (error) {
      setCountries([]);
      showMessage("error", "Countries Failed", apiMessage(error, "Could not load countries."));
    }
  };

  const filteredCompanies = useMemo(() => {
    const query = search.trim().toLowerCase();
    if (!query) return companies;
    return companies.filter((company) => [
      company.company_name,
      company.name,
      companyCodeForDisplay(company),
      company.domain,
      company.city,
      companyCountryForDisplay(company),
      company.status,
    ].some((value) => String(value || "").toLowerCase().includes(query)));
  }, [companies, search]);

  const loadCompanyDetails = async (companyId, fallback = null) => {
    if (!companyId) return;
    try {
      setDetailLoading(true);
      const [companyResult, licenseResult] = await Promise.all([
        getPlatformCompany(companyId),
        getPlatformCompanyLicenses(companyId),
      ]);
      const company = responseCompany(companyResult) || fallback;
      setSelectedCompany(company);
      setLicenses(extractPlatformLicenses(licenseResult));
    } catch (error) {
      showMessage("error", "Company Failed", apiMessage(error, "Could not load the selected company."));
    } finally {
      setDetailLoading(false);
    }
  };

  const loadCompanies = async (preferredCompanyId = null) => {
    try {
      setLoading(true);
      const response = await getPlatformCompanies({ per_page: 500, page: 1 });
      const rows = [...extractPlatformCompanies(response)];
      for (let page = 2; page <= responseLastPage(response); page += 1) {
        const nextPage = await getPlatformCompanies({ per_page: 500, page });
        rows.push(...extractPlatformCompanies(nextPage));
      }
      setCompanies(rows);

      const selectedId = preferredCompanyId || selectedCompany?.id;
      const next = rows.find((company) => String(company.id) === String(selectedId)) || rows[0] || null;
      if (next) await loadCompanyDetails(next.id, next);
      else {
        setSelectedCompany(null);
        setLicenses([]);
      }
    } catch (error) {
      showMessage("error", "Companies Failed", apiMessage(error, "Could not load companies."));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (!open || !isPlatformAdmin) return;
    setSearch("");
    setActivePanel("overview");
    setLastIssuedKey("");
    setCompanyLogoFile(null);
    setCompanyLogoPreview("");
    loadCountries();
    loadCompanies();
    // Load on each explicit window opening.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, isPlatformAdmin]);

  useEffect(() => () => {
    if (companyLogoPreview.startsWith("blob:")) URL.revokeObjectURL(companyLogoPreview);
  }, [companyLogoPreview]);

  if (!open || !isPlatformAdmin) return null;

  const openCreateCompany = () => {
    setEditingCompany(null);
    setCompanyForm(emptyCompany);
    setCompanyLogoFile(null);
    setCompanyLogoPreview("");
    setInitialBranch({ ...emptyBranch, is_main_branch: true });
    setInitialLicense(emptyLicense());
    setCompanyFormOpen(true);
  };

  const openEditCompany = () => {
    if (!selectedCompany) return;
    setEditingCompany(selectedCompany);
    setCompanyForm(normaliseCompanyForm(selectedCompany));
    setCompanyLogoFile(null);
    setCompanyLogoPreview("");
    setCompanyFormOpen(true);
  };

  const selectCompanyLogo = (event) => {
    const file = event.target.files?.[0];
    event.target.value = "";
    if (!file) return;
    if (!["image/png", "image/jpeg", "image/webp"].includes(file.type)) {
      showMessage("error", "Invalid Logo", "Select a PNG, JPG or WEBP image.");
      return;
    }
    if (file.size > 4 * 1024 * 1024) {
      showMessage("error", "Invalid Logo", "The logo must be 4 MB or smaller.");
      return;
    }
    setCompanyLogoFile(file);
    setCompanyLogoPreview(URL.createObjectURL(file));
  };

  const saveCompany = async (event) => {
    event.preventDefault();
    if (saving) return;
    if (
      !companyForm.company_name.trim() ||
      !companyForm.location_address.trim() ||
      !companyForm.city.trim() ||
      !companyForm.country_id
    ) {
      showMessage("error", "Validation Error", "Company name, country, address and city are required.");
      return;
    }
    if (!editingCompany && (!initialBranch.branch_name.trim() || !initialLicense.expires_at)) {
      showMessage("error", "Validation Error", "An initial branch and license expiry date are required.");
      return;
    }

    const selectedCountry = countries.find((country) => String(country.id) === String(companyForm.country_id));
    const companyPayload = {
      ...companyForm,
      company_name: companyForm.company_name.trim(),
      country_id: companyForm.country_id,
      country: selectedCountry?.name || companyForm.country || null,
      domain: companyForm.domain.trim() || null,
      host_ip: companyForm.host_ip || null,
      logo: undefined,
      license_support_phone: companyForm.license_support_phone || null,
    };

    try {
      setSaving(true);
      let response;
      if (editingCompany) {
        response = await updatePlatformCompany(editingCompany.id, buildCompanyRequestPayload({
          company: companyPayload,
          logoFile: companyLogoFile,
        }));
      } else {
        const preparedBranch = {
            ...initialBranch,
            branch_name: initialBranch.branch_name.trim(),
            status: "active",
          };
        const preparedLicense = {
            ...initialLicense,
            max_branches: Number(initialLicense.max_branches),
            max_users: Number(initialLicense.max_users),
            max_devices: Number(initialLicense.max_devices),
          };
        response = await createPlatformCompany(buildCompanyRequestPayload({
          company: companyPayload,
          logoFile: companyLogoFile,
          initialBranch: preparedBranch,
          initialLicense: preparedLicense,
        }));
      }

      const savedCompany = responseCompany(response);
      const issuedLicense = responseLicense(response) || savedCompany?.active_license || savedCompany?.activeLicense;
      if (issuedLicense?.license_key) setLastIssuedKey(licenseKeyForDisplay(issuedLicense));
      setCompanyFormOpen(false);
      showMessage("success", editingCompany ? "Company Updated" : "Company Created", apiMessage(response, editingCompany ? "Company updated successfully." : "Company, branch and initial license created successfully."));
      await loadCompanies(savedCompany?.id || editingCompany?.id);
    } catch (error) {
      showMessage("error", editingCompany ? "Update Failed" : "Create Failed", apiMessage(error, "Company could not be saved."));
    } finally {
      setSaving(false);
    }
  };

  const openCreateBranch = () => {
    setEditingBranch(null);
    setBranchForm(emptyBranch);
    setBranchFormOpen(true);
  };

  const openEditBranch = (branch) => {
    setEditingBranch(branch);
    setBranchForm(normaliseBranchForm(branch));
    setBranchFormOpen(true);
  };

  const saveBranch = async (event) => {
    event.preventDefault();
    if (!selectedCompany || saving) return;
    if (!branchForm.branch_name.trim()) {
      showMessage("error", "Validation Error", "Branch name is required.");
      return;
    }

    const payload = {
      ...branchForm,
      branch_name: branchForm.branch_name.trim(),
      branch_code: branchForm.branch_code || null,
      branch_address: branchForm.branch_address || null,
      branch_city: branchForm.branch_city || null,
      branch_country: branchForm.branch_country || null,
      level: branchForm.level || null,
    };

    try {
      setSaving(true);
      const response = editingBranch
        ? await updatePlatformCompanyBranch(editingBranch.id, payload)
        : await createPlatformCompanyBranch(selectedCompany.id, payload);
      setBranchFormOpen(false);
      showMessage("success", editingBranch ? "Branch Updated" : "Branch Created", apiMessage(response, editingBranch ? "Branch updated successfully." : "Branch created successfully."));
      await loadCompanyDetails(selectedCompany.id, selectedCompany);
    } catch (error) {
      showMessage("error", editingBranch ? "Update Failed" : "Create Failed", apiMessage(error, "Branch could not be saved."));
    } finally {
      setSaving(false);
    }
  };

  const saveLicense = async (event) => {
    event.preventDefault();
    if (!selectedCompany || saving) return;
    if (!licenseForm.expires_at) {
      showMessage("error", "Validation Error", "License expiry date is required.");
      return;
    }

    try {
      setSaving(true);
      const response = await renewPlatformCompanyLicense(selectedCompany.id, {
        ...licenseForm,
        max_branches: Number(licenseForm.max_branches),
        max_users: Number(licenseForm.max_users),
        max_devices: Number(licenseForm.max_devices),
      });
      const issuedLicense = responseLicense(response);
      const key = licenseKeyForDisplay(issuedLicense);
      setLastIssuedKey(key === "-" ? "" : key);
      setLicenseFormOpen(false);
      showMessage("success", "License Assigned", apiMessage(response, "A new compact license was assigned successfully."));
      const licenseResult = await getPlatformCompanyLicenses(selectedCompany.id);
      setLicenses(extractPlatformLicenses(licenseResult));
    } catch (error) {
      showMessage("error", "License Failed", apiMessage(error, "License could not be assigned."));
    } finally {
      setSaving(false);
    }
  };

  const runDelete = async () => {
    if (!confirmation || deleting) return;
    try {
      setDeleting(true);
      if (confirmation.type === "company") {
        await deletePlatformCompany(confirmation.record.id);
        setConfirmation(null);
        showMessage("success", "Company Deleted", "Company deleted successfully.");
        await loadCompanies();
      } else {
        await deletePlatformCompanyBranch(confirmation.record.id);
        setConfirmation(null);
        showMessage("success", "Branch Deleted", "Branch deleted successfully.");
        await loadCompanyDetails(selectedCompany.id, selectedCompany);
      }
    } catch (error) {
      showMessage("error", "Delete Failed", apiMessage(error, "The record could not be deleted."));
    } finally {
      setDeleting(false);
    }
  };

  const copyText = async (value, label = "Value") => {
    try {
      await navigator.clipboard.writeText(value);
      showMessage("success", `${label} Copied`, `${value} was copied to the clipboard.`);
    } catch {
      showMessage("error", "Copy Failed", `Copy the ${label.toLowerCase()} manually from the screen.`);
    }
  };

  const branches = companyBranches(selectedCompany);

  return (
    <>
      <FullScreenModal open={open} onClose={onClose} title="COMPANY MANAGEMENT" subtitle="">
        <div className="grid h-full min-h-0 grid-cols-[300px_minmax(0,1fr)] bg-[#F4F7F9]">
          <aside className="flex min-h-0 flex-col border-r border-[#BAC4C4] bg-white">
            <div className="border-b border-[#D7DFDE] bg-[#004142] p-3 text-white">
              <div className="flex items-center justify-between gap-2">
                <div>
                  <div className="text-[12px] font-black uppercase">Companies</div>
                  <div className="text-[9px] uppercase text-white/60">Platform register</div>
                </div>
                <button type="button" onClick={openCreateCompany} className="flex h-8 items-center gap-1 bg-[#069495] px-3 text-[10px] font-black uppercase"><Plus size={13} /> New</button>
              </div>
              <label className="mt-3 flex h-9 items-center border border-white/25 bg-white/10 px-2">
                <Search size={14} className="mr-2 text-white/60" />
                <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search companies" className="min-w-0 flex-1 bg-transparent text-[11px] text-white outline-none placeholder:text-white/45" />
              </label>
            </div>
            <div className="flex items-center justify-between border-b border-[#D7DFDE] bg-[#F8FAFC] px-3 py-2">
              <span className="text-[10px] font-bold text-[#637877]">{filteredCompanies.length} records</span>
              <button type="button" onClick={() => loadCompanies()} disabled={loading} aria-label="Refresh companies" className="text-[#008585] disabled:opacity-50"><RefreshCw size={14} className={loading ? "animate-spin" : ""} /></button>
            </div>
            <div className="min-h-0 flex-1 overflow-auto">
              {filteredCompanies.map((company) => (
                <button key={company.id} type="button" onClick={() => loadCompanyDetails(company.id, company)} className={`block w-full border-b border-[#E5E9E9] px-3 py-3 text-left ${selectedCompany?.id === company.id ? "border-l-4 border-l-[#069495] bg-[#E8F7F5]" : "border-l-4 border-l-transparent hover:bg-[#F5F8FA]"}`}>
                  <div className="truncate text-[12px] font-black text-[#003636]">{company.company_name || company.name}</div>
                  <div className="mt-1 truncate font-mono text-[10px] text-[#465858]">{companyCodeForDisplay(company) || "Code pending"}</div>
                  <div className="mt-0.5 truncate text-[9px] text-[#93A6A6]">{company.domain || "Shared cloud"}</div>
                  <div className="mt-1 flex items-center justify-between gap-2 text-[9px] uppercase text-[#637877]"><span>{company.city || companyCountryForDisplay(company) || "Location not set"}</span><span className={`border px-1.5 py-0.5 font-black ${statusClass(company.status || "active")}`}>{company.status || "active"}</span></div>
                </button>
              ))}
              {!loading && !filteredCompanies.length && <div className="p-5 text-center text-[11px] text-[#637877]">No companies found.</div>}
            </div>
          </aside>

          <section className="flex min-h-0 min-w-0 flex-col">
            {selectedCompany ? (
              <>
                <header className="flex min-h-16 items-center justify-between gap-3 border-b border-[#BAC4C4] bg-white px-4 py-2">
                  <div className="flex min-w-0 items-center gap-3">
                    <div className="grid h-10 w-10 shrink-0 place-items-center bg-[#004142] text-white"><Building2 size={20} /></div>
                    <div className="min-w-0"><h1 className="truncate text-[16px] font-black text-[#003636]">{selectedCompany.company_name || selectedCompany.name}</h1><p className="truncate font-mono text-[10px] text-[#465858]">{companyCodeForDisplay(selectedCompany) || "Company code pending"}{selectedCompany.domain ? ` · ${selectedCompany.domain}` : " · Shared cloud"}</p></div>
                  </div>
                  <div className="flex shrink-0 gap-2">
                    <button type="button" onClick={openEditCompany} className="h-8 border border-[#069494] bg-white px-3 text-[10px] font-black uppercase text-[#008585]">Edit Company</button>
                    <button type="button" onClick={() => setConfirmation({ type: "company", record: selectedCompany, message: `Delete company "${selectedCompany.company_name || selectedCompany.name}"? This also deletes all of its branches, shops, tills, users and products, and revokes its licence.` })} className="h-8 bg-[#B42318] px-3 text-[10px] font-black uppercase text-white">Delete</button>
                  </div>
                </header>

                <nav className="flex h-10 shrink-0 border-b border-[#BAC4C4] bg-white">
                  {[["overview", "Company"], ["branches", `Branches (${branches.length})`], ["licenses", `Licenses (${licenses.length})`]].map(([key, label]) => (
                    <button key={key} type="button" onClick={() => setActivePanel(key)} className={`border-r border-[#D7DFDE] px-5 text-[10px] font-black uppercase ${activePanel === key ? "border-b-2 border-b-[#069495] bg-[#E8F7F5] text-[#067C7C]" : "text-[#465858] hover:bg-[#F8FAFC]"}`}>{label}</button>
                  ))}
                </nav>

                <div className="min-h-0 flex-1 overflow-auto p-4">
                  {detailLoading && <div className="mb-3 border border-[#ADE5E4] bg-[#EFF7F7] p-2 text-[11px] text-[#006E6E]">Refreshing company details...</div>}
                  {lastIssuedKey && (
                    <div className="mb-4 flex items-center justify-between gap-3 border border-[#86EFAC] bg-[#F0FDF4] p-3">
                      <div><div className="text-[9px] font-black uppercase text-[#166534]">Latest Generated License Key</div><div className="mt-1 font-mono text-[19px] font-black tracking-[0.12em] text-[#14532D]">{lastIssuedKey}</div></div>
                      <button type="button" onClick={() => copyText(lastIssuedKey, "License Key")} className="flex h-9 items-center gap-2 border border-[#16A34A] bg-white px-3 text-[10px] font-black uppercase text-[#166534]"><Clipboard size={14} /> Copy</button>
                    </div>
                  )}

                  {activePanel === "overview" && (
                    <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
                      <div className="min-h-20 border border-[#93F5F4] bg-[#F0FDFA] p-3">
                        <div className="flex items-center justify-between gap-2">
                          <div className="text-[9px] font-black uppercase text-[#057B7B]">Company Code</div>
                          <button type="button" disabled={!companyCodeForDisplay(selectedCompany)} onClick={() => copyText(companyCodeForDisplay(selectedCompany), "Company Code")} className="flex h-7 items-center gap-1 border border-[#52E9E8] bg-white px-2 text-[9px] font-black uppercase text-[#057B7B] disabled:opacity-40"><Clipboard size={12} /> Copy</button>
                        </div>
                        <div className="mt-2 break-words font-mono text-[14px] font-black text-[#114E4E]">{companyCodeForDisplay(selectedCompany) || "Pending server assignment"}</div>
                        <div className="mt-1 text-[9px] text-[#637877]">Immutable tenant identity used for shared-cloud login and POS pairing.</div>
                      </div>
                      {[
                        ["Company Name", selectedCompany.company_name || selectedCompany.name],
                        ["Status", selectedCompany.status || "active"],
                        ["Legacy Domain", selectedCompany.domain],
                        ["Address", selectedCompany.location_address || selectedCompany.address],
                        ["City", selectedCompany.city],
                        ["Country", companyCountryForDisplay(selectedCompany)],
                        ["Host IP", selectedCompany.host_ip],
                        ["License Contact", selectedCompany.license_support_phone],
                        ["Branches", branches.length],
                      ].map(([label, value]) => <div key={label} className="min-h-20 border border-[#CAD5D5] bg-white p-3"><div className="text-[9px] font-black uppercase text-[#637877]">{label}</div><div className="mt-2 break-words text-[13px] font-bold text-[#003636]">{value || "-"}</div></div>)}
                    </div>
                  )}

                  {activePanel === "branches" && (
                    <div className="border border-[#BAC4C4] bg-white">
                      <div className="flex h-11 items-center justify-between border-b border-[#BAC4C4] bg-[#F8FAFC] px-3"><div><div className="text-[11px] font-black uppercase">Company Branches</div><div className="text-[9px] text-[#637877]">Create and maintain this company&apos;s operating locations.</div></div><button type="button" onClick={openCreateBranch} className="flex h-8 items-center gap-1 bg-[#069495] px-3 text-[10px] font-black uppercase text-white"><Plus size={13} /> Add Branch</button></div>
                      <div className="overflow-auto"><table className="w-full border-collapse"><thead><tr className="bg-[#EFF3F3]"><th className="p-2 text-left text-[9px] uppercase">Branch</th><th className="p-2 text-left text-[9px] uppercase">Code</th><th className="p-2 text-left text-[9px] uppercase">Location</th><th className="p-2 text-left text-[9px] uppercase">Level</th><th className="p-2 text-left text-[9px] uppercase">Status</th><th className="w-40 p-2 text-right text-[9px] uppercase">Actions</th></tr></thead><tbody>
                        {branches.map((branch) => <tr key={branch.id} className="border-t border-[#E5E9E9]"><td className="p-2 text-[11px] font-bold">{branch.branch_name || branch.name}{branch.is_main_branch ? <small className="ml-2 bg-[#DBEDEC] px-1 py-0.5 text-[8px] font-black uppercase text-[#006E6E]">Main</small> : null}</td><td className="p-2 text-[11px]">{branch.branch_code || branch.code || "-"}</td><td className="p-2 text-[11px]">{[branch.branch_city || branch.city, branch.branch_country || branch.country].filter(Boolean).join(", ") || "-"}</td><td className="p-2 text-[11px]">{branch.level || "-"}</td><td className="p-2 text-[11px]">{branch.status || "active"}</td><td className="p-2 text-right"><button type="button" onClick={() => openEditBranch(branch)} className="mr-2 h-7 border border-[#069494] px-2 text-[9px] font-black uppercase text-[#008585]">Edit</button><button type="button" onClick={() => setConfirmation({ type: "branch", record: branch, message: `Delete branch "${branch.branch_name || branch.name}"?` })} className="h-7 border border-red-300 px-2 text-[9px] font-black uppercase text-red-700">Delete</button></td></tr>)}
                        {!branches.length && <tr><td colSpan="6" className="p-8 text-center text-[11px] text-[#637877]">No branches found for this company.</td></tr>}
                      </tbody></table></div>
                    </div>
                  )}

                  {activePanel === "licenses" && (
                    <div className="border border-[#BAC4C4] bg-white">
                      <div className="flex h-11 items-center justify-between border-b border-[#BAC4C4] bg-[#F8FAFC] px-3"><div><div className="text-[11px] font-black uppercase">License History</div><div className="text-[9px] text-[#637877]">Assigning a license retains the previous records for audit.</div></div><button type="button" onClick={() => { setLicenseForm(emptyLicense()); setLicenseFormOpen(true); }} className="flex h-8 items-center gap-1 bg-[#069495] px-3 text-[10px] font-black uppercase text-white"><Plus size={13} /> Assign / Renew</button></div>
                      <div className="overflow-auto"><table className="w-full border-collapse"><thead><tr className="bg-[#EFF3F3]"><th className="p-2 text-left text-[9px] uppercase">License Key</th><th className="p-2 text-left text-[9px] uppercase">Type</th><th className="p-2 text-right text-[9px] uppercase">Branches</th><th className="p-2 text-right text-[9px] uppercase">Users</th><th className="p-2 text-right text-[9px] uppercase">Devices</th><th className="p-2 text-left text-[9px] uppercase">Starts</th><th className="p-2 text-left text-[9px] uppercase">Expires</th><th className="p-2 text-left text-[9px] uppercase">Status</th><th className="w-20 p-2 text-right text-[9px] uppercase">Actions</th></tr></thead><tbody>
                        {licenses.map((license) => { const key = licenseKeyForDisplay(license); return <tr key={license.id || key} className="border-t border-[#E5E9E9]"><td className="p-2 font-mono text-[11px] font-black tracking-wide">{key}</td><td className="p-2 text-[11px]">{license.license_type || "-"}</td><td className="p-2 text-right text-[11px]">{license.max_branches ?? "-"}</td><td className="p-2 text-right text-[11px]">{license.max_users ?? "-"}</td><td className="p-2 text-right text-[11px]">{license.max_devices ?? "-"}</td><td className="p-2 text-[11px]">{formatDate(license.starts_at)}</td><td className="p-2 text-[11px]">{formatDate(license.expires_at)}</td><td className="p-2"><span className={`border px-2 py-1 text-[9px] font-black uppercase ${statusClass(license.status)}`}>{license.status || "-"}</span></td><td className="p-2 text-right"><button type="button" onClick={() => copyText(key, "License Key")} disabled={key === "-"} title="Copy license key" className="h-7 border border-[#069494] px-2 text-[#008585] disabled:opacity-40"><Clipboard size={13} /></button></td></tr>; })}
                        {!licenses.length && <tr><td colSpan="9" className="p-8 text-center text-[11px] text-[#637877]">No license history found.</td></tr>}
                      </tbody></table></div>
                    </div>
                  )}
                </div>
              </>
            ) : <div className="grid h-full place-items-center p-6 text-center"><div><Building2 size={44} className="mx-auto text-[#93A6A6]" /><h2 className="mt-3 text-[15px] font-black text-[#003636]">No company selected</h2><p className="mt-1 text-[11px] text-[#637877]">Create a company or select one from the register.</p><button type="button" onClick={openCreateCompany} className="mt-4 h-9 bg-[#069495] px-4 text-[11px] font-black uppercase text-white">Create Company</button></div></div>}
          </section>
        </div>
      </FullScreenModal>

      <EnterpriseFormModal open={companyFormOpen} title={editingCompany ? "Edit Company" : "Create Company"} subtitle="" onClose={() => !saving && setCompanyFormOpen(false)} busy={saving} width="940px">
        <form onSubmit={saveCompany} className="space-y-4">
          <section><h3 className="mb-2 border-b border-[#D7DFDE] pb-1 text-[10px] font-black uppercase text-[#008585]">Company Details</h3><div className="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-3">
            <Field label="Company Name" required><input className={inputClass} value={companyForm.company_name} onChange={(event) => setCompanyForm({ ...companyForm, company_name: event.target.value })} /></Field>
            <Field label="Domain"><input className={inputClass} value={companyForm.domain} onChange={(event) => setCompanyForm({ ...companyForm, domain: event.target.value })} /></Field>
            <Field label="License Support Phone"><input className={inputClass} value={companyForm.license_support_phone} onChange={(event) => setCompanyForm({ ...companyForm, license_support_phone: event.target.value })} placeholder="Contact shown in expiry reminders" /></Field>
            <Field label="Address" required><input className={inputClass} value={companyForm.location_address} onChange={(event) => setCompanyForm({ ...companyForm, location_address: event.target.value })} /></Field>
            <Field label="City" required><input className={inputClass} value={companyForm.city} onChange={(event) => setCompanyForm({ ...companyForm, city: event.target.value })} /></Field>
            <Field label="Country" required><select className={inputClass} value={companyForm.country_id} onChange={(event) => { const country = countries.find((item) => String(item.id) === event.target.value); setCompanyForm({ ...companyForm, country_id: event.target.value, country: country?.name || "" }); }} required><option value="">Select country</option>{countries.map((country) => <option key={country.id} value={country.id}>{country.name}{country.code ? ` (${country.code})` : ""}</option>)}</select></Field>
            <Field label="Host IP"><input className={inputClass} value={companyForm.host_ip} onChange={(event) => setCompanyForm({ ...companyForm, host_ip: event.target.value })} /></Field>
            <Field label="Status"><select className={inputClass} value={companyForm.status} onChange={(event) => setCompanyForm({ ...companyForm, status: event.target.value })}><option value="active">Active</option><option value="inactive">Inactive</option><option value="suspended">Suspended</option></select></Field>
            <div className="lg:col-span-3"><span className="mb-1 block text-[10px] font-black uppercase text-[#465858]">Company Logo</span><div className="flex min-h-[108px] items-center gap-3 border border-[#CBD6D6] bg-[#F8FAFC] p-3"><div className="flex h-20 w-24 shrink-0 items-center justify-center overflow-hidden border border-[#CBD6D6] bg-white">{companyLogoPreview || companyForm.logo ? <img src={companyLogoPreview || companyForm.logo} alt="Company logo preview" className="h-full w-full object-contain" /> : <ImageIcon size={24} className="text-[#93A6A6]" />}</div><div className="flex flex-wrap gap-2"><input id="company-logo-file" type="file" accept="image/png,image/jpeg,image/jpg,image/webp" className="hidden" onChange={selectCompanyLogo} /><label htmlFor="company-logo-file" className="inline-flex h-9 cursor-pointer items-center gap-1.5 border border-[#ACB8B8] bg-white px-3 text-[10px] font-black uppercase text-[#324444] hover:border-[#069495]"><Upload size={13} /> Select Image</label>{companyLogoPreview && <button type="button" onClick={() => { setCompanyLogoFile(null); setCompanyLogoPreview(""); }} className="h-9 border border-red-300 bg-white px-3 text-[10px] font-black uppercase text-red-700">Clear Selection</button>}</div></div></div>
          </div></section>
          {!editingCompany && <><section><h3 className="mb-2 border-b border-[#D7DFDE] pb-1 text-[10px] font-black uppercase text-[#008585]">Initial Branch</h3><div className="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-3">
            <Field label="Branch Name" required><input className={inputClass} value={initialBranch.branch_name} onChange={(event) => setInitialBranch({ ...initialBranch, branch_name: event.target.value })} /></Field><Field label="Branch Code"><input className={inputClass} value={initialBranch.branch_code} onChange={(event) => setInitialBranch({ ...initialBranch, branch_code: event.target.value })} /></Field><Field label="Level"><input className={inputClass} value={initialBranch.level} onChange={(event) => setInitialBranch({ ...initialBranch, level: event.target.value })} /></Field><Field label="Address"><input className={inputClass} value={initialBranch.branch_address} onChange={(event) => setInitialBranch({ ...initialBranch, branch_address: event.target.value })} /></Field><Field label="City"><input className={inputClass} value={initialBranch.branch_city} onChange={(event) => setInitialBranch({ ...initialBranch, branch_city: event.target.value })} /></Field><Field label="Country"><input className={inputClass} value={initialBranch.branch_country} onChange={(event) => setInitialBranch({ ...initialBranch, branch_country: event.target.value })} /></Field>
          </div></section><section><h3 className="mb-2 border-b border-[#D7DFDE] pb-1 text-[10px] font-black uppercase text-[#008585]">Initial License</h3><LicenseFields value={initialLicense} onChange={setInitialLicense} /></section></>}
          <div className="flex justify-end gap-2 border-t border-[#D7DFDE] pt-3"><button type="button" onClick={() => setCompanyFormOpen(false)} disabled={saving} className="h-9 border border-[#ACB8B8] bg-white px-4 text-[10px] font-black uppercase">Cancel</button><button type="submit" disabled={saving} className="h-9 bg-[#069495] px-5 text-[10px] font-black uppercase text-white disabled:opacity-50">{saving ? "Saving..." : editingCompany ? "Update Company" : "Create Company"}</button></div>
        </form>
      </EnterpriseFormModal>

      <EnterpriseFormModal open={branchFormOpen} title={editingBranch ? "Edit Branch" : "Add Branch"} subtitle={selectedCompany?.company_name || "Selected company"} onClose={() => !saving && setBranchFormOpen(false)} busy={saving} width="760px"><form onSubmit={saveBranch} className="space-y-4"><div className="grid grid-cols-1 gap-3 md:grid-cols-2"><Field label="Branch Name" required><input className={inputClass} value={branchForm.branch_name} onChange={(event) => setBranchForm({ ...branchForm, branch_name: event.target.value })} /></Field><Field label="Branch Code"><input className={inputClass} value={branchForm.branch_code} onChange={(event) => setBranchForm({ ...branchForm, branch_code: event.target.value })} /></Field><Field label="Address"><input className={inputClass} value={branchForm.branch_address} onChange={(event) => setBranchForm({ ...branchForm, branch_address: event.target.value })} /></Field><Field label="City"><input className={inputClass} value={branchForm.branch_city} onChange={(event) => setBranchForm({ ...branchForm, branch_city: event.target.value })} /></Field><Field label="Country"><input className={inputClass} value={branchForm.branch_country} onChange={(event) => setBranchForm({ ...branchForm, branch_country: event.target.value })} /></Field><Field label="Level"><input className={inputClass} value={branchForm.level} onChange={(event) => setBranchForm({ ...branchForm, level: event.target.value })} /></Field><Field label="Status"><select className={inputClass} value={branchForm.status} onChange={(event) => setBranchForm({ ...branchForm, status: event.target.value })}><option value="active">Active</option><option value="inactive">Inactive</option><option value="suspended">Suspended</option></select></Field><Field label="Main Branch"><label className="flex h-9 items-center gap-2 border border-[#ACB8B8] bg-[#F8FAFC] px-3 text-[11px] font-bold"><input type="checkbox" checked={branchForm.is_main_branch} onChange={(event) => setBranchForm({ ...branchForm, is_main_branch: event.target.checked })} /> Company main branch</label></Field></div><div className="flex justify-end gap-2 border-t border-[#D7DFDE] pt-3"><button type="button" onClick={() => setBranchFormOpen(false)} disabled={saving} className="h-9 border border-[#ACB8B8] px-4 text-[10px] font-black uppercase">Cancel</button><button type="submit" disabled={saving} className="h-9 bg-[#069495] px-5 text-[10px] font-black uppercase text-white disabled:opacity-50">{saving ? "Saving..." : "Save Branch"}</button></div></form></EnterpriseFormModal>

      <EnterpriseFormModal open={licenseFormOpen} title="Assign / Renew License" subtitle={selectedCompany?.company_name || "Selected company"} onClose={() => !saving && setLicenseFormOpen(false)} busy={saving} width="760px"><form onSubmit={saveLicense} className="space-y-4"><div className="border border-[#ADE5E4] bg-[#EFF7F7] p-3 text-[11px] text-[#005D5D]">A new <strong>XXXX-XXXX-XXXX-XXXX</strong> key will be generated by the server and can activate this company without an internet connection.</div><LicenseFields value={licenseForm} onChange={setLicenseForm} /><div className="flex justify-end gap-2 border-t border-[#D7DFDE] pt-3"><button type="button" onClick={() => setLicenseFormOpen(false)} disabled={saving} className="h-9 border border-[#ACB8B8] px-4 text-[10px] font-black uppercase">Cancel</button><button type="submit" disabled={saving} className="h-9 bg-[#069495] px-5 text-[10px] font-black uppercase text-white disabled:opacity-50">{saving ? "Generating..." : "Generate & Assign"}</button></div></form></EnterpriseFormModal>

      <ConfirmDialog confirmation={confirmation} busy={deleting} onCancel={() => setConfirmation(null)} onConfirm={runDelete} />
      <ReusableMessageModal open={message.open} type={message.type} title={message.title} message={message.message} onClose={() => setMessage((current) => ({ ...current, open: false }))} />
    </>
  );
}

function LicenseFields({ value, onChange }) {
  return <div className="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-3"><Field label="License Type" required><select className={inputClass} value={value.license_type} onChange={(event) => onChange({ ...value, license_type: event.target.value })}>{PLATFORM_LICENSE_TYPES.map((type) => <option key={type} value={type}>{type.charAt(0).toUpperCase() + type.slice(1)}</option>)}</select></Field><Field label="Maximum Branches" required><input type="number" min="1" className={inputClass} value={value.max_branches} onChange={(event) => onChange({ ...value, max_branches: event.target.value })} /></Field><Field label="Maximum Users" required><input type="number" min="1" className={inputClass} value={value.max_users} onChange={(event) => onChange({ ...value, max_users: event.target.value })} /></Field><Field label="Maximum Devices" required><input type="number" min="1" className={inputClass} value={value.max_devices} onChange={(event) => onChange({ ...value, max_devices: event.target.value })} /></Field><Field label="Starts"><input type="date" className={inputClass} value={value.starts_at} onChange={(event) => onChange({ ...value, starts_at: event.target.value })} /></Field><Field label="Expires" required><input type="date" min={value.starts_at || today()} className={inputClass} value={value.expires_at} onChange={(event) => onChange({ ...value, expires_at: event.target.value })} /></Field></div>;
}

import React, { useEffect, useMemo, useRef, useState } from "react";
import {
  commitHospitalityWorkbook,
  getHospitalityImportScope,
  previewHospitalityWorkbook,
} from "../../../services/ProductImportService";

const ACCEPTED_FILE_TYPES = ".xlsx";

const unwrapCompany = (payload) => (
  payload?.company
  || payload?.data?.company
  || payload?.data?.companyInfo
  || payload?.schoolInfo
  || payload?.data?.schoolInfo
  || payload?.data
  || null
);

const unwrapImportData = (payload) => payload?.data?.data || payload?.data || payload || {};

const getStoredBranchId = () => {
  try {
    const user = JSON.parse(localStorage.getItem("back_user") || "{}");
    return user?.selectedBranchId || user?.selectedBranch?.id || user?.branch_id || user?.branch?.id || localStorage.getItem("branch_id") || "";
  } catch {
    return localStorage.getItem("branch_id") || "";
  }
};

const labelFor = (record, fallback) => {
  if (!record) return fallback;
  const name = record.name || record.branch_name || record.company_name || fallback;
  const code = record.code || record.branch_code || record.company_code || "";
  return code ? `${name} (${code})` : name;
};

const fileKey = (file, brandId, branchId) => (
  file
    ? [file.name, file.size, file.lastModified, brandId, branchId || ""].join(":")
    : ""
);

const formatBytes = (bytes = 0) => {
  const size = Number(bytes || 0);
  if (size < 1024 * 1024) return `${Math.max(size / 1024, 0).toFixed(1)} KB`;
  return `${(size / (1024 * 1024)).toFixed(2)} MB`;
};

const humanize = (value = "") => String(value)
  .replace(/[_-]+/g, " ")
  .replace(/\b\w/g, (character) => character.toUpperCase());

const statEntries = (statistics = {}) => {
  const rows = [];
  Object.entries(statistics || {}).forEach(([key, value]) => {
    if (typeof value === "number" || typeof value === "string") {
      rows.push([humanize(key), value]);
      return;
    }
    if (value && typeof value === "object" && !Array.isArray(value)) {
      Object.entries(value).forEach(([childKey, childValue]) => {
        if (typeof childValue === "number" || typeof childValue === "string") {
          rows.push([humanize(`${key} ${childKey}`), childValue]);
        }
      });
    }
  });
  return rows.slice(0, 12);
};

const messageRows = (messages = []) => {
  if (messages && typeof messages === "object" && !Array.isArray(messages)) {
    return Object.entries(messages).flatMap(([field, value]) => (
      messageRows(value).map((message) => `${humanize(field)}: ${message}`)
    ));
  }
  if (!Array.isArray(messages)) return messages ? [String(messages)] : [];
  return messages.map((item) => {
    if (typeof item === "string") return item;
    const sheet = item?.sheet ? `${item.sheet}${item.row ? ` row ${item.row}` : ""}: ` : "";
    return `${sheet}${item?.message || item?.error || JSON.stringify(item)}`;
  });
};

const Field = ({ label, required = false, children }) => (
  <label className="grid gap-1">
    <span className="text-[12px] font-semibold text-[#324443]">
      {label}{required ? " *" : ""}
    </span>
    {children}
  </label>
);

const ActionButton = ({ tone = "default", children, className = "", ...props }) => {
  const tones = {
    default: "border-[#96A5A4] bg-white text-[#324443] hover:bg-[#F2F4F7]",
    primary: "border-[#004343] bg-[#004344] text-white hover:bg-[#004F4F]",
    accent: "border-[#079F9F] bg-[#079F9F] text-white hover:bg-[#067C7C]",
    danger: "border-[#B42318] bg-[#B42318] text-white hover:bg-[#912018]",
  };
  return (
    <button
      type="button"
      {...props}
      className={`h-10 border px-4 text-[13px] font-semibold disabled:cursor-not-allowed disabled:opacity-50 ${tones[tone]} ${className}`}
    >
      {children}
    </button>
  );
};

export default function ProductImportModal({ open = true, onClose, onImported }) {
  const fileInputRef = useRef(null);
  const [company, setCompany] = useState(null);
  const [brands, setBrands] = useState([]);
  const [requiresBranch, setRequiresBranch] = useState(false);
  const [brandId, setBrandId] = useState("");
  const [branchId, setBranchId] = useState("");
  const [file, setFile] = useState(null);
  const [preview, setPreview] = useState(null);
  const [previewedKey, setPreviewedKey] = useState("");
  const [commitResult, setCommitResult] = useState(null);
  const [loading, setLoading] = useState("");
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  const selectedBrand = useMemo(
    () => brands.find((brand) => String(brand.id) === String(brandId)) || null,
    [brands, brandId],
  );
  const availableShops = useMemo(
    () => (Array.isArray(selectedBrand?.shops) ? selectedBrand.shops : []),
    [selectedBrand],
  );
  const selectedBranch = useMemo(
    () => availableShops.find((shop) => String(shop.branch_id) === String(branchId)) || null,
    [availableShops, branchId],
  );
  const currentFileKey = fileKey(file, brandId, branchId);
  const previewData = unwrapImportData(preview);
  const resultData = unwrapImportData(commitResult);
  const statistics = resultData.statistics || previewData.statistics || {};
  const warnings = messageRows(resultData.warnings || previewData.warnings || []);
  const errors = messageRows(previewData.errors || []);
  const sheets = Array.isArray(previewData.sheets) ? previewData.sheets : [];
  const canCommit = Boolean(
    preview
    && previewData.can_commit
    && previewedKey
    && previewedKey === currentFileKey
    && errors.length === 0
    && !commitResult
  );

  const clearOutcome = () => {
    setPreview(null);
    setPreviewedKey("");
    setCommitResult(null);
    setError("");
    setNotice("");
  };

  const loadScope = async () => {
    try {
      setLoading("scope");
      setError("");
      const response = await getHospitalityImportScope();
      const scope = unwrapImportData(response);
      const nextCompany = scope?.company || unwrapCompany(response);
      const nextBrands = Array.isArray(scope?.brands) ? scope.brands : [];
      const nextRequiresBranch = Boolean(scope?.requires_branch);
      const storedBranchId = getStoredBranchId();
      const currentBrand = nextBrands.find((brand) => String(brand.id) === String(brandId));
      const storedBranchBrands = storedBranchId
        ? nextBrands.filter((brand) => (
            Array.isArray(brand?.shops)
            && brand.shops.some((shop) => String(shop.branch_id) === String(storedBranchId))
          ))
        : [];
      const initialBrand = currentBrand
        || (storedBranchBrands.length === 1 ? storedBranchBrands[0] : null)
        || (nextBrands.length === 1 ? nextBrands[0] : null);
      const initialShops = Array.isArray(initialBrand?.shops) ? initialBrand.shops : [];

      setCompany(nextCompany);
      setBrands(nextBrands);
      setRequiresBranch(nextRequiresBranch);
      setBrandId(initialBrand ? String(initialBrand.id) : "");
      setBranchId(
        storedBranchId && initialShops.some((shop) => String(shop.branch_id) === String(storedBranchId))
          ? String(storedBranchId)
          : nextRequiresBranch && initialShops.length === 1
            ? String(initialShops[0].branch_id)
            : "",
      );
    } catch (scopeError) {
      setCompany(null);
      setBrands([]);
      setRequiresBranch(false);
      setBrandId("");
      setBranchId("");
      setPreview(null);
      setPreviewedKey("");
      setCommitResult(null);
      setError(scopeError?.response?.data?.message || scopeError?.message || "Unable to load brands.");
    } finally {
      setLoading("");
    }
  };

  useEffect(() => {
    if (open) loadScope();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const reset = () => {
    setFile(null);
    clearOutcome();
    if (fileInputRef.current) fileInputRef.current.value = "";
  };

  const validateSelection = () => {
    if (!brandId) {
      setError("Select a brand.");
      return false;
    }
    if (requiresBranch && !branchId) {
      setError("Select a shop.");
      return false;
    }
    if (!(file instanceof File)) {
      setError("Select an Excel workbook.");
      return false;
    }
    return true;
  };

  const previewWorkbook = async () => {
    if (!validateSelection()) return;
    try {
      setLoading("preview");
      setError("");
      setNotice("");
      setCommitResult(null);
      const response = await previewHospitalityWorkbook({
        file,
        brand_id: brandId,
        branch_id: branchId,
        source_system: "gaap",
      });
      const data = unwrapImportData(response);
      setPreview(response);
      setPreviewedKey(currentFileKey);
      setNotice(data.can_commit ? "Workbook is ready to import." : "Review the validation results.");
    } catch (previewError) {
      const failure = previewError?.response?.data;
      if (failure?.data) {
        setPreview(failure);
        setPreviewedKey(currentFileKey);
      }
      setError(failure?.message || previewError?.message || "Workbook validation failed.");
    } finally {
      setLoading("");
    }
  };

  const commitWorkbook = async () => {
    if (!canCommit || !validateSelection()) return;
    try {
      setConfirmOpen(false);
      setLoading("commit");
      setError("");
      setNotice("");
      const response = await commitHospitalityWorkbook({
        file,
        brand_id: brandId,
        branch_id: branchId,
        source_system: "gaap",
      });
      setCommitResult(response);
      setNotice(response?.message || "Brand menu imported.");
      if (typeof onImported === "function") onImported(response);
    } catch (commitError) {
      const failure = commitError?.response?.data;
      setError(failure?.message || commitError?.message || "Import failed.");
    } finally {
      setLoading("");
    }
  };

  if (!open) return null;

  return (
    <>
      <div className="fixed inset-0 z-[80] flex items-center justify-center bg-[#003030]/75 p-3 font-sans">
        <div
          role="dialog"
          aria-modal="true"
          aria-label="Brand menu import"
          className="flex h-[min(94vh,920px)] w-[min(97vw,1540px)] min-h-[620px] flex-col overflow-hidden border border-[#96A5A4] bg-[#EEF2F2] shadow-2xl"
        >
          <header className="flex min-h-16 items-center justify-between border-b-2 border-[#0AA7A7] bg-[#003333] px-5 text-white">
            <div>
              <h2 className="text-[17px] font-bold">Brand Menu Import</h2>
              <p className="text-[12px] text-white/70">Products, recipes, modifiers and prep messages</p>
            </div>
            <button
              type="button"
              onClick={onClose}
              disabled={Boolean(loading)}
              className="h-10 min-w-20 border border-white/35 px-4 text-[13px] font-semibold hover:bg-white/10 disabled:opacity-50"
            >
              Close
            </button>
          </header>

          {(error || notice) && (
            <div className={`border-b px-5 py-2 text-[13px] font-semibold ${error ? "border-[#FDA29B] bg-[#FEF3F2] text-[#B42318]" : "border-[#ABEFC6] bg-[#ECFDF3] text-[#067647]"}`}>
              {error || notice}
            </div>
          )}

          <div className="grid min-h-0 flex-1 gap-3 overflow-hidden p-3 xl:grid-cols-[390px_minmax(0,1fr)]">
            <section className="flex min-h-0 flex-col border border-[#B9C3C3] bg-white">
              <div className="border-b border-[#CFD6D6] bg-[#F8FAFC] px-4 py-3 text-[13px] font-bold text-[#0C1B1B]">
                Import Setup
              </div>
              <div className="grid gap-4 overflow-auto p-4">
                <Field label="Company">
                  <div className="flex h-10 items-center border border-[#CFD6D6] bg-[#F2F4F7] px-3 text-[13px] text-[#465757]">
                    {company ? labelFor(company, "Company") : loading === "scope" ? "Loading..." : "Not available"}
                  </div>
                </Field>

                <Field label="Brand" required>
                  <select
                    value={brandId}
                    onChange={(event) => {
                      const nextBrandId = event.target.value;
                      const nextBrand = brands.find((brand) => String(brand.id) === String(nextBrandId));
                      const storedBranchId = getStoredBranchId();
                      const hasStoredShop = storedBranchId && Array.isArray(nextBrand?.shops)
                        && nextBrand.shops.some((shop) => String(shop.branch_id) === String(storedBranchId));
                      const nextShops = Array.isArray(nextBrand?.shops) ? nextBrand.shops : [];
                      setBrandId(nextBrandId);
                      setBranchId(
                        hasStoredShop
                          ? String(storedBranchId)
                          : requiresBranch && nextShops.length === 1
                            ? String(nextShops[0].branch_id)
                            : "",
                      );
                      clearOutcome();
                    }}
                    disabled={Boolean(loading)}
                    className="h-10 border border-[#96A5A4] bg-white px-3 text-[13px] outline-none focus:border-[#079F9F]"
                  >
                    <option value="">
                      {loading === "scope" ? "Loading brands..." : brands.length ? "Select brand" : "No brands available"}
                    </option>
                    {brands.map((brand) => (
                      <option key={brand.id} value={brand.id}>{labelFor(brand, "Brand")}</option>
                    ))}
                  </select>
                </Field>

                <Field label={requiresBranch ? "Shop" : "Shop (optional)"} required={requiresBranch}>
                  <select
                    value={branchId}
                    onChange={(event) => {
                      setBranchId(event.target.value);
                      clearOutcome();
                    }}
                    disabled={Boolean(loading)}
                    className="h-10 border border-[#96A5A4] bg-white px-3 text-[13px] outline-none focus:border-[#079F9F]"
                  >
                    <option value="">{requiresBranch ? "Select shop" : "Brand-wide import"}</option>
                    {availableShops.map((shop) => (
                      <option key={shop.id} value={shop.branch_id}>
                        {labelFor(shop, "Shop")}{shop.branch_name ? ` · ${shop.branch_name}` : ""}
                      </option>
                    ))}
                  </select>
                </Field>

                <Field label="Excel Workbook" required>
                  <input
                    ref={fileInputRef}
                    type="file"
                    accept={ACCEPTED_FILE_TYPES}
                    onChange={(event) => {
                      setFile(event.target.files?.[0] || null);
                      clearOutcome();
                    }}
                    disabled={Boolean(loading)}
                    className="min-h-11 w-full border border-[#96A5A4] bg-white px-3 py-2 text-[13px]"
                  />
                </Field>

                {file && (
                  <div className="border border-[#CFD6D6] bg-[#F8FAFC] px-3 py-3 text-[12px]">
                    <div className="truncate font-semibold text-[#0C1B1B]">{file.name}</div>
                    <div className="mt-1 text-[#627474]">{formatBytes(file.size)}</div>
                  </div>
                )}

                <div className="grid grid-cols-2 gap-2">
                  <ActionButton
                    tone="primary"
                    onClick={previewWorkbook}
                    disabled={Boolean(loading)}
                  >
                    {loading === "preview" ? "Validating..." : "Validate"}
                  </ActionButton>
                  <ActionButton onClick={reset} disabled={Boolean(loading)}>Clear</ActionButton>
                </div>
              </div>
            </section>

            <section className="flex min-h-0 flex-col gap-3 overflow-hidden">
              <div className="grid shrink-0 gap-2 sm:grid-cols-2 lg:grid-cols-4">
                {(statEntries(statistics).length ? statEntries(statistics).slice(0, 4) : [
                  ["Menu Items", 0],
                  ["Raw Materials", 0],
                  ["Recipes", 0],
                  ["Issues", errors.length],
                ]).map(([label, value]) => (
                  <div key={label} className="border border-[#B9C3C3] bg-white px-4 py-3">
                    <div className="text-[11px] font-semibold uppercase tracking-wide text-[#627474]">{label}</div>
                    <div className="mt-1 text-[22px] font-bold text-[#0C1B1B]">{value ?? 0}</div>
                  </div>
                ))}
              </div>

              <div className="flex min-h-0 flex-1 flex-col border border-[#B9C3C3] bg-white">
                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-[#CFD6D6] bg-[#F8FAFC] px-4 py-3">
                  <div>
                    <div className="text-[13px] font-bold text-[#0C1B1B]">Validation</div>
                    {preview && (
                      <div className="mt-0.5 text-[11px] text-[#627474]">
                        {selectedBrand?.name || "Brand"}
                        {selectedBranch ? ` · ${labelFor(selectedBranch, "Branch")}` : ""}
                        {previewData.format ? ` · ${humanize(previewData.format)}` : ""}
                      </div>
                    )}
                  </div>
                  <ActionButton
                    tone="accent"
                    onClick={() => setConfirmOpen(true)}
                    disabled={!canCommit || Boolean(loading)}
                  >
                    {loading === "commit" ? "Importing..." : commitResult ? "Imported" : "Import"}
                  </ActionButton>
                </div>

                <div className="min-h-0 flex-1 overflow-auto">
                  {!preview && !commitResult ? (
                    <div className="flex h-full min-h-60 items-center justify-center text-[13px] text-[#627474]">
                      Select a brand and workbook, then validate.
                    </div>
                  ) : (
                    <div className="grid gap-3 p-4">
                      {sheets.length > 0 && (
                        <div className="flex flex-wrap gap-1.5">
                          {sheets.map((sheet) => (
                            <span key={typeof sheet === "string" ? sheet : sheet?.name || JSON.stringify(sheet)} className="border border-[#CFD6D6] bg-[#F8FAFC] px-2 py-1 text-[11px] text-[#465757]">
                              {typeof sheet === "string" ? sheet : sheet?.name || sheet?.sheet || "Sheet"}
                            </span>
                          ))}
                        </div>
                      )}

                      {statEntries(statistics).length > 4 && (
                        <div className="overflow-hidden border border-[#CFD6D6]">
                          <table className="w-full border-collapse text-left text-[12px]">
                            <thead className="bg-[#004344] text-white">
                              <tr><th className="px-3 py-2 font-semibold">Record</th><th className="w-32 px-3 py-2 text-right font-semibold">Count</th></tr>
                            </thead>
                            <tbody>
                              {statEntries(statistics).map(([label, value]) => (
                                <tr key={label} className="border-b border-[#E9EDED] last:border-b-0">
                                  <td className="px-3 py-2 text-[#324443]">{label}</td>
                                  <td className="px-3 py-2 text-right font-semibold text-[#0C1B1B]">{value}</td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </div>
                      )}

                      {(errors.length > 0 || warnings.length > 0) && (
                        <div className="overflow-hidden border border-[#CFD6D6]">
                          <table className="w-full border-collapse text-left text-[12px]">
                            <thead className="bg-[#004344] text-white">
                              <tr><th className="w-28 px-3 py-2 font-semibold">Type</th><th className="px-3 py-2 font-semibold">Details</th></tr>
                            </thead>
                            <tbody>
                              {errors.map((item, index) => (
                                <tr key={`error-${index}`} className="border-b border-[#E9EDED] bg-[#FEF3F2]">
                                  <td className="px-3 py-2 font-semibold text-[#B42318]">Error</td>
                                  <td className="px-3 py-2 text-[#B42318]">{item}</td>
                                </tr>
                              ))}
                              {warnings.map((item, index) => (
                                <tr key={`warning-${index}`} className="border-b border-[#E9EDED] bg-[#FFFAEB]">
                                  <td className="px-3 py-2 font-semibold text-[#B54708]">Warning</td>
                                  <td className="px-3 py-2 text-[#7A2E0E]">{item}</td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </div>
                      )}

                      {previewData.can_commit && errors.length === 0 && !commitResult && (
                        <div className="border border-[#ABEFC6] bg-[#ECFDF3] px-4 py-3 text-[13px] font-semibold text-[#067647]">
                          Ready to import into {selectedBrand?.name || "the selected brand"}.
                        </div>
                      )}

                      {commitResult && (
                        <div className="border border-[#ABEFC6] bg-[#ECFDF3] px-4 py-3 text-[13px] text-[#067647]">
                          <div className="font-bold">Import complete</div>
                          <div className="mt-1">{resultData.import_id ? `Reference: ${resultData.import_id}` : "The brand menu is ready."}</div>
                        </div>
                      )}
                    </div>
                  )}
                </div>
              </div>
            </section>
          </div>
        </div>
      </div>

      {confirmOpen && (
        <div className="fixed inset-0 z-[95] flex items-center justify-center bg-[#003333]/80 p-4 font-sans">
          <div role="dialog" aria-modal="true" aria-label="Confirm brand menu import" className="sx-large-modal-window border border-[#96A5A4] bg-white shadow-2xl">
            <div className="border-b-2 border-[#0AA7A7] bg-[#003333] px-5 py-4 text-white">
              <h3 className="text-[17px] font-bold">Import Brand Menu</h3>
            </div>
            <div className="flex flex-1 flex-col justify-center p-6">
              <div className="text-[18px] font-semibold text-[#0C1B1B]">{selectedBrand?.name || "Selected brand"}</div>
              <div className="mt-2 text-[13px] text-[#465757]">{file?.name}</div>
              <div className="mt-5 border border-[#CFD6D6] bg-[#F8FAFC] p-4 text-[13px] text-[#324443]">
                Existing matching records will be updated. New records will be created only for this brand.
              </div>
            </div>
            <div className="flex justify-end gap-2 border-t border-[#CFD6D6] bg-[#F8FAFC] p-4">
              <ActionButton onClick={() => setConfirmOpen(false)} disabled={loading === "commit"}>Cancel</ActionButton>
              <ActionButton tone="accent" onClick={commitWorkbook} disabled={loading === "commit"}>
                {loading === "commit" ? "Importing..." : "Import"}
              </ActionButton>
            </div>
          </div>
        </div>
      )}
    </>
  );
}

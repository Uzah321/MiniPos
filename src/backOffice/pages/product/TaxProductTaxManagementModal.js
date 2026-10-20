import React, { useEffect, useMemo, useState } from "react";
import FullScreenModal from "../../../components/FullScreenModal";
import { requestEnterpriseConfirmation } from "../../../components/EnterpriseConfirmHost";
import EnterpriseFormModal from "../../../components/EnterpriseFormModal";
import {
  getTaxes,
  createTax,
  updateTax,
  deleteTax,
  getProductTaxes,
  syncManyProductTaxes,
  previewProductTax,
} from "../../../services/TaxService";
import { getProducts } from "../../../services/ProductService";

const emptyTax = {
  id: null,
  name: "",
  tax_code: "",
  tax_id: "",
  type: "percentage",
  rate: "0",
  obliged_to_zimra: false,
  is_active: true,
  valid_from: "",
  valid_till: "",
  description: "",
};

const normaliseRows = (response) => {
  const payload = response?.data || response;
  if (Array.isArray(payload?.data)) return payload.data;
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.rows)) return payload.rows;
  return [];
};

const getErrorMessage = (error, fallback = "Request failed.") => {
  const data = error?.response?.data;
  if (data?.errors && typeof data.errors === "object") {
    return Object.values(data.errors).flat().join("\n");
  }
  return data?.message || data?.error || error?.message || fallback;
};

const boolValue = (value) =>
  value === true || value === 1 || value === "1" || value === "true";

const money = (value, currency = "USD") =>
  `${currency || "USD"} ${Number(value || 0).toFixed(2)}`;

const getPrimaryPrice = (product) => {
  const prices = Array.isArray(product?.prices) ? product.prices : [];
  const active = prices.filter(
    (price) => price.is_active !== false && !price.deleted_at
  );
  if (!active.length) return null;
  return (
    active.find((price) => boolValue(price?.price_level?.is_default)) ||
    active.find((price) => boolValue(price?.is_default)) ||
    active[0]
  );
};

const getProductPriceNumber = (product) =>
  Number(getPrimaryPrice(product)?.price || 0);

const getProductCurrency = (product) =>
  getPrimaryPrice(product)?.currency || "USD";

const getProductTaxRows = (product) => {
  if (Array.isArray(product?.taxes) && product.taxes.length) return product.taxes;
  if (Array.isArray(product?.product_taxes) && product.product_taxes.length) {
    return product.product_taxes;
  }
  return [];
};

const productHasTax = (product) => getProductTaxRows(product).length > 0;

const getTaxCode = (row) =>
  row?.tax_code || row?.tax?.tax_code || row?.tax_name || row?.tax?.name || "Tax";

const getTaxName = (row) =>
  row?.tax_name || row?.tax?.name || row?.tax_code || row?.tax?.tax_code || "Tax";

const getTaxRate = (row) => row?.tax_rate || row?.tax?.rate || row?.rate || "";

const getProductTaxSummary = (product) => {
  const rows = getProductTaxRows(product);
  if (!rows.length) return "No Tax";

  return rows
    .map((row) => {
      const name = getTaxName(row);
      const code = row?.tax_code || row?.tax?.tax_code || "";
      const rate = getTaxRate(row);
      const inclusive = row?.is_inclusive ? "Inclusive" : "Exclusive";
      return `${name}${code ? ` (${code})` : ""}${
        rate ? ` ${Number(rate).toFixed(2)}%` : ""
      } - ${inclusive}`;
    })
    .join(", ");
};

const getProductTaxShort = (product) => {
  const rows = getProductTaxRows(product);
  if (!rows.length) return "No Tax";

  return rows
    .map((row) => {
      const code = getTaxCode(row);
      const rate = getTaxRate(row);
      return `${code}${rate ? ` ${Number(rate).toFixed(2)}%` : ""}`;
    })
    .join(" + ");
};

export default function TaxProductTaxManagementModal({ open, onClose }) {
  const [activeTab, setActiveTab] = useState("taxes");
  const [notice, setNotice] = useState("");
  const [loading, setLoading] = useState(false);

  const [taxes, setTaxes] = useState([]);
  const [taxSearch, setTaxSearch] = useState("");
  const [taxForm, setTaxForm] = useState(emptyTax);
  const [taxFormOpen, setTaxFormOpen] = useState(false);

  const [products, setProducts] = useState([]);
  const [productSearch, setProductSearch] = useState("");
  const [selectedProductIds, setSelectedProductIds] = useState([]);
  const [focusedProductId, setFocusedProductId] = useState(null);

  const [assignmentTaxes, setAssignmentTaxes] = useState([]);
  const [previewQuantity, setPreviewQuantity] = useState("1");
  const [previewResult, setPreviewResult] = useState(null);

  useEffect(() => {
    if (!open) return;
    loadAll();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const filteredTaxes = useMemo(() => {
    const q = taxSearch.toLowerCase();
    return taxes.filter((tax) =>
      [
        tax.name,
        tax.tax_code,
        tax.tax_id,
        tax.type,
        tax.rate,
        tax.obliged_to_zimra ? "zimra" : "local",
      ]
        .join(" ")
        .toLowerCase()
        .includes(q)
    );
  }, [taxes, taxSearch]);

  const filteredProducts = useMemo(() => {
    const q = productSearch.toLowerCase();
    return products.filter((product) =>
      [
        product.name,
        product.code,
        product.sku,
        product.barcode,
        product.category?.name,
        product.supplier?.name,
        product.uom?.name,
        product.uom?.code,
        getProductTaxSummary(product),
        productHasTax(product) ? "taxed taxable has tax assigned" : "no tax untaxed",
      ]
        .join(" ")
        .toLowerCase()
        .includes(q)
    );
  }, [products, productSearch]);

  const focusedProduct = useMemo(() => {
    if (focusedProductId) {
      return products.find((product) => product.id === focusedProductId) || null;
    }
    if (selectedProductIds.length === 1) {
      return products.find((product) => product.id === selectedProductIds[0]) || null;
    }
    return products.find((product) => selectedProductIds.includes(product.id)) || null;
  }, [products, focusedProductId, selectedProductIds]);

  const selectedProducts = useMemo(
    () => products.filter((product) => selectedProductIds.includes(product.id)),
    [products, selectedProductIds]
  );

  const allFilteredSelected =
    filteredProducts.length > 0 &&
    filteredProducts.every((product) => selectedProductIds.includes(product.id));

  const assignmentPayload = useMemo(() => {
    return assignmentTaxes.map((row) => ({
      tax_id: row.tax_id || row.tax?.id,
      branch_id: row.branch_id || null,
      is_inclusive: boolValue(row.is_inclusive),
      is_active: row.is_active !== false,
      starts_at: row.starts_at || null,
      ends_at: row.ends_at || null,
    }));
  }, [assignmentTaxes]);

  if (!open) return null;

  const showNotice = (message) => {
    setNotice(message);
    window.setTimeout(() => setNotice(""), 4000);
  };

  const loadAll = async () => {
    try {
      setLoading(true);
      const [taxResponse, productResponse] = await Promise.all([
        getTaxes({ per_page: 500 }),
        getProducts({ per_page: 500 }),
      ]);
      setTaxes(normaliseRows(taxResponse));
      setProducts(normaliseRows(productResponse));
    } catch (error) {
      showNotice(getErrorMessage(error));
    } finally {
      setLoading(false);
    }
  };

  const resetTaxForm = () => setTaxForm(emptyTax);
  const openNewTax = () => {
    resetTaxForm();
    setTaxFormOpen(true);
  };
  const openEditTax = (tax) => {
    setTaxForm({ ...emptyTax, ...tax });
    setTaxFormOpen(true);
  };

  const saveTax = async () => {
    try {
      setLoading(true);
      const payload = {
        ...taxForm,
        tax_code: taxForm.tax_code ? taxForm.tax_code.toUpperCase().slice(0, 3) : null,
        tax_id: taxForm.tax_id === "" || taxForm.tax_id === null ? null : Number(taxForm.tax_id),
        rate: Number(taxForm.rate || 0),
        obliged_to_zimra: boolValue(taxForm.obliged_to_zimra),
        is_active: taxForm.is_active !== false,
        valid_from: taxForm.valid_from || null,
        valid_till: taxForm.valid_till || null,
      };

      if (payload.obliged_to_zimra && !payload.tax_id && payload.tax_id !== 0) {
        showNotice("FDMS Tax ID is required when Obliged to ZIMRA is checked.");
        return;
      }
      if (payload.obliged_to_zimra && !payload.tax_code) {
        showNotice("Tax Code is required when Obliged to ZIMRA is checked.");
        return;
      }

      if (taxForm.id) {
        await updateTax(taxForm.id, payload);
        showNotice("Tax updated successfully.");
      } else {
        await createTax(payload);
        showNotice("Tax created successfully.");
      }

      resetTaxForm();
      setTaxFormOpen(false);
      await loadAll();
    } catch (error) {
      showNotice(getErrorMessage(error));
    } finally {
      setLoading(false);
    }
  };

  const removeTax = async (tax) => {
    if (!await requestEnterpriseConfirmation({
      title: "Delete Tax",
      message: `Delete ${tax.name}?`,
      confirmLabel: "Delete tax",
    })) return;
    try {
      setLoading(true);
      await deleteTax(tax.id);
      showNotice("Tax deleted successfully.");
      await loadAll();
    } catch (error) {
      showNotice(getErrorMessage(error));
    } finally {
      setLoading(false);
    }
  };

  const toggleProduct = (product) => {
    setFocusedProductId(product.id);
    setPreviewResult(null);
    setSelectedProductIds((prev) =>
      prev.includes(product.id)
        ? prev.filter((id) => id !== product.id)
        : [...prev, product.id]
    );
  };

  const focusOnlyProduct = (product) => {
    setFocusedProductId(product.id);
    setPreviewResult(null);
  };

  const selectAllFilteredProducts = () => {
    const filteredIds = filteredProducts.map((product) => product.id);
    setPreviewResult(null);
    if (allFilteredSelected) {
      setSelectedProductIds((prev) => prev.filter((id) => !filteredIds.includes(id)));
      return;
    }
    setSelectedProductIds((prev) => Array.from(new Set([...prev, ...filteredIds])));
  };

  const clearProductSelection = () => {
    setSelectedProductIds([]);
    setFocusedProductId(null);
    setPreviewResult(null);
  };

  const loadTaxesFromFocusedProduct = async (product = focusedProduct) => {
    if (!product?.id) {
      showNotice("Select a product first.");
      return;
    }
    try {
      setLoading(true);
      setFocusedProductId(product.id);
      setPreviewResult(null);
      const response = await getProductTaxes(product.id);
      const rows = normaliseRows(response);
      setAssignmentTaxes(
        rows.map((row) => ({
          id: row.id,
          tax_id: row.tax_id || row.tax?.id,
          tax: row.tax,
          branch_id: row.branch_id || null,
          is_inclusive: boolValue(row.is_inclusive),
          is_active: row.is_active !== false,
          starts_at: row.starts_at || "",
          ends_at: row.ends_at || "",
        }))
      );
      showNotice(`Loaded existing taxes from ${product.name}.`);
    } catch (error) {
      showNotice(getErrorMessage(error));
    } finally {
      setLoading(false);
    }
  };

  const toggleAssignmentTax = (tax) => {
    const exists = assignmentTaxes.some(
      (row) => String(row.tax_id || row.tax?.id) === String(tax.id)
    );
    setPreviewResult(null);
    if (exists) {
      setAssignmentTaxes((prev) =>
        prev.filter((row) => String(row.tax_id || row.tax?.id) !== String(tax.id))
      );
      return;
    }
    setAssignmentTaxes((prev) => [
      ...prev,
      {
        id: `new-${tax.id}`,
        tax_id: tax.id,
        tax,
        branch_id: null,
        is_inclusive: false,
        is_active: true,
        starts_at: "",
        ends_at: "",
      },
    ]);
  };

  const updateAssignmentTax = (taxId, patch) => {
    setPreviewResult(null);
    setAssignmentTaxes((prev) =>
      prev.map((row) =>
        String(row.tax_id || row.tax?.id) === String(taxId)
          ? { ...row, ...patch }
          : row
      )
    );
  };

  const saveSelectedProductTaxes = async () => {
    if (!selectedProductIds.length) {
      showNotice("Select at least one product.");
      return;
    }
    try {
      setLoading(true);
      const response = await syncManyProductTaxes({
        product_ids: selectedProductIds,
        taxes: assignmentPayload,
      });
      const updated = response?.data?.products_updated || selectedProductIds.length;
      const assigned = response?.data?.taxes_assigned || assignmentPayload.length;
      showNotice(`${updated} product(s) updated with ${assigned} tax assignment(s).`);
      await loadAll();
    } catch (error) {
      showNotice(getErrorMessage(error));
    } finally {
      setLoading(false);
    }
  };

  const runPreview = async () => {
    if (!focusedProduct) {
      showNotice("Focus/select a product first.");
      return;
    }
    const price = getProductPriceNumber(focusedProduct);
    if (!price) {
      showNotice("Focused product has no price. Add a product price first.");
      return;
    }
    try {
      setLoading(true);
      const response = await previewProductTax(focusedProduct.id, {
        price,
        quantity: Number(previewQuantity || 1),
        discount_amount: 0,
        taxes: assignmentPayload,
      });
      setPreviewResult(response?.data || null);
    } catch (error) {
      showNotice(getErrorMessage(error));
    } finally {
      setLoading(false);
    }
  };

  return (
    <FullScreenModal
      open={open}
      onClose={onClose}
      title="TAX CONFIGURATION"
      subtitle="TAX SETUP AND MULTI PRODUCT TAX ASSIGNMENT"
    >
      <div className="relative h-[calc(100vh-86px)] overflow-hidden bg-[#D8DFDF] text-[#1C2C2B]">
        {notice && (
          <div className="absolute left-1/2 top-3 z-50 -translate-x-1/2 whitespace-pre-line border border-[#004343] bg-[#004344] px-4 py-2 text-[12px] font-semibold text-white">
            {notice}
          </div>
        )}

        <div className="grid h-full grid-rows-[auto_minmax(0,1fr)] gap-2 p-2">
          <div className="flex flex-wrap items-center justify-between gap-2 border border-[#B9C3C3] bg-[#EEF2F2] px-2 py-1">
            <div className="flex flex-wrap gap-1">
              <Tab active={activeTab === "taxes"} onClick={() => setActiveTab("taxes")}>
                Tax Register
              </Tab>
              <Tab active={activeTab === "products"} onClick={() => setActiveTab("products")}>
                Product Taxes
              </Tab>
            </div>

            <div className="flex flex-wrap items-center gap-1">
              <button type="button" onClick={loadAll} disabled={loading} className="h-8 border border-[#B9C3C3] bg-white px-3 text-[12px] font-semibold text-[#324443] disabled:opacity-60">
                {loading ? "Loading..." : "Refresh"}
              </button>

              {activeTab === "taxes" && (
                <>
                  <button type="button" onClick={openNewTax} className="h-8 border border-[#027A48] bg-[#027A48] px-3 text-[12px] font-semibold text-white">
                    New Tax
                  </button>
                </>
              )}

              {activeTab === "products" && (
                <>
                  <button type="button" onClick={loadTaxesFromFocusedProduct} disabled={loading || !focusedProduct} className="h-8 border border-[#B9C3C3] bg-white px-3 text-[12px] font-semibold text-[#324443] disabled:opacity-60">
                    Load Existing
                  </button>
                  <button type="button" onClick={saveSelectedProductTaxes} disabled={loading || !selectedProductIds.length} className="h-8 border border-[#027A48] bg-[#027A48] px-3 text-[12px] font-semibold text-white disabled:opacity-60">
                    Save To {selectedProductIds.length} Product(s)
                  </button>
                </>
              )}
            </div>
          </div>

          {activeTab === "taxes" ? (
            <TaxRegisterTab taxForm={taxForm} setTaxForm={setTaxForm} filteredTaxes={filteredTaxes} taxSearch={taxSearch} setTaxSearch={setTaxSearch} removeTax={removeTax} taxFormOpen={taxFormOpen} setTaxFormOpen={setTaxFormOpen} saveTax={saveTax} loading={loading} openEditTax={openEditTax} />
          ) : (
            <ProductTaxesTab
              filteredProducts={filteredProducts}
              productSearch={productSearch}
              setProductSearch={setProductSearch}
              selectedProductIds={selectedProductIds}
              selectedProducts={selectedProducts}
              focusedProduct={focusedProduct}
              toggleProduct={toggleProduct}
              focusOnlyProduct={focusOnlyProduct}
              selectAllFilteredProducts={selectAllFilteredProducts}
              clearProductSelection={clearProductSelection}
              allFilteredSelected={allFilteredSelected}
              taxes={taxes}
              assignmentTaxes={assignmentTaxes}
              toggleAssignmentTax={toggleAssignmentTax}
              updateAssignmentTax={updateAssignmentTax}
              previewQuantity={previewQuantity}
              setPreviewQuantity={setPreviewQuantity}
              runPreview={runPreview}
              previewResult={previewResult}
            />
          )}
        </div>
      </div>
    </FullScreenModal>
  );
}

function TaxRegisterTab({ taxForm, setTaxForm, filteredTaxes, taxSearch, setTaxSearch, removeTax, taxFormOpen, setTaxFormOpen, saveTax, loading, openEditTax }) {
  return (
    <div className="min-h-0">
      <EnterpriseFormModal open={taxFormOpen} title={taxForm.id ? "Edit Tax" : "Add Tax"} subtitle="Tax configuration" onClose={() => setTaxFormOpen(false)} busy={loading} width="720px">
      <Panel title={taxForm.id ? "Tax Details" : "New Tax Details"}>
        <div className="grid gap-2">
          <Input label="Tax Name" value={taxForm.name} onChange={(v) => setTaxForm({ ...taxForm, name: v })} />
          <Input label="Tax Code" value={taxForm.tax_code} onChange={(v) => setTaxForm({ ...taxForm, tax_code: v.toUpperCase().slice(0, 3) })} placeholder="A / VAT / EX" />
          <Input label="FDMS Numeric Tax ID" value={taxForm.tax_id} onChange={(v) => setTaxForm({ ...taxForm, tax_id: v })} placeholder="This is not the UUID" />
          <Select label="Tax Type" value={taxForm.type} onChange={(v) => setTaxForm({ ...taxForm, type: v })} options={[["percentage", "Percentage"], ["fixed", "Fixed Amount"]]} />
          <Input label="Rate" value={taxForm.rate} onChange={(v) => setTaxForm({ ...taxForm, rate: v })} />
          <Check label="Obliged to ZIMRA / FDMS" checked={taxForm.obliged_to_zimra} onChange={(v) => setTaxForm({ ...taxForm, obliged_to_zimra: v })} />
          <Check label="Active" checked={taxForm.is_active} onChange={(v) => setTaxForm({ ...taxForm, is_active: v })} />
          <Input label="Valid From" type="date" value={taxForm.valid_from || ""} onChange={(v) => setTaxForm({ ...taxForm, valid_from: v })} />
          <Input label="Valid Till" type="date" value={taxForm.valid_till || ""} onChange={(v) => setTaxForm({ ...taxForm, valid_till: v })} />
          <label>
            <span className="mb-1 block text-[11px] font-semibold uppercase text-[#627474]">Description</span>
            <textarea value={taxForm.description || ""} onChange={(e) => setTaxForm({ ...taxForm, description: e.target.value })} className="h-20 w-full border border-[#B9C3C3] p-2 text-[12px] outline-none" />
          </label>
          <div className="mt-2 flex justify-end gap-2 border-t border-[#D8DFDF] pt-3">
            <button type="button" onClick={() => setTaxFormOpen(false)} className="h-9 border border-[#B9C3C3] bg-white px-5 text-[12px] font-semibold">Cancel</button>
            <button type="button" onClick={saveTax} disabled={loading} className="h-9 border border-[#027A48] bg-[#027A48] px-5 text-[12px] font-semibold text-white disabled:opacity-60">{taxForm.id ? "Update Tax" : "Save Tax"}</button>
          </div>
        </div>
      </Panel>
      </EnterpriseFormModal>

      <div className="min-h-0 overflow-hidden border border-[#AFB9B9] bg-white">
        <div className="flex items-center justify-between border-b border-[#D8DFDF] bg-[#EEF2F2] p-2">
          <div>
            <div className="text-[13px] font-semibold">Tax Register</div>
            <div className="text-[11px] text-[#627474]">Configure taxes. FDMS Tax ID is the numeric ZIMRA taxID.</div>
          </div>
          <input value={taxSearch} onChange={(e) => setTaxSearch(e.target.value)} placeholder="Search taxes..." className="h-8 w-[260px] border border-[#B9C3C3] px-3 text-[12px] outline-none" />
        </div>

        <div className="h-full overflow-auto pb-12">
          <table className="w-full min-w-[1080px] border-collapse text-[12px]">
            <thead className="sticky top-0 z-10">
              <tr className="border-b bg-[#F8FAFC] text-left text-[10px] uppercase text-[#627474]">
                <th className="px-3 py-2">Tax</th>
                <th className="px-3 py-2">Description</th>
                <th className="px-3 py-2">Code</th>
                <th className="px-3 py-2">FDMS Tax ID</th>
                <th className="px-3 py-2">Type</th>
                <th className="px-3 py-2 text-right">Rate</th>
                <th className="px-3 py-2">ZIMRA</th>
                <th className="px-3 py-2">Status</th>
                <th className="w-[58px] px-3 py-2 text-center" aria-label="Actions">⋮</th>
              </tr>
            </thead>
            <tbody>
              {filteredTaxes.map((tax) => (
                <tr key={tax.id} className="border-b hover:bg-[#F8FAFC]">
                  <td className="px-3 py-2 font-semibold text-[#0C1B1B]">{tax.name}</td>
                  <td className="px-3 py-2">{tax.description || "-"}</td>
                  <td className="px-3 py-2">{tax.tax_code || "-"}</td>
                  <td className="px-3 py-2 font-semibold">{tax.tax_id ?? "-"}</td>
                  <td className="px-3 py-2">{tax.type}</td>
                  <td className="px-3 py-2 text-right">{Number(tax.rate || 0).toFixed(4)}</td>
                  <td className="px-3 py-2"><Badge tone={tax.obliged_to_zimra ? "success" : "default"}>{tax.obliged_to_zimra ? "Yes" : "No"}</Badge></td>
                  <td className="px-3 py-2"><Badge tone={tax.is_active ? "success" : "danger"}>{tax.is_active ? "Active" : "Inactive"}</Badge></td>
                  <td className="px-3 py-2 text-right"><button type="button" onClick={() => openEditTax(tax)} className="mr-2 h-7 border border-[#B9C3C3] bg-white px-3 text-[11px] font-semibold">Edit</button><button type="button" onClick={() => removeTax(tax)} className="h-7 border border-[#FECDCA] bg-white px-3 text-[11px] font-semibold text-[#B42318]">Delete</button></td>
                </tr>
              ))}
              {!filteredTaxes.length && <tr><td colSpan={9} className="px-3 py-12 text-center text-[#627474]">No taxes found.</td></tr>}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}

function ProductTaxesTab({
  filteredProducts,
  productSearch,
  setProductSearch,
  selectedProductIds,
  selectedProducts,
  focusedProduct,
  toggleProduct,
  focusOnlyProduct,
  selectAllFilteredProducts,
  clearProductSelection,
  allFilteredSelected,
  taxes,
  assignmentTaxes,
  toggleAssignmentTax,
  updateAssignmentTax,
  previewQuantity,
  setPreviewQuantity,
  runPreview,
  previewResult,
}) {
  const isAssignmentSelected = (tax) => assignmentTaxes.some((row) => String(row.tax_id || row.tax?.id) === String(tax.id));
  const getAssignmentRow = (tax) => assignmentTaxes.find((row) => String(row.tax_id || row.tax?.id) === String(tax.id));

  return (
    <div className="grid min-h-0 grid-cols-1 gap-2 xl:grid-cols-[430px_minmax(0,1fr)_380px]">
      <div className="min-h-0 overflow-hidden border border-[#AFB9B9] bg-white">
        <div className="border-b border-[#D8DFDF] bg-[#EEF2F2] p-2">
          <div className="flex items-center justify-between gap-2">
            <div><div className="text-[13px] font-semibold">Products</div><div className="text-[11px] text-[#627474]">{selectedProductIds.length} selected</div></div>
            <div className="flex gap-1"><button type="button" onClick={selectAllFilteredProducts} className="h-7 border border-[#B9C3C3] bg-white px-2 text-[11px] font-semibold">{allFilteredSelected ? "Unselect All" : "Select All"}</button><button type="button" onClick={clearProductSelection} className="h-7 border border-[#B9C3C3] bg-white px-2 text-[11px] font-semibold">Clear</button></div>
          </div>
          <input value={productSearch} onChange={(e) => setProductSearch(e.target.value)} placeholder="Search product, tax name, tax code, no tax..." className="mt-2 h-8 w-full border border-[#B9C3C3] px-3 text-[12px] outline-none" />
        </div>

        <div className="h-full overflow-auto pb-16">
          {filteredProducts.map((product) => {
            const price = getPrimaryPrice(product);
            const selected = selectedProductIds.includes(product.id);
            const focused = focusedProduct?.id === product.id;
            const hasTax = productHasTax(product);
            const taxRows = getProductTaxRows(product);
            return (
              <button key={product.id} type="button" onClick={() => toggleProduct(product)} onDoubleClick={() => focusOnlyProduct(product)} className={`block w-full border-b px-3 py-2 text-left hover:bg-[#F8FAFC] ${focused ? "bg-[#E5F2F2]" : selected ? "bg-[#F0FDF4]" : "bg-white"}`}>
                <div className="grid grid-cols-[24px_1fr_110px] items-start gap-2">
                  <input type="checkbox" checked={selected} onChange={() => toggleProduct(product)} onClick={(e) => e.stopPropagation()} className="mt-1" />
                  <div className="min-w-0">
                    <div className="truncate text-[12px] font-semibold text-[#0C1B1B]">{product.name}</div>
                    <div className="truncate text-[10px] text-[#627474]">{product.code || "-"} / {product.sku || "-"}</div>
                    <div className="truncate text-[10px] text-[#627474]">{product.category?.name || "Uncategorised"} / {product.uom?.code || product.uom?.name || "-"}</div>
                    <div className="mt-1 flex flex-wrap gap-1">
                      <span className={`inline-flex border px-2 py-[2px] text-[9px] font-semibold uppercase ${hasTax ? "border-[#ABEFC6] bg-[#ECFDF3] text-[#067647]" : "border-[#FECDCA] bg-[#FEF3F2] text-[#B42318]"}`}>{hasTax ? `${taxRows.length} Tax Assigned` : "No Tax"}</span>
                      {taxRows.slice(0, 2).map((row) => (<span key={row.id || `${row.tax_id}-${getTaxCode(row)}`} className="inline-flex border border-[#A4E5E4] bg-[#F1F8F8] px-2 py-[2px] text-[9px] font-semibold uppercase text-[#057B7B]" title={getProductTaxSummary(product)}>{getTaxCode(row)} {getTaxRate(row) ? `${Number(getTaxRate(row)).toFixed(2)}%` : ""}</span>))}
                      {taxRows.length > 2 && (<span className="inline-flex border border-[#CFD6D6] bg-[#F9FAFB] px-2 py-[2px] text-[9px] font-semibold uppercase text-[#465757]">+{taxRows.length - 2} More</span>)}
                    </div>
                  </div>
                  <div className="text-right">
                    <div className="text-[11px] font-semibold text-[#027A48]">{price ? money(price.price, price.currency) : "No price"}</div>
                    <div className="text-[9px] text-[#627474]">{price?.price_level?.name || price?.price_level?.code || "Price"}</div>
                    <div className={`mt-1 text-[9px] font-semibold ${hasTax ? "text-[#067647]" : "text-[#B42318]"}`} title={getProductTaxSummary(product)}>{getProductTaxShort(product)}</div>
                  </div>
                </div>
              </button>
            );
          })}
          {!filteredProducts.length && <div className="px-3 py-10 text-center text-[12px] text-[#627474]">No products found.</div>}
        </div>
      </div>

      <div className="min-h-0 overflow-hidden border border-[#AFB9B9] bg-white">
        <div className="border-b border-[#D8DFDF] bg-[#EEF2F2] p-2"><div className="text-[13px] font-semibold">Tax Assignment Template</div><div className="text-[11px] text-[#627474]">Select taxes once, then save them to all selected products.</div></div>
        <div className="h-full overflow-auto pb-16">
          <table className="w-full min-w-[950px] border-collapse text-[12px]">
            <thead className="sticky top-0 z-10"><tr className="border-b bg-[#F8FAFC] text-left text-[10px] uppercase text-[#627474]"><th className="px-3 py-2">Use</th><th className="px-3 py-2">Tax</th><th className="px-3 py-2">Tax Code</th><th className="px-3 py-2">FDMS ID</th><th className="px-3 py-2">Rate</th><th className="px-3 py-2">Inclusive</th><th className="px-3 py-2">Active</th><th className="px-3 py-2">ZIMRA</th></tr></thead>
            <tbody>
              {taxes.map((tax) => {
                const checked = isAssignmentSelected(tax);
                const row = getAssignmentRow(tax);
                return (
                  <tr key={tax.id} className="border-b hover:bg-[#F8FAFC]"><td className="px-3 py-2"><input type="checkbox" checked={checked} onChange={() => toggleAssignmentTax(tax)} /></td><td className="px-3 py-2 font-semibold">{tax.name}</td><td className="px-3 py-2">{tax.tax_code || "-"}</td><td className="px-3 py-2">{tax.tax_id ?? "-"}</td><td className="px-3 py-2">{Number(tax.rate || 0).toFixed(4)}</td><td className="px-3 py-2"><input type="checkbox" checked={!!row?.is_inclusive} disabled={!checked} onChange={(e) => updateAssignmentTax(tax.id, { is_inclusive: e.target.checked })} /></td><td className="px-3 py-2"><input type="checkbox" checked={row?.is_active !== false} disabled={!checked} onChange={(e) => updateAssignmentTax(tax.id, { is_active: e.target.checked })} /></td><td className="px-3 py-2"><Badge tone={tax.obliged_to_zimra ? "success" : "default"}>{tax.obliged_to_zimra ? "Yes" : "No"}</Badge></td></tr>
                );
              })}
              {!taxes.length && <tr><td colSpan={8} className="px-3 py-10 text-center text-[#627474]">No taxes configured.</td></tr>}
            </tbody>
          </table>
        </div>
      </div>

      <Panel title="Preview / Selection">
        <div className="mb-3 border border-[#D8DFDF] bg-[#F8FAFC] p-2 text-[12px]"><div className="mb-1 flex items-center justify-between"><span className="font-semibold text-[#0C1B1B]">Selected Products</span><span className="text-[11px] font-semibold text-[#627474]">{selectedProducts.length}</span></div><div className="max-h-[92px] overflow-auto">{selectedProducts.length ? selectedProducts.map((product) => (<div key={product.id} className="truncate border-b py-1 last:border-b-0"><span>{product.name}</span><span className={`ml-2 text-[10px] font-semibold ${productHasTax(product) ? "text-[#067647]" : "text-[#B42318]"}`}>{getProductTaxShort(product)}</span></div>)) : <div className="text-[#627474]">No products selected.</div>}</div></div>
        <div className="mb-3 border border-[#D8DFDF] bg-white p-2 text-[12px]"><div className="font-semibold text-[#0C1B1B]">Focused Product</div><div className="mt-1 text-[#627474]">{focusedProduct ? focusedProduct.name : "Select a product to preview."}</div>{focusedProduct && (<div className="mt-2"><Info label="Product Price" valueText={money(getProductPriceNumber(focusedProduct), getProductCurrency(focusedProduct))} /><Info label="Currency" valueText={getProductCurrency(focusedProduct)} /><Info label="Price Level" valueText={getPrimaryPrice(focusedProduct)?.price_level?.name || "-"} /><Info label="Current Tax" valueText={getProductTaxShort(focusedProduct)} /><div className="mt-2 border-t pt-2"><div className="mb-1 text-[11px] font-semibold uppercase text-[#627474]">Current Product Taxes</div>{getProductTaxRows(focusedProduct).length ? getProductTaxRows(focusedProduct).map((row) => (<div key={row.id || `${row.tax_id}-${getTaxCode(row)}`} className="mb-1 border bg-[#F8FAFC] p-2"><div className="font-semibold text-[#0C1B1B]">{getTaxName(row)}</div><div className="text-[10px] text-[#627474]">Code: {getTaxCode(row)} / Rate: {getTaxRate(row) ? Number(getTaxRate(row)).toFixed(4) : "0.0000"} / {row.is_inclusive ? "Inclusive" : "Exclusive"} / {row.obliged_to_zimra ? "ZIMRA" : "Local"}</div></div>)) : <div className="text-[#B42318]">No tax assigned to this product.</div>}</div></div>)}</div>
        <Input label="Preview Quantity" value={previewQuantity} onChange={setPreviewQuantity} />
        <button type="button" onClick={runPreview} disabled={!focusedProduct} className="mt-2 h-8 w-full border border-[#004343] bg-[#004344] text-[12px] font-semibold text-white disabled:opacity-50">Preview Using Product Price</button>
        {previewResult && (<div className="mt-3 border border-[#D8DFDF] bg-[#F8FAFC] p-2 text-[12px]"><Info label="Gross" value={previewResult.gross} /><Info label="Taxable Amount" value={previewResult.taxable_amount} /><Info label="Inclusive Tax" value={previewResult.inclusive_tax_total} /><Info label="Exclusive Tax" value={previewResult.exclusive_tax_total} /><Info label="Tax Total" value={previewResult.tax_total} /><Info label="Net Total" value={previewResult.net_total} /><div className="mt-2 border-t pt-2"><div className="mb-1 text-[11px] font-semibold uppercase text-[#627474]">Preview Taxes</div>{(previewResult.taxes || []).map((row, index) => (<div key={index} className="mb-1 border bg-white p-2"><div className="font-semibold">{row.tax_name}</div><div className="text-[10px] text-[#627474]">{row.is_inclusive ? "Inclusive" : "Exclusive"} / Tax: {Number(row.tax_amount).toFixed(4)}</div></div>))}</div></div>)}
      </Panel>
    </div>
  );
}

function Tab({ active, children, onClick }) {
  return <button type="button" onClick={onClick} className={`h-8 border px-4 text-[12px] font-semibold ${active ? "border-[#7C8888] bg-white text-[#0C1B1B]" : "border-transparent text-[#465757] hover:border-[#B9C3C3] hover:bg-white"}`}>{children}</button>;
}

function Panel({ title, children }) {
  return <div className="overflow-auto border border-[#AFB9B9] bg-white"><div className="border-b border-[#D8DFDF] bg-[#EEF2F2] px-3 py-2 text-[13px] font-semibold">{title}</div><div className="p-3">{children}</div></div>;
}

function Input({ label, value, onChange, type = "text", placeholder = "" }) {
  return <label className="block"><span className="mb-1 block text-[11px] font-semibold uppercase text-[#627474]">{label}</span><input type={type} value={value || ""} onChange={(e) => onChange(e.target.value)} placeholder={placeholder} className="h-8 w-full border border-[#B9C3C3] px-2 text-[12px] outline-none focus:border-[#004343]" /></label>;
}

function Select({ label, value, onChange, options }) {
  return <label className="block"><span className="mb-1 block text-[11px] font-semibold uppercase text-[#627474]">{label}</span><select value={value || ""} onChange={(e) => onChange(e.target.value)} className="h-8 w-full border border-[#B9C3C3] bg-white px-2 text-[12px] outline-none focus:border-[#004343]">{options.map(([key, title]) => <option key={key} value={key}>{title}</option>)}</select></label>;
}

function Check({ label, checked, onChange }) {
  return <label className="flex items-center gap-2 text-[12px] font-semibold text-[#324443]"><input type="checkbox" checked={!!checked} onChange={(e) => onChange(e.target.checked)} />{label}</label>;
}

function Badge({ children, tone = "default" }) {
  const tones = { default: "border-[#CFD6D6] bg-[#F9FAFB] text-[#465757]", success: "border-[#ABEFC6] bg-[#ECFDF3] text-[#067647]", danger: "border-[#FECDCA] bg-[#FEF3F2] text-[#B42318]" };
  return <span className={`inline-flex border px-2 py-1 text-[10px] font-semibold ${tones[tone]}`}>{children}</span>;
}

function Info({ label, value, valueText }) {
  return <div className="grid grid-cols-[1fr_135px] border-b py-1 last:border-b-0"><span className="text-[#627474]">{label}</span><span className="text-right font-semibold">{valueText ?? Number(value || 0).toFixed(4)}</span></div>;
}

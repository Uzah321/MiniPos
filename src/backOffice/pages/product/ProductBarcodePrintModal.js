import React, { useEffect, useMemo, useState } from "react";
import { getAuthorisedCompany } from "../../../services/AuthenticationService";
import {
  prepareBarcodePrint,
  resolveBarcodePrinter,
  searchProductBarcodes,
} from "../../../services/ProductBarcodePrintService";
import {
  getBarcodePrintUrlFromBackendResponse,
  printBarcodePreparedResponse,
} from "../../../utils/barcodePrinter";

const qty = (value) => Number(value || 0).toFixed(4);
const money = (value, currency = "") =>
  `${currency ? `${currency} ` : ""}${Number(value || 0).toFixed(2)}`;

const branchLabel = (branch) => {
  if (!branch) return "";
  const name = branch.branch_name || branch.name || "Unnamed Branch";
  const code = branch.branch_code || branch.code || "";
  return code ? `${name} (${code})` : name;
};

const selectedKey = (row) =>
  `${row.product_id}-${row.barcode || row.product_barcode || row.barcode_id || row.code || row.sku}`;

const normalizeBarcodeRow = (row) => ({
  product_id: row.product_id,
  product_name: row.product_name,
  code: row.code,
  sku: row.sku,
  barcode: row.barcode || row.product_barcode || "",
  barcode_type: row.barcode_type || "primary",
  copies: 1,
  category_name: row.category_name,
  supplier_name: row.supplier_name,
  quantity_on_hand: row.quantity_on_hand,
  selling_price: row.selling_price,
  selling_currency: row.selling_currency || "USD",
});

const Field = ({ label, children }) => (
  <label className="flex flex-col gap-1">
    <span className="text-[11px] font-black uppercase tracking-wide text-[#637877]">
      {label}
    </span>
    {children}
  </label>
);

const Button = ({ children, variant = "default", className = "", ...props }) => {
  const styles = {
    default: "border-[#ACB8B8] bg-white text-[#003636] hover:bg-[#F6F8FA]",
    primary: "border-[#003636] bg-[#003637] text-white hover:bg-[#004444]",
    danger: "border-[#B42318] bg-[#B42318] text-white hover:bg-[#8F1D14]",
  };

  return (
    <button
      type="button"
      {...props}
      className={`h-9 border px-4 text-[12px] font-black disabled:cursor-not-allowed disabled:opacity-50 ${styles[variant]} ${className}`}
    >
      {children}
    </button>
  );
};

const Metric = ({ label, value, danger }) => (
  <div className="border border-[#D7DFDF] bg-white p-3">
    <div className="text-[10px] font-black uppercase tracking-wide text-[#637877]">{label}</div>
    <div className={`mt-1 text-[18px] font-black ${danger ? "text-[#B42318]" : "text-[#003636]"}`}>
      {value}
    </div>
  </div>
);

export default function ProductBarcodePrintModal({ open, onClose }) {
  const [branches, setBranches] = useState([]);
  const [branchId, setBranchId] = useState("");
  const [query, setQuery] = useState("");
  const [barcodeType, setBarcodeType] = useState("");
  const [includeMissingBarcode, setIncludeMissingBarcode] = useState(false);
  const [rows, setRows] = useState([]);
  const [selected, setSelected] = useState({});
  const [activeTab, setActiveTab] = useState("search");
  const [printer, setPrinter] = useState(null);
  const [lastPrepared, setLastPrepared] = useState(null);
  const [label, setLabel] = useState({
    width_mm: 50,
    height_mm: 30,
    show_product_name: true,
    show_sku: true,
    show_price: true,
    show_company: false,
  });
  const [batchCopies, setBatchCopies] = useState(1);
  const [notice, setNotice] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  const selectedItems = useMemo(
    () => Object.values(selected).filter((item) => item && Number(item.copies || 0) > 0 && item.barcode),
    [selected]
  );

  const totalLabels = useMemo(
    () => selectedItems.reduce((sum, item) => sum + Number(item.copies || 0), 0),
    [selectedItems]
  );

  const preparedUrl = getBarcodePrintUrlFromBackendResponse(lastPrepared);
  const missingBarcodeCount = rows.filter((row) => !row.barcode && !row.product_barcode).length;

  const loadBranches = async () => {
    try {
      const res = await getAuthorisedCompany();
      const info = res?.company || res?.schoolInfo || res?.data?.companyInfo || res?.data?.schoolInfo || res?.data || null;
      const apiBranches = res?.company?.branches || res?.data?.branches || info?.branches || [];
      const list = Array.isArray(apiBranches) ? apiBranches : [];
      setBranches(list);
      const first = list.length ? list[0] : null;
      setBranchId((current) => current || first?.id || "");
    } catch (err) {
      setError(err?.response?.data?.message || "Failed to load authorised branches.");
    }
  };

  const checkPrinter = async () => {
    if (!branchId) return null;

    try {
      setLoading(true);
      setError("");
      const res = await resolveBarcodePrinter({ branch_id: branchId });
      const resolvedPrinter = res?.data || null;
      setPrinter(resolvedPrinter);
      setNotice(`Barcode printer ready: ${resolvedPrinter?.printer_name || "Configured printer"}`);
      return resolvedPrinter;
    } catch (err) {
      setPrinter(null);
      setError(err?.response?.data?.message || "No active barcode printer configured.");
      return null;
    } finally {
      setLoading(false);
    }
  };

  const search = async () => {
    if (!branchId) {
      setError("Select branch first.");
      return;
    }

    try {
      setLoading(true);
      setError("");
      setNotice("");

      const res = await searchProductBarcodes({
        branch_id: branchId,
        q: query,
        barcode_type: barcodeType,
        include_missing_barcode: includeMissingBarcode ? 1 : 0,
        limit: 100,
      });

      setRows(res?.data || []);
      setNotice(`Loaded ${(res?.data || []).length} barcode records.`);
    } catch (err) {
      setError(err?.response?.data?.message || "Failed to fetch product barcodes.");
    } finally {
      setLoading(false);
    }
  };

  const toggleRow = (row) => {
    const normal = normalizeBarcodeRow(row);
    const key = selectedKey(row);

    if (!normal.barcode) {
      setError("This product has no barcode. Add a barcode first.");
      return;
    }

    setSelected((current) => {
      if (current[key]) {
        const next = { ...current };
        delete next[key];
        return next;
      }

      return { ...current, [key]: normal };
    });
    setLastPrepared(null);
  };

  const updateSelected = (key, changes) => {
    setSelected((current) => ({
      ...current,
      [key]: {
        ...current[key],
        ...changes,
      },
    }));
    setLastPrepared(null);
  };

  const updateCopiesFromRow = (row, copies) => {
    const normal = normalizeBarcodeRow(row);
    const key = selectedKey(row);
    if (!normal.barcode) return;

    setSelected((current) => ({
      ...current,
      [key]: {
        ...(current[key] || normal),
        copies: Number(copies || 0),
      },
    }));
    setLastPrepared(null);
  };

  const selectAllVisible = () => {
    const next = { ...selected };

    rows.forEach((row) => {
      const normal = normalizeBarcodeRow(row);
      if (!normal.barcode) return;

      const key = selectedKey(row);
      next[key] = next[key] || { ...normal, copies: Number(batchCopies || 1) };
    });

    setSelected(next);
    setLastPrepared(null);
  };

  const clearSelected = () => {
    setSelected({});
    setLastPrepared(null);
  };

  const removeSelected = (key) => {
    setSelected((current) => {
      const next = { ...current };
      delete next[key];
      return next;
    });
    setLastPrepared(null);
  };

  const buildPreparePayload = () => {
    if (!selectedItems.length) throw new Error("Select at least one barcode to print.");

    return {
      branch_id: branchId,
      items: selectedItems.map((item) => ({
        product_id: item.product_id,
        barcode: item.barcode,
        copies: Number(item.copies || 1),
      })),
      label,
    };
  };

  const prepare = async () => {
    try {
      setLoading(true);
      setError("");
      setNotice("");

      const response = await prepareBarcodePrint(buildPreparePayload());
      const data = response?.data || response || {};
      setLastPrepared(data);
      setPrinter(data?.printer || data?.print?.printer || null);
      setActiveTab("preview");
      setNotice(`Barcode PDF prepared: ${data.total_labels || totalLabels} labels.`);
      return data;
    } catch (err) {
      setError(err?.response?.data?.message || err?.message || "Prepare barcode print failed.");
      return null;
    } finally {
      setLoading(false);
    }
  };

  const preview = async () => {
    const data = preparedUrl ? lastPrepared : await prepare();

    if (!data) return;

    setActiveTab("preview");
  };

  const print = async () => {
    try {
      setLoading(true);
      setError("");
      setNotice("");

      const data = preparedUrl ? lastPrepared : await prepare();
      if (!data) return;

      await printBarcodePreparedResponse(data);
      const usedPrinter = data?.printer || data?.print?.printer;
      setNotice(`Barcode labels sent to ${usedPrinter?.printer_name || "barcode printer"}.`);
    } catch (err) {
      setError(err?.response?.data?.message || err?.message || "Barcode print failed.");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (open) loadBranches();
  }, [open]);

  useEffect(() => {
    if (open && branchId) {
      search();
      checkPrinter();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [branchId, open]);

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-[100] bg-white">
      <div className="flex h-screen flex-col bg-[#F6F8FA] text-[#003636] font-bold">
        <div className="flex h-14 items-center justify-between border-b-2 border-[#00BCBC] bg-[#004142] px-4 text-white">
          <div>
            <div className="text-[16px] font-black uppercase tracking-wide">Product Barcode Printing</div>
            <div className="text-[11px] font-black text-white/75">
              Uses the same /api/assets base path style as sales receipts, with PDF preview and QZ printing.
            </div>
          </div>

          <button type="button" onClick={onClose} className="h-8 border border-white/30 px-3 text-[12px] font-black hover:bg-white/10">
            Close
          </button>
        </div>

        {(error || notice) && (
          <div className={`border-b px-4 py-2 text-[12px] font-black ${error ? "border-[#FDA29B] bg-[#FEF3F2] text-[#B42318]" : "border-[#ABEFC6] bg-[#ECFDF3] text-[#067647]"}`}>
            {error || notice}
          </div>
        )}

        <div className="grid min-h-0 flex-1 grid-cols-1 overflow-hidden xl:grid-cols-[440px_1fr]">
          <aside className="min-h-0 overflow-auto border-r border-[#D7DFDF] bg-white p-4">
            <div className="space-y-3">
              <div className="grid gap-2 sm:grid-cols-2">
                <Metric label="Selected Products" value={selectedItems.length} />
                <Metric label="Total Labels" value={totalLabels} />
                <Metric label="Search Results" value={rows.length} />
                <Metric label="Missing Barcodes" value={missingBarcodeCount} danger={missingBarcodeCount > 0} />
              </div>

              <div className="border border-[#D7DFDF] bg-[#F6F8FA] p-3">
                <div className="mb-2 text-[12px] font-black uppercase">Search Controls</div>

                <div className="grid gap-2 sm:grid-cols-2">
                  <Field label="Branch">
                    <select value={branchId} onChange={(event) => setBranchId(event.target.value)} className="h-9 border border-[#ACB8B8] px-2 text-[12px] font-black">
                      <option value="">Select branch</option>
                      {branches.map((branch) => (
                        <option key={branch.id} value={branch.id}>{branchLabel(branch)}</option>
                      ))}
                    </select>
                  </Field>

                  <Field label="Barcode Type">
                    <select value={barcodeType} onChange={(event) => setBarcodeType(event.target.value)} className="h-9 border border-[#ACB8B8] px-2 text-[12px] font-black">
                      <option value="">All</option>
                      <option value="primary">Primary</option>
                      <option value="alternative">Alternative</option>
                      <option value="supplier">Supplier</option>
                    </select>
                  </Field>
                </div>

                <div className="mt-2">
                  <Field label="Product / SKU / Barcode">
                    <div className="grid gap-2 sm:grid-cols-[1fr_auto]">
                      <input
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        onKeyDown={(event) => {
                          if (event.key === "Enter") {
                            event.preventDefault();
                            search();
                          }
                        }}
                        placeholder="Type product, SKU, code or barcode..."
                        className="h-9 border border-[#ACB8B8] px-3 text-[12px] font-black"
                      />
                      <Button onClick={search} disabled={loading}>Search</Button>
                    </div>
                  </Field>
                </div>

                <label className="mt-2 flex items-center gap-2 text-[12px] font-black text-[#324443]">
                  <input type="checkbox" checked={includeMissingBarcode} onChange={(event) => setIncludeMissingBarcode(event.target.checked)} />
                  Include products with missing barcode
                </label>
              </div>

              <div className="border border-[#D7DFDF] bg-[#F6F8FA] p-3">
                <div className="mb-2 text-[12px] font-black uppercase">Label Options</div>

                <div className="grid gap-2 sm:grid-cols-3">
                  <Field label="Width MM">
                    <input type="number" value={label.width_mm} onChange={(event) => setLabel({ ...label, width_mm: Number(event.target.value || 50) })} className="h-9 border border-[#ACB8B8] px-2 text-right text-[12px] font-black" />
                  </Field>

                  <Field label="Height MM">
                    <input type="number" value={label.height_mm} onChange={(event) => setLabel({ ...label, height_mm: Number(event.target.value || 30) })} className="h-9 border border-[#ACB8B8] px-2 text-right text-[12px] font-black" />
                  </Field>

                  <Field label="Batch Copies">
                    <input type="number" value={batchCopies} onChange={(event) => setBatchCopies(Number(event.target.value || 1))} className="h-9 border border-[#ACB8B8] px-2 text-right text-[12px] font-black" />
                  </Field>
                </div>

                <div className="mt-2 grid gap-2 text-[12px] font-black">
                  {[
                    ["show_product_name", "Show product name"],
                    ["show_sku", "Show SKU/code"],
                    ["show_price", "Show selling price"],
                    ["show_company", "Show company name"],
                  ].map(([key, text]) => (
                    <label key={key} className="flex items-center gap-2">
                      <input type="checkbox" checked={Boolean(label[key])} onChange={(event) => setLabel({ ...label, [key]: event.target.checked })} />
                      {text}
                    </label>
                  ))}
                </div>
              </div>

              <div className="border border-[#D7DFDF] bg-white p-3">
                <div className="mb-2 text-[12px] font-black uppercase">Backend Printer</div>
                <div className="text-[12px] font-black text-[#324443]">
                  {printer ? `Ready: ${printer.printer_name || printer.name}` : "No barcode printer resolved."}
                </div>
                <Button onClick={checkPrinter} className="mt-2 w-full">Resolve Barcode Printer</Button>
              </div>

              <div className="grid gap-2 sm:grid-cols-2">
                <Button onClick={selectAllVisible} disabled={loading || !rows.length}>Select Visible</Button>
                <Button onClick={clearSelected} disabled={loading || !selectedItems.length} variant="danger">Clear Queue</Button>
                <Button onClick={prepare} disabled={loading || !selectedItems.length}>Prepare Preview</Button>
                <Button onClick={preview} disabled={loading || !selectedItems.length}>Preview</Button>
                <Button onClick={print} disabled={loading || !selectedItems.length} variant="primary" className="sm:col-span-2">Print Barcode</Button>
              </div>
            </div>
          </aside>

          <main className="min-h-0 overflow-hidden">
            <div className="flex h-full flex-col">
              <div className="flex border-b border-[#D7DFDF] bg-white">
                {[
                  ["search", "Product Barcodes"],
                  ["queue", `Print Queue (${selectedItems.length})`],
                  ["preview", "Barcode Preview"],
                ].map(([key, labelText]) => (
                  <button
                    key={key}
                    type="button"
                    onClick={() => setActiveTab(key)}
                    className={`h-10 border-r border-[#D7DFDF] px-4 text-[12px] font-black ${activeTab === key ? "bg-[#E8F7F5] text-[#006869]" : "bg-white text-[#003636]"}`}
                  >
                    {labelText}
                  </button>
                ))}
              </div>

              <div className="min-h-0 flex-1 overflow-auto p-4">
                {activeTab === "search" && (
                  <SearchResultsTable rows={rows} selected={selected} toggleRow={toggleRow} updateCopiesFromRow={updateCopiesFromRow} />
                )}

                {activeTab === "queue" && (
                  <PrintQueueTable selected={selected} updateSelected={updateSelected} removeSelected={removeSelected} />
                )}

                {activeTab === "preview" && (
                  <PreparedPdfPanel preparedUrl={preparedUrl} lastPrepared={lastPrepared} onPrepare={prepare} loading={loading} />
                )}
              </div>
            </div>
          </main>
        </div>
      </div>
    </div>
  );
}

function SearchResultsTable({ rows, selected, toggleRow, updateCopiesFromRow }) {
  return (
    <div className="border border-[#D7DFDF] bg-white">
      <div className="flex items-center justify-between border-b border-[#D7DFDF] bg-[#F6F8FA] px-3 py-2">
        <div className="text-[12px] font-black uppercase tracking-wide">Product Barcodes</div>
        <div className="text-[11px] font-black text-[#637877]">{rows.length} results</div>
      </div>

      <div className="overflow-auto">
        <table className="min-w-[1400px] w-full border-collapse text-left text-[12px]">
          <thead className="bg-[#003637] text-white">
            <tr>
              <th className="px-3 py-2 font-black">Select</th>
              <th className="px-3 py-2 font-black">Product</th>
              <th className="px-3 py-2 font-black">SKU</th>
              <th className="px-3 py-2 font-black">Barcode</th>
              <th className="px-3 py-2 font-black">Type</th>
              <th className="px-3 py-2 font-black">Category</th>
              <th className="px-3 py-2 font-black">Supplier</th>
              <th className="px-3 py-2 text-right font-black">Stock</th>
              <th className="px-3 py-2 text-right font-black">Price</th>
              <th className="px-3 py-2 text-right font-black">Copies</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => {
              const key = selectedKey(row);
              const selectedRow = selected[key];
              const checked = Boolean(selectedRow);
              const barcode = row.barcode || row.product_barcode || "";

              return (
                <tr key={key} className="border-b border-[#D7DFDF] hover:bg-[#F6F8FA]">
                  <td className="px-3 py-2">
                    <input type="checkbox" checked={checked} disabled={!barcode} onChange={() => toggleRow(row)} />
                  </td>
                  <td className="px-3 py-2 font-black">{row.product_name}</td>
                  <td className="px-3 py-2 font-bold">{row.sku || row.code || "-"}</td>
                  <td className={`px-3 py-2 font-black ${barcode ? "text-[#003636]" : "text-[#B42318]"}`}>{barcode || "Missing"}</td>
                  <td className="px-3 py-2 font-bold">{row.barcode_type || "-"}</td>
                  <td className="px-3 py-2 font-bold">{row.category_name || "-"}</td>
                  <td className="px-3 py-2 font-bold">{row.supplier_name || "-"}</td>
                  <td className="px-3 py-2 text-right font-bold">{qty(row.quantity_on_hand)}</td>
                  <td className="px-3 py-2 text-right font-bold">{money(row.selling_price, row.selling_currency)}</td>
                  <td className="px-3 py-2 text-right">
                    <input
                      type="number"
                      min="1"
                      max="500"
                      value={selectedRow?.copies || ""}
                      disabled={!checked}
                      onChange={(event) => updateCopiesFromRow(row, event.target.value)}
                      className="h-8 w-20 border border-[#ACB8B8] px-2 text-right text-[12px] font-black disabled:bg-[#E4E8E8]"
                    />
                  </td>
                </tr>
              );
            })}

            {rows.length === 0 && (
              <tr>
                <td colSpan={10} className="px-3 py-10 text-center text-[#637877] font-bold">Search products to print barcodes.</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function PrintQueueTable({ selected, updateSelected, removeSelected }) {
  const items = Object.entries(selected);

  return (
    <div className="border border-[#D7DFDF] bg-white">
      <div className="flex items-center justify-between border-b border-[#D7DFDF] bg-[#F6F8FA] px-3 py-2">
        <div className="text-[12px] font-black uppercase tracking-wide">Print Queue</div>
        <div className="text-[11px] font-black text-[#637877]">{items.length} products selected</div>
      </div>

      <div className="overflow-auto">
        <table className="min-w-[1250px] w-full border-collapse text-left text-[12px]">
          <thead className="bg-[#003637] text-white">
            <tr>
              <th className="px-3 py-2 font-black">Product</th>
              <th className="px-3 py-2 font-black">SKU</th>
              <th className="px-3 py-2 font-black">Barcode</th>
              <th className="px-3 py-2 font-black">Type</th>
              <th className="px-3 py-2 text-right font-black">Copies</th>
              <th className="px-3 py-2 text-right font-black">Price</th>
              <th className="px-3 py-2 text-right font-black">Action</th>
            </tr>
          </thead>
          <tbody>
            {items.map(([key, item]) => (
              <tr key={key} className="border-b border-[#D7DFDF] hover:bg-[#F6F8FA]">
                <td className="px-3 py-2 font-black">{item.product_name}</td>
                <td className="px-3 py-2 font-bold">{item.sku || item.code || "-"}</td>
                <td className="px-3 py-2">
                  <input value={item.barcode || ""} onChange={(event) => updateSelected(key, { barcode: event.target.value })} className="h-8 w-52 border border-[#ACB8B8] px-2 text-[12px] font-black" />
                </td>
                <td className="px-3 py-2 font-bold">{item.barcode_type || "-"}</td>
                <td className="px-3 py-2 text-right">
                  <input type="number" min="1" max="500" value={item.copies || 1} onChange={(event) => updateSelected(key, { copies: Number(event.target.value || 1) })} className="h-8 w-20 border border-[#ACB8B8] px-2 text-right text-[12px] font-black" />
                </td>
                <td className="px-3 py-2 text-right font-bold">{money(item.selling_price, item.selling_currency)}</td>
                <td className="px-3 py-2 text-right">
                  <Button onClick={() => removeSelected(key)} variant="danger">Remove</Button>
                </td>
              </tr>
            ))}

            {items.length === 0 && (
              <tr>
                <td colSpan={7} className="px-3 py-10 text-center text-[#637877] font-bold">No barcode labels queued.</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function PreparedPdfPanel({ preparedUrl, lastPrepared, onPrepare, loading }) {
  return (
    <div className="border border-[#D7DFDF] bg-white">
      <div className="flex items-center justify-between border-b border-[#D7DFDF] bg-[#F6F8FA] px-3 py-2">
        <div className="text-[12px] font-black uppercase tracking-wide">Barcode Preview</div>
        <Button onClick={onPrepare} disabled={loading}>Prepare / Refresh</Button>
      </div>

      {preparedUrl ? (
        <div className="p-3">
          <div className="mb-3 grid gap-2 md:grid-cols-3">
            <Metric label="Target" value={lastPrepared?.target || "barcode"} />
            <Metric label="Total Labels" value={lastPrepared?.total_labels || 0} />
            <Metric label="Printer" value={lastPrepared?.printer?.printer_name || "-"} />
          </div>

          <div className="mb-3 break-all border border-[#D7DFDF] bg-[#F6F8FA] p-3 text-[12px] font-bold text-[#324443]">
            {preparedUrl}
          </div>

          <iframe title="Barcode PDF" src={preparedUrl} className="h-[640px] w-full bg-white" />
        </div>
      ) : (
        <div className="px-3 py-16 text-center text-[12px] font-bold text-[#637877]">
          Click Prepare Preview to create a backend barcode URL.
        </div>
      )}
    </div>
  );
}

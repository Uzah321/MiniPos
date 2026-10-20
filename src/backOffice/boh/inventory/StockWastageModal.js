import React, { useEffect, useMemo, useState } from "react";
import { Plus, Save, Search } from "lucide-react";
import FullScreenModal from "../../../components/FullScreenModal";
import { lookupWastageProducts, postStockWastage } from "../../../services/StockWastageService";

const today = () => new Date().toISOString().slice(0, 10);
const qty = (v) => Number(v || 0).toFixed(4);
const money = (v, c = "USD") => `${c || "USD"} ${Number(v || 0).toFixed(2)}`;

const REASONS = ["Expired", "Damaged", "Spoilt", "Over production", "Kitchen error", "Quality reject", "Other"];

const ALLOWED_WASTAGE_PRODUCT_TYPES = new Set([
  "raw_material",
  "raw material",
  "semi_finished_good",
  "semi finished good",
  "semi-finished good",
  "packaging",
  "consumable",
  "consumables",
]);

const normalizeProductType = (value) =>
  String(value || "")
    .trim()
    .toLowerCase();

const isAllowedWastageProduct = (product) =>
  ALLOWED_WASTAGE_PRODUCT_TYPES.has(normalizeProductType(product?.product_type || product?.productType));

const allowedTypeText = "Raw Materials, Semi Finished Goods, Packaging and Consumables";

const StockWastageModal = ({ open, onClose }) => {
  const [wastageDate, setWastageDate] = useState(today());
  const [stockArea, setStockArea] = useState("front");
  const [reasonCode, setReasonCode] = useState("Expired");
  const [notes, setNotes] = useState("");
  const [search, setSearch] = useState("");
  const [products, setProducts] = useState([]);
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState("");

  const showNotice = (m) => {
    setNotice(m);
    setTimeout(() => setNotice(""), 3500);
  };

  const loadProducts = async () => {
    try {
      setLoading(true);
      const data = await lookupWastageProducts({ stock_area: stockArea, search, limit: 100 });
      const rows = Array.isArray(data) ? data : data?.data || [];
      setProducts(rows.filter(isAllowedWastageProduct));
    } catch (e) {
      showNotice(e?.response?.data?.message || e.message || "Failed to load products.");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (!open) return;
    loadProducts();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, stockArea]);

  const totals = useMemo(() => items.reduce((acc, row) => {
    const q = Number(row.quantity || 0);
    const cost = q * Number(row.unit_cost || 0);
    const key = row.currency_code || "USD";
    acc.qty += q;
    acc.currency[key] = acc.currency[key] || { currency_code: key, total_cost: 0, total_quantity: 0 };
    acc.currency[key].total_cost += cost;
    acc.currency[key].total_quantity += q;
    return acc;
  }, { qty: 0, currency: {} }), [items]);

  const addProduct = (product) => {
    if (!isAllowedWastageProduct(product)) {
      showNotice(`Only ${allowedTypeText} can be posted to wastage.`);
      return;
    }

    if (items.some((row) => String(row.product_id) === String(product.product_id))) {
      showNotice("Product is already on the wastage list.");
      return;
    }
    setItems((prev) => [...prev, { ...product, quantity: "", reason_code: reasonCode, notes: "" }]);
  };

  const updateItem = (productId, updates) => {
    setItems((prev) => prev.map((row) => String(row.product_id) === String(productId) ? { ...row, ...updates } : row));
  };

  const removeItem = (productId) => setItems((prev) => prev.filter((row) => String(row.product_id) !== String(productId)));

  const save = async () => {
    if (!items.length) return showNotice("Add at least one product.");
    const invalid = items.find((row) => Number(row.quantity || 0) <= 0);
    if (invalid) return showNotice("Enter wastage quantity for all selected products.");
    const over = items.find((row) => Number(row.quantity || 0) > Number(row.available_quantity || row.quantity_on_hand || 0));
    if (over) return showNotice(`${over.product_name} wastage is above available stock.`);

    try {
      setSaving(true);
      const response = await postStockWastage({
        wastage_date: wastageDate,
        stock_area: stockArea,
        reason_code: reasonCode,
        notes,
        items: items.map((row) => ({
          product_id: row.product_id,
          quantity: Number(row.quantity || 0),
          reason_code: row.reason_code || reasonCode,
          notes: row.notes || null,
        })),
      });
      showNotice(response?.message || "Wastage posted.");
      setItems([]);
      setNotes("");
      loadProducts();
    } catch (e) {
      showNotice(e?.response?.data?.message || e.message || "Failed to post wastage.");
    } finally {
      setSaving(false);
    }
  };

  if (!open) return null;

  return (
    <FullScreenModal open={open} onClose={onClose} title="Stock Wastage" subtitle="Capture damaged, expired, spoiled or written-off stock">
      <div className="relative flex h-[calc(100vh-86px)] flex-col overflow-hidden bg-[#EFF3F3] text-[#122424] text-[11px]">
        {notice && <div className="absolute left-1/2 top-3 z-50 -translate-x-1/2 border border-[#122424] bg-white px-4 py-2 shadow-lg font-semibold">{notice}</div>}

        <div className="shrink-0 border-b border-[#CBD6D6] bg-white p-3">
          <div className="grid gap-2 xl:grid-cols-[170px_150px_170px_1fr_130px]">
            <label><div className="mb-1 text-[9px] uppercase text-[#637877]">Wastage Date</div><input type="date" value={wastageDate} onChange={(e)=>setWastageDate(e.target.value)} className="h-9 w-full border border-[#CBD6D6] px-2 outline-none" /></label>
            <label><div className="mb-1 text-[9px] uppercase text-[#637877]">Stock Area</div><select value={stockArea} onChange={(e)=>setStockArea(e.target.value)} className="h-9 w-full border border-[#CBD6D6] px-2 outline-none"><option value="front">FRONT</option><option value="back">BOH / BACK</option></select></label>
            <label><div className="mb-1 text-[9px] uppercase text-[#637877]">Default Reason</div><select value={reasonCode} onChange={(e)=>setReasonCode(e.target.value)} className="h-9 w-full border border-[#CBD6D6] px-2 outline-none">{REASONS.map((r)=><option key={r}>{r}</option>)}</select></label>
            <label><div className="mb-1 text-[9px] uppercase text-[#637877]">Search Product</div><div className="flex h-9 border border-[#CBD6D6] bg-white"><span className="flex w-9 items-center justify-center border-r border-[#E2E9E9]"><Search className="h-4 w-4" /></span><input value={search} onChange={(e)=>setSearch(e.target.value)} onKeyDown={(e)=>{if(e.key==='Enter')loadProducts();}} placeholder="Name, code or SKU" className="flex-1 px-2 outline-none" /></div></label>
            <button type="button" onClick={loadProducts} disabled={loading} className="mt-[16px] h-9 border border-[#122424] bg-[#003A3A] px-3 text-white uppercase text-[10px] font-semibold">Search</button>
          </div>
        </div>

        <div className="grid min-h-0 flex-1 grid-cols-1 gap-3 overflow-hidden p-3 xl:grid-cols-[420px_1fr]">
          <div className="min-h-0 overflow-hidden border border-[#CBD6D6] bg-white flex flex-col">
            <div className="border-b border-[#CBD6D6] bg-[#F8FAFC] px-3 py-2">
              <div className="text-[10px] uppercase tracking-wide text-[#324444]">Available Stock</div>
              <div className="mt-0.5 text-[9px] text-[#637877]">Allowed: Raw Materials, Semi Finished Goods, Packaging, Consumables</div>
            </div>
            <div className="min-h-0 flex-1 overflow-auto">
              {products.map((p)=>(
                <button key={p.product_id} type="button" onClick={()=>addProduct(p)} className="grid w-full grid-cols-[1fr_95px_30px] border-b border-[#EEF3F2] px-3 py-2 text-left hover:bg-[#F8FAFC]">
                  <span className="min-w-0"><span className="block truncate text-[12px] font-semibold">{p.product_name}</span><span className="block truncate text-[10px] text-[#637877]">{p.product_code || p.product_sku || '-'} · {p.uom_code} · {money(p.unit_cost, p.currency_code)}</span></span>
                  <span className="text-right text-[10px]"><span className="block text-[#637877]">Available</span><b>{qty(p.available_quantity)}</b></span>
                  <span className="flex items-center justify-end"><Plus className="h-4 w-4" /></span>
                </button>
              ))}
            </div>
          </div>

          <div className="min-h-0 overflow-hidden border border-[#CBD6D6] bg-white flex flex-col">
            <div className="shrink-0 grid grid-cols-[1fr_130px_150px_150px] border-b border-[#CBD6D6] bg-[#003A3A] px-3 py-2 text-white">
              <div><div className="text-[13px] font-semibold">Wastage Entry</div><div className="text-[9px] text-white/70">Opening stock → wasted qty → closing stock and cost</div></div>
              <div className="text-right"><div className="text-[9px] text-white/70">Total Qty</div><div className="text-[13px] font-semibold">{qty(totals.qty)}</div></div>
              <div className="text-right"><div className="text-[9px] text-white/70">Cost</div>{Object.values(totals.currency).map((c)=><div key={c.currency_code} className="text-[12px] font-semibold">{money(c.total_cost, c.currency_code)}</div>)}</div>
              <button type="button" onClick={save} disabled={saving || !items.length} className="ml-2 h-8 self-center border border-[#D1FAE5] bg-[#16A34A] px-3 text-white uppercase text-[10px] font-semibold inline-flex items-center justify-center gap-2 disabled:opacity-50"><Save className="h-4 w-4" /> {saving ? 'Saving' : 'Post Waste'}</button>
            </div>

            <div className="min-h-0 flex-1 overflow-auto">
              <table className="sx-enterprise-table min-w-[1380px] w-full text-[10.5px]">
                <thead className="bg-[#F8FAFC] text-[9px] uppercase text-[#465858]"><tr><th className="px-2 py-2 text-left">Product</th><th className="px-2 text-left">Code</th><th className="px-2 text-left">SKU</th><th className="px-2 text-left">UOM</th><th className="text-right px-2">Available</th><th className="text-right px-2">Wasted</th><th className="text-right px-2">Closing</th><th className="text-right px-2">Unit Cost</th><th className="text-right px-2">Cost</th><th className="px-2 text-left">Reason</th><th className="px-2 text-left">Notes</th><th className="text-center">Actions</th></tr></thead>
                <tbody>
                  {items.map((row)=>{
                    const q = Number(row.quantity || 0);
                    const closing = Number(row.available_quantity || row.quantity_on_hand || 0) - q;
                    return <tr key={row.product_id} className="border-t border-[#EEF3F2]"><td className="px-2 py-2"><b>{row.product_name}</b></td><td className="px-2">{row.product_code || '-'}</td><td className="px-2">{row.product_sku || '-'}</td><td className="px-2">{row.uom_code || '-'}</td><td className="px-2 text-right">{qty(row.available_quantity)}</td><td className="px-2"><input type="number" min="0" step="0.0001" value={row.quantity} onChange={(e)=>updateItem(row.product_id,{quantity:e.target.value})} className="h-8 w-full border border-[#CBD6D6] px-2 text-right outline-none" /></td><td className="px-2 text-right">{qty(closing)}</td><td className="px-2 text-right">{money(row.unit_cost,row.currency_code)}</td><td className="px-2 text-right font-semibold">{money(q * Number(row.unit_cost || 0), row.currency_code)}</td><td className="px-2"><select value={row.reason_code || reasonCode} onChange={(e)=>updateItem(row.product_id,{reason_code:e.target.value})} className="h-8 w-full border border-[#CBD6D6] px-2 outline-none">{REASONS.map((r)=><option key={r}>{r}</option>)}</select></td><td className="px-2"><input value={row.notes || ""} onChange={(e)=>updateItem(row.product_id,{notes:e.target.value})} className="h-8 w-full border border-[#CBD6D6] px-2 outline-none" /></td><td className="px-2 text-center"><WastageRowActionMenu label={`Actions for ${row.product_name}`} onRemove={()=>removeItem(row.product_id)} /></td></tr>;
                  })}
                  {!items.length && <tr><td colSpan="12" className="p-8 text-center text-[#637877]">No wastage items selected.</td></tr>}
                </tbody>
              </table>
            </div>

            <div className="shrink-0 sticky bottom-0 z-20 border-t border-[#CBD6D6] bg-[#F8FAFC] p-2 grid gap-2 lg:grid-cols-[1fr_150px_170px] shadow-[0_-6px_18px_rgba(9,27,27,0.08)]">
              <input value={notes} onChange={(e)=>setNotes(e.target.value)} placeholder="Header notes / supervisor comments" className="h-9 border border-[#CBD6D6] px-3 outline-none" />
              <button type="button" onClick={()=>{setItems([]); setNotes('');}} className="h-9 border border-[#CBD6D6] bg-white uppercase text-[10px]">Clear</button>
              <button type="button" onClick={save} disabled={saving || !items.length} className="h-9 border border-[#166534] bg-[#166534] text-white uppercase text-[10px] font-semibold inline-flex items-center justify-center gap-2 disabled:opacity-50"><Save className="h-4 w-4" /> {saving ? 'Saving' : 'Post Waste'}</button>
            </div>
          </div>
        </div>
      </div>
    </FullScreenModal>
  );
};

export default StockWastageModal;

const WastageRowActionMenu = ({ label, onRemove }) => {
  const [open, setOpen] = useState(false);

  return (
    <div
      className="action-menu-wrap relative flex justify-center"
      onClick={(event) => event.stopPropagation()}
      onBlur={(event) => {
        if (!event.currentTarget.contains(event.relatedTarget)) setOpen(false);
      }}
    >
      <button
        type="button"
        aria-label={label}
        aria-haspopup="menu"
        aria-expanded={open}
        onClick={() => setOpen((current) => !current)}
        className="flex h-7 w-7 items-center justify-center border border-[#B9C3C3] bg-white text-[18px] font-bold leading-none text-[#324443] hover:bg-[#F2F4F7]"
      >
        ⋮
      </button>
      {open && (
        <div role="menu" className="absolute right-0 top-8 z-30 min-w-[130px] border border-[#CBD6D6] bg-white p-1 text-left shadow-lg">
          <button
            type="button"
            role="menuitem"
            onClick={() => {
              setOpen(false);
              onRemove();
            }}
            className="block w-full px-3 py-2 text-left text-[11px] font-semibold text-[#B42318] hover:bg-[#FEF3F2]"
          >
            Remove line
          </button>
        </div>
      )}
    </div>
  );
};

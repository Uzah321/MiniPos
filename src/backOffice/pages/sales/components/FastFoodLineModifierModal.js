import React, { useEffect, useMemo, useState } from "react";
import { getPosFastFoodProductOptions } from "../../../../services/FastFoodPrepModifierService";

const normalizeRows = (response) => {
  const payload = response?.data || response;
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data)) return payload.data;
  if (Array.isArray(payload?.data?.data)) return payload.data.data;
  return [];
};

const normalizeOptions = (response) => {
  const payload = response?.data || response;
  return payload?.data || payload || {};
};

const getApiMessage = (source, fallback = "Request failed.") => {
  const responseData = source?.response?.data;
  if (responseData?.errors && typeof responseData.errors === "object") {
    return Object.values(responseData.errors).flat().join("\n");
  }
  return responseData?.message || responseData?.error || source?.message || fallback;
};

const asMoney = (value, currency = "USD") => `${currency || "USD"} ${Number(value || 0).toFixed(2)}`;

export default function FastFoodLineModifierModal({
  open,
  line,
  branchId = "",
  saleCurrencyCode = "USD",
  convertMoney,
  onClose,
  onApply,
}) {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [availableModifiers, setAvailableModifiers] = useState([]);
  const [selectedMap, setSelectedMap] = useState({});

  useEffect(() => {
    if (!open || !line) return;
    setError("");

    const map = {};
    (line.modifiers || []).forEach((modifier) => {
      const id = String(modifier.id || modifier.modifier_id || "");
      if (!id) return;
      map[id] = {
        ...modifier,
        id,
        modifier_id: id,
        quantity: Number(modifier.quantity || 1),
        price: Number(modifier.price || 0),
        currency_code: modifier.currency_code || modifier.currency || saleCurrencyCode,
      };
    });
    setSelectedMap(map);

    let mounted = true;
    const load = async () => {
      try {
        setLoading(true);
        const productId = line.product_id || line.productId;
        const response = await getPosFastFoodProductOptions(productId, { branch_id: branchId || undefined });
        if (!mounted) return;
        const options = normalizeOptions(response);
        setAvailableModifiers(normalizeRows(options.modifiers));
      } catch (err) {
        if (!mounted) return;
        setError(getApiMessage(err, "Failed to load modifiers."));
        setAvailableModifiers([]);
      } finally {
        if (mounted) setLoading(false);
      }
    };
    load();

    return () => {
      mounted = false;
    };
  }, [open, line, branchId, saleCurrencyCode]);

  if (!open || !line) return null;

  const selectedRows = Object.values(selectedMap);
  const selectedUnitTotal = selectedRows.reduce((sum, modifier) => sum + Number(modifier.price || 0) * Number(modifier.quantity || 1), 0);
  const baseUnitPrice = Number(line.baseMenuPrice ?? line.menuBasePrice ?? line.basePrice ?? line.price ?? 0);
  const newUnitPrice = baseUnitPrice + selectedUnitTotal;

  const toggle = async (modifier) => {
    const id = String(modifier.id || "");
    if (!id) return;

    setSelectedMap((prev) => {
      if (prev[id]) {
        const next = { ...prev };
        delete next[id];
        return next;
      }

      return {
        ...prev,
        [id]: {
          ...modifier,
          id,
          modifier_id: id,
          quantity: 1,
          price: Number(modifier.price || 0),
          currency_code: modifier.currency_code || modifier.currency || saleCurrencyCode,
          print_on_kitchen: modifier.print_on_kitchen !== false,
          print_on_customer_receipt: modifier.print_on_customer_receipt !== false,
          deduct_recipe_stock: modifier.deduct_recipe_stock !== false,
          recipe_id: modifier.recipe_id || null,
        },
      };
    });
  };

  const setModifierQty = (modifierId, qty) => {
    const cleanQty = Math.max(1, Number(qty || 1));
    setSelectedMap((prev) => ({
      ...prev,
      [modifierId]: {
        ...prev[modifierId],
        quantity: cleanQty,
      },
    }));
  };

  const apply = async () => {
    let rows = Object.values(selectedMap);

    if (convertMoney) {
      rows = await Promise.all(rows.map(async (modifier) => {
        const fromCurrency = modifier.currency_code || modifier.currency || saleCurrencyCode;
        let convertedPrice = Number(modifier.price || 0);
        try {
          convertedPrice = await convertMoney(fromCurrency, saleCurrencyCode, convertedPrice);
        } catch {
          convertedPrice = Number(modifier.price || 0);
        }
        return {
          ...modifier,
          price: Number(convertedPrice || 0),
          currency_code: saleCurrencyCode,
        };
      }));
    }

    const modifierUnitTotal = rows.reduce((sum, modifier) => sum + Number(modifier.price || 0) * Number(modifier.quantity || 1), 0);
    const baseMenuPrice = Number(line.baseMenuPrice ?? line.menuBasePrice ?? line.basePrice ?? line.price ?? 0);

    onApply?.({
      baseMenuPrice,
      menuBasePrice: baseMenuPrice,
      modifiers: rows,
      modifier_ids: rows.map((row) => row.id || row.modifier_id).filter(Boolean),
      modifierIds: rows.map((row) => row.id || row.modifier_id).filter(Boolean),
      modifierUnitTotal,
      price: Number((baseMenuPrice + modifierUnitTotal).toFixed(4)),
    });
  };

  return (
    <div className="absolute inset-0 z-[110] flex items-center justify-center bg-[#003637]/55 p-4">
      <div className="sx-large-modal-window border border-[#00BCBC] bg-white text-[#1C2C2B] shadow-2xl" role="dialog" aria-modal="true" aria-label="Modifiers">
        <div className="grid min-h-[52px] grid-cols-[1fr_auto] border-b border-[#00BCBC] bg-[#004142] text-white">
          <div className="min-w-0 px-3 py-2">
            <div className="truncate text-[14px] font-black uppercase tracking-[0.08em]">Modifiers</div>
            <div className="truncate text-[10px] font-semibold uppercase tracking-[0.12em] text-white/70">{line.name} / select modifiers and change modifier quantity</div>
          </div>
          <button type="button" onClick={onClose} className="flex h-full w-[58px] items-center justify-center border-l border-white/20 bg-[#B42318] text-[20px] font-black text-white">×</button>
        </div>

        <div className="grid min-h-0 flex-1 gap-2 overflow-hidden p-3 lg:grid-cols-[250px_minmax(0,1fr)]">
          <aside className="border border-[#ACB8B8] bg-[#F8FAFC] p-3 text-[11px]">
            <div className="border-b border-[#CFD9D8] pb-2 text-[10px] font-black uppercase text-[#627474]">Price Preview</div>
            <Summary label="Product" value={line.name} />
            <Summary label="Base unit" value={asMoney(baseUnitPrice, saleCurrencyCode)} />
            <Summary label="Modifiers/unit" value={asMoney(selectedUnitTotal, saleCurrencyCode)} />
            <Summary label="New unit" value={asMoney(newUnitPrice, saleCurrencyCode)} />
            <Summary label="Line qty" value={line.qty || line.quantity || 1} />
            <Summary label="Selected" value={selectedRows.length} />
            <div className="mt-3 border border-[#A4E5E4] bg-[#F1F8F8] p-2 text-[10px] font-semibold text-[#057B7B]">Modifier quantity is per item. Example: Extra Cheese x 2 adds two cheese units to each burger line.</div>
          </aside>

          <main className="min-h-0 overflow-hidden border border-[#ACB8B8] bg-white">
            <div className="grid h-9 grid-cols-[1fr_130px_130px_120px] border-b border-[#CFD9D8] bg-[#0E4D4D] px-2 text-[10px] font-black uppercase text-white">
              <div className="flex items-center">Modifier</div>
              <div className="flex items-center justify-end">Price</div>
              <div className="flex items-center justify-center">Qty</div>
              <div className="flex items-center justify-end">Selected</div>
            </div>
            <div className="max-h-[calc(100vh-178px)] overflow-y-auto overflow-x-hidden">
              {error ? <Empty danger message={error} /> : loading ? <Empty message="Loading modifiers..." /> : availableModifiers.length === 0 ? <Empty message="No modifiers configured for this menu item." /> : availableModifiers.map((modifier) => {
                const id = String(modifier.id);
                const selected = Boolean(selectedMap[id]);
                const qty = Number(selectedMap[id]?.quantity || 1);
                return (
                  <div key={id} className={`grid grid-cols-[1fr_130px_130px_120px] border-b border-[#EFF2F2] text-[11px] ${selected ? "bg-[#ECFDF3]" : "bg-white hover:bg-[#F8FAFC]"}`}>
                    <button type="button" onClick={() => toggle(modifier)} className="px-2 py-2 text-left">
                      <div className="font-black uppercase text-[#0C1B1B]">{modifier.name}</div>
                      <div className="text-[10px] text-[#627474]">
                        {modifier.source === "modifier_group_option"
                          ? `${modifier.group_name || "Modifier group"} / ${modifier.code || "-"}`
                          : `${modifier.code || "-"} / Recipe: ${modifier.recipe_id ? "linked" : "none"}`}
                      </div>
                    </button>
                    <div className="border-l border-[#EFF2F2] px-2 py-2 text-right font-black text-[#057B7C]">{asMoney(modifier.price, modifier.currency_code || saleCurrencyCode)}</div>
                    <div className="flex items-center justify-center gap-1 border-l border-[#EFF2F2] px-2 py-2">
                      <button type="button" disabled={!selected} onClick={() => setModifierQty(id, qty - 1)} className="h-7 w-8 border border-[#ACB8B8] bg-white text-[13px] font-black disabled:opacity-40">-</button>
                      <input type="number" min="1" disabled={!selected} value={qty} onChange={(event) => setModifierQty(id, event.target.value)} className="h-7 w-12 border border-[#ACB8B8] text-center text-[11px] font-black disabled:bg-[#EEF2F2]" />
                      <button type="button" disabled={!selected} onClick={() => setModifierQty(id, qty + 1)} className="h-7 w-8 border border-[#ACB8B8] bg-white text-[13px] font-black disabled:opacity-40">+</button>
                    </div>
                    <label className="flex items-center justify-end gap-2 border-l border-[#EFF2F2] px-2 py-2 font-black uppercase text-[#324443]"><input type="checkbox" checked={selected} onChange={() => toggle(modifier)} /> {selected ? "Yes" : "No"}</label>
                  </div>
                );
              })}
            </div>
          </main>
        </div>

        <div className="flex min-h-[52px] shrink-0 items-center justify-between gap-2 border-t border-[#CFD9D8] bg-[#F5F7FA] px-3 py-2">
          <div className="text-[10px] font-semibold uppercase text-[#627474]">Selected modifiers update line price and are posted to kitchen/customer receipt.</div>
          <div className="flex gap-2">
            <button type="button" onClick={onClose} className="h-9 border border-[#AAB5B5] bg-white px-4 text-[10px] font-black uppercase text-[#324443]">Cancel</button>
            <button type="button" onClick={() => setSelectedMap({})} className="h-9 border border-[#AAB5B5] bg-white px-4 text-[10px] font-black uppercase text-[#324443]">Clear</button>
            <button type="button" onClick={apply} className="h-9 border border-[#069495] bg-[#069495] px-5 text-[10px] font-black uppercase text-white">Apply Modifiers</button>
          </div>
        </div>
      </div>
    </div>
  );
}

const Summary = ({ label, value }) => <div className="mt-2 flex justify-between gap-2 border-b border-[#E4E8E8] pb-1"><span className="text-[#627474]">{label}</span><strong className="truncate text-right text-[#1C2C2B]">{value || "-"}</strong></div>;
const Empty = ({ message, danger = false }) => <div className={`p-8 text-center text-[12px] font-semibold ${danger ? "text-[#B42318]" : "text-[#627474]"}`}>{message}</div>;

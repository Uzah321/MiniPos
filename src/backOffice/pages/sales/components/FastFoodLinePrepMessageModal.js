import React, { useEffect, useMemo, useRef, useState } from "react";
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

const cleanId = (value) => String(value || "").trim();

export default function FastFoodLinePrepMessageModal({
  open,
  line,
  branchId = "",
  onClose,
  onApply,
}) {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [prepMessages, setPrepMessages] = useState([]);
  const [selectedIds, setSelectedIds] = useState([]);
  const [prepNote, setPrepNote] = useState("");
  const noteRef = useRef(null);

  const selectedRows = useMemo(() => {
    const ids = new Set(selectedIds.map(String));
    return prepMessages.filter((row) => ids.has(String(row.id)));
  }, [prepMessages, selectedIds]);

  useEffect(() => {
    if (!open || !line) return;
    setSelectedIds((line.prep_message_ids || line.prepMessageIds || []).map(String));
    setPrepNote(line.prep_note || line.prepNote || "");
    setError("");

    let mounted = true;
    const load = async () => {
      try {
        setLoading(true);
        const productId = line.product_id || line.productId;
        const response = await getPosFastFoodProductOptions(productId, { branch_id: branchId || undefined });
        if (!mounted) return;
        const options = normalizeOptions(response);
        setPrepMessages(normalizeRows(options.prep_messages));
      } catch (err) {
        if (!mounted) return;
        setError(getApiMessage(err, "Failed to load prep messages."));
        setPrepMessages([]);
      } finally {
        if (mounted) setLoading(false);
      }
    };
    load();

    window.setTimeout(() => noteRef.current?.focus?.(), 80);
    return () => {
      mounted = false;
    };
  }, [open, line, branchId]);

  useEffect(() => {
    if (!open) return undefined;
    const handler = (event) => {
      if (event.key === "Escape") onClose?.();
      if (event.key === "Enter" && (event.ctrlKey || event.metaKey)) apply();
    };
    window.addEventListener("keydown", handler);
    return () => window.removeEventListener("keydown", handler);
  });

  if (!open || !line) return null;

  const toggle = (id) => {
    const value = cleanId(id);
    setSelectedIds((prev) => prev.includes(value) ? prev.filter((row) => row !== value) : [...prev, value]);
  };

  const apply = () => {
    onApply?.({
      prep_message_ids: selectedIds,
      prepMessageIds: selectedIds,
      prep_messages: selectedRows,
      prepMessages: selectedRows,
      prep_note: prepNote.trim() || null,
      prepNote: prepNote.trim() || null,
    });
  };

  return (
    <div className="absolute inset-0 z-[110] flex items-center justify-center bg-[#003637]/55 p-4">
      <div className="sx-large-modal-window border border-[#00BCBC] bg-white text-[#1C2C2B] shadow-2xl" role="dialog" aria-modal="true" aria-label="Prep Messages">
        <div className="grid min-h-[52px] grid-cols-[1fr_auto] border-b border-[#00BCBC] bg-[#004142] text-white">
          <div className="min-w-0 px-3 py-2">
            <div className="truncate text-[14px] font-black uppercase tracking-[0.08em]">Prep Messages</div>
            <div className="truncate text-[10px] font-semibold uppercase tracking-[0.12em] text-white/70">{line.name} / select many messages or type customer note</div>
          </div>
          <button type="button" onClick={onClose} className="flex h-full w-[58px] items-center justify-center border-l border-white/20 bg-[#B42318] text-[20px] font-black text-white">×</button>
        </div>

        <div className="grid min-h-0 flex-1 gap-2 overflow-hidden p-3 md:grid-cols-[260px_minmax(0,1fr)]">
          <aside className="border border-[#ACB8B8] bg-[#F8FAFC] p-3 text-[11px]">
            <div className="border-b border-[#CFD9D8] pb-2 text-[10px] font-black uppercase text-[#627474]">Selected Line</div>
            <Summary label="Product" value={line.name} />
            <Summary label="Qty" value={line.qty || line.quantity || 1} />
            <Summary label="Selected" value={selectedIds.length} />
            <div className="mt-3 border border-[#FEDF89] bg-[#FFFAEB] p-2 text-[10px] font-semibold text-[#B54708]">Prep messages print on kitchen ticket and customer receipt for reference.</div>
          </aside>

          <main className="grid min-h-0 grid-rows-[minmax(0,1fr)_140px] gap-2">
            <section className="min-h-0 overflow-hidden border border-[#ACB8B8] bg-white">
              <div className="flex h-9 items-center justify-between border-b border-[#CFD9D8] bg-[#F5F7FA] px-2">
                <div className="text-[11px] font-black uppercase text-[#004141]">Configured Prep Messages</div>
                <div className="text-[10px] font-semibold uppercase text-[#627474]">{loading ? "Loading..." : `${prepMessages.length} row(s)`}</div>
              </div>
              <div className="max-h-[calc(100vh-300px)] overflow-y-auto overflow-x-hidden">
                {error ? <Empty danger message={error} /> : loading ? <Empty message="Loading prep messages..." /> : prepMessages.length === 0 ? <Empty message="No prep messages configured for this menu item." /> : prepMessages.map((message) => {
                  const checked = selectedIds.includes(String(message.id));
                  return (
                    <button key={message.id} type="button" onClick={() => toggle(message.id)} className={`grid w-full grid-cols-[36px_1fr_120px] border-b border-[#EFF2F2] text-left text-[11px] ${checked ? "bg-[#ECFDF3]" : "bg-white hover:bg-[#F8FAFC]"}`}>
                      <span className="flex items-center justify-center border-r border-[#EFF2F2]"><input type="checkbox" checked={checked} onChange={() => toggle(message.id)} onClick={(e) => e.stopPropagation()} /></span>
                      <span className="px-2 py-2"><span className="block font-black uppercase text-[#0C1B1B]">{message.message}</span><span className="text-[10px] text-[#627474]">{message.code || "-"}</span></span>
                      <span className="border-l border-[#EFF2F2] px-2 py-2 text-right text-[10px] font-semibold uppercase text-[#627474]">{message.allow_free_text ? "Allows note" : "Configured"}</span>
                    </button>
                  );
                })}
              </div>
            </section>

            <label className="block border border-[#ACB8B8] bg-[#F8FAFC] p-2">
              <div className="mb-1 text-[10px] font-black uppercase text-[#627474]">Other Customer Request / Prep Note</div>
              <textarea ref={noteRef} value={prepNote} onChange={(event) => setPrepNote(event.target.value)} className="h-[94px] w-full resize-none border border-[#ACB8B8] bg-white p-2 text-[13px] font-semibold uppercase text-[#004141] outline-none focus:border-[#069495]" placeholder="Example: NO ONION, WELL DONE, CUT IN HALF" />
            </label>
          </main>
        </div>

        <div className="flex min-h-[52px] shrink-0 items-center justify-between gap-2 border-t border-[#CFD9D8] bg-[#F5F7FA] px-3 py-2">
          <div className="text-[10px] font-semibold uppercase text-[#627474]">Ctrl + Enter applies. Escape closes.</div>
          <div className="flex gap-2">
            <button type="button" onClick={onClose} className="h-9 border border-[#AAB5B5] bg-white px-4 text-[10px] font-black uppercase text-[#324443]">Cancel</button>
            <button type="button" onClick={() => { setSelectedIds([]); setPrepNote(""); }} className="h-9 border border-[#AAB5B5] bg-white px-4 text-[10px] font-black uppercase text-[#324443]">Clear</button>
            <button type="button" onClick={apply} className="h-9 border border-[#069495] bg-[#069495] px-5 text-[10px] font-black uppercase text-white">Apply Prep</button>
          </div>
        </div>
      </div>
    </div>
  );
}

const Summary = ({ label, value }) => <div className="mt-2 flex justify-between gap-2 border-b border-[#E4E8E8] pb-1"><span className="text-[#627474]">{label}</span><strong className="truncate text-right text-[#1C2C2B]">{value || "-"}</strong></div>;
const Empty = ({ message, danger = false }) => <div className={`p-8 text-center text-[12px] font-semibold ${danger ? "text-[#B42318]" : "text-[#627474]"}`}>{message}</div>;

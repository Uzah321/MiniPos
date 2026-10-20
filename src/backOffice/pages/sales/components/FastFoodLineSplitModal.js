import React, { useEffect, useRef, useState } from "react";

export default function FastFoodLineSplitModal({ open, line, onClose, onApply }) {
  const [qty, setQty] = useState("1");
  const [copyCustomisation, setCopyCustomisation] = useState(false);
  const inputRef = useRef(null);

  useEffect(() => {
    if (!open || !line) return;
    setQty("1");
    setCopyCustomisation(false);
    window.setTimeout(() => inputRef.current?.focus?.(), 80);
  }, [open, line]);

  if (!open || !line) return null;

  const currentQty = Number(line.qty || line.quantity || 0);
  const maxSplitQty = Math.max(currentQty - 1, 1);
  const splitQty = Math.min(Math.max(Number(qty || 1), 1), maxSplitQty);
  const remainingQty = Math.max(currentQty - splitQty, 0);

  const apply = (event) => {
    event?.preventDefault?.();
    if (currentQty <= 1 || remainingQty <= 0) return;
    onApply?.({ splitQty, copyCustomisation });
  };

  return (
    <div className="absolute inset-0 z-[110] flex items-center justify-center bg-[#003637]/55 p-4">
      <form onSubmit={apply} className="sx-large-modal-window border border-[#00BCBC] bg-white text-[#1C2C2B] shadow-2xl" role="dialog" aria-modal="true" aria-label="Split Line">
        <div className="flex h-12 items-center justify-between border-b border-[#00BCBC] bg-[#004142] px-3 text-white">
          <div>
            <div className="text-[14px] font-black uppercase tracking-[0.08em]">Split Line</div>
            <div className="text-[10px] font-semibold uppercase text-white/70">Split same product so each burger can have different prep/modifiers</div>
          </div>
          <button type="button" onClick={onClose} className="flex h-8 w-8 items-center justify-center border border-white/20 bg-white/10 text-[18px] font-black">×</button>
        </div>

        <div className="grid min-h-0 flex-1 gap-3 overflow-y-auto p-3 md:grid-cols-[220px_1fr]">
          <aside className="border border-[#CFD9D8] bg-[#F5F7FA] p-3 text-[11px]">
            <Summary label="Product" value={line.name} />
            <Summary label="Current Qty" value={currentQty} />
            <Summary label="Split Qty" value={splitQty} />
            <Summary label="Remaining" value={remainingQty} />
            <div className="mt-3 border border-[#FEDF89] bg-[#FFFAEB] p-2 text-[10px] font-semibold text-[#B54708]">Example: Qty 2 burgers split into 1 + 1. Then select one line and add prep messages/modifiers.</div>
          </aside>

          <main>
            <label className="block">
              <div className="mb-1 text-[10px] font-black uppercase text-[#627474]">Quantity to split into new line</div>
              <input ref={inputRef} type="number" min="1" max={maxSplitQty} step="1" value={qty} onChange={(event) => setQty(event.target.value)} className="h-14 w-full border border-[#AAB5B5] bg-white px-3 text-right text-[30px] font-black text-[#004141] outline-none focus:border-[#069495]" />
            </label>

            <label className="mt-3 flex min-h-[42px] items-center gap-2 border border-[#CBD6D6] bg-[#F8FAFC] px-3 text-[11px] font-semibold text-[#324443]">
              <input type="checkbox" checked={copyCustomisation} onChange={(event) => setCopyCustomisation(event.target.checked)} />
              Copy prep messages and modifiers to the new split line
            </label>

            <div className="mt-3 grid grid-cols-4 gap-2">
              {["1", "2", "3", "4"].map((value) => <button key={value} type="button" onClick={() => setQty(value)} className="h-12 border border-[#ACB8B8] bg-white text-[18px] font-black">{value}</button>)}
            </div>
          </main>
        </div>

        <div className="flex h-12 items-center justify-between border-t border-[#CFD9D8] bg-[#F5F7FA] px-3">
          <div className="text-[10px] font-semibold uppercase text-[#627474]">Line must have quantity greater than 1.</div>
          <div className="flex gap-2">
            <button type="button" onClick={onClose} className="h-8 border border-[#AAB5B5] bg-white px-4 text-[10px] font-black uppercase text-[#324443]">Cancel</button>
            <button type="submit" disabled={currentQty <= 1} className="h-8 border border-[#069495] bg-[#069495] px-5 text-[10px] font-black uppercase text-white disabled:opacity-50">Split</button>
          </div>
        </div>
      </form>
    </div>
  );
}

const Summary = ({ label, value }) => <div className="mt-2 flex justify-between gap-2 border-b border-[#E4E8E8] pb-1"><span className="text-[#627474]">{label}</span><strong className="truncate text-right text-[#1C2C2B]">{value || "-"}</strong></div>;

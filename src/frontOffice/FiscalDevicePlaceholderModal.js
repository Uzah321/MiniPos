import React from "react";
import { Clock3, KeyRound, Link2, ShieldCheck } from "lucide-react";
import FullScreenModal from "../components/FullScreenModal";

export default function FiscalDevicePlaceholderModal({ open, onClose }) {
  return (
    <FullScreenModal
      open={open}
      onClose={onClose}
      title="FISCAL DEVICE"
      subtitle="FRONT OFFICE / INTEGRATION PLACEHOLDER"
    >
      <div className="h-full overflow-auto bg-gradient-to-br from-[#F7FAFC] to-[#EBF1F1] p-5 sm:p-8">
        <div className="mx-auto max-w-3xl overflow-hidden rounded-xl border border-[#CAD6D6] bg-white shadow-[0_16px_40px_rgba(3,71,71,0.12)]">
          <div className="flex items-start gap-4 border-b border-[#DBE3E3] bg-[#F8FBFD] p-5 sm:p-6">
            <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-[#007474] text-white">
              <ShieldCheck className="h-6 w-6" />
            </div>
            <div>
              <h1 className="text-[18px] font-semibold text-[#054747]">Fiscal device connection</h1>
              <p className="mt-1 text-[12px] leading-relaxed text-[#6B7C7B]">Connection details will be enabled when the fiscal integration is available.</p>
              <span className="mt-3 inline-flex items-center gap-2 rounded-full border border-[#E2B93B] bg-[#FFF8DD] px-3 py-1 text-[10px] font-semibold uppercase tracking-wide text-[#785F0D]"><Clock3 className="h-3.5 w-3.5" /> Awaiting integration</span>
            </div>
          </div>

          <div className="space-y-5 p-5 sm:p-6">
            <label className="block">
              <span className="mb-2 flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.08em] text-[#445656]"><Link2 className="h-4 w-4 text-[#007474]" /> API URL</span>
              <input
                type="text"
                readOnly
                value=""
                placeholder="https://fiscal-provider.example/api/v1"
                className="h-11 w-full rounded-md border border-[#BCCAC9] bg-[#F8FAFC] px-3 font-mono text-[12px] text-[#394C4C] placeholder:text-[#8E9C9B] focus:outline-none"
              />
            </label>

            <label className="block">
              <span className="mb-2 flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.08em] text-[#445656]"><KeyRound className="h-4 w-4 text-[#007474]" /> Bearer Token</span>
              <input
                type="password"
                readOnly
                value=""
                placeholder="Bearer token will be supplied by the integration provider"
                className="h-11 w-full rounded-md border border-[#BCCAC9] bg-[#F8FAFC] px-3 font-mono text-[12px] text-[#394C4C] placeholder:text-[#8E9C9B] focus:outline-none"
              />
            </label>

            <div className="rounded-md border border-[#CCDAD9] bg-[#EFF5F5] px-4 py-3 text-[11px] leading-relaxed text-[#556969]">
              These fields are placeholders only. No fiscal credentials are stored or transmitted until the integration is implemented.
            </div>
          </div>
        </div>
      </div>
    </FullScreenModal>
  );
}

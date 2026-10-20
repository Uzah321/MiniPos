import React from "react";
import { Lock, RefreshCw } from "lucide-react";

const DayEndPosBlockedOverlay = ({ blocked, status }) => {
  if (!blocked) return null;

  return (
    <div className="fixed inset-0 z-[9999] flex items-center justify-center bg-black/70 px-4">
      <div className="w-full max-w-xl border border-[#F79009] bg-white p-5 text-center shadow-2xl">
        <div className="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-[#FFFAEB] text-[#B54708]">
          <Lock className="h-8 w-8" />
        </div>
        <div className="text-[20px] font-black uppercase text-[#003636]">POS Blocked For Day End</div>
        <div className="mt-2 text-[13px] font-semibold text-[#465757]">
          Day end is running on this branch. Sales are blocked so that the closing and DSR report are not disturbed.
        </div>
        <div className="mt-4 border border-[#FEDF89] bg-[#FFFAEB] p-3 text-[12px] font-black uppercase text-[#B54708]">
          Trading Date: {status?.active_day_end?.trading_date || status?.active_day_end?.trading_date?.date || "Current"}
        </div>
        <div className="mt-4 flex items-center justify-center gap-2 text-[11px] font-bold uppercase text-[#627474]">
          <RefreshCw className="h-4 w-4 animate-spin" /> Waiting for day end to finish...
        </div>
      </div>
    </div>
  );
};

export default DayEndPosBlockedOverlay;

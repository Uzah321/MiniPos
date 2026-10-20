import React from "react";
import { FileSearch } from "lucide-react";

export default function ReportGenerationPrompt({
  title = "Configure this report",
  message = "Choose the report scope above, then select Generate to load the report.",
}) {
  return (
    <div className="flex min-h-[240px] flex-1 items-center justify-center bg-[#F7F8F9] p-6">
      <div className="w-full max-w-[520px] border border-[#CFD6D6] bg-white px-6 py-8 text-center shadow-sm">
        <FileSearch className="mx-auto h-8 w-8 text-[#069494]" />
        <div className="mt-3 text-[14px] font-semibold text-[#293232]">{title}</div>
        <div className="mt-1 text-[11px] leading-5 text-[#627474]">{message}</div>
      </div>
    </div>
  );
}

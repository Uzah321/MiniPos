import React from "react";

const PageWrapper = ({ title = "Dashboard", subtitle, right, children }) => {
  return (
    <div className="h-full min-h-0 w-full bg-white text-[#293232]">
      <div className="flex h-full min-h-0 w-full flex-col px-4 py-3">
        <header className="mb-3 flex min-h-[48px] shrink-0 items-center justify-between gap-3 border-b border-[#D8DCDC] bg-white px-1">
          <div className="min-w-0 border-b-2 border-[#069494] pb-2 pr-8">
            <div className="truncate text-[15px] font-medium text-[#293232]">{title}</div>
            {subtitle && <div className="mt-0.5 truncate text-[11px] font-normal text-[#7C8685]">{subtitle}</div>}
          </div>

          {right && (
            <div className="flex shrink-0 items-center gap-1">
              {right}
            </div>
          )}
        </header>

        <main className="min-h-0 flex-1 overflow-hidden bg-white">
          <div className="h-full min-h-0 overflow-auto">
            {children}
          </div>
        </main>
      </div>
    </div>
  );
};

export default PageWrapper;

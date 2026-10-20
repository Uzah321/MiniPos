import React from "react";

const PageWrapper = ({ children }) => {
  return (
    <div className="min-h-screen bg-[#1E2323] text-gray-100 flex flex-col">
      {/* Global content wrapper (consistent spacing across all pages) */}
      <div className="p-4 space-y-4 w-full">
        {children}
      </div>
    </div>
  );
};

export default PageWrapper;

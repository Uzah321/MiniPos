import React from "react";
import HospitalityStructureManagementModal, {
  extractManagementPagination,
  extractManagementRows,
} from "./HospitalityStructureManagementModal";

// Compatibility exports for older imports. The former combined hierarchy
// workspace has been replaced by focused management screens.
export const extractHierarchyCollection = (response, level) => {
  const aliases = {
    country: ["countries", "country"],
    company: ["companies", "company"],
    brand: ["brands", "brand"],
    complex: ["complexes", "complex"],
    shop: ["shops", "branches", "shop", "branch"],
    till: ["tills", "terminals", "till", "terminal"],
    menu: ["menu_items", "products", "items", "menu"],
  }[level] || [level];
  const containers = [response, response?.data, response?.data?.data].filter(Boolean);
  for (const container of containers) {
    if (Array.isArray(container)) return container;
    for (const key of aliases) {
      if (Array.isArray(container?.[key])) return container[key];
      if (Array.isArray(container?.[key]?.data)) return container[key].data;
    }
  }
  return extractManagementRows(response);
};

export const extractPagination = extractManagementPagination;

export default function EnterpriseHierarchyManagementModal(props) {
  return <HospitalityStructureManagementModal {...props} section={props.section || "countries"} />;
}


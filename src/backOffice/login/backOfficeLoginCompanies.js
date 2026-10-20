export const extractBackOfficeCompanyChoices = (response) => {
  const candidates = [
    response?.companies,
    response?.data?.companies,
    response?.company_choices,
    response?.data?.company_choices,
  ];

  return candidates.find(Array.isArray) || [];
};

export const backOfficeCompanyName = (company) =>
  company?.company_name || company?.name || "Unnamed company";

export const backOfficeCompanyLocation = (company) => {
  const country = company?.country;
  const countryLabel = country && typeof country === "object"
    ? country.name || country.code || country.iso_alpha_3 || country.iso_alpha_2 || ""
    : country || "";

  return [company?.city, countryLabel].filter(Boolean).join(" · ") || "Hospitality account";
};

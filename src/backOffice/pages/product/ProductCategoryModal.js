// src/backOffice/pages/product/ProductCategoryModal.jsx

import React, { useEffect, useMemo, useRef, useState } from "react";
import FullScreenModal from "../../../components/FullScreenModal";
import { requestEnterpriseConfirmation } from "../../../components/EnterpriseConfirmHost";
import ReusableMessageModal from "../../../components/ReusableMessageModal";
import { publicAssetUrl } from "../../../utils/publicAssetUrl";

import {
  getProductCategories,
  createProductCategory,
  updateProductCategory,
  deleteProductCategory,
  uploadProductCategoryImage,
  deleteProductCategoryImage,
  uploadProductCategoryBannerImage,
  deleteProductCategoryBannerImage,
  getProductSubCategories,
  createProductSubCategory,
  updateProductSubCategory,
  deleteProductSubCategory,
  uploadProductSubCategoryImage,
  deleteProductSubCategoryImage,
  uploadProductSubCategoryBannerImage,
  deleteProductSubCategoryBannerImage,
} from "../../../services/ProductService";

const emptyCategoryForm = {
  id: null,
  parent_id: "",
  name: "",
  code: "",
  description: "",
  display_area: "both",
  sort_order: 0,
  is_active: true,
  image_url: "",
  image_path: "",
  banner_image_url: "",
  banner_image_path: "",
};

const emptySubCategoryForm = {
  id: null,
  category_id: "",
  name: "",
  code: "",
  description: "",
  display_area: "both",
  sort_order: 0,
  is_active: true,
  image_url: "",
  image_path: "",
  banner_image_url: "",
  banner_image_path: "",
};

const normalizeListResponse = (response) => {
  const payload = response?.data || response;
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data)) return payload.data;
  if (Array.isArray(payload?.data?.data)) return payload.data.data;
  return [];
};

const getApiMessage = (source, fallback = "Request failed.") => {
  const responseData = source?.response?.data;

  if (responseData?.errors && typeof responseData.errors === "object") {
    return Object.values(responseData.errors).flat().join("\n");
  }

  if (responseData?.message) return responseData.message;
  if (responseData?.error) return responseData.error;
  if (source?.message && !source?.isAxiosError) return source.message;
  if (source?.data?.message) return source.data.message;
  if (source?.data?.error) return source.data.error;

  return fallback;
};

const getImage = (row) => publicAssetUrl(
  row?.image_path || row?.image_url || row?.imageUrl || row?.image || row?.photo_url,
);

const getBannerImage = (row) => publicAssetUrl(
  row?.banner_image_path || row?.banner_image_url || row?.bannerImageUrl || row?.banner_image,
);

const DISPLAY_AREAS = [
  ["front", "Front Only"],
  ["back", "Back Only"],
  ["both", "Front & Back"],
];

export default function ProductCategoryModal({ open, onClose }) {
  const imageInputRef = useRef(null);
  const bannerInputRef = useRef(null);

  const [categories, setCategories] = useState([]);
  const [subCategories, setSubCategories] = useState([]);

  const [activeTab, setActiveTab] = useState("categories");
  const [formOpen, setFormOpen] = useState(false);
  const [formType, setFormType] = useState("category");

  const [categoryForm, setCategoryForm] = useState(emptyCategoryForm);
  const [subCategoryForm, setSubCategoryForm] = useState(emptySubCategoryForm);

  const [selectedCategoryId, setSelectedCategoryId] = useState(null);
  const [selectedSubCategoryId, setSelectedSubCategoryId] = useState(null);

  const [selectedImageFile, setSelectedImageFile] = useState(null);
  const [selectedImagePreview, setSelectedImagePreview] = useState("");
  const [selectedBannerFile, setSelectedBannerFile] = useState(null);
  const [selectedBannerPreview, setSelectedBannerPreview] = useState("");

  const [imageMode, setImageMode] = useState("image");
  const [imageZoomOpen, setImageZoomOpen] = useState(false);

  const [search, setSearch] = useState("");
  const [displayAreaFilter, setDisplayAreaFilter] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [categoryFilter, setCategoryFilter] = useState("");

  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [uploadingImage, setUploadingImage] = useState(false);
  const [deletingId, setDeletingId] = useState(null);

  const [messageModal, setMessageModal] = useState({
    open: false,
    type: "success",
    title: "",
    message: "",
  });

  const activeForm = formType === "category" ? categoryForm : subCategoryForm;
  const isEditing = !!activeForm.id;

  const currentZoomImage =
    imageMode === "banner"
      ? selectedBannerPreview || activeForm.banner_image_url || ""
      : selectedImagePreview || activeForm.image_url || "";

  const showMessage = ({ type = "success", title, message }) => {
    setMessageModal({ open: true, type, title, message });
  };

  const closeMessageModal = () => {
    setMessageModal((prev) => ({ ...prev, open: false }));
  };

  const loadData = async () => {
    try {
      setLoading(true);

      const [categoryRes, subCategoryRes] = await Promise.all([
        getProductCategories({ per_page: 500 }),
        getProductSubCategories({ per_page: 500 }),
      ]);

      setCategories(normalizeListResponse(categoryRes));
      setSubCategories(normalizeListResponse(subCategoryRes));
    } catch (error) {
      showMessage({
        type: "error",
        title: "Load Failed",
        message: getApiMessage(error),
      });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (open) loadData();
  }, [open]);

  const selectedCategory = useMemo(
    () => categories.find((row) => row.id === selectedCategoryId) || null,
    [categories, selectedCategoryId],
  );

  const selectedSubCategory = useMemo(
    () => subCategories.find((row) => row.id === selectedSubCategoryId) || null,
    [subCategories, selectedSubCategoryId],
  );

  const registerCounts = useMemo(() => {
    return {
      categories: categories.length,
      subCategories: subCategories.length,
      activeCategories: categories.filter((row) => row.is_active).length,
      withImages: categories.filter((row) => !!getImage(row)).length,
      front: categories.filter(
        (row) => row.display_area === "front" || row.display_area === "both",
      ).length,
    };
  }, [categories, subCategories]);

  const filteredCategories = useMemo(() => {
    const q = search.trim().toLowerCase();

    return categories.filter((category) => {
      const parentName = category?.parent?.name || "";

      const matchesSearch =
        !q ||
        [category.name, category.code, category.description, parentName]
          .filter(Boolean)
          .some((value) => String(value).toLowerCase().includes(q));

      const matchesDisplay =
        !displayAreaFilter ||
        category.display_area === displayAreaFilter ||
        category.display_area === "both";

      const matchesStatus =
        statusFilter === "" ||
        String(Boolean(category.is_active)) === String(statusFilter);

      return matchesSearch && matchesDisplay && matchesStatus;
    });
  }, [categories, search, displayAreaFilter, statusFilter]);

  const filteredSubCategories = useMemo(() => {
    const q = search.trim().toLowerCase();

    return subCategories.filter((subCategory) => {
      const categoryName = subCategory?.category?.name || "";

      const matchesSearch =
        !q ||
        [subCategory.name, subCategory.code, subCategory.description, categoryName]
          .filter(Boolean)
          .some((value) => String(value).toLowerCase().includes(q));

      const matchesCategory =
        !categoryFilter || String(subCategory.category_id) === String(categoryFilter);

      const matchesDisplay =
        !displayAreaFilter ||
        subCategory.display_area === displayAreaFilter ||
        subCategory.display_area === "both";

      const matchesStatus =
        statusFilter === "" ||
        String(Boolean(subCategory.is_active)) === String(statusFilter);

      return matchesSearch && matchesCategory && matchesDisplay && matchesStatus;
    });
  }, [
    subCategories,
    search,
    categoryFilter,
    displayAreaFilter,
    statusFilter,
  ]);

  const parentCategoryOptions = useMemo(() => {
    return categories.filter((category) => category.id !== categoryForm.id);
  }, [categories, categoryForm.id]);

  const openCreateCategory = () => {
    setFormType("category");
    setCategoryForm(emptyCategoryForm);
    setSelectedImageFile(null);
    setSelectedImagePreview("");
    setSelectedBannerFile(null);
    setSelectedBannerPreview("");
    setImageMode("image");
    setFormOpen(true);
  };

  const openCreateSubCategory = () => {
    setFormType("subcategory");
    setSubCategoryForm({
      ...emptySubCategoryForm,
      category_id: selectedCategoryId || categoryFilter || "",
    });
    setSelectedImageFile(null);
    setSelectedImagePreview("");
    setSelectedBannerFile(null);
    setSelectedBannerPreview("");
    setImageMode("image");
    setFormOpen(true);
  };

  const openEditCategory = (category) => {
    setFormType("category");
    setSelectedCategoryId(category.id);
    setCategoryForm({
      ...emptyCategoryForm,
      id: category.id,
      parent_id: category.parent_id || "",
      name: category.name || "",
      code: category.code || "",
      description: category.description || "",
      display_area: category.display_area || "both",
      sort_order: category.sort_order ?? 0,
      is_active: category.is_active ?? true,
      image_url: getImage(category),
      image_path: category.image_path || "",
      banner_image_url: getBannerImage(category),
      banner_image_path: category.banner_image_path || "",
    });
    setSelectedImageFile(null);
    setSelectedImagePreview(getImage(category));
    setSelectedBannerFile(null);
    setSelectedBannerPreview(getBannerImage(category));
    setImageMode("image");
    setFormOpen(true);
  };

  const openEditSubCategory = (subCategory) => {
    setFormType("subcategory");
    setSelectedSubCategoryId(subCategory.id);
    setSubCategoryForm({
      ...emptySubCategoryForm,
      id: subCategory.id,
      category_id: subCategory.category_id || subCategory.category?.id || "",
      name: subCategory.name || "",
      code: subCategory.code || "",
      description: subCategory.description || "",
      display_area: subCategory.display_area || "both",
      sort_order: subCategory.sort_order ?? 0,
      is_active: subCategory.is_active ?? true,
      image_url: getImage(subCategory),
      image_path: subCategory.image_path || "",
      banner_image_url: getBannerImage(subCategory),
      banner_image_path: subCategory.banner_image_path || "",
    });
    setSelectedImageFile(null);
    setSelectedImagePreview(getImage(subCategory));
    setSelectedBannerFile(null);
    setSelectedBannerPreview(getBannerImage(subCategory));
    setImageMode("image");
    setFormOpen(true);
  };

  const closeForm = () => {
    if (saving || uploadingImage) return;

    setFormOpen(false);
    setCategoryForm(emptyCategoryForm);
    setSubCategoryForm(emptySubCategoryForm);
    setSelectedImageFile(null);
    setSelectedImagePreview("");
    setSelectedBannerFile(null);
    setSelectedBannerPreview("");
    setImageZoomOpen(false);
  };

  const handleCategoryChange = (event) => {
    const { name, value, checked, type } = event.target;
    setCategoryForm((prev) => ({
      ...prev,
      [name]: type === "checkbox" ? checked : value,
    }));
  };

  const handleSubCategoryChange = (event) => {
    const { name, value, checked, type } = event.target;
    setSubCategoryForm((prev) => ({
      ...prev,
      [name]: type === "checkbox" ? checked : value,
    }));
  };

  const handleImageSelect = (event) => {
    const file = event.target.files?.[0];
    if (!file) return;

    if (!file.type.startsWith("image/")) {
      showMessage({
        type: "error",
        title: "Invalid Image",
        message: "Select a JPG, PNG or WEBP image.",
      });
      return;
    }

    setSelectedImageFile(file);
    setSelectedImagePreview(URL.createObjectURL(file));
  };

  const handleBannerSelect = (event) => {
    const file = event.target.files?.[0];
    if (!file) return;

    if (!file.type.startsWith("image/")) {
      showMessage({
        type: "error",
        title: "Invalid Banner",
        message: "Select a JPG, PNG or WEBP image.",
      });
      return;
    }

    setSelectedBannerFile(file);
    setSelectedBannerPreview(URL.createObjectURL(file));
  };

  const validateForm = () => {
    if (!activeForm.name.trim()) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message:
          formType === "category"
            ? "Category name is required."
            : "Sub category name is required.",
      });
      return false;
    }

    if (formType === "subcategory" && !subCategoryForm.category_id) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: "Parent category is required for sub category.",
      });
      return false;
    }

    return true;
  };

  const buildCategoryPayload = () => ({
    parent_id: categoryForm.parent_id || null,
    name: categoryForm.name,
    code: categoryForm.code || null,
    description: categoryForm.description || null,
    display_area: categoryForm.display_area || "both",
    sort_order: Number(categoryForm.sort_order || 0),
    is_active: !!categoryForm.is_active,
  });

  const buildSubCategoryPayload = () => ({
    category_id: subCategoryForm.category_id,
    name: subCategoryForm.name,
    code: subCategoryForm.code || null,
    description: subCategoryForm.description || null,
    display_area: subCategoryForm.display_area || "both",
    sort_order: Number(subCategoryForm.sort_order || 0),
    is_active: !!subCategoryForm.is_active,
  });

  const saveForm = async (event) => {
    if (event?.preventDefault) event.preventDefault();
    if (!validateForm()) return;

    try {
      setSaving(true);

      let response;
      let savedId;

      if (formType === "category") {
        response = categoryForm.id
          ? await updateProductCategory(categoryForm.id, buildCategoryPayload())
          : await createProductCategory(buildCategoryPayload());

        const saved = response?.data || response?.data?.data || response;
        savedId = saved?.id || categoryForm.id;

        if (selectedImageFile && savedId) {
          setUploadingImage(true);
          await uploadProductCategoryImage(savedId, selectedImageFile);
        }

        if (selectedBannerFile && savedId) {
          setUploadingImage(true);
          await uploadProductCategoryBannerImage(savedId, selectedBannerFile);
        }
      } else {
        response = subCategoryForm.id
          ? await updateProductSubCategory(
              subCategoryForm.id,
              buildSubCategoryPayload(),
            )
          : await createProductSubCategory(buildSubCategoryPayload());

        const saved = response?.data || response?.data?.data || response;
        savedId = saved?.id || subCategoryForm.id;

        if (selectedImageFile && savedId) {
          setUploadingImage(true);
          await uploadProductSubCategoryImage(savedId, selectedImageFile);
        }

        if (selectedBannerFile && savedId) {
          setUploadingImage(true);
          await uploadProductSubCategoryBannerImage(savedId, selectedBannerFile);
        }
      }

      showMessage({
        type: "success",
        title: isEditing
          ? formType === "category"
            ? "Category Updated"
            : "Sub Category Updated"
          : formType === "category"
            ? "Category Created"
            : "Sub Category Created",
        message: getApiMessage(response, "Saved successfully."),
      });

      closeForm();
      await loadData();
    } catch (error) {
      showMessage({
        type: "error",
        title: isEditing ? "Update Failed" : "Create Failed",
        message: getApiMessage(error),
      });
    } finally {
      setSaving(false);
      setUploadingImage(false);
    }
  };

  const deleteSelectedImage = async () => {
    if (!activeForm.id) {
      setSelectedImageFile(null);
      setSelectedImagePreview("");
      return;
    }

    try {
      setUploadingImage(true);

      if (formType === "category") {
        await deleteProductCategoryImage(activeForm.id);
        setCategoryForm((prev) => ({ ...prev, image_url: "", image_path: "" }));
      } else {
        await deleteProductSubCategoryImage(activeForm.id);
        setSubCategoryForm((prev) => ({
          ...prev,
          image_url: "",
          image_path: "",
        }));
      }

      setSelectedImageFile(null);
      setSelectedImagePreview("");
      await loadData();

      showMessage({
        type: "success",
        title: "Image Removed",
        message: "Image removed successfully.",
      });
    } catch (error) {
      showMessage({
        type: "error",
        title: "Remove Failed",
        message: getApiMessage(error),
      });
    } finally {
      setUploadingImage(false);
    }
  };

  const deleteSelectedBanner = async () => {
    if (!activeForm.id) {
      setSelectedBannerFile(null);
      setSelectedBannerPreview("");
      return;
    }

    try {
      setUploadingImage(true);

      if (formType === "category") {
        await deleteProductCategoryBannerImage(activeForm.id);
        setCategoryForm((prev) => ({
          ...prev,
          banner_image_url: "",
          banner_image_path: "",
        }));
      } else {
        await deleteProductSubCategoryBannerImage(activeForm.id);
        setSubCategoryForm((prev) => ({
          ...prev,
          banner_image_url: "",
          banner_image_path: "",
        }));
      }

      setSelectedBannerFile(null);
      setSelectedBannerPreview("");
      await loadData();

      showMessage({
        type: "success",
        title: "Banner Removed",
        message: "Banner image removed successfully.",
      });
    } catch (error) {
      showMessage({
        type: "error",
        title: "Remove Failed",
        message: getApiMessage(error),
      });
    } finally {
      setUploadingImage(false);
    }
  };

  const handleDeleteCategory = async (categoryId) => {
    if (!await requestEnterpriseConfirmation({
      title: "Delete Product Category",
      message: "Delete this product category?",
      confirmLabel: "Delete category",
    })) return;

    try {
      setDeletingId(categoryId);
      const response = await deleteProductCategory(categoryId);
      showMessage({
        type: "success",
        title: "Category Deleted",
        message: getApiMessage(response, "Category deleted successfully."),
      });
      await loadData();
    } catch (error) {
      showMessage({
        type: "error",
        title: "Delete Failed",
        message: getApiMessage(error),
      });
    } finally {
      setDeletingId(null);
    }
  };

  const handleDeleteSubCategory = async (subCategoryId) => {
    if (!await requestEnterpriseConfirmation({
      title: "Delete Product Subcategory",
      message: "Delete this product subcategory?",
      confirmLabel: "Delete subcategory",
    })) return;

    try {
      setDeletingId(subCategoryId);
      const response = await deleteProductSubCategory(subCategoryId);
      showMessage({
        type: "success",
        title: "Sub Category Deleted",
        message: getApiMessage(response, "Sub category deleted successfully."),
      });
      await loadData();
    } catch (error) {
      showMessage({
        type: "error",
        title: "Delete Failed",
        message: getApiMessage(error),
      });
    } finally {
      setDeletingId(null);
    }
  };

  const getDisplayAreaLabel = (value) =>
    DISPLAY_AREAS.find(([key]) => key === value)?.[1] || "Front & Back";

  const getCategoryName = (categoryId) =>
    categories.find((category) => String(category.id) === String(categoryId))
      ?.name || "-";

  if (!open) return null;

  return (
    <>
      <FullScreenModal
        open={open}
        onClose={onClose}
        title="CATEGORY MANAGEMENT"
        subtitle="Manage product categories, sub categories, images and display visibility"
      >
        <div className="h-[calc(100vh-86px)] min-h-0 bg-[#D8DFDF] overflow-hidden text-[#1C2C2B] font-normal">
          <div className="grid h-full min-h-0 grid-rows-[auto_auto_minmax(0,1fr)] gap-2 p-2">
            <div className="grid shrink-0 grid-cols-1 gap-2 xl:grid-cols-[300px_minmax(0,1fr)_300px]">
              <DeskPanel title="Category Card">
                <div className="grid grid-cols-[110px_1fr] items-center gap-1 text-[11px]">
                  <DeskLabel>Categories</DeskLabel>
                  <DeskField value={registerCounts.categories} disabled />
                  <DeskLabel>Sub Categories</DeskLabel>
                  <DeskField value={registerCounts.subCategories} disabled />
                  <DeskLabel>Active</DeskLabel>
                  <DeskField value={registerCounts.activeCategories} disabled />
                  <DeskLabel>With Images</DeskLabel>
                  <DeskField value={registerCounts.withImages} disabled />
                  <DeskLabel>Front Visible</DeskLabel>
                  <DeskField value={registerCounts.front} disabled />
                </div>
              </DeskPanel>

              <DeskPanel title="Category Search">
                <div className="grid grid-cols-[120px_1fr] items-center gap-1 text-[11px]">
                  <DeskLabel>Search</DeskLabel>
                  <input
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Search name, code, description..."
                    className="h-7 border border-[#B9C3C3] bg-white px-2 text-[11px] outline-none focus:border-[#004343]"
                  />

                  <DeskLabel>Display</DeskLabel>
                  <select
                    value={displayAreaFilter}
                    onChange={(e) => setDisplayAreaFilter(e.target.value)}
                    className="desk-select"
                  >
                    <option value="">All Areas</option>
                    {DISPLAY_AREAS.map(([value, label]) => (
                      <option key={value} value={value}>
                        {label}
                      </option>
                    ))}
                  </select>

                  <DeskLabel>Status</DeskLabel>
                  <select
                    value={statusFilter}
                    onChange={(e) => setStatusFilter(e.target.value)}
                    className="desk-select"
                  >
                    <option value="">All Status</option>
                    <option value="true">Active</option>
                    <option value="false">Inactive</option>
                  </select>

                  <DeskLabel>Category</DeskLabel>
                  <select
                    value={categoryFilter}
                    onChange={(e) => setCategoryFilter(e.target.value)}
                    className="desk-select"
                  >
                    <option value="">All Categories</option>
                    {categories.map((category) => (
                      <option key={category.id} value={category.id}>
                        {category.name}
                      </option>
                    ))}
                  </select>
                </div>
              </DeskPanel>

              <DeskPanel title="Selected">
                <div className="grid grid-cols-[90px_1fr] items-center gap-1 text-[11px]">
                  <DeskLabel>Category</DeskLabel>
                  <DeskField value={selectedCategory?.name || "None"} disabled />
                  <DeskLabel>Sub Category</DeskLabel>
                  <DeskField
                    value={selectedSubCategory?.name || "None"}
                    disabled
                  />
                  <DeskLabel>Area</DeskLabel>
                  <DeskField
                    value={
                      selectedCategory
                        ? getDisplayAreaLabel(selectedCategory.display_area)
                        : "-"
                    }
                    disabled
                  />
                </div>
              </DeskPanel>
            </div>

            <div className="flex shrink-0 flex-wrap items-center justify-between gap-2 border border-[#B9C3C3] bg-[#EEF2F2] px-2 py-1">
              <div className="flex flex-wrap gap-1">
                <DeskTab
                  active={activeTab === "categories"}
                  onClick={() => setActiveTab("categories")}
                >
                  Categories
                </DeskTab>
                <DeskTab
                  active={activeTab === "subcategories"}
                  onClick={() => setActiveTab("subcategories")}
                >
                  Sub Categories
                </DeskTab>
                <DeskTab
                  active={activeTab === "images"}
                  onClick={() => setActiveTab("images")}
                >
                  Images
                </DeskTab>
              </div>

              <div className="flex flex-wrap items-center gap-1">
                <DeskTool onClick={openCreateCategory} green>
                  Add Category
                </DeskTool>
                <DeskTool onClick={openCreateSubCategory} green>
                  Add Sub Category
                </DeskTool>
                <DeskTool
                  onClick={() => selectedCategory && openEditCategory(selectedCategory)}
                  disabled={!selectedCategory}
                >
                  Edit Category
                </DeskTool>
                <DeskTool
                  onClick={() =>
                    selectedSubCategory && openEditSubCategory(selectedSubCategory)
                  }
                  disabled={!selectedSubCategory}
                >
                  Edit Sub
                </DeskTool>
                <DeskTool onClick={loadData} disabled={loading}>
                  {loading ? "Loading..." : "Refresh"}
                </DeskTool>
                <DeskTool
                  onClick={() => {
                    setSearch("");
                    setDisplayAreaFilter("");
                    setStatusFilter("");
                    setCategoryFilter("");
                  }}
                >
                  Clear
                </DeskTool>
                <DeskTool
                  onClick={() =>
                    activeTab === "subcategories" && selectedSubCategory
                      ? handleDeleteSubCategory(selectedSubCategory.id)
                      : selectedCategory && handleDeleteCategory(selectedCategory.id)
                  }
                  disabled={
                    activeTab === "subcategories"
                      ? !selectedSubCategory
                      : !selectedCategory
                  }
                  danger
                >
                  Delete
                </DeskTool>
              </div>
            </div>

            <div className="min-h-0 border border-[#AFB9B9] bg-white">
              <div className="flex h-full min-h-0 flex-col">
                {activeTab === "categories" && (
                  <>
                    <GridHeader columns="grid-cols-[52px_90px_minmax(220px,1fr)_260px_130px_160px_140px_100px_110px_120px_72px]">
                      <GridHead>Line</GridHead>
                      <GridHead>Image</GridHead>
                      <GridHead>Category</GridHead>
                      <GridHead>Description</GridHead>
                      <GridHead>Code</GridHead>
                      <GridHead>Parent</GridHead>
                      <GridHead>Display</GridHead>
                      <GridHead>Banner</GridHead>
                      <GridHead>Sub Categories</GridHead>
                      <GridHead>Status</GridHead>
                      <GridHead>⋮</GridHead>
                    </GridHeader>

                    <div className="min-h-0 flex-1 overflow-auto">
                      {loading ? (
                        <Empty message="Loading categories..." />
                      ) : filteredCategories.length === 0 ? (
                        <Empty message="No categories found." />
                      ) : (
                        filteredCategories.map((category, index) => {
                          const image = getImage(category);
                          const selected = selectedCategoryId === category.id;

                          return (
                            <div
                              key={category.id}
                              onClick={() => setSelectedCategoryId(category.id)}
                              className={`sx-enterprise-grid-row grid min-w-[1450px] cursor-pointer grid-cols-[52px_90px_minmax(220px,1fr)_260px_130px_160px_140px_100px_110px_120px_72px] border-b border-[#D8DFDF] text-[11px] ${
                                selected
                                  ? "sx-enterprise-grid-selected bg-[#E5F2F2]"
                                  : "bg-white hover:bg-[#F8FAFC]"
                              }`}
                            >
                              <GridCell muted>{index + 1}</GridCell>

                              <GridCell center>
                                {image ? (
                                  <img
                                    src={image}
                                    alt={category.name}
                                    className="h-14 w-16 object-cover border border-[#CBD6D6] bg-white"
                                  />
                                ) : (
                                  <div className="h-14 w-16 border border-dashed border-[#CBD6D6] bg-[#F8FAFC] text-[9px] flex items-center justify-center text-[#627474]">
                                    No Image
                                  </div>
                                )}
                              </GridCell>

                              <GridCell>{category.name}</GridCell>
                              <GridCell>{category.description || "-"}</GridCell>
                              <GridCell>{category.code || "-"}</GridCell>
                              <GridCell>{category?.parent?.name || "-"}</GridCell>
                              <GridCell>{getDisplayAreaLabel(category.display_area)}</GridCell>
                              <GridCell>{getBannerImage(category) ? "Yes" : "No"}</GridCell>
                              <GridCell>{Array.isArray(category.sub_categories) ? category.sub_categories.length : 0}</GridCell>
                              <GridCell>
                                <Status active={category.is_active} />
                              </GridCell>

                              <GridCell>
                                <CategoryRowActionMenu
                                  label={`Actions for ${category.name}`}
                                  actions={[
                                    { label: "Edit", onClick: () => openEditCategory(category) },
                                    {
                                      label: "Image",
                                      onClick: () => {
                                        openEditCategory(category);
                                        window.setTimeout(() => imageInputRef.current?.click(), 0);
                                      },
                                    },
                                    {
                                      label: deletingId === category.id ? "Deleting..." : "Delete",
                                      danger: true,
                                      disabled: deletingId === category.id,
                                      onClick: () => handleDeleteCategory(category.id),
                                    },
                                  ]}
                                />
                              </GridCell>
                            </div>
                          );
                        })
                      )}
                    </div>
                  </>
                )}

                {activeTab === "subcategories" && (
                  <>
                    <GridHeader columns="grid-cols-[52px_90px_minmax(220px,1fr)_260px_180px_130px_140px_100px_120px_72px]">
                      <GridHead>Line</GridHead>
                      <GridHead>Image</GridHead>
                      <GridHead>Sub Category</GridHead>
                      <GridHead>Description</GridHead>
                      <GridHead>Category</GridHead>
                      <GridHead>Code</GridHead>
                      <GridHead>Display</GridHead>
                      <GridHead>Banner</GridHead>
                      <GridHead>Status</GridHead>
                      <GridHead>⋮</GridHead>
                    </GridHeader>

                    <div className="min-h-0 flex-1 overflow-auto">
                      {loading ? (
                        <Empty message="Loading sub categories..." />
                      ) : filteredSubCategories.length === 0 ? (
                        <Empty message="No sub categories found." />
                      ) : (
                        filteredSubCategories.map((subCategory, index) => {
                          const image = getImage(subCategory);
                          const selected = selectedSubCategoryId === subCategory.id;

                          return (
                            <div
                              key={subCategory.id}
                              onClick={() => setSelectedSubCategoryId(subCategory.id)}
                              className={`sx-enterprise-grid-row grid min-w-[1330px] cursor-pointer grid-cols-[52px_90px_minmax(220px,1fr)_260px_180px_130px_140px_100px_120px_72px] border-b border-[#D8DFDF] text-[11px] ${
                                selected
                                  ? "sx-enterprise-grid-selected bg-[#E5F2F2]"
                                  : "bg-white hover:bg-[#F8FAFC]"
                              }`}
                            >
                              <GridCell muted>{index + 1}</GridCell>

                              <GridCell center>
                                {image ? (
                                  <img
                                    src={image}
                                    alt={subCategory.name}
                                    className="h-14 w-16 object-cover border border-[#CBD6D6] bg-white"
                                  />
                                ) : (
                                  <div className="h-14 w-16 border border-dashed border-[#CBD6D6] bg-[#F8FAFC] text-[9px] flex items-center justify-center text-[#627474]">
                                    No Image
                                  </div>
                                )}
                              </GridCell>

                              <GridCell>{subCategory.name}</GridCell>
                              <GridCell>{subCategory.description || "-"}</GridCell>
                              <GridCell>
                                {subCategory?.category?.name ||
                                  getCategoryName(subCategory.category_id)}
                              </GridCell>
                              <GridCell>{subCategory.code || "-"}</GridCell>
                              <GridCell>
                                {getDisplayAreaLabel(subCategory.display_area)}
                              </GridCell>
                              <GridCell>{getBannerImage(subCategory) ? "Yes" : "No"}</GridCell>
                              <GridCell>
                                <Status active={subCategory.is_active} />
                              </GridCell>

                              <GridCell>
                                <CategoryRowActionMenu
                                  label={`Actions for ${subCategory.name}`}
                                  actions={[
                                    { label: "Edit", onClick: () => openEditSubCategory(subCategory) },
                                    {
                                      label: "Image",
                                      onClick: () => {
                                        openEditSubCategory(subCategory);
                                        window.setTimeout(() => imageInputRef.current?.click(), 0);
                                      },
                                    },
                                    {
                                      label: deletingId === subCategory.id ? "Deleting..." : "Delete",
                                      danger: true,
                                      disabled: deletingId === subCategory.id,
                                      onClick: () => handleDeleteSubCategory(subCategory.id),
                                    },
                                  ]}
                                />
                              </GridCell>
                            </div>
                          );
                        })
                      )}
                    </div>
                  </>
                )}

                {activeTab === "images" && (
                  <div className="min-h-0 flex-1 overflow-auto bg-[#F8FAFC] p-2">
                    <div className="mb-2 text-[11px] font-bold uppercase text-[#465757]">
                      Category Images
                    </div>
                    <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5">
                      {filteredCategories.map((category) => (
                        <ImageCard
                          key={category.id}
                          title={category.name}
                          code={category.code}
                          image={getImage(category)}
                          banner={getBannerImage(category)}
                          onEdit={() => openEditCategory(category)}
                        />
                      ))}
                    </div>

                    <div className="mb-2 mt-4 text-[11px] font-bold uppercase text-[#465757]">
                      Sub Category Images
                    </div>
                    <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5">
                      {filteredSubCategories.map((subCategory) => (
                        <ImageCard
                          key={subCategory.id}
                          title={subCategory.name}
                          code={
                            subCategory.code ||
                            subCategory?.category?.name ||
                            getCategoryName(subCategory.category_id)
                          }
                          image={getImage(subCategory)}
                          banner={getBannerImage(subCategory)}
                          onEdit={() => openEditSubCategory(subCategory)}
                        />
                      ))}
                    </div>
                  </div>
                )}
              </div>
            </div>
          </div>
        </div>
      </FullScreenModal>

      {formOpen && (
        <div className="menu-modal-backdrop">
          <div className="menu-item-modal">
            <div className="menu-modal-header">
              <div>
                <h2>
                  {isEditing
                    ? formType === "category"
                      ? "Edit Category"
                      : "Edit Sub Category"
                    : formType === "category"
                      ? "Add Category"
                      : "Add Sub Category"}
                </h2>
                <p>
                  Configure visibility, images and banner images for front or
                  back office display.
                </p>
              </div>

              <button
                type="button"
                onClick={closeForm}
                disabled={saving || uploadingImage}
                className="menu-close-button"
              >
                x
              </button>
            </div>

            <form onSubmit={saveForm} className="menu-modal-body">
              <section className="menu-section">
                <div className="menu-section-header">
                  <h3>
                    {formType === "category"
                      ? "Category Identity"
                      : "Sub Category Identity"}
                  </h3>
                </div>

                {formType === "category" ? (
                  <div className="menu-grid">
                    <Field label="Category Name" span="5">
                      <input
                        name="name"
                        value={categoryForm.name}
                        onChange={handleCategoryChange}
                        className="menu-input"
                        autoFocus
                      />
                    </Field>

                    <Field label="Code" span="3">
                      <input
                        name="code"
                        value={categoryForm.code}
                        onChange={handleCategoryChange}
                        className="menu-input"
                      />
                    </Field>

                    <Field label="Parent Category" span="4">
                      <select
                        name="parent_id"
                        value={categoryForm.parent_id}
                        onChange={handleCategoryChange}
                        className="menu-input"
                      >
                        <option value="">No Parent</option>
                        {parentCategoryOptions.map((category) => (
                          <option key={category.id} value={category.id}>
                            {category.name}
                          </option>
                        ))}
                      </select>
                    </Field>

                    <Field label="Display Area" span="4">
                      <select
                        name="display_area"
                        value={categoryForm.display_area}
                        onChange={handleCategoryChange}
                        className="menu-input"
                      >
                        {DISPLAY_AREAS.map(([value, label]) => (
                          <option key={value} value={value}>
                            {label}
                          </option>
                        ))}
                      </select>
                    </Field>

                    <Field label="Sort Order" span="4">
                      <input
                        type="number"
                        name="sort_order"
                        value={categoryForm.sort_order}
                        onChange={handleCategoryChange}
                        className="menu-input"
                      />
                    </Field>

                    <label className="menu-check">
                      <input
                        type="checkbox"
                        name="is_active"
                        checked={categoryForm.is_active}
                        onChange={handleCategoryChange}
                      />{" "}
                      Active
                    </label>

                    <Field label="Description" span="12">
                      <textarea
                        name="description"
                        value={categoryForm.description}
                        onChange={handleCategoryChange}
                        rows={3}
                        className="menu-textarea"
                      />
                    </Field>
                  </div>
                ) : (
                  <div className="menu-grid">
                    <Field label="Parent Category" span="4">
                      <select
                        name="category_id"
                        value={subCategoryForm.category_id}
                        onChange={handleSubCategoryChange}
                        className="menu-input"
                      >
                        <option value="">Select Category</option>
                        {categories.map((category) => (
                          <option key={category.id} value={category.id}>
                            {category.name}
                          </option>
                        ))}
                      </select>
                    </Field>

                    <Field label="Sub Category Name" span="5">
                      <input
                        name="name"
                        value={subCategoryForm.name}
                        onChange={handleSubCategoryChange}
                        className="menu-input"
                        autoFocus
                      />
                    </Field>

                    <Field label="Code" span="3">
                      <input
                        name="code"
                        value={subCategoryForm.code}
                        onChange={handleSubCategoryChange}
                        className="menu-input"
                      />
                    </Field>

                    <Field label="Display Area" span="4">
                      <select
                        name="display_area"
                        value={subCategoryForm.display_area}
                        onChange={handleSubCategoryChange}
                        className="menu-input"
                      >
                        {DISPLAY_AREAS.map(([value, label]) => (
                          <option key={value} value={value}>
                            {label}
                          </option>
                        ))}
                      </select>
                    </Field>

                    <Field label="Sort Order" span="4">
                      <input
                        type="number"
                        name="sort_order"
                        value={subCategoryForm.sort_order}
                        onChange={handleSubCategoryChange}
                        className="menu-input"
                      />
                    </Field>

                    <label className="menu-check">
                      <input
                        type="checkbox"
                        name="is_active"
                        checked={subCategoryForm.is_active}
                        onChange={handleSubCategoryChange}
                      />{" "}
                      Active
                    </label>

                    <Field label="Description" span="12">
                      <textarea
                        name="description"
                        value={subCategoryForm.description}
                        onChange={handleSubCategoryChange}
                        rows={3}
                        className="menu-textarea"
                      />
                    </Field>
                  </div>
                )}
              </section>

              <section className="menu-section">
                <div className="menu-section-header">
                  <h3>Category Image</h3>
                  <button
                    type="button"
                    onClick={() => imageInputRef.current?.click()}
                    className="menu-small-button"
                  >
                    Upload Image
                  </button>
                </div>

                <div className="menu-image-panel">
                  <button
                    type="button"
                    className="menu-image-preview"
                    onClick={() => {
                      setImageMode("image");
                      currentZoomImage && setImageZoomOpen(true);
                    }}
                  >
                    {selectedImagePreview || activeForm.image_url ? (
                      <>
                        <img
                          src={selectedImagePreview || activeForm.image_url}
                          alt="Category preview"
                        />
                        <span className="menu-image-zoom-hint">
                          Click to zoom
                        </span>
                      </>
                    ) : (
                      <span>No image selected</span>
                    )}
                  </button>

                  <div className="menu-image-actions">
                    <input
                      ref={imageInputRef}
                      type="file"
                      accept="image/png,image/jpeg,image/jpg,image/webp"
                      onChange={handleImageSelect}
                      className="hidden"
                    />

                    <button
                      type="button"
                      onClick={() => imageInputRef.current?.click()}
                      className="menu-primary-outline"
                    >
                      Choose Image
                    </button>

                    {(selectedImagePreview || activeForm.image_url) && (
                      <button
                        type="button"
                        onClick={deleteSelectedImage}
                        disabled={uploadingImage}
                        className="menu-danger-outline"
                      >
                        Remove Image
                      </button>
                    )}

                    <div className="menu-note">
                      Image is uploaded after saving. Max backend size: 4MB.
                    </div>
                  </div>
                </div>
              </section>

              <section className="menu-section">
                <div className="menu-section-header">
                  <h3>Banner Image</h3>
                  <button
                    type="button"
                    onClick={() => bannerInputRef.current?.click()}
                    className="menu-small-button"
                  >
                    Upload Banner
                  </button>
                </div>

                <div className="menu-image-panel">
                  <button
                    type="button"
                    className="menu-image-preview menu-banner-preview"
                    onClick={() => {
                      setImageMode("banner");
                      const image =
                        selectedBannerPreview || activeForm.banner_image_url || "";
                      image && setImageZoomOpen(true);
                    }}
                  >
                    {selectedBannerPreview || activeForm.banner_image_url ? (
                      <>
                        <img
                          src={
                            selectedBannerPreview || activeForm.banner_image_url
                          }
                          alt="Banner preview"
                        />
                        <span className="menu-image-zoom-hint">
                          Click to zoom
                        </span>
                      </>
                    ) : (
                      <span>No banner selected</span>
                    )}
                  </button>

                  <div className="menu-image-actions">
                    <input
                      ref={bannerInputRef}
                      type="file"
                      accept="image/png,image/jpeg,image/jpg,image/webp"
                      onChange={handleBannerSelect}
                      className="hidden"
                    />

                    <button
                      type="button"
                      onClick={() => bannerInputRef.current?.click()}
                      className="menu-primary-outline"
                    >
                      Choose Banner
                    </button>

                    {(selectedBannerPreview || activeForm.banner_image_url) && (
                      <button
                        type="button"
                        onClick={deleteSelectedBanner}
                        disabled={uploadingImage}
                        className="menu-danger-outline"
                      >
                        Remove Banner
                      </button>
                    )}

                    <div className="menu-note">
                      Banner image can be used for POS front display headers.
                    </div>
                  </div>
                </div>
              </section>
            </form>

            <div className="menu-modal-footer">
              <div className="menu-modal-status">
                {activeForm.name ||
                  (formType === "category" ? "New category" : "New sub category")}
              </div>

              <div className="menu-footer-actions">
                <button
                  type="button"
                  onClick={closeForm}
                  disabled={saving || uploadingImage}
                  className="menu-secondary-button"
                >
                  Cancel
                </button>

                <button
                  type="button"
                  onClick={saveForm}
                  disabled={saving || uploadingImage}
                  className="menu-primary-button"
                >
                  {saving || uploadingImage
                    ? "Saving..."
                    : isEditing
                      ? "Update"
                      : "Save"}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {imageZoomOpen && currentZoomImage && (
        <div
          className="menu-image-zoom-backdrop"
          onClick={() => setImageZoomOpen(false)}
        >
          <div
            className="menu-image-zoom-card"
            onClick={(event) => event.stopPropagation()}
          >
            <div className="menu-image-zoom-header">
              <span>{activeForm.name || "Category image"}</span>
              <button type="button" onClick={() => setImageZoomOpen(false)}>
                x
              </button>
            </div>

            <div className="menu-image-zoom-body">
              <img src={currentZoomImage} alt={activeForm.name || "Category"} />
            </div>
          </div>
        </div>
      )}

      <ReusableMessageModal
        open={messageModal.open}
        type={messageModal.type}
        title={messageModal.title}
        message={messageModal.message}
        onClose={closeMessageModal}
      />

      <CategoryInlineStyles />
    </>
  );
}

function ImageCard({ title, code, image, banner, onEdit }) {
  return (
    <div className="border border-[#CBD6D6] bg-white p-2">
      <div className="h-32 border border-[#E2E9E9] bg-[#F8FAFC] flex items-center justify-center overflow-hidden">
        {image ? (
          <img src={image} alt={title} className="h-full w-full object-cover" />
        ) : (
          <span className="text-[11px] text-[#627474]">No Image</span>
        )}
      </div>

      <div className="mt-2 h-16 border border-[#E2E9E9] bg-[#F8FAFC] flex items-center justify-center overflow-hidden">
        {banner ? (
          <img src={banner} alt={`${title} banner`} className="h-full w-full object-cover" />
        ) : (
          <span className="text-[10px] text-[#627474]">No Banner</span>
        )}
      </div>

      <div className="mt-2 text-[12px] font-semibold text-[#0C1B1B] truncate">
        {title}
      </div>

      <div className="text-[10px] text-[#627474] truncate">
        {code || "-"}
      </div>

      <button
        type="button"
        onClick={onEdit}
        className="mt-2 h-8 w-full bg-[#004142] text-white text-[11px] font-semibold uppercase"
      >
        Manage Images
      </button>
    </div>
  );
}

function DeskPanel({ title, children }) {
  return (
    <div className="border border-[#AFB9B9] bg-white">
      <div className="border-b border-[#D8DFDF] bg-[#EEF2F2] px-2 py-1 text-[11px] font-semibold text-[#324443]">
        {title}
      </div>
      <div className="p-2">{children}</div>
    </div>
  );
}

function DeskLabel({ children }) {
  return <div className="text-[11px] text-[#465757]">{children}</div>;
}

function DeskField({ value, disabled }) {
  return (
    <input
      value={value ?? ""}
      disabled={disabled}
      readOnly
      className="h-7 border border-[#B9C3C3] bg-white px-2 text-[11px] outline-none disabled:bg-[#EEF2F2]"
    />
  );
}

function DeskTab({ active, onClick, children }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={`h-7 border px-4 text-[11px] font-semibold ${
        active
          ? "border-[#7C8888] bg-white text-[#0C1B1B]"
          : "border-transparent bg-transparent text-[#465757] hover:border-[#B9C3C3] hover:bg-white"
      }`}
    >
      {children}
    </button>
  );
}

function DeskTool({ children, onClick, disabled, green, danger }) {
  let cls = "border-[#B9C3C3] bg-white text-[#324443]";
  if (green) cls = "border-[#027A48] bg-[#027A48] text-white";
  if (danger) cls = "border-[#FECDCA] bg-white text-[#B42318]";

  return (
    <button
      type="button"
      disabled={disabled}
      onClick={onClick}
      className={`h-7 border px-3 text-[11px] font-semibold disabled:opacity-50 ${cls}`}
    >
      {children}
    </button>
  );
}

function GridHeader({ columns, children }) {
  return (
    <div
      className={`sx-enterprise-grid-head grid shrink-0 ${columns} border-b border-[#AFB9B9] bg-[#EEF2F2] text-[10px] font-semibold uppercase text-[#465757]`}
    >
      {children}
    </div>
  );
}

function GridHead({ children }) {
  return (
    <div className="sx-enterprise-grid-heading border-r border-[#D8DFDF] px-2 py-2 text-[10px]">
      {children}
    </div>
  );
}

function GridCell({ children, right, center, muted }) {
  return (
    <div
      className={`sx-enterprise-grid-cell min-h-[36px] border-r border-[#E4EAEA] px-2 py-2 ${
        right ? "text-right" : center ? "text-center" : ""
      } ${muted ? "text-[#627474]" : "text-[#1C2C2B]"}`}
    >
      {children}
    </div>
  );
}

function Status({ active }) {
  return (
    <span
      className={`inline-flex border px-2 py-1 text-[10px] font-semibold ${
        active
          ? "border-[#ABEFC6] bg-[#ECFDF3] text-[#067647]"
          : "border-[#FECDCA] bg-[#FEF3F2] text-[#B42318]"
      }`}
    >
      {active ? "Active" : "Inactive"}
    </span>
  );
}

function CategoryRowActionMenu({ label = "Row actions", actions = [] }) {
  const [open, setOpen] = useState(false);
  return (
    <div
      className="action-menu-wrap relative inline-flex"
      onClick={(event) => event.stopPropagation()}
      onBlur={(event) => {
        if (!event.currentTarget.contains(event.relatedTarget)) setOpen(false);
      }}
    >
      <button
        type="button"
        aria-label={label}
        aria-haspopup="menu"
        aria-expanded={open}
        onClick={() => setOpen((current) => !current)}
        className="flex h-7 w-7 items-center justify-center border border-[#B9C3C3] bg-white text-[20px] font-bold leading-none text-[#324443] hover:border-[#069494] hover:bg-[#F3F8F8]"
      >
        ⋮
      </button>
      {open && (
        <div role="menu" className="absolute right-0 top-8 z-[90] min-w-[150px] border border-[#96A5A4] bg-white p-1 text-left shadow-xl">
          {actions.filter(Boolean).map((action) => (
            <button
              key={action.label}
              type="button"
              role="menuitem"
              disabled={action.disabled}
              onClick={() => {
                setOpen(false);
                action.onClick?.();
              }}
              className={`block h-8 w-full px-2 text-left text-[10px] font-semibold hover:bg-[#F2F4F7] disabled:opacity-50 ${action.danger ? "text-[#B42318]" : "text-[#324443]"}`}
            >
              {action.label}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

function Empty({ message }) {
  return (
    <div className="flex h-[220px] items-center justify-center text-[12px] text-[#627474]">
      {message}
    </div>
  );
}

function Field({ label, children, span = "12" }) {
  return (
    <div className={`menu-field menu-span-${span}`}>
      <label>{label}</label>
      {children}
    </div>
  );
}

function CategoryInlineStyles() {
  return (
    <style>{`
      .desk-select { height: 1.75rem; border: 1px solid #B9C3C3; background: white; padding: 0 0.5rem; font-size: 11px; outline: none; }

      .menu-modal-backdrop { position: fixed; inset: 0; z-index: 99999; background: rgba(0, 52, 52, 0.62); display: flex; align-items: center; justify-content: center; padding: 14px; }
      .menu-item-modal { width: min(1180px, 98vw); height: min(90vh, 820px); background: white; border: 1px solid #9BA5A5; box-shadow: 0 20px 50px rgba(0,0,0,.32); display: flex; flex-direction: column; color: #0D1B1B; }
      .menu-modal-header { min-height: 54px; flex-shrink: 0; background: #F8FAFC; border-bottom: 1px solid #CBD6D6; padding: 9px 13px; display: flex; align-items: center; justify-content: space-between; gap: 14px; }
      .menu-modal-header h2 { margin: 0; font-size: 15px; font-weight: 600; color: #091B1B; }
      .menu-modal-header p { margin: 3px 0 0; font-size: 12px; color: #637877; }
      .menu-close-button { width: 34px; height: 34px; border: 1px solid #CBD6D6; background: white; font-size: 18px; color: #324444; }

      .menu-modal-body { flex: 1; min-height: 0; overflow-y: auto; padding: 10px; background: #F1F5F5; }
      .menu-section { background: white; border: 1px solid #CBD6D6; margin-bottom: 10px; }
      .menu-section-header { min-height: 34px; border-bottom: 1px solid #E2E9E9; background: #F8FAFC; padding: 6px 9px; display: flex; align-items: center; justify-content: space-between; gap: 10px; }
      .menu-section-header h3 { margin: 0; font-size: 12px; font-weight: 600; color: #091B1B; }

      .menu-grid { padding: 9px; display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 8px; }
      .menu-field label { display: block; margin-bottom: 3px; font-size: 10.5px; color: #465858; }
      .menu-span-3 { grid-column: span 3 / span 3; }
      .menu-span-4 { grid-column: span 4 / span 4; }
      .menu-span-5 { grid-column: span 5 / span 5; }
      .menu-span-12 { grid-column: span 12 / span 12; }

      .menu-input, .menu-textarea { width: 100%; border: 1px solid #93A6A6; background: white; color: #091B1B; font-size: 12px; outline: none; border-radius: 0; }
      .menu-input { height: 31px; padding: 0 8px; }
      .menu-textarea { padding: 8px; resize: vertical; }
      .menu-input:focus, .menu-textarea:focus { border-color: #069495; box-shadow: inset 0 0 0 1px #93F5F4; }

      .menu-small-button, .menu-secondary-button, .menu-primary-button, .menu-primary-outline, .menu-danger-outline { height: 31px; border: 1px solid #93A6A6; padding: 0 10px; font-size: 11px; white-space: nowrap; border-radius: 0; }
      .menu-small-button { background: #F8FAFC; color: #091B1B; }
      .menu-primary-button { background: #069495; border-color: #057B7C; color: white; }
      .menu-secondary-button { background: white; color: #091B1B; }
      .menu-primary-outline { background: white; border-color: #057B7C; color: #057B7C; }
      .menu-danger-outline { background: white; border-color: #DC2626; color: #B91C1C; }

      .menu-check { min-height: 31px; border: 1px solid #CBD6D6; background: #F8FAFC; padding: 0 8px; display: flex; align-items: center; gap: 7px; font-size: 12px; color: #324444; }

      .menu-image-panel { display: grid; grid-template-columns: 220px 1fr; gap: 12px; padding: 9px; }
      .menu-image-preview { position: relative; height: 150px; width: 220px; border: 1px solid #CBD6D6; background: #F8FAFC; display: flex; align-items: center; justify-content: center; overflow: hidden; color: #637877; font-size: 12px; cursor: zoom-in; padding: 0; }
      .menu-banner-preview { height: 110px; }
      .menu-image-preview img { width: 100%; height: 100%; object-fit: contain; background: #fff; }
      .menu-image-zoom-hint { position: absolute; right: 6px; bottom: 6px; background: rgba(0,52,52,.78); color: white; font-size: 10px; padding: 3px 6px; }
      .menu-image-actions { display: flex; flex-direction: column; align-items: flex-start; gap: 8px; }
      .menu-note { border: 1px solid #A4E5E4; background: #F1F8F8; color: #057B7B; padding: 8px; font-size: 11px; }

      .menu-image-zoom-backdrop { position: fixed; inset: 0; z-index: 1000000; background: rgba(0,52,52,.82); display: flex; align-items: center; justify-content: center; padding: 18px; }
      .menu-image-zoom-card { width: min(96vw, 980px); height: min(92vh, 760px); background: white; border: 1px solid #CBD6D6; box-shadow: 0 24px 70px rgba(0,0,0,.45); display: flex; flex-direction: column; }
      .menu-image-zoom-header { height: 42px; flex-shrink: 0; display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 0 10px; border-bottom: 1px solid #CBD6D6; background: #F8FAFC; font-size: 12px; font-weight: 700; color: #091B1B; }
      .menu-image-zoom-header button { width: 32px; height: 30px; border: 1px solid #CBD6D6; background: white; font-size: 18px; color: #324444; }
      .menu-image-zoom-body { flex: 1; min-height: 0; background: #003434; display: flex; align-items: center; justify-content: center; overflow: auto; padding: 12px; }
      .menu-image-zoom-body img { max-width: 100%; max-height: 100%; object-fit: contain; background: white; }

      .menu-modal-footer { min-height: 50px; flex-shrink: 0; border-top: 1px solid #CBD6D6; background: #F8FAFC; padding: 9px 12px; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
      .menu-modal-status { font-size: 12px; color: #637877; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
      .menu-footer-actions { display: flex; align-items: center; justify-content: flex-end; gap: 8px; }

      button:disabled, input:disabled, select:disabled, textarea:disabled { opacity: .55; cursor: not-allowed; }
      button, input, select, textarea { border-radius: 0 !important; }

      @media (max-width: 900px) {
        .menu-span-3, .menu-span-4, .menu-span-5 { grid-column: span 6 / span 6; }
        .menu-image-panel { grid-template-columns: 1fr; }
      }

      @media (max-width: 640px) {
        .menu-item-modal { width: 99vw; height: 98vh; }
        .menu-grid { grid-template-columns: 1fr; }
        .menu-span-3, .menu-span-4, .menu-span-5, .menu-span-12 { grid-column: span 1 / span 1; }
        .menu-modal-footer { align-items: stretch; flex-direction: column; }
        .menu-footer-actions { width: 100%; }
        .menu-footer-actions button { flex: 1; }
      }
    `}</style>
  );
}

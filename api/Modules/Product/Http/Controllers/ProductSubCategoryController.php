<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Enterprise\BrandScopeService;
use App\Services\PublicAssetStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Product\Models\ProductCategory;
use Modules\Product\Models\ProductSubCategory;
use function App\Helpers\hasPermission;

class ProductSubCategoryController extends Controller
{
    public function index(Request $request)
    {
        $company = app('company');

        $result = hasPermission(auth()->user()->role_id, 'view_product_category', $company->id);
        if ($result !== true) {
            return $result;
        }

        $query = $this->brandSubCategories()
            ->with('category')
            ->latest();

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('q')) {
            $q = $request->q;

            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhere('code', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%");
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('display_area')) {
            $query->whereIn('display_area', [$request->display_area, 'both']);
        }

        return response()->json([
            'message' => 'Product sub categories fetched successfully.',
            'data' => $query->paginate($request->per_page ?? 15),
        ]);
    }

    public function store(Request $request)
    {
        $company = app('company');

        $result = hasPermission(auth()->user()->role_id, 'create_product_category', $company->id);
        if ($result !== true) {
            return $result;
        }

        $validator = Validator::make($request->all(), [
            'category_id' => [
                'required',
                Rule::exists('product_categories', 'id')->where('company_id', $company->id),
            ],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('product_sub_categories', 'name')->withoutTrashed()
                    ->where('company_id', $company->id)
                    ->where('category_id', $request->category_id),
            ],
            'code' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('product_sub_categories', 'code')->withoutTrashed()
                    ->where('company_id', $company->id)
                    ->where('category_id', $request->category_id),
            ],
            'description' => ['nullable', 'string'],
            'display_area' => ['nullable', 'in:front,back,both'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $category = app(BrandScopeService::class)->constrainBrandColumn(
            ProductCategory::where('company_id', $company->id),
            $this->workingBrandIds()
        )
            ->where('id', $request->category_id)
            ->first();

        if (!$category) {
            return response()->json([
                'message' => 'Product category not found.',
            ], 404);
        }

        DB::beginTransaction();

        try {
            $subCategory = ProductSubCategory::create([
                'company_id' => $company->id,
                'brand_id' => $category->brand_id ?? null,
                'category_id' => $request->category_id,
                'name' => $request->name,
                'code' => $request->code,
                'description' => $request->description,
                'display_area' => $request->display_area ?: 'both',
                'is_active' => $request->is_active ?? true,
                'sort_order' => $request->sort_order ?? 0,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Product sub category created successfully.',
                'data' => $subCategory->fresh('category'),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong while creating sub category.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(string $id)
    {
        $company = app('company');

        $subCategory = $this->brandSubCategories()
            ->with('category')
            ->where('id', $id)
            ->first();

        if (!$subCategory) {
            return response()->json([
                'message' => 'Product sub category not found.',
            ], 404);
        }

        return response()->json([
            'message' => 'Product sub category fetched successfully.',
            'data' => $subCategory,
        ]);
    }

    public function update(Request $request, string $id)
    {
        $company = app('company');

        $result = hasPermission(auth()->user()->role_id, 'update_product_category', $company->id);
        if ($result !== true) {
            return $result;
        }

        $subCategory = $this->brandSubCategories()
            ->where('id', $id)
            ->first();

        if (!$subCategory) {
            return response()->json([
                'message' => 'Product sub category not found.',
            ], 404);
        }

        $categoryId = $request->category_id ?: $subCategory->category_id;

        $validator = Validator::make($request->all(), [
            'category_id' => [
                'nullable',
                Rule::exists('product_categories', 'id')->where('company_id', $company->id),
            ],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('product_sub_categories', 'name')->withoutTrashed()
                    ->where('company_id', $company->id)
                    ->where('category_id', $categoryId)
                    ->ignore($subCategory->id),
            ],
            'code' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('product_sub_categories', 'code')->withoutTrashed()
                    ->where('company_id', $company->id)
                    ->where('category_id', $categoryId)
                    ->ignore($subCategory->id),
            ],
            'description' => ['nullable', 'string'],
            'display_area' => ['nullable', 'in:front,back,both'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $subCategory->update([
            'category_id' => $categoryId,
            'name' => $request->name,
            'code' => $request->code,
            'description' => $request->description,
            'display_area' => $request->display_area ?: $subCategory->display_area,
            'is_active' => $request->has('is_active') ? $request->is_active : $subCategory->is_active,
            'sort_order' => $request->sort_order ?? $subCategory->sort_order,
        ]);

        return response()->json([
            'message' => 'Product sub category updated successfully.',
            'data' => $subCategory->fresh('category'),
        ]);
    }

    public function destroy(string $id)
    {
        $company = app('company');

        $result = hasPermission(auth()->user()->role_id, 'delete_product_category', $company->id);
        if ($result !== true) {
            return $result;
        }

        $subCategory = $this->brandSubCategories()
            ->where('id', $id)
            ->first();

        if (!$subCategory) {
            return response()->json([
                'message' => 'Product sub category not found.',
            ], 404);
        }

        DB::beginTransaction();

        try {
            $this->deleteStoredFile($subCategory->image_path);
            $this->deleteStoredFile($subCategory->banner_image_path);

            $subCategory->delete();

            DB::commit();

            return response()->json([
                'message' => 'Product sub category deleted successfully.',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong while deleting sub category.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function uploadImage(Request $request, string $id)
    {
        return $this->uploadSubCategoryFile($request, $id, 'image');
    }

    public function deleteImage(string $id)
    {
        return $this->deleteSubCategoryFile($id, 'image');
    }

    public function uploadBannerImage(Request $request, string $id)
    {
        return $this->uploadSubCategoryFile($request, $id, 'banner');
    }

    public function deleteBannerImage(string $id)
    {
        return $this->deleteSubCategoryFile($id, 'banner');
    }

    /** Sub-categories of the brand the Back Office is working in, plus brand-less shared ones. */
    private function brandSubCategories()
    {
        return app(BrandScopeService::class)->constrainBrandColumn(
            ProductSubCategory::where('company_id', app('company')->id),
            $this->workingBrandIds()
        );
    }

    /** @return list<string> */
    private function workingBrandIds(): array
    {
        return app(BrandScopeService::class)->forRequest(request(), (string) app('company')->id);
    }

    private function uploadSubCategoryFile(Request $request, string $id, string $type)
    {
        $company = app('company');

        $result = hasPermission(auth()->user()->role_id, 'update_product_category', $company->id);
        if ($result !== true) {
            return $result;
        }

        $subCategory = $this->brandSubCategories()
            ->where('id', $id)
            ->first();

        if (!$subCategory) {
            return response()->json([
                'message' => 'Product sub category not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:max_width=8000,max_height=8000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $oldPath = $type === 'banner'
            ? $subCategory->banner_image_path
            : $subCategory->image_path;
        $fullPath = null;
        DB::beginTransaction();

        try {
            $file = $request->file('image');

            if (!$file || !$file->isValid()) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Invalid uploaded image.',
                    'error' => $file ? $file->getErrorMessage() : 'No file received.',
                ], 422);
            }

            $folder = $type === 'banner'
                ? 'sub-category-banner-images'
                : 'sub-category-images';

            $relativeDirectory = $folder . '/' . $company->id;
            $assets = app(PublicAssetStorage::class);
            $absoluteDirectory = $assets->ensureDirectory($relativeDirectory);

            $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');

            $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
            if (!in_array($extension, $allowedExtensions, true)) {
                $extension = 'jpg';
            }

            $safeName = Str::slug($subCategory->name ?: 'sub-category');
            $filename = $safeName . '-' . $subCategory->id . '-' . time() . '.' . $extension;

            $file->move($absoluteDirectory, $filename);

            $fullPath = $absoluteDirectory . DIRECTORY_SEPARATOR . $filename;

            $assets->hardenFile($fullPath);

            if (!File::exists($fullPath) || !File::isFile($fullPath)) {
                throw new \Exception('Image file was not saved correctly.');
            }

            if (@getimagesize($fullPath) === false) {
                File::delete($fullPath);
                DB::rollBack();

                return response()->json([
                    'message' => 'Uploaded file was saved but is not a valid image.',
                ], 422);
            }

            $relativePath = $relativeDirectory . '/' . $filename;
            $imagePath = 'assets/' . $relativePath;
            $imageUrl = $this->publicAssetUrl($relativePath);

            if ($type === 'banner') {
                $subCategory->banner_image_path = $imagePath;
                $subCategory->banner_image_url = $imageUrl;
            } else {
                $subCategory->image_path = $imagePath;
                $subCategory->image_url = $imageUrl;
            }

            $subCategory->save();

            DB::commit();
            $this->deleteStoredFile($oldPath);

            return response()->json([
                'message' => $type === 'banner'
                    ? 'Product sub category banner image uploaded successfully.'
                    : 'Product sub category image uploaded successfully.',
                'data' => $subCategory->fresh('category'),
                'image_path' => $imagePath,
                'image_url' => $imageUrl,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            if ($fullPath && File::isFile($fullPath)) {
                File::delete($fullPath);
            }

            return response()->json([
                'message' => 'Something went wrong while uploading sub category image.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function deleteSubCategoryFile(string $id, string $type)
    {
        $company = app('company');

        $result = hasPermission(auth()->user()->role_id, 'update_product_category', $company->id);
        if ($result !== true) {
            return $result;
        }

        $subCategory = $this->brandSubCategories()
            ->where('id', $id)
            ->first();

        if (!$subCategory) {
            return response()->json([
                'message' => 'Product sub category not found.',
            ], 404);
        }

        DB::beginTransaction();

        try {
            $oldPath = $type === 'banner'
                ? $subCategory->banner_image_path
                : $subCategory->image_path;
            if ($type === 'banner') {
                $subCategory->banner_image_path = null;
                $subCategory->banner_image_url = null;
            } else {
                $subCategory->image_path = null;
                $subCategory->image_url = null;
            }

            $subCategory->save();

            DB::commit();
            $this->deleteStoredFile($oldPath);

            return response()->json([
                'message' => $type === 'banner'
                    ? 'Product sub category banner image removed successfully.'
                    : 'Product sub category image removed successfully.',
                'data' => $subCategory->fresh('category'),
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong while removing sub category image.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }



    private function publicAssetsRoot(): string
    {
        return app(PublicAssetStorage::class)->root();
    }

    private function publicAssetsPath(string $relativeDirectory): string
    {
        return app(PublicAssetStorage::class)->path($relativeDirectory);
    }

    private function publicAssetUrl(string $relativePath): string
    {
        $relativePath = $this->encodeUrlPath($relativePath);

        $host = rtrim(request()->getSchemeAndHttpHost(), '/');
        $basePath = rtrim(request()->getBaseUrl(), '/');

        if (Str::endsWith($basePath, '/api')) {
            $basePath = Str::beforeLast($basePath, '/api');
        }

        return $host . $basePath . '/assets/' . $relativePath;
    }

    private function deleteStoredFile(?string $path): void
    {
        if (empty($path)) {
            return;
        }

        $relativePath = ltrim(str_replace('\\', '/', $path), '/');

        if (Str::startsWith($relativePath, 'assets/')) {
            $relativePath = Str::after($relativePath, 'assets/');
        }

        $absolutePath = $this->publicAssetsPath(dirname($relativePath))
            . DIRECTORY_SEPARATOR
            . basename($relativePath);

        if (File::exists($absolutePath) && File::isFile($absolutePath)) {
            File::delete($absolutePath);
        }
    }

    private function encodeUrlPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return collect(explode('/', $path))
            ->map(fn ($part) => rawurlencode($part))
            ->implode('/');
    }
}

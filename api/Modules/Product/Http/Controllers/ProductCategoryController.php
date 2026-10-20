<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Enterprise\BrandScopeService;
use App\Services\PublicAssetStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Product\Models\ProductCategory;
use function App\Helpers\hasPermission;

class ProductCategoryController extends Controller
{
    public function index(Request $request)
    {
        $company = app('company');

        $result = hasPermission(auth()->user()->role_id, 'view_product_category', $company->id);
        if ($result !== true) {
            return $result;
        }

        $query = $this->brandCategories()
            ->with(['parent', 'children', 'subCategories'])
            ->latest();

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
            'message' => 'Product categories fetched successfully.',
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
            'parent_id' => [
                'nullable',
                Rule::exists('product_categories', 'id')->where('company_id', $company->id),
            ],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('product_categories', 'name')->withoutTrashed()->where('company_id', $company->id)
                    ->where(fn ($query) => $this->whereBrand($query, $this->workingBrandId())),
            ],
            'code' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('product_categories', 'code')->withoutTrashed()->where('company_id', $company->id)
                    ->where(fn ($query) => $this->whereBrand($query, $this->workingBrandId())),
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

        DB::beginTransaction();

        try {
            $category = ProductCategory::create([
                'company_id' => $company->id,
                'brand_id' => $this->workingBrandId(),
                'parent_id' => $request->parent_id,
                'name' => $request->name,
                'code' => $request->code,
                'description' => $request->description,
                'display_area' => $request->display_area ?: 'both',
                'is_active' => $request->is_active ?? true,
                'sort_order' => $request->sort_order ?? 0,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Product category created successfully.',
                'data' => $category->fresh(['parent', 'children', 'subCategories']),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong while creating category.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(string $id)
    {
        $company = app('company');

        $category = $this->brandCategories()
            ->with(['parent', 'children', 'subCategories'])
            ->where('id', $id)
            ->first();

        if (!$category) {
            return response()->json([
                'message' => 'Product category not found.',
            ], 404);
        }

        return response()->json([
            'message' => 'Product category fetched successfully.',
            'data' => $category,
        ]);
    }

    public function update(Request $request, string $id)
    {
        $company = app('company');

        $result = hasPermission(auth()->user()->role_id, 'update_product_category', $company->id);
        if ($result !== true) {
            return $result;
        }

        $category = $this->brandCategories()
            ->where('id', $id)
            ->first();

        if (!$category) {
            return response()->json([
                'message' => 'Product category not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'parent_id' => [
                'nullable',
                Rule::exists('product_categories', 'id')->where('company_id', $company->id),
            ],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('product_categories', 'name')->withoutTrashed()
                    ->where('company_id', $company->id)
                    ->where(fn ($query) => $this->whereBrand($query, $category->brand_id))
                    ->ignore($category->id),
            ],
            'code' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('product_categories', 'code')->withoutTrashed()
                    ->where('company_id', $company->id)
                    ->where(fn ($query) => $this->whereBrand($query, $category->brand_id))
                    ->ignore($category->id),
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

        $category->update([
            'parent_id' => $request->parent_id,
            'name' => $request->name,
            'code' => $request->code,
            'description' => $request->description,
            'display_area' => $request->display_area ?: $category->display_area,
            'is_active' => $request->has('is_active') ? $request->is_active : $category->is_active,
            'sort_order' => $request->sort_order ?? $category->sort_order,
        ]);

        return response()->json([
            'message' => 'Product category updated successfully.',
            'data' => $category->fresh(['parent', 'children', 'subCategories']),
        ]);
    }

    public function destroy(string $id)
    {
        $company = app('company');

        $result = hasPermission(auth()->user()->role_id, 'delete_product_category', $company->id);
        if ($result !== true) {
            return $result;
        }

        $category = $this->brandCategories()
            ->where('id', $id)
            ->first();

        if (!$category) {
            return response()->json([
                'message' => 'Product category not found.',
            ], 404);
        }

        DB::beginTransaction();

        try {
            $this->deleteStoredFile($category->image_path);
            $this->deleteStoredFile($category->banner_image_path);

            $category->delete();

            DB::commit();

            return response()->json([
                'message' => 'Product category deleted successfully.',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong while deleting category.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function uploadImage(Request $request, string $id)
    {
        return $this->uploadCategoryFile($request, $id, 'image');
    }

    public function deleteImage(string $id)
    {
        return $this->deleteCategoryFile($id, 'image');
    }

    public function uploadBannerImage(Request $request, string $id)
    {
        return $this->uploadCategoryFile($request, $id, 'banner');
    }

    public function deleteBannerImage(string $id)
    {
        return $this->deleteCategoryFile($id, 'banner');
    }

    private function uploadCategoryFile(Request $request, string $id, string $type)
    {
        $company = app('company');

        $result = hasPermission(auth()->user()->role_id, 'update_product_category', $company->id);
        if ($result !== true) {
            return $result;
        }

        $category = $this->brandCategories()
            ->where('id', $id)
            ->first();

        if (!$category) {
            return response()->json([
                'message' => 'Product category not found.',
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
            ? $category->banner_image_path
            : $category->image_path;
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
                ? 'category-banner-images'
                : 'category-images';

            $relativeDirectory = $folder . '/' . $company->id;

            /*
             * Saves to:
             * C:/xampp/htdocs/salexaapi/assets/category-images/{company_id}
             *
             * because base_path() is:
             * C:/xampp/htdocs/salexaapi/api
             */
            $assets = app(PublicAssetStorage::class);
            $absoluteDirectory = $assets->ensureDirectory($relativeDirectory);

            $extension = strtolower(
                $file->getClientOriginalExtension()
                    ?: $file->extension()
                    ?: 'jpg'
            );

            $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];

            if (!in_array($extension, $allowedExtensions, true)) {
                $extension = 'jpg';
            }

            $safeName = Str::slug($category->name ?: 'category');
            $filename = $safeName . '-' . $category->id . '-' . time() . '.' . $extension;

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

            /*
             * Saved in DB:
             * assets/category-images/{company_id}/{filename}
             */
            $imagePath = 'assets/' . $relativePath;

            /*
             * Public URL:
             * http://localhost/salexaapi/assets/category-images/{company_id}/{filename}
             */
            $imageUrl = $this->publicAssetUrl($relativePath);

            if ($type === 'banner') {
                $category->banner_image_path = $imagePath;
                $category->banner_image_url = $imageUrl;
            } else {
                $category->image_path = $imagePath;
                $category->image_url = $imageUrl;
            }

            $category->save();

            DB::commit();
            $this->deleteStoredFile($oldPath);

            return response()->json([
                'message' => $type === 'banner'
                    ? 'Product category banner image uploaded successfully.'
                    : 'Product category image uploaded successfully.',
                'data' => $category->fresh(['parent', 'children', 'subCategories']),
                'image_path' => $imagePath,
                'image_url' => $imageUrl,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            if ($fullPath && File::isFile($fullPath)) {
                File::delete($fullPath);
            }

            return response()->json([
                'message' => 'Something went wrong while uploading category image.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function deleteCategoryFile(string $id, string $type)
    {
        $company = app('company');

        $result = hasPermission(auth()->user()->role_id, 'update_product_category', $company->id);
        if ($result !== true) {
            return $result;
        }

        $category = $this->brandCategories()
            ->where('id', $id)
            ->first();

        if (!$category) {
            return response()->json([
                'message' => 'Product category not found.',
            ], 404);
        }

        DB::beginTransaction();

        try {
            $oldPath = $type === 'banner'
                ? $category->banner_image_path
                : $category->image_path;
            if ($type === 'banner') {
                $category->banner_image_path = null;
                $category->banner_image_url = null;
            } else {
                $category->image_path = null;
                $category->image_url = null;
            }

            $category->save();

            DB::commit();
            $this->deleteStoredFile($oldPath);

            return response()->json([
                'message' => $type === 'banner'
                    ? 'Product category banner image removed successfully.'
                    : 'Product category image removed successfully.',
                'data' => $category->fresh(['parent', 'children', 'subCategories']),
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong while removing category image.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /** Categories of the brand the Back Office is working in, plus brand-less shared ones. */
    private function brandCategories()
    {
        return app(BrandScopeService::class)->constrainBrandColumn(
            ProductCategory::where('company_id', app('company')->id),
            $this->workingBrandIds()
        );
    }

    /** @return list<string> */
    private function workingBrandIds(): array
    {
        return app(BrandScopeService::class)->forRequest(request(), (string) app('company')->id);
    }

    private function workingBrandId(): ?string
    {
        return app(BrandScopeService::class)->primaryBrandId($this->workingBrandIds());
    }

    /** Category names and codes only need to be unique within one brand. */
    private function whereBrand($query, ?string $brandId)
    {
        if (! Schema::hasColumn('product_categories', 'brand_id')) {
            return $query;
        }

        return $brandId ? $query->where('brand_id', $brandId) : $query->whereNull('brand_id');
    }

    private function publicAssetsRoot(): string
    {
        /*
         * XAMPP localhost:
         * Laravel API folder:
         * C:/xampp/htdocs/salexaapi/api
         *
         * Public assets folder:
         * C:/xampp/htdocs/salexaapi/assets
         */
        return app(PublicAssetStorage::class)->root();
    }

    private function publicAssetsPath(string $relativeDirectory): string
    {
        return app(PublicAssetStorage::class)->path($relativeDirectory);
    }

    private function publicAssetUrl(string $relativePath): string
    {
        $relativePath = $this->encodeUrlPath($relativePath);

        // Use the origin that reached this API. A branch may address the same
        // installation through localhost, a reserved LAN IP or a public host;
        // persisting one APP_ASSET_URL must not make images unreachable from
        // the other clients.
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

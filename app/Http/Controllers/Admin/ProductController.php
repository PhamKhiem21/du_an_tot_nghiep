<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin\Product;
use App\Models\Admin\Category;
use App\Models\Admin\ProductVariant;
use App\Models\Admin\Attribute;
use App\Models\Admin\AttributeValue;
use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    public function index()
    {
        $categories = Category::all();
        $products = Product::where('is_active', 1)->with(['variants'])->orderBy('created_at', 'desc')->get();
        return view('admin.products.index', compact('products', 'categories'));
    }

    public function create()
    {
        $categories = Category::all();
        $attributes = Attribute::with('attributeValues')->where('is_active', 1)->get();
        $brands = Brand::where('is_active', 1)->get(); // Lấy danh sách brand đang hoạt động
        return view('admin.products.create', compact('attributes', 'categories', 'brands'));
    }
    public function store(Request $request)
    {
        $data = $request->validate([
             'category_id' => 'required|exists:categories,id',
            'brand_id' => 'required|exists:brands,id',
            'stock' => 'required|integer|min:0',
            'name' => 'required|string|max:255|unique:products,name',
            'short_description' => 'required|string',
            'description' => 'required|string',
            'stock' => 'required|integer|min:0|max:100',
            'thumbnail' => 'required|image|max:2048',
            'price' => 'required|numeric',
            'sale_price' => 'nullable|numeric',
            'sale_price_start_at' => 'nullable|date',
            'sale_price_end_at' => 'nullable|date|after_or_equal:sale_price_start_at',
            'is_sale' => 'boolean',
            'is_active' => 'boolean',
            'variants' => 'required_if:has_variants,true|array',
            'variants.*.attribute_id' => 'required|exists:attributes,id',
            'variants.*.attribute_value_id' => 'required|exists:attribute_values,id',
            'variants.*.price' => 'required|numeric',
            'variants.*.sku' => 'nullable|string|max:255',
            'variants.*.thumbnail' => 'nullable|image',
        ]);

        // Xử lý checkbox
        $data['is_sale'] = $request->has('is_sale');
        $data['is_active'] = $request->has('is_active');
        // Kiểm tra kích thước ảnh chính (thumbnail)
        if ($request->hasFile('thumbnail')) {
            [$width, $height] = getimagesize($request->file('thumbnail'));
            if ($width < 600 || $height < 600 || $width > 1200 || $height > 1200) {
                return redirect()->back()->withInput()->withErrors([
                    'thumbnail' => 'Ảnh phải có kích thước từ 600x600 đến 1200x1200 pixels.',
                ]);
            }
        }
        // Upload ảnh chính
        if ($request->hasFile('thumbnail')) {
            $data['thumbnail'] = $request->file('thumbnail')->store('uploads/products', 'public');
        }

        // Tạo sản phẩm
        $product = Product::create($data);
        // Xử lý hình ảnh chi tiết
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                if ($image->isValid()) {
                    $path = $image->store('uploads/images', 'public');
                    $product->galleries()->create([
                        'image' => $path,
                    ]);
                }
            }
        }
        // Tạo các biến thể
        if ($request->has('variants')) {
            foreach ($request->variants as $variantData) {
                $variant = new ProductVariant([
                    'price' => $variantData['price'],
                    'stock' => $variantData['stock'],
                    'sku' => $variantData['sku'] ?? null,
                ]);

                // Upload ảnh biến thể nếu có
                if (isset($variantData['thumbnail']) && $variantData['thumbnail'] instanceof \Illuminate\Http\UploadedFile && $variantData['thumbnail']->isValid()) {
                    $variant->thumbnail = $variantData['thumbnail']->store('uploads/variants', 'public');
                } else {
                    $variant->thumbnail = $product->thumbnail;
                }

                // Lưu biến thể
                $product->variants()->save($variant);

                // Gán giá trị thuộc tính cho sản phẩm (nếu có)
                        if ($request->has('attribute_value_id')) {
                            $product->attributeValues()->sync($request->input('attribute_value_id'));
                        }
                
                // $variantData['attribute_value_id'] là mảng các id
                if (isset($variantData['attribute_value_id'])) {
                    $variant->attributeValues()->attach($variantData['attribute_value_id']);
                }
            }
        }

        return redirect()->route('admin.products.index')->with('success', 'Product created successfully.');
    }
    public function edit(Product $product)
    {
        $categories = Category::all();
        $brands = Brand::where('is_active', 1)->get(); // Thêm dòng này
        $product->load(['variants.attributeValues']);
        $colors = AttributeValue::whereHas('attribute', function ($q) {
            $q->where('slug', 'color');
        })->get();

        $sizes = AttributeValue::whereHas('attribute', function ($q) {
            $q->where('slug', 'size');
        })->get();

        return view('admin.products.edit', compact('product', 'colors', 'sizes', 'categories', 'brands'));
    }

    public function update(Request $request, Product $product)
    {
        $data = $request->validate([
            'brand_id' => 'required|exists:brands,id',
            // 'stock' => 'required|integer|min:0|max:100',
            'name' => 'required|string|max:255|unique:products,name,' . $product->id,
            'short_description' => 'required|string',
            'description' => 'required|string',
            'thumbnail' => 'nullable|image|max:2048',
            'sku' => 'nullable|string|unique:products,sku,' . $product->id,
            'price' => 'required|numeric',
            'sale_price' => 'nullable|numeric',
            'sale_price_start_at' => 'nullable|date',
            'sale_price_end_at' => 'nullable|date|after_or_equal:sale_price_start_at',
            'is_sale' => 'boolean',
            'is_active' => 'boolean',
            'variants' => 'sometimes|array',
            'variants.*.price' => 'required_with:variants|numeric',
            'variants.*.sku' => 'nullable|string|unique:product_variants,sku,' . ($request->variants[0]['id'] ?? 'NULL'),
            'variants.*.thumbnail' => 'nullable|image',
        ]);

        // Xử lý checkbox
        $data['is_sale'] = $request->has('is_sale');
        $data['is_active'] = $request->has('is_active');

        // Upload ảnh chính nếu có
        if ($request->hasFile('thumbnail')) {
            // Xóa ảnh cũ nếu tồn tại
            if ($product->thumbnail) {
                Storage::disk('public')->delete($product->thumbnail);
            }
            $data['thumbnail'] = $request->file('thumbnail')->store('uploads/products', 'public');
        }
        if ($request->hasFile('thumbnail')) {
            [$width, $height] = getimagesize($request->file('thumbnail'));
            if ($width < 600 || $height < 600 || $width > 1200 || $height > 1200) {
                return redirect()->back()->withInput()->withErrors([
                    'thumbnail' => 'Ảnh phải có kích thước từ 600x600 đến 1200x1200 pixels.',
                ]);
            }
        }
        // Cập nhật sản phẩm
        $product->update($data);

        // Xử lý biến thể

        if ($request->has('variants')) {
            $existingVariantIds = [];

            foreach ($request->variants as $variantData) {
                $variantData = array_filter($variantData);

                if (isset($variantData['id'])) {
                    // Cập nhật biến thể hiện có
                    $variant = ProductVariant::find($variantData['id']);
                    $variant->update([
                        'price' => $variantData['price'],
                        'sku' => $variantData['sku'] ?? $variant->sku,
                    ]);

                    // Upload ảnh biến thể nếu có
                    if (isset($variantData['thumbnail']) && $variantData['thumbnail'] instanceof \Illuminate\Http\UploadedFile && $variantData['thumbnail']->isValid()) {
                        if ($variant->thumbnail) {
                            Storage::disk('public')->delete($variant->thumbnail);
                        }
                        $variant->thumbnail = $variantData['thumbnail']->store('uploads/variants', 'public');
                        $variant->save();
                    }

                    // Cập nhật thuộc tính biến thể (dùng mảng động)
                    if (isset($variantData['attribute_value_id'])) {
                        $variant->attributeValues()->sync($variantData['attribute_value_id']);
                    }

                    $existingVariantIds[] = $variantData['id'];
                } else {
                    // Tạo biến thể mới
                    $variant = new ProductVariant([
                        'sku' => $variantData['sku'] ?? null,
                        'price' => $variantData['price'],
                    ]);

                    if (isset($variantData['thumbnail']) && $variantData['thumbnail'] instanceof \Illuminate\Http\UploadedFile && $variantData['thumbnail']->isValid()) {
                        $variant->thumbnail = $variantData['thumbnail']->store('uploads/variants', 'public');
                    } else {
                        $variant->thumbnail = $product->thumbnail;
                    }

                    $product->variants()->save($variant);

                    if (isset($variantData['attribute_value_id'])) {
                        $variant->attributeValues()->attach($variantData['attribute_value_id']);
                    }

                    $existingVariantIds[] = $variant->id;
                }
            }

            // Xóa các biến thể không còn tồn tại
            $product->variants()->whereNotIn('id', $existingVariantIds)->delete();
        }

        return redirect()->route('admin.products.index')->with('success', 'Product updated successfully.');
    }

   public function show(Product $product)
{
    // Load các quan hệ cần thiết
    $product->load([
        'variants.attributeValues.attribute',
        'galleries',
        // 'orderItems.order.customer' // Load cả customer để hiển thị sau này
    ]);

    // Lấy tất cả order items của sản phẩm
    $orderItems = $product->orderItems;

    $orderStats = $product->getOrderStatusStats();

    // Nhóm thống kê theo trạng thái đơn hàng
    $orderStats = $orderItems->groupBy('order.status')
        ->map(function ($items, $status) {
            return [
                'status' => $status,
                'order_count' => $items->unique('order_id')->count(),
                'total_quantity' => $items->sum('quantity'),
                'total_revenue' => $items->sum(function ($item) {
                    return $item->price * $item->quantity;
                })
            ];
        })->values();

    // Tính tổng các thống kê
    $totalOrders = $orderStats->sum('order_count');
    $totalSold = $orderStats->sum('total_quantity');
    $totalRevenue = $orderStats->sum('total_revenue');

    // Lấy danh sách đơn hàng gần nhất (10 đơn)
    $recentOrders = $orderItems->sortByDesc('created_at')
        ->take(10)
        ->groupBy('order_id')
        ->map(function ($items) {
            return $items->first(); // Lấy 1 item đại diện cho mỗi đơn
        });

    return view('admin.products.show', compact(
        'product',
        'orderStats',
        'totalOrders',
        'totalSold',
        'totalRevenue',
        'recentOrders'
    ));
}

    public function destroy(Product $product)
    {
        // Xóa ảnh chính
        // if ($product->thumbnail) {
        //     Storage::disk('public')->delete($product->thumbnail);
        // }

        // // Xóa các ảnh biến thể
        // foreach ($product->variants as $variant) {
        //     if ($variant->thumbnail && $variant->thumbnail != $product->thumbnail) {
        //         Storage::disk('public')->delete($variant->thumbnail);
        //     }
        // }

        // $product->variants()->delete();
        // $product->forceDelete();
        $product->is_active = 0;
        $product->save();
        return redirect()->route('admin.products.index')->with('success', 'Sản phẩm đã được ẩn.');
    }
    /**
     * Tạo SKU tự động cho biến thể
     */
    protected function generateVariantSku(Product $product, array $variantData)
    {
        $color = AttributeValue::find($variantData['color_id']);
        $size = AttributeValue::find($variantData['size_id']);

        $productSku = $product->sku ?: substr(strtoupper(preg_replace('/[^a-z0-9]/i', '', $product->name)), 0, 3);
        $colorCode = substr(strtoupper($color->value), 0, 3);
        $sizeCode = $size->value;

        return $productSku . '-' . $colorCode . '-' . $sizeCode;
    }
    public function adminListByCategory($id)
    {
        $category = Category::findOrFail($id);
        $products = Product::whereHas('categories', function ($query) use ($id) {
            $query->where('categories.id', $id);
        })->with('variants')->get();

        $categories = Category::all();
        return view('admin.products.index', compact('category', 'products', 'categories'));
    }
    // Hiển thị danh sách sản phẩm đã xóa mềm
    public function trashed()
    {
        $products = Product::where('is_active', 0)->get();
        return view('admin.products.trashed', compact('products'));
    }

    // Khôi phục sản phẩm
    public function restore(Product $product)
    {
        $product->is_active = 1;
        $product->save();
        return redirect()->route('admin.products.trashed')->with('success', 'Sản phẩm đã được khôi phục!');
    }

    // Xóa cứng sản phẩm (chỉ khi chưa từng có trong giỏ hàng)
    public function forceDelete($id)
    {
        $product = Product::findOrFail($id);

        // Nếu sản phẩm đã từng có trong giỏ hàng, không cho xóa cứng
        if ($product->cartItems()->exists()) {
            return redirect()->back()->with('error', 'Không thể xóa cứng sản phẩm đã từng có trong giỏ hàng!');
        }

        // Xóa ảnh chính nếu có
        if ($product->thumbnail) {
            Storage::disk('public')->delete($product->thumbnail);
        }
        // Xóa các ảnh biến thể
        foreach ($product->variants as $variant) {
            if ($variant->thumbnail && $variant->thumbnail != $product->thumbnail) {
                Storage::disk('public')->delete($product->thumbnail);
            }
        }
        $product->variants()->delete();
        $product->delete();

        return redirect()->route('admin.products.trashed')->with('success', 'Đã xóa vĩnh viễn sản phẩm!');
    }
}

<?php

/*
|--------------------------------------------------------------------------
| POS Routes
|--------------------------------------------------------------------------
|
| Here is where you can register admin routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

use App\Http\Controllers\PosController;
Route::post('/pos-order', [PosController::class, 'order_store'])->name('pos.order_place');
Route::post('/add-to-cart-pos', [PosController::class, 'addToCart'])->name('pos.addToCart');
// Route::
Route::get('/admin/pos', [PosController::class, 'index'])->name('poin-of-sales.index')->middleware('admin');
Route::get('/pos/products', [PosController::class, 'search'])->name('pos.search_product');
Route::get('/variants', [PosController::class, 'getVarinats'])->name('variants');
Route::get('/get_shipping_address', [PosController::class, 'getShippingAddress'])->name('pos.getShippingAddress');
Route::post('/update-quantity-cart-pos', [PosController::class, 'updateQuantity'])->name('pos.updateQuantity');
Route::post('/remove-from-cart-pos', [PosController::class, 'removeFromCart'])->name('pos.removeFromCart');
// Route::post('/get_shipping_address', [PosController::class, 'getShippingAddress'])->name('pos.getShippingAddress');
Route::post('/get_shipping_address_seller', [PosController::class, 'getShippingAddressForSeller'])->name('pos.getShippingAddressForSeller');
Route::post('/setDiscount', [PosController::class, 'setDiscount'])->name('pos.setDiscount');
Route::post('/setShipping', [PosController::class, 'setShipping'])->name('pos.setShipping');

// Admin
Route::group(['prefix' => 'admin', 'middleware' => ['auth', 'admin']], function () {
    // pos
    Route::get('/pos-activation', [PosController::class, 'pos_activation'])->name('poin-of-sales.activation');
});

Route::group(['prefix' => 'seller', 'middleware' => ['seller', 'verified']], function () {
    // pos
    Route::get('/pos', [PosController::class, 'index'])->name('poin-of-sales.seller_index');
});

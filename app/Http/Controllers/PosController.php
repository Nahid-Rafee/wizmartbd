<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\OTPVerificationController;
use App\Http\Controllers\ClubPointController;
use App\Http\Controllers\AffiliateController;
use App\OtpConfiguration;
use App\Models\BusinessSetting;
use App\Models\OrderDetail;
use App\Models\ProductStock;
use App\Models\Product;
use App\Models\Order;
use App\Models\Color;
use App\Models\User;
use App\Models\Address;
use Session;
use Auth;
use DB;
use PDF;
use Mail;
use App\Models\FlashDeal;
use App\Models\FlashDealProduct;
use App\Mail\InvoiceEmailManager;
use App\Http\Resources\PosProductCollection;
use App\Utility\CategoryUtility;

class PosController extends Controller
{
    public function index()
    {
        if (Auth::user()->user_type == 'admin' || Auth::user()->user_type == 'staff') {

            return view('pos.index');
        }
        else {
            $pos_activation = BusinessSetting::where('type', 'pos_activation_for_seller')->first();
            if ($pos_activation != null && $pos_activation->value == 1) {
                return view('pos.frontend.seller.pos.index');
            }
            else {
                flash(translate('POS is disable for Sellers!!!'))->error();
                return back();
            }
        }
    }

    public function search(Request $request)
    {
        // dd($request->all());
        $products = Product::select('products.*', 'uploads.file_name');
        if(Auth::user()->user_type == 'admin' || Auth::user()->user_type == 'staff'){
            $products = $products->where('added_by', 'admin')->where('published', '1');
        }
        else {
            $products = $products->where('user_id', Auth::user()->id)->where('published', '1');
        }
        if($request->category != null){
            $category_ids = CategoryUtility::children_ids($request->category);
            $category_ids[] = $request->category;
            $products = $products->whereIn('category_id', $category_ids);
        }
        if($request->brand != null){
            $products = $products->where('brand_id', $request->brand);
        }

        if ($request->keyword != null) {
            // $products = $products->where('name', 'like', '%'.$request->keyword.'%')->orWhere('barcode', $request->keyword)->orderBy('created_at', 'desc');
            $products = $products->where('name', 'like', '%'.$request->keyword.'%')->orderBy('created_at', 'desc');

        }
        // $stocks = new PosProductCollection(Product::where('added_by', 'admin')->where('published', '1')->paginate(16));
        // $stocks->appends(['keyword' =>  null]);
        return $products->leftJoin('uploads', 'products.thumbnail_img', '=', 'uploads.id')->paginate(20);
    }

    public function getVarinats(Request $request){
        $stocks = Product::find($request->id)->stocks;
        if(count($stocks) > 0){
            return view('pos.variants', compact('stocks'));
        }
        else {
            return 0;
        }
    }

    public function addToCart(Request $request)
    {

        $product = Product::find($request->product_id);
        $product_stock = ProductStock::where('product_id',$request->product_id);
        if($request->variant)
        {
            $product_stock = $product_stock->where('variant',$request->variant)->first();
        }
        else
        {
            $product_stock = $product_stock->first();
        }
        // dd($product_stock->qty);
        if($request->quantity> $product_stock->qty)
        {
            flash('Requested Quantity is too high')->error();
            return back();
        }
        $product_stock->qty =   $product_stock->qty-$request->quantity;
        
        $product_stock->save();

        $data = array();
        $data['id'] = $product->id;
        $tax = 0;
        $data['variant'] = $request->variant;
        
        if($request->variant != null && $product->variant_product){
            $product_stock = $product->stocks->where('variant', $request->variant)->first();
            $price = $product_stock->price;
            $quantity = $product_stock->qty;

            if($request['quantity'] > $quantity){
                return 0;
            }
        }
        else{
            $price = $product->unit_price;
        }

        //discount calculation based on flash deal and regular discount
        //calculation of taxes
        $flash_deals = FlashDeal::where('status', 1)->get();
        $inFlashDeal = false;
        foreach ($flash_deals as $flash_deal) {
            if ($flash_deal != null && $flash_deal->status == 1  && strtotime(date('d-m-Y')) >= $flash_deal->start_date && strtotime(date('d-m-Y')) <= $flash_deal->end_date && \App\Models\FlashDealProduct::where('flash_deal_id', $flash_deal->id)->where('product_id', $product->id)->first() != null) {
                $flash_deal_product = FlashDealProduct::where('flash_deal_id', $flash_deal->id)->where('product_id', $product->id)->first();
                if($flash_deal_product->discount_type == 'percent'){
                    $price -= ($price*$flash_deal_product->discount)/100;
                }
                elseif($flash_deal_product->discount_type == 'amount'){
                    $price -= $flash_deal_product->discount;
                }
                $inFlashDeal = true;
                break;
            }
        }
        if (!$inFlashDeal) {
            if($product->discount_type == 'percent'){
                $price -= ($price*$product->discount)/100;
            }
            elseif($product->discount_type == 'amount'){
                $price -= $product->discount;
            }
        }

        if($product->tax_type == 'percent'){
            $tax = ($price*$product->tax)/100;
        }
        elseif($product->tax_type == 'amount'){
            $tax = $product->tax;
        }

        $data['quantity'] = $request->quantity;
        $data['price'] = $price;
        $data['tax'] = $tax;
        $data['shipping'] = $product->shipping_cost;

        if($request->session()->has('posCart')){
            $foundInCart = false;
            $cart = collect();

            foreach ($request->session()->get('posCart') as $key => $cartItem){
                if($cartItem['id'] == $request->product_id){
                    if($cartItem['variant'] == $request->variant){
                        $foundInCart = true;
                        $product = Product::find($cartItem['id']);
                        if($cartItem['variant'] != null && $product->variant_product){
                            $product_stock = $product->stocks->where('variant', $cartItem['variant'])->first();
                            $quantity = $product_stock->qty;
                            if($quantity >= $request->quantity){
                                if($request->quantity >= $product->min_qty){
                                    $cartItem['quantity'] = $request->quantity;
                                }
                            }
                        }
                        elseif ($product->current_stock >= $request->quantity) {
                            if($request->quantity >= $product->min_qty){
                                $cartItem['quantity'] = $request->quantity;
                            }
                        }
                    }
                }
                $cart->push($cartItem);
            }

            if (!$foundInCart) {
                $cart->push($data);
            }
            $request->session()->put('posCart', $cart);
        }
        else{
            $cart = collect([$data]);
            $request->session()->put('posCart', $cart);
        }


        return view('pos.cart');
    }

    //updated the quantity for a cart item
    public function updateQuantity(Request $request)
    {
        $cart = $request->session()->get('posCart', collect([]));
        // this code is done by shadin
        $i=0;
        foreach($cart as $key=>$cart_in)
        {
            if($i==$request->key)
            {
                $qty_stock_id = $cart_in['id'];
                $qty_stock_variant = $cart_in['variant'];
        
        
                $product_stock = ProductStock::where('product_id',$qty_stock_id);
                if($request->variant)
                {
                    $product_stock = $product_stock->where('variant',$qty_stock_variant)->first();
                }
                else
                {
                    $product_stock = $product_stock->first();
                }
                if($request->quantity> $product_stock->qty)
                {
                    flash('Requested Quantity is too high')->error();
                    return back();
                }
                if($request->quantity==1)
                {
                    $product_stock->qty =   $product_stock->qty-$request->quantity;
        
                }
                else
                {
                    $product_stock->qty =   $product_stock->qty-$request->quantity+1;
        
                }
                // dd($product_stock);
                
                $product_stock->save();
            }


            $i+=1;
        }
 



        $cart = $cart->map(function ($object, $key) use ($request) {
            if($key == $request->key){
                $product = Product::find($object['id']);
                if($object['variant'] != null && $product->variant_product){
                    $product_stock = $product->stocks->where('variant', $object['variant'])->first();
                    $quantity = $product_stock->qty;
                    if($quantity >= $request->quantity){
                        if($request->quantity >= $product->min_qty){
                            $object['quantity'] = $request->quantity;
                        }
                    }
                }
                elseif ($product->current_stock >= $request->quantity) {
                    if($request->quantity >= $product->min_qty){
                        $object['quantity'] = $request->quantity;
                    }
                }
            }
            return $object;
        });
        $request->session()->put('posCart', $cart);

        return view('pos.cart');
    }

    //removes from Cart
    public function removeFromCart(Request $request)
    {
        // dd($request->all());
        if($request->session()->get('posCart', collect([]))){
    
            $cart = $request->session()->get('posCart', collect([]));
            $i=0;
            foreach($cart as $key=>$cart_in)
            {
                if($request->key==$i)
                {
                    $qty_stock_id = $cart_in['id'];
                    $qty_stock_variant = $cart_in['variant'];
                    $qty_cart =  $cart_in['quantity'];
                    $product_stock = ProductStock::where('product_id',$qty_stock_id);
                    if($request->variant)
                    {
                        $product_stock = $product_stock->where('variant',$qty_stock_variant)->first();
                    }
                    else
                    {
                        $product_stock = $product_stock->first();
                    }
        
                    $product_stock->qty =   $product_stock->qty+$request->quantity;
                    $product_stock->save();
                    $cart->forget($request->key);
                    Session::put('posCart', $cart);
                }
                $i+=1;

            }

            
  
        }

        return view('pos.cart');
    }

    //Shipping Address for admin
    public function getShippingAddress(Request $request){
        $user_id = $request->id;
        if($user_id == ''){
            return view('pos.guest_shipping_address');
        }
        else{
            return view('pos.shipping_address', compact('user_id'));
        }
    }

    //Shipping Address for seller
    public function getShippingAddressForSeller(Request $request){
        $user_id = $request->id;
        if($user_id == ''){
            return view('pos.frontend.seller.pos.guest_shipping_address');
        }
        else{
            return view('pos.frontend.seller.pos.shipping_address', compact('user_id'));
        }
    }

    //set Discount
    public function setDiscount(Request $request){
        if($request->discount >= 0){
            Session::put('pos_discount', $request->discount);
        }
        return view('pos.cart');
    }

    //set Shipping Cost
    public function setShipping(Request $request){
        if($request->shipping != null){
            Session::put('shipping', $request->shipping);
        }
        return view('pos.cart');
    }

    //order place
    public function order_store(Request $request)
    {
        // dd($request->all());
        if(Session::has('posCart') && count(Session::get('posCart')) > 0){
            $order = new Order;
            $name = '';
            $email = '';
            $address = '';
            $country = '';
            $city = '';
            $postal_code = '';
            $phone = '';

            if ($request->user_id == null) {
                $order->guest_id    = mt_rand(100000, 999999);
                $name               = $request->name;
                $email              = $request->email;
                $address            = $request->address;
                $country            = $request->country;
                $city               = $request->city;
                $postal_code        = $request->postal_code;
                $phone              = $request->phone;
            }
            else {
                $order->user_id = $request->user_id;
                $user           = User::findOrFail($request->user_id);
                $name   = $user->name;
                $email  = $user->email;

                if($request->shipping_address != null){
                    $address_data   = Address::findOrFail($request->shipping_address);
                    $address        = $address_data->address;
                    $country        = $address_data->country->name;
                    $city           = $address_data->city->name;
                    $postal_code    = $address_data->postal_code;
                    $phone          = $address_data->phone;
                }
            }

            $data['name']           = $name;
            $data['email']          = $email;
            $data['address']        = $address;
            $data['country']        = $country;
            $data['city']           = $city;
            $data['postal_code']    = $postal_code;
            $data['phone']          = $phone;

            $order->shipping_address = json_encode($data);

            $order->payment_type = $request->payment_type;
            $order->delivery_viewed = '0';
            $order->payment_status_viewed = '0';
            $order->code = date('Ymd-His').rand(10,99);
            $order->date = strtotime('now');
            $order->payment_status = 'paid';
            $order->payment_details = $request->payment_type;
            if($order->save()){
                $subtotal = 0;
                $tax = 0;
                $shipping = 0;
                foreach (Session::get('posCart') as $key => $cartItem){
                    $product = Product::find($cartItem['id']);

                    $subtotal += $cartItem['price']*$cartItem['quantity'];
                    $tax += $cartItem['tax']*$cartItem['quantity'];

                    $product_variation = $cartItem['variant'];

                    if($product_variation != null){
                        $product_stock = $product->stocks->where('variant', $product_variation)->first();
                        if($cartItem['quantity'] > $product_stock->qty){
                            $order->delete();
                            return 0;
                        }
                        else {
                            $product_stock->qty -= $cartItem['quantity'];
                            $product_stock->save();
                        }
                    }
                    else {
                        if ($cartItem['quantity'] > $product->current_stock) {
                            $order->delete();
                            return 0;
                        }
                        else {
                            $product->current_stock -= $cartItem['quantity'];
                            $product->save();
                        }
                    }

                    $order_detail = new OrderDetail();
                    $order_detail->order_id  =$order->id;
                    $order_detail->seller_id = $product->user_id;
                    $order_detail->product_id = $product->id;
                    $order_detail->payment_status = 'paid';
                    $order_detail->variation = $product_variation;
                    $order_detail->price = $cartItem['price'] * $cartItem['quantity'];
                    $order_detail->tax = $cartItem['tax'] * $cartItem['quantity'];
                    $order_detail->shipping_type = null;

                    if (Session::get('shipping', 0) == 0){
                        $order_detail->shipping_cost = 0;
                    }
                    else {
                        if($cartItem['shipping'] == null){
                            $order_detail->shipping_cost = 0;
                        }
                        else {
                            $order_detail->shipping_cost = $cartItem['shipping'];
                            $shipping += $cartItem['shipping'];
                        }
                    }

                    $order_detail->quantity = $cartItem['quantity'];
                    $order_detail->save();

                    $product->num_of_sale++;
                    $product->save();
                }

                $order->grand_total = $subtotal + $tax + $shipping;

                if(Session::has('pos_discount')){
                    $order->grand_total -= Session::get('pos_discount');
                    $order->coupon_discount = Session::get('pos_discount');
                }

                $order->save();

                $array['view'] = 'emails.invoice';
                $array['subject'] = 'Your order has been placed - '.$order->code;
                $array['from'] = env('MAIL_USERNAME');
                $array['order'] = $order;

                $admin_products = array();
                $seller_products = array();
                foreach ($order->orderDetails as $key => $orderDetail){
                    if($orderDetail->product->added_by == 'admin'){
                        array_push($admin_products, $orderDetail->product->id);
                    }
                    else{
                        $product_ids = array();
                        if(array_key_exists($orderDetail->product->user_id, $seller_products)){
                            $product_ids = $seller_products[$orderDetail->product->user_id];
                        }
                        array_push($product_ids, $orderDetail->product->id);
                        $seller_products[$orderDetail->product->user_id] = $product_ids;
                    }
                }
                foreach($seller_products as $key => $seller_product){
                    try {
                        Mail::to(User::find($key)->email)->queue(new InvoiceEmailManager($array));
                    } catch (\Exception $e) {
                        
                    }
                }

                //sends email to customer with the invoice pdf attached
                // if(env('MAIL_USERNAME') != null){
                //     try {
                //         Mail::to($request->session()->get('pos_shipping_info')['email'])->queue(new InvoiceEmailManager($array));
                //         Mail::to(User::where('user_type', 'admin')->first()->email)->queue(new InvoiceEmailManager($array));
                //     } catch (\Exception $e) {

                //     }
                // }

                // if($request->user_id != NULL){
                //     if (Addon::where('unique_identifier', 'club_point')->first() != null && Addon::where('unique_identifier', 'club_point')->first()->activated) {
                //         $clubpointController = new ClubPointController;
                //         $clubpointController->processClubPoints($order);
                //     }
                // }

                if (BusinessSetting::where('type', 'category_wise_commission')->first()->value != 1) {
                    $commission_percentage = BusinessSetting::where('type', 'vendor_commission')->first()->value;
                    foreach ($order->orderDetails as $key => $orderDetail) {
                        $orderDetail->payment_status = 'paid';
                        $orderDetail->save();
                        if($orderDetail->product->user->user_type == 'seller'){
                            $seller = $orderDetail->product->user->seller;
                            $seller->admin_to_pay = $seller->admin_to_pay - ($orderDetail->price*$commission_percentage)/100;
                            $seller->save();
                        }
                    }
                }
                else{
                    foreach ($order->orderDetails as $key => $orderDetail) {
                        $orderDetail->payment_status = 'paid';
                        $orderDetail->save();
                        if($orderDetail->product->user->user_type == 'seller'){
                            $commission_percentage = $orderDetail->product->category->commision_rate;
                            $seller = $orderDetail->product->user->seller;
                            $seller->admin_to_pay = $seller->admin_to_pay - ($orderDetail->price*$commission_percentage)/100;
                            $seller->save();
                        }
                    }
                }

                $order->commission_calculated = 1;
                $order->save();

                // $request->session()->put('order_id', $order->id);

                Session::forget('pos_shipping_info');
                Session::forget('shipping');
                Session::forget('pos_discount');
                Session::forget('posCart');
                $request->session()->forget(['posCart', 'pos_discount', 'shipping', 'pos_shipping_info']);
                $request->session()->put('posCart', []);
                // dd($request->session()->all());
                echo 1;exit;
            }
            else {
                echo 0;exit;
            }
        }
        echo 0;exit;
    }

    public function pos_activation()
    {
        $pos_activation = BusinessSetting::where('type', 'pos_activation_for_seller')->first();
        return view('pos.pos_activation', compact('pos_activation'));
    }
}

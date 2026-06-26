<?php



namespace App\Modules\Shop\Services;

use App\Models\Safe;
use App\Models\Shop;

class ShopService{

    public function create($data){
        
      
        $shop = Shop::create($data);
        $safe = Safe::create([
            'shop_id' => $shop->id,
            'balance' => 0
        ]);

        return $shop;
    }

    public function update($data){
            
         $shop = Shop::find($data['id']);
         $shop->update($data);
    
         return $shop;
    }


}
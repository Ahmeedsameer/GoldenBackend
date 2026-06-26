<?php  

namespace App\Http\Services;

use App\Models\User;
use DB;
use Hash;


class UserManagmentService{

    public function createUser(array $data){
       
        $data['password'] = Hash::make($data['password']);  
        $user = User::create($data);

        return $user;
      

    }
}

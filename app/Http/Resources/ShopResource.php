<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShopResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'address'         => $this->address,
            'username'        => $this->username,
            'status'          => $this->status,
            'employees_count' => $this->whenCounted('employees'),
            'manager'         => $this->whenLoaded('manager', fn() => [
                'id'    => $this->manager->id,
                'name'  => $this->manager->name,
                'email' => $this->manager->email,
                'role'  => $this->manager->role,
            ]),
            'created_at'      => $this->created_at,
            'updated_at'      => $this->updated_at,
        ];
    }
}

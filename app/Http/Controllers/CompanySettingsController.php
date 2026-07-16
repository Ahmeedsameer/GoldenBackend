<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCompanySettingsRequest;
use App\Models\CompanySetting;
use Illuminate\Support\Facades\Storage;

class CompanySettingsController extends Controller
{
    /**
     * Public — no auth required. The login page and the app-init fetch both
     * need company branding before a user is authenticated.
     */
    public function show()
    {
        return response()->json([
            'message' => 'ok',
            'data'    => CompanySetting::current(),
        ]);
    }

    public function update(UpdateCompanySettingsRequest $request)
    {
        $company = CompanySetting::current();
        $data = $request->safe()->except('logo');

        if ($request->hasFile('logo')) {
            if ($company->logo_path) {
                Storage::disk('public')->delete($company->logo_path);
            }
            $data['logo_path'] = $request->file('logo')->store('company', 'public');
        }

        $company->update($data);

        return response()->json([
            'message' => 'تم تحديث بيانات الشركة بنجاح',
            'data'    => $company->fresh(),
        ]);
    }
}

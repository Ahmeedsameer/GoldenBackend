<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCompanySettingsRequest;
use App\Models\CompanySetting;
use App\Models\SocialChannel;
use Illuminate\Support\Facades\Storage;

class CompanySettingsController extends Controller
{
    /**
     * Public — no auth required. The login page, the app-init fetch, and the
     * Landing Page all need company branding + active social channels before
     * a user is authenticated. Reuses this single endpoint rather than
     * adding a second public route for social channels.
     */
    public function show()
    {
        $company = CompanySetting::current();
        $company->setAttribute('social_channels', SocialChannel::enabled()->get(['platform', 'label', 'value']));

        return response()->json([
            'message' => 'ok',
            'data'    => $company,
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

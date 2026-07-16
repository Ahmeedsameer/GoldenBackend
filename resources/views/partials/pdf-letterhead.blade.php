{{-- Shared PDF letterhead: logo + company name + contact details.
     Expects $company (App\Models\CompanySetting, for the logo path only) and
     $companyDisplay (pre-shaped display strings — see ArabicShaper::companyDisplay). --}}
<div class="pdf-letterhead">
    @if ($company->logoAbsolutePath())
        <img src="{{ $company->logoAbsolutePath() }}" alt="logo" class="pdf-letterhead-logo">
    @endif
    <div class="pdf-letterhead-info">
        <div class="pdf-letterhead-name">{{ $companyDisplay->name }}</div>
        @if ($companyDisplay->contactLine)
            <div class="pdf-letterhead-contact">{{ $companyDisplay->contactLine }}</div>
        @endif
    </div>
</div>

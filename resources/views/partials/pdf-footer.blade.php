{{-- Shared PDF footer: company name + contact, rendered as plain HTML so
     dompdf's bidi-aware layout engine handles the Arabic correctly (unlike
     the raw canvas page-number badge drawn separately by PdfPageFooter).
     Expects $companyDisplay (pre-shaped — see ArabicShaper::companyDisplay)
     and $company (App\Models\CompanySetting, for phone/email only — both
     pure Latin/digits, no shaping needed). --}}
@php
    $footerContact = array_filter([$company->phone, $company->email]);
@endphp
<div class="pdf-footer">
    <span class="pdf-footer-co">{{ $companyDisplay->name }}</span>
    @if (count($footerContact))
        <span class="pdf-footer-contact">{{ implode(' · ', $footerContact) }}</span>
    @endif
</div>

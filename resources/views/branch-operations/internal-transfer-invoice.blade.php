<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: 'Tajawal', 'DejaVu Sans', sans-serif; }
        body { direction: rtl; color: #1f2937; font-size: 11px; margin-bottom: 40px; }
        h1 { font-size: 17px; margin: 0 0 2px; }
        .internal-note {
            background: #FEF3C7; color: #92400E; border: 1px solid #FDE68A;
            border-radius: 4px; padding: 5px 10px; font-size: 9px; font-weight: bold;
            text-align: center; margin: 8px 0 14px;
        }
        .invoice-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
        .invoice-head-meta { color: #6b7280; font-size: 10px; }
        .qr-block { text-align: center; }
        .qr-block img { width: 90px; height: 90px; }
        .qr-block .invoice-number { font-size: 9px; color: #6b7280; margin-top: 2px; }

        .meta-grid { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        .meta-grid td { padding: 6px 8px; border: 1px solid #e5e7eb; font-size: 10px; }
        .meta-grid .label { background: #f9fafb; color: #6b7280; font-weight: bold; width: 18%; }

        table.items { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.items th, table.items td { border: 1px solid #d1d5db; padding: 5px 7px; text-align: center; font-size: 10px; }
        table.items th { background: #f3f4f6; font-weight: bold; }
        table.items td.product-cell { text-align: right; }
        table.items tr:nth-child(even) td { background: #fafafa; }

        .totals { width: 40%; margin-inline-start: auto; border-collapse: collapse; margin-bottom: 20px; }
        .totals td { padding: 5px 8px; font-size: 10px; border: 1px solid #e5e7eb; }
        .totals .label { background: #f9fafb; color: #6b7280; font-weight: bold; }

        .signatures { width: 100%; border-collapse: collapse; margin-top: 30px; }
        .signatures td { width: 50%; padding: 10px; vertical-align: top; }
        .sig-box { border-top: 1px solid #9ca3af; padding-top: 6px; margin-top: 30px; font-size: 9px; color: #6b7280; }
        .sig-name { font-size: 10px; color: #1f2937; font-weight: bold; margin-bottom: 2px; }

        @include('partials.pdf-branding-css')
    </style>
</head>
<body>
    @include('partials.pdf-letterhead')

    <div class="internal-note">{{ $internalOnlyNote }}</div>

    <div class="invoice-head">
        <div>
            <h1>{{ $title }}</h1>
            <div class="invoice-head-meta">{{ $invoice->invoice_number }} &middot; {{ $generatedAt }}</div>
        </div>
        <div class="qr-block">
            <img src="{{ $qrDataUri }}" alt="QR">
            <div class="invoice-number">{{ $invoice->invoice_number }}</div>
        </div>
    </div>

    <table class="meta-grid">
        <tr>
            <td class="label">رقم طلب النقل</td>
            <td>{{ $transfer->request_number }}</td>
            <td class="label">الحالة</td>
            <td>{{ $statusLabel }}</td>
        </tr>
        <tr>
            <td class="label">نوع النقل</td>
            <td>{{ $transferType }}</td>
            <td class="label">الأولوية</td>
            <td>{{ $transfer->priority }}</td>
        </tr>
        <tr>
            <td class="label">من فرع</td>
            <td>{{ $sourceShopName }}</td>
            <td class="label">إلى فرع</td>
            <td>{{ $destinationShopName }}</td>
        </tr>
        <tr>
            <td class="label">طلب بواسطة</td>
            <td>{{ $requestedByName }}</td>
            <td class="label">اعتمد بواسطة</td>
            <td>{{ $approvedByName }}</td>
        </tr>
        <tr>
            <td class="label">شحن بواسطة</td>
            <td>{{ $shippedByName }}</td>
            <td class="label">استلم بواسطة</td>
            <td>{{ $receivedByName }}</td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>مفقود</th>
                <th>تالف</th>
                <th>الكمية المستلمة</th>
                <th>الكمية المشحونة</th>
                <th>الكمية المعتمدة</th>
                <th>الكمية المطلوبة</th>
                <th>تكلفة الوحدة</th>
                <th>الوحدة</th>
                <th class="product-cell">المنتج</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($items as $item)
                <tr>
                    <td>{{ $item['missing_quantity'] ?? '—' }}</td>
                    <td>{{ $item['damaged_quantity'] ?? '—' }}</td>
                    <td>{{ $item['received_quantity'] ?? '—' }}</td>
                    <td>{{ $item['shipped_quantity'] }}</td>
                    <td>{{ $item['approved_quantity'] ?? '—' }}</td>
                    <td>{{ $item['requested_quantity'] }}</td>
                    <td>{{ number_format($item['unit_cost'], 2) }}</td>
                    <td>{{ $item['unit'] }}</td>
                    <td class="product-cell">{{ $item['product_name'] }} ({{ $item['sku'] }})</td>
                </tr>
            @empty
                <tr><td colspan="9">لا توجد أصناف</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="label">القيمة المرجعية</td>
            <td>{{ number_format((float) $invoice->reference_value, 2) }} ج.م</td>
        </tr>
        @if ($notes)
            <tr>
                <td class="label">ملاحظات</td>
                <td>{{ $notes }}</td>
            </tr>
        @endif
    </table>

    <table class="signatures">
        <tr>
            <td>
                <div class="sig-box">
                    <div class="sig-name">{{ $shippedByName }}</div>
                    توقيع المُسلّم
                </div>
            </td>
            <td>
                <div class="sig-box">
                    <div class="sig-name">{{ $receivedByName }}</div>
                    توقيع المستلم
                </div>
            </td>
        </tr>
    </table>

    @include('partials.pdf-footer')
</body>
</html>

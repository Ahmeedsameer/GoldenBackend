<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: 'Tajawal', 'DejaVu Sans', sans-serif; }
        body { direction: rtl; color: #1f2937; font-size: 9px; margin-bottom: 40px; }
        h1 { font-size: 14px; margin: 0 0 4px; }
        .meta { color: #6b7280; font-size: 9px; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #d1d5db; padding: 4px 5px; text-align: center; vertical-align: top; }
        th { background: #f3f4f6; font-weight: bold; }
        td.emp { text-align: right; white-space: nowrap; }
        .type { font-weight: bold; display: block; }
        .sub { color: #6b7280; font-size: 8px; display: block; }
        @include('partials.pdf-branding-css')
    </style>
</head>
<body>
    @include('partials.pdf-letterhead')
    <h1>{{ ar('الجدول الأسبوعي') }}</h1>
    <div class="meta">{{ $metaLine }}</div>
    <table>
        <thead>
            <tr>
                {{-- dompdf lays out table columns strictly left-to-right regardless of
                     dir="rtl" — days are already reversed by the controller, and the
                     employee column is emitted LAST here, so LTR placement produces the
                     correct RTL visual order (employee name rightmost, days flowing left). --}}
                @foreach ($roster['days'] as $d)
                    <th>{{ $d }}</th>
                @endforeach
                <th>{{ ar('الموظف') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($roster['employees'] as $emp)
                <tr>
                    @foreach ($emp['cells'] as $cell)
                        <td>
                            @if ($cell['type'])
                                <span class="type">{{ $typeLabels[$cell['type']] ?? $cell['type'] }}</span>
                                @if ($cell['type'] === 'transferred')
                                    <span class="sub">{{ $cell['transfer']['branch_name'] ?? '' }}</span>
                                @elseif ($cell['type'] === 'work' && !empty($cell['shift']))
                                    <span class="sub">{{ $cell['shift']['name'] }}</span>
                                    <span class="sub">{{ $cell['shift']['start_time'] }}–{{ $cell['shift']['end_time'] }}</span>
                                @endif
                            @endif
                        </td>
                    @endforeach
                    <td class="emp">{{ $emp['name'] }}<br><span class="sub">{{ $emp['primary_branch'] ?? '-' }}</span></td>
                </tr>
            @empty
                <tr><td colspan="{{ count($roster['days']) + 1 }}">{{ ar('لا يوجد موظفون') }}</td></tr>
            @endforelse
        </tbody>
    </table>
    @include('partials.pdf-footer')
</body>
</html>

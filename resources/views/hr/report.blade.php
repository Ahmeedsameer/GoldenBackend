<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: 'Tajawal', 'DejaVu Sans', sans-serif; }
        body { direction: rtl; color: #1f2937; font-size: 11px; }
        h1 { font-size: 15px; margin: 0 0 4px; }
        .meta { color: #6b7280; font-size: 10px; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #d1d5db; padding: 5px 7px; text-align: right; }
        th { background: #f3f4f6; font-weight: bold; }
        tr:nth-child(even) td { background: #fafafa; }
    </style>
</head>
<body>
    <h1>{{ $report['title'] }}</h1>
    <div class="meta">Alpha Business — الموارد البشرية · {{ now()->format('Y-m-d H:i') }}</div>
    <table>
        <thead>
            <tr>
                @foreach ($report['columns'] as $col)
                    <th>{{ $col }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($report['rows'] as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($report['columns']) ?: 1 }}">لا توجد بيانات</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>

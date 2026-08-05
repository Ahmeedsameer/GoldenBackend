<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Customer Tags catalog — both automatic (fixed, seeded) and manual
 * (Admin/Manager-created) definitions live in the same `tags` table (see
 * CustomerTagService for how automatic tags are evaluated/assigned; this
 * controller only manages the definitions list + manual creation).
 */
class TagController extends Controller
{
    /** GET /api/tags — every tag definition, read-only for all 3 roles (needed to render badges anywhere). */
    public function index()
    {
        return response()->json(['message' => 'ok', 'data' => Tag::orderBy('type')->orderBy('name')->get()]);
    }

    /** POST /api/tags { name, color } — Admin/Manager only (route-gated). Always creates a 'manual' tag. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'  => 'required|string|max:100',
            'color' => 'required|string|in:primary,success,error,warning,info,light,dark',
        ]);

        $slug = Str::slug($data['name']);
        $unique = $slug;
        $i = 1;
        while (Tag::where('slug', $unique)->exists()) {
            $unique = $slug . '-' . $i++;
        }

        $tag = Tag::create([
            'name'       => $data['name'],
            'slug'       => $unique,
            'color'      => $data['color'],
            'type'       => 'manual',
            'created_by' => auth()->id(),
        ]);

        return response()->json(['message' => 'تم إنشاء الوسم بنجاح', 'data' => $tag], 201);
    }
}

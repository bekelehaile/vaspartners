<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use App\Models\Faq;
use App\Models\GalleryItem;

class WebsiteContentController extends Controller
{
    public function faqs()
    {
        $rows = Faq::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'question', 'answer', 'sort_order']);

        return response()->json(['data' => $rows]);
    }

    public function blogPosts()
    {
        $rows = BlogPost::query()
            ->published()
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get([
                'id',
                'title',
                'slug',
                'excerpt',
                'cover_image',
                'is_featured',
                'published_at',
                'sort_order',
            ]);

        return response()->json(['data' => $rows]);
    }

    public function blogPost(string $slug)
    {
        $post = BlogPost::query()
            ->published()
            ->where('slug', $slug)
            ->firstOrFail();

        return response()->json(['data' => $post]);
    }

    public function gallery()
    {
        $rows = GalleryItem::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get([
                'id',
                'title',
                'caption',
                'image',
                'alt_text',
                'album',
                'sort_order',
            ]);

        return response()->json(['data' => $rows]);
    }
}

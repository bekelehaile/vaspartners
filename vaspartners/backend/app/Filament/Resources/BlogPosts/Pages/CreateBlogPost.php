<?php

namespace App\Filament\Resources\BlogPosts\Pages;

use App\Filament\Resources\BlogPosts\BlogPostResource;
use App\Models\BlogPost;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateBlogPost extends CreateRecord
{
    protected static string $resource = BlogPostResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $base = Str::slug((string) ($data['title'] ?? 'post')) ?: 'post';
        $slug = $base;
        $i = 1;
        while (BlogPost::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i;
            $i++;
        }
        $data['slug'] = $slug;

        if (! empty($data['is_published']) && empty($data['published_at'])) {
            $data['published_at'] = now();
        }

        return $data;
    }
}

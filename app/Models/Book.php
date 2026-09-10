<?php

namespace App\Models;

use Database\Factories\BookFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['uuid', 'bucket', 'object_key', 'category_id', 'title', 'author', 'isbn', 'description', 'published_year', 'cover_url', 'total_copies', 'available_copies', 'file_path', 'original_filename', 'mime_type', 'file_size', 'is_starred', 'last_opened_at'])]
class Book extends Model
{
    /** @use HasFactory<BookFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Book $book): void {
            $book->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function storageKey(): ?string
    {
        return $this->object_key ?: $this->file_path;
    }

    protected function casts(): array
    {
        return [
            'published_year' => 'integer',
            'file_size' => 'integer',
            'is_starred' => 'boolean',
            'last_opened_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        return $query->where(function (Builder $query) use ($user): void {
            $query->whereNull('desktop_user_id');
            if ($user) {
                $query->orWhere('desktop_user_id', $user->id);
            }
        });
    }

    public function authorizeDesktopOwner(?User $user): void
    {
        abort_if($this->desktop_user_id !== null && (int) $this->desktop_user_id !== $user?->id, 404);
    }
}

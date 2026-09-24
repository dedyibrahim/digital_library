<?php

namespace App\Models;

use App\Jobs\IndexDocument;
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

    protected $hidden = ['search_text', 'search_version'];

    protected static function booted(): void
    {
        static::creating(function (Book $book): void {
            $book->uuid ??= (string) Str::uuid();
        });
        static::saving(function (Book $book): void {
            if ($book->isDirty(['object_key', 'file_path'])) {
                $book->search_text = null;
                $book->search_indexed_at = null;
                $book->search_status = $book->storageKey() ? 'pending' : 'unsupported';
                $book->search_version = (string) Str::uuid();
            }
        });
        static::saved(function (Book $book): void {
            if ($book->isDirty('search_version') && $book->storageKey()) {
                IndexDocument::dispatch($book->id, $book->search_version)
                    ->onConnection('document-indexing')->onQueue('indexing')->afterCommit();
            }
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

    public function scopeMatchingContent(Builder $query, string $search): Builder
    {
        $query->whereIn('search_status', ['ready', 'partial']);
        if ($query->getConnection()->getDriverName() === 'pgsql') {
            return $query->whereRaw("to_tsvector('simple', coalesce(search_text, '')) @@ plainto_tsquery('simple', ?)", [$search]);
        }
        foreach (preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $query->whereLike('search_text', '%'.$word.'%');
        }

        return $query;
    }
}

<?php

namespace App\Models;

use App\Enums\DocumentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'reservation_id',
    'user_id',
    'document_type',
    'file_name',
    'file_path',
    'mime_type',
    'file_size',
    'checksum',
    'generation_mode',
    'generation_context',
    'template_key',
    'template_version',
    'generated_at',
    'is_official',
    'version',
    'is_available',
])]
class Document extends Model
{
    use HasFactory;

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function notificationDeliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }

    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'file_size' => 'integer',
            'generation_context' => 'array',
            'generated_at' => 'datetime',
            'is_official' => 'boolean',
            'version' => 'integer',
            'is_available' => 'boolean',
        ];
    }
}

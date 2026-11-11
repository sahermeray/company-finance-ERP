<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Attachment extends Model
{
    use SoftDeletes, TenantScoped;

    protected $fillable = [
        'company_id',
        'uploaded_by',
        'disk',
        'path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'checksum',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function parties(): MorphToMany
    {
        return $this->morphedByMany(Party::class, 'attachable', 'attachmentables')->using(Attachmentable::class)->withTimestamps();
    }

    public function products(): MorphToMany
    {
        return $this->morphedByMany(Product::class, 'attachable', 'attachmentables')->using(Attachmentable::class)->withTimestamps();
    }

    public function warehouses(): MorphToMany
    {
        return $this->morphedByMany(Warehouse::class, 'attachable', 'attachmentables')->using(Attachmentable::class)->withTimestamps();
    }

    public function projects(): MorphToMany
    {
        return $this->morphedByMany(Project::class, 'attachable', 'attachmentables')->using(Attachmentable::class)->withTimestamps();
    }

    public function purchases(): MorphToMany
    {
        return $this->morphedByMany(Purchase::class, 'attachable', 'attachmentables')->using(Attachmentable::class)->withTimestamps();
    }

    public function revenues(): MorphToMany
    {
        return $this->morphedByMany(Revenue::class, 'attachable', 'attachmentables')->using(Attachmentable::class)->withTimestamps();
    }

    public function expenses(): MorphToMany
    {
        return $this->morphedByMany(Expense::class, 'attachable', 'attachmentables')->using(Attachmentable::class)->withTimestamps();
    }

    public function payments(): MorphToMany
    {
        return $this->morphedByMany(Payment::class, 'attachable', 'attachmentables')->using(Attachmentable::class)->withTimestamps();
    }

    public function stockMovements(): MorphToMany
    {
        return $this->morphedByMany(StockMovement::class, 'attachable', 'attachmentables')->using(Attachmentable::class)->withTimestamps();
    }

    public function accountingJournals(): MorphToMany
    {
        return $this->morphedByMany(AccountingJournal::class, 'attachable', 'attachmentables')->using(Attachmentable::class)->withTimestamps();
    }
}

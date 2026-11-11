<?php

namespace App\Models;

use App\Eloquent\CompanyScope;
use App\Services\CrossCompanyValidationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Validation\ValidationException;

class Attachmentable extends MorphPivot
{
    protected static function booted(): void
    {
        static::saving(function (self $pivot): void {
            $type = $pivot->getAttribute('attachable_type');
            $class = is_string($type) ? (Relation::getMorphedModel($type) ?? $type) : null;

            if ($class === null || ! is_subclass_of($class, Model::class)) {
                throw ValidationException::withMessages([
                    'attachable_type' => 'The selected record type is invalid.',
                ]);
            }

            $attachable = $class::withoutGlobalScope(CompanyScope::class)
                ->find($pivot->getAttribute('attachable_id'));
            $attachment = Attachment::withoutGlobalScope(CompanyScope::class)
                ->find($pivot->getAttribute('attachment_id'));

            if ($attachable === null || $attachment === null) {
                throw ValidationException::withMessages([
                    'attachment_id' => 'The attachment and record must both exist.',
                ]);
            }

            app(CrossCompanyValidationService::class)
                ->assertSameCompany($attachable, $attachment, 'attachment_id');
        });
    }
}

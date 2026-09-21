<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\Organizations\Models\Organization
 */
class OrganizationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status->value,
            'billing_email' => $this->billing_email,
            'tax_id' => $this->tax_id,
            'country' => $this->country,
            'timezone' => $this->timezone,
            'locale' => $this->locale,
            'is_owner' => $request->user()?->id === $this->owner_user_id,
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'membership' => $this->whenPivotLoaded('organization_user', fn () => [
                'status' => $this->pivot->status,
                'all_brands_access' => (bool) $this->pivot->all_brands_access,
                'joined_at' => $this->pivot->joined_at,
            ]),
            'roles' => $this->when(isset($this->current_roles), fn () => $this->current_roles),
        ];
    }
}

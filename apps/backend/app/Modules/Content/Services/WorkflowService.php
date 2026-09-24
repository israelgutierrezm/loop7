<?php

declare(strict_types=1);

namespace App\Modules\Content\Services;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Entitlements\Entitlement;
use App\Modules\Billing\Exceptions\PlanLimitExceededException;
use App\Modules\Billing\Services\EntitlementsService;
use App\Modules\Content\Enums\ContentStatus;
use App\Modules\Content\Models\ApprovalRequest;
use App\Modules\Content\Models\ContentComment;
use App\Modules\Content\Models\ContentItem;
use App\Modules\Organizations\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkflowService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PublicationPlanner $planner,
        private readonly EntitlementsService $entitlements,
    ) {
    }

    public function submit(ContentItem $content, User $user): void
    {
        if (! $this->approvalsEnabled($content)) {
            throw new PlanLimitExceededException(
                'Los flujos de aprobación no están incluidos en tu plan: puedes aprobar el contenido directamente.',
                Entitlement::FEATURE_APPROVALS,
            );
        }

        $this->assertStatusIn($content, [
            ContentStatus::IDEA, ContentStatus::DRAFT, ContentStatus::CHANGES_REQUESTED,
        ], 'Sólo se puede enviar a revisión un borrador.');

        DB::transaction(function () use ($content, $user): void {
            $content->update(['status' => ContentStatus::IN_REVIEW->value]);
            ApprovalRequest::query()->create([
                'organization_id' => $content->organization_id,
                'content_item_id' => $content->id,
                'requested_by_user_id' => $user->id,
                'status' => 'pending',
            ]);
            $this->audit->log(AuditAction::CONTENT_SUBMITTED, $content);
        });
    }

    public function approve(ContentItem $content, User $user): void
    {
        // Sin flujos de aprobación en el plan, se aprueba directamente desde el borrador.
        $this->assertStatusIn(
            $content,
            $this->approvalsEnabled($content)
                ? [ContentStatus::IN_REVIEW]
                : [ContentStatus::IDEA, ContentStatus::DRAFT, ContentStatus::CHANGES_REQUESTED, ContentStatus::IN_REVIEW],
            'El contenido no está en revisión.',
        );

        DB::transaction(function () use ($content, $user): void {
            $content->update([
                'status' => ContentStatus::APPROVED->value,
                'approved_by_user_id' => $user->id,
                'approved_at' => now(),
            ]);
            $this->resolvePendingRequest($content, $user, 'approved');
            $this->audit->log(AuditAction::CONTENT_APPROVED, $content);
        });
    }

    public function requestChanges(ContentItem $content, User $user, string $note): void
    {
        $this->assertStatusIn($content, [ContentStatus::IN_REVIEW], 'El contenido no está en revisión.');

        DB::transaction(function () use ($content, $user, $note): void {
            $content->update(['status' => ContentStatus::CHANGES_REQUESTED->value]);
            $this->resolvePendingRequest($content, $user, 'changes_requested', $note);
            $this->comment($content, $user, $note);
            $this->audit->log(AuditAction::CONTENT_CHANGES_REQUESTED, $content, ['note' => $note]);
        });
    }

    public function comment(ContentItem $content, User $user, string $body): ContentComment
    {
        return ContentComment::query()->create([
            'organization_id' => $content->organization_id,
            'content_item_id' => $content->id,
            'user_id' => $user->id,
            'body' => $body,
        ]);
    }

    public function schedule(ContentItem $content, Carbon $when, User $user): void
    {
        $this->assertStatusIn(
            $content,
            [ContentStatus::APPROVED, ContentStatus::SCHEDULED],
            'El contenido debe estar aprobado para programarse.',
        );
        $this->planner->assertPublishable($content);

        DB::transaction(function () use ($content, $when): void {
            $content->update([
                'status' => ContentStatus::SCHEDULED->value,
                'scheduled_at' => $when,
            ]);

            $this->planner->createTargets($content, $when);

            $this->audit->log(AuditAction::CONTENT_SCHEDULED, $content, ['scheduled_at' => $when->toIso8601String()]);
        });
    }

    private function approvalsEnabled(ContentItem $content): bool
    {
        $organization = Organization::query()->find($content->organization_id);

        return $organization !== null && $this->entitlements->allows($organization, Entitlement::FEATURE_APPROVALS);
    }

    private function resolvePendingRequest(ContentItem $content, User $user, string $status, ?string $note = null): void
    {
        ApprovalRequest::query()
            ->where('content_item_id', $content->id)
            ->where('status', 'pending')
            ->latest()
            ->first()
            ?->update([
                'status' => $status,
                'resolved_by_user_id' => $user->id,
                'resolved_at' => now(),
                'note' => $note,
            ]);
    }

    /**
     * @param  list<ContentStatus>  $allowed
     */
    private function assertStatusIn(ContentItem $content, array $allowed, string $message): void
    {
        if (! in_array($content->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }
}

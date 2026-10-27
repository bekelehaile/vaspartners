<?php

namespace App\Services\Provisioning;

use App\Enums\ProvisioningStepStatus;
use App\Models\AppSetting;
use App\Models\Contact;
use App\Models\ServiceProvisioning;
use App\Models\ServiceProvisioningStep;
use App\Models\User;
use App\Services\PartnerNotificationService;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class ProvisioningNotificationService
{
    public function __construct(
        protected PartnerNotificationService $partnerNotifications,
    ) {}

    public function notifyHandoff(ServiceProvisioning $case): void
    {
        $waiting = $case->steps
            ->filter(fn (ServiceProvisioningStep $s) => $s->status === ProvisioningStepStatus::Waiting);

        foreach ($waiting as $step) {
            $this->notifyStepWaiting($case, $step);
        }
    }

    public function notifyGoLive(ServiceProvisioning $case): void
    {
        $this->notifyPartners($case, 'provisioning.go_live', $this->partnerPlaceholders($case, [
            'step' => 'Go Live',
        ]));
    }

    /**
     * Partner credentials SMS — only after VAS Sales / AM completes Share credentials.
     */
    public function notifyCredentialsReady(ServiceProvisioning $case): void
    {
        $this->notifyPartners($case, 'provisioning.credentials_ready', $this->partnerPlaceholders($case, [
            'short_code' => (string) (($case->shared_payload ?? [])['short_code'] ?? ''),
            'step' => 'Credentials shared',
        ]));
    }

    protected function notifyStepWaiting(ServiceProvisioning $case, ServiceProvisioningStep $step): void
    {
        $placeholders = $this->partnerPlaceholders($case, [
            'short_code' => (string) (($case->shared_payload ?? [])['short_code'] ?? ''),
            'step' => $step->label,
            'from' => $step->from_department?->label() ?? '',
            'to' => $step->to_department?->label() ?? '',
        ]);

        $from = $step->from_department?->value;

        // Actor is always the FROM department. Do not SMS the partner just because
        // the next handoff targets them (e.g. Share credentials waiting on VAS Sales).
        if ($from === 'partner') {
            $this->notifyPartners($case, 'provisioning.step_waiting_partner', $placeholders);

            return;
        }

        if ($from && $from !== 'partner') {
            $this->notifyStaffRole($from, 'provisioning.step_waiting_staff', $placeholders);
        }
    }

    /**
     * @param  array<string, scalar|null>  $extra
     * @return array<string, scalar|null>
     */
    protected function partnerPlaceholders(ServiceProvisioning $case, array $extra = []): array
    {
        $case->loadMissing(['subscription.contact', 'company', 'service']);
        $contact = $case->subscription?->contact;
        $portalPath = '/portal/provisioning/'.$case->public_id;
        $portalBase = rtrim((string) config('vas.frontend_url', config('app.frontend_url', config('app.url'))), '/');
        $portalUrl = $portalBase !== '' ? $portalBase.$portalPath : $portalPath;

        return array_merge([
            'prv_number' => $case->public_id,
            'company' => $case->company?->name ?? '',
            'company_name' => $case->company?->name ?? '',
            'service' => $case->service?->name ?? '',
            'contact_name' => $contact?->name ?: 'Partner',
            'portal_url' => $portalUrl,
        ], $extra);
    }

    /**
     * @param  array<string, scalar|null>  $placeholders
     */
    protected function notifyPartners(ServiceProvisioning $case, string $templateKey, array $placeholders): void
    {
        try {
            $body = $this->render($templateKey, $placeholders);
            $case->loadMissing(['subscription.contact', 'company']);
            $contact = $case->subscription?->contact;
            if ($contact instanceof Contact) {
                // Reuse partner SMS path via a lightweight direct send if available.
                $this->partnerNotifications->notifyProvisioningTemplate(
                    $contact,
                    $case,
                    $templateKey,
                    $body,
                    $placeholders,
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Provisioning partner notify failed', [
                'prv' => $case->public_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, scalar|null>  $placeholders
     */
    protected function notifyStaffRole(?string $role, string $templateKey, array $placeholders): void
    {
        if (! filled($role)) {
            return;
        }

        try {
            $body = $this->render($templateKey, $placeholders);
            $users = User::role($role)->where('is_active', true)->get();
            foreach ($users as $user) {
                Notification::make()
                    ->title($placeholders['prv_number'].' — action needed')
                    ->body($body)
                    ->sendToDatabase($user);
            }
        } catch (\Throwable $e) {
            Log::warning('Provisioning staff notify failed', [
                'role' => $role,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, scalar|null>  $placeholders
     */
    protected function render(string $templateKey, array $placeholders): string
    {
        $provisioningTemplates = config('notifications.provisioning', []);
        $template = match ($templateKey) {
            'provisioning.go_live' => AppSetting::smsTemplateGoLive(),
            'provisioning.credentials_ready' => AppSetting::smsTemplateCredentialsReady(),
            default => (string) (is_array($provisioningTemplates)
                ? ($provisioningTemplates[$templateKey] ?? '[{prv_number}] {step}: {from} → {to}. {company} / {service}.')
                : '[{prv_number}] {step}: {from} → {to}. {company} / {service}.'),
        };

        $out = $template;
        foreach ($placeholders as $key => $value) {
            $out = str_replace('{'.$key.'}', (string) ($value ?? ''), $out);
        }

        return trim(preg_replace('/\s+/', ' ', $out) ?? $out);
    }
}

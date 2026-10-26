<?php

namespace App\Services\Provisioning;

use App\Enums\ProvisioningStepStatus;
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
        $this->notifyPartners($case, 'provisioning.go_live', [
            'prv_number' => $case->public_id,
            'company' => $case->company?->name,
            'service' => $case->service?->name,
            'step' => 'Go Live',
        ]);
    }

    protected function notifyStepWaiting(ServiceProvisioning $case, ServiceProvisioningStep $step): void
    {
        $placeholders = [
            'prv_number' => $case->public_id,
            'company' => $case->company?->name ?? '',
            'service' => $case->service?->name ?? '',
            'short_code' => (string) (($case->shared_payload ?? [])['short_code'] ?? ''),
            'step' => $step->label,
            'from' => $step->from_department?->label() ?? '',
            'to' => $step->to_department?->label() ?? '',
        ];

        $to = $step->to_department?->value;

        if ($to === 'partner') {
            $this->notifyPartners($case, 'provisioning.step_waiting_partner', $placeholders);

            return;
        }

        $this->notifyStaffRole($to, 'provisioning.step_waiting_staff', $placeholders);
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
        $templates = config('notifications.provisioning', []);
        $template = $templates[$templateKey]
            ?? '[{prv_number}] {step}: {from} → {to}. {company} / {service}.';

        $out = $template;
        foreach ($placeholders as $key => $value) {
            $out = str_replace('{'.$key.'}', (string) ($value ?? ''), $out);
        }

        return trim(preg_replace('/\s+/', ' ', $out) ?? $out);
    }
}

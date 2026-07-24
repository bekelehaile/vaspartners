<?php

namespace App\Services\Migration;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\ServiceFinalApprover;
use App\Models\Subscription;
use App\Models\Ticket;
use App\Models\TicketDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Wipe previously migrated MVAS dump data so migration can be re-run cleanly.
 */
class MvasDumpClearService
{
    /**
     * @param  array{
     *   dry_run?: bool,
     *   clear_approvers?: bool,
     *   clear_attachment_files?: bool
     * }  $options
     * @return array<string, int|bool>
     */
    public function clear(array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $clearApprovers = (bool) ($options['clear_approvers'] ?? true);
        $clearFiles = (bool) ($options['clear_attachment_files'] ?? true);

        $stats = [
            'ticket_documents' => 0,
            'attachment_files_removed' => 0,
            'tickets' => 0,
            'subscriptions' => 0,
            'memberships' => 0,
            'customers' => 0,
            'companies' => 0,
            'approvers' => 0,
            'dry_run' => $dryRun,
        ];

        $companyIds = Company::query()->whereNotNull('legacy_mvas_client_id')->pluck('id');
        $customerIds = Customer::query()->whereNotNull('legacy_mvas_client_id')->pluck('id');
        $ticketIds = Ticket::withTrashed()
            ->whereNotNull('legacy_mvas_ticket_id')
            ->pluck('id');

        if ($customerIds->isNotEmpty()) {
            $ticketIds = $ticketIds->merge(
                Ticket::withTrashed()->whereIn('customer_id', $customerIds)->pluck('id')
            )->unique()->values();
        }

        $documents = TicketDocument::withTrashed()
            ->where(function ($q) use ($ticketIds) {
                $q->whereNotNull('legacy_mvas_file_id');
                if ($ticketIds->isNotEmpty()) {
                    $q->orWhereIn('ticket_id', $ticketIds);
                }
            })
            ->get(['id', 'disk', 'path', 'legacy_mvas_file_id']);

        $stats['ticket_documents'] = $documents->count();

        if (! $dryRun && $clearFiles) {
            foreach ($documents as $doc) {
                if (! filled($doc->path)) {
                    continue;
                }
                try {
                    $disk = $doc->disk ?: 'local';
                    if (Storage::disk($disk)->exists($doc->path)) {
                        Storage::disk($disk)->delete($doc->path);
                        $stats['attachment_files_removed']++;
                    } else {
                        $abs = storage_path('app/private/'.ltrim((string) $doc->path, '/'));
                        if (is_file($abs)) {
                            File::delete($abs);
                            $stats['attachment_files_removed']++;
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('MVAS clear: could not delete attachment file', [
                        'path' => $doc->path,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($dryRun) {
            $stats['tickets'] = $ticketIds->count();
            $stats['subscriptions'] = Subscription::withTrashed()
                ->where(function ($q) use ($companyIds, $customerIds) {
                    $q->whereNotNull('legacy_mvas_client_id');
                    if ($companyIds->isNotEmpty()) {
                        $q->orWhereIn('company_id', $companyIds);
                    }
                    if ($customerIds->isNotEmpty()) {
                        $q->orWhereIn('customer_id', $customerIds);
                    }
                })
                ->count();
            $stats['memberships'] = 0;
            if ($companyIds->isNotEmpty()) {
                $stats['memberships'] += CompanyMembership::query()->whereIn('company_id', $companyIds)->count();
            }
            if ($customerIds->isNotEmpty()) {
                $stats['memberships'] += CompanyMembership::query()
                    ->whereIn('customer_id', $customerIds)
                    ->when($companyIds->isNotEmpty(), fn ($q) => $q->whereNotIn('company_id', $companyIds))
                    ->count();
            }
            $stats['customers'] = $customerIds->count();
            $stats['companies'] = $companyIds->count();
            $stats['approvers'] = $clearApprovers ? ServiceFinalApprover::query()->count() : 0;

            return $stats;
        }

        DB::transaction(function () use (
            $documents,
            $ticketIds,
            $companyIds,
            $customerIds,
            $clearApprovers,
            &$stats,
        ): void {
            if ($documents->isNotEmpty()) {
                TicketDocument::withTrashed()->whereIn('id', $documents->pluck('id'))->forceDelete();
            }

            // Break subscription FKs on tickets before deleting subscriptions/tickets.
            if ($ticketIds->isNotEmpty()) {
                Ticket::withTrashed()->whereIn('id', $ticketIds)->update([
                    'subscription_id' => null,
                    'parent_ticket_id' => null,
                ]);
            }

            // Subscriptions may point at tickets as activated_by / terminated_by.
            $subscriptionIds = Subscription::withTrashed()
                ->where(function ($q) use ($companyIds, $customerIds) {
                    $q->whereNotNull('legacy_mvas_client_id');
                    if ($companyIds->isNotEmpty()) {
                        $q->orWhereIn('company_id', $companyIds);
                    }
                    if ($customerIds->isNotEmpty()) {
                        $q->orWhereIn('customer_id', $customerIds);
                    }
                })
                ->pluck('id');

            $stats['subscriptions'] = $subscriptionIds->count();
            if ($subscriptionIds->isNotEmpty()) {
                Subscription::withTrashed()->whereIn('id', $subscriptionIds)->update([
                    'activated_by_ticket_id' => null,
                    'terminated_by_ticket_id' => null,
                ]);
                Subscription::withTrashed()->whereIn('id', $subscriptionIds)->forceDelete();
            }
            if ($ticketIds->isNotEmpty()) {
                $stats['tickets'] = Ticket::withTrashed()->whereIn('id', $ticketIds)->count();
                Ticket::withTrashed()->whereIn('id', $ticketIds)->forceDelete();
            }

            $membershipIds = collect();
            if ($companyIds->isNotEmpty()) {
                $membershipIds = $membershipIds->merge(
                    CompanyMembership::query()->whereIn('company_id', $companyIds)->pluck('id')
                );
            }
            if ($customerIds->isNotEmpty()) {
                $membershipIds = $membershipIds->merge(
                    CompanyMembership::query()->whereIn('customer_id', $customerIds)->pluck('id')
                );
            }
            $membershipIds = $membershipIds->unique()->values();
            $stats['memberships'] = $membershipIds->count();
            if ($membershipIds->isNotEmpty()) {
                CompanyMembership::query()->whereIn('id', $membershipIds)->delete();
            }

            if ($customerIds->isNotEmpty()) {
                Customer::query()->whereIn('id', $customerIds)->update(['current_company_id' => null]);
            }
            if ($companyIds->isNotEmpty()) {
                Company::query()->whereIn('id', $companyIds)->update([
                    'created_by_customer_id' => null,
                    'approved_by_user_id' => null,
                ]);
            }

            if ($customerIds->isNotEmpty()) {
                $stats['customers'] = Customer::withTrashed()->whereIn('id', $customerIds)->count();
                Customer::withTrashed()->whereIn('id', $customerIds)->forceDelete();
            }

            // Company model blocks Eloquent delete — use query builder.
            if ($companyIds->isNotEmpty()) {
                $stats['companies'] = $companyIds->count();
                DB::table('companies')->whereIn('id', $companyIds->all())->delete();
            }

            if ($clearApprovers) {
                $stats['approvers'] = ServiceFinalApprover::query()->count();
                ServiceFinalApprover::query()->delete();
            }
        });

        Log::info('MVAS dump migration data cleared', $stats);

        return $stats;
    }
}

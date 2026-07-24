<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rename customers → contacts and all customer_* FKs/columns for existing databases.
 * No-op when the contacts table already exists (fresh installs with updated migrations).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contacts') && ! Schema::hasTable('customers')) {
            $this->updateMorphTypes();
            $this->renameShieldPermissions();

            return;
        }

        if (! Schema::hasTable('customers')) {
            return;
        }

        // Drop FKs that reference customers before rename.
        $this->dropForeignKeys([
            ['tickets', 'customer_id'],
            ['subscriptions', 'customer_id'],
            ['company_memberships', 'customer_id'],
            ['company_change_requests', 'customer_id'],
            ['company_change_requests', 'target_customer_id'],
            ['company_change_requests', 'reviewed_by_customer_id'],
            ['companies', 'created_by_customer_id'],
            ['ticket_documents', 'uploaded_by_customer_id'],
        ]);

        Schema::rename('customers', 'contacts');

        $this->renameColumnIfExists('tickets', 'customer_id', 'contact_id');
        $this->renameColumnIfExists('subscriptions', 'customer_id', 'contact_id');
        $this->renameColumnIfExists('company_memberships', 'customer_id', 'contact_id');
        $this->renameColumnIfExists('company_change_requests', 'customer_id', 'contact_id');
        $this->renameColumnIfExists('company_change_requests', 'target_customer_id', 'target_contact_id');
        $this->renameColumnIfExists('company_change_requests', 'reviewed_by_customer_id', 'reviewed_by_contact_id');
        $this->renameColumnIfExists('company_change_requests', 'customer_note', 'contact_note');
        $this->renameColumnIfExists('companies', 'created_by_customer_id', 'created_by_contact_id');
        $this->renameColumnIfExists('ticket_documents', 'uploaded_by_customer_id', 'uploaded_by_contact_id');

        // Recreate FKs to contacts.
        $this->addForeign('tickets', 'contact_id', 'contacts', 'cascade');
        $this->addForeign('subscriptions', 'contact_id', 'contacts', 'cascade');
        $this->addForeign('company_memberships', 'contact_id', 'contacts', 'cascade');
        $this->addForeign('company_change_requests', 'contact_id', 'contacts', 'cascade');
        $this->addForeign('company_change_requests', 'target_contact_id', 'contacts', 'set null');
        $this->addForeign('company_change_requests', 'reviewed_by_contact_id', 'contacts', 'set null');
        $this->addForeign('companies', 'created_by_contact_id', 'contacts', 'set null');
        $this->addForeign('ticket_documents', 'uploaded_by_contact_id', 'contacts', 'set null');

        // Rename known indexes if present.
        $this->renameIndexIfExists('tickets', 'tickets_customer_idx', 'tickets_contact_idx');
        $this->renameIndexIfExists('subscriptions', 'subscriptions_customer_service_status', 'subscriptions_contact_service_status');
        $this->renameIndexIfExists('subscriptions', 'subscriptions_one_alive_per_customer_service', 'subscriptions_one_alive_per_contact_service');

        $this->updateMorphTypes();
        $this->renameShieldPermissions();
    }

    public function down(): void
    {
        if (! Schema::hasTable('contacts') || Schema::hasTable('customers')) {
            return;
        }

        $this->dropForeignKeys([
            ['tickets', 'contact_id'],
            ['subscriptions', 'contact_id'],
            ['company_memberships', 'contact_id'],
            ['company_change_requests', 'contact_id'],
            ['company_change_requests', 'target_contact_id'],
            ['company_change_requests', 'reviewed_by_contact_id'],
            ['companies', 'created_by_contact_id'],
            ['ticket_documents', 'uploaded_by_contact_id'],
        ]);

        Schema::rename('contacts', 'customers');

        $this->renameColumnIfExists('tickets', 'contact_id', 'customer_id');
        $this->renameColumnIfExists('subscriptions', 'contact_id', 'customer_id');
        $this->renameColumnIfExists('company_memberships', 'contact_id', 'customer_id');
        $this->renameColumnIfExists('company_change_requests', 'contact_id', 'customer_id');
        $this->renameColumnIfExists('company_change_requests', 'target_contact_id', 'target_customer_id');
        $this->renameColumnIfExists('company_change_requests', 'reviewed_by_contact_id', 'reviewed_by_customer_id');
        $this->renameColumnIfExists('company_change_requests', 'contact_note', 'customer_note');
        $this->renameColumnIfExists('companies', 'created_by_contact_id', 'created_by_customer_id');
        $this->renameColumnIfExists('ticket_documents', 'uploaded_by_contact_id', 'uploaded_by_customer_id');

        $this->addForeign('tickets', 'customer_id', 'customers', 'cascade');
        $this->addForeign('subscriptions', 'customer_id', 'customers', 'cascade');
        $this->addForeign('company_memberships', 'customer_id', 'customers', 'cascade');
        $this->addForeign('company_change_requests', 'customer_id', 'customers', 'cascade');
        $this->addForeign('company_change_requests', 'target_customer_id', 'customers', 'set null');
        $this->addForeign('company_change_requests', 'reviewed_by_customer_id', 'customers', 'set null');
        $this->addForeign('companies', 'created_by_customer_id', 'customers', 'set null');
        $this->addForeign('ticket_documents', 'uploaded_by_customer_id', 'customers', 'set null');

        DB::table('ticket_comments')->where('author_type', 'App\\Models\\Contact')->update(['author_type' => 'App\\Models\\Customer']);
        DB::table('ticket_status_histories')->where('actor_type', 'App\\Models\\Contact')->update(['actor_type' => 'App\\Models\\Customer']);
        DB::table('personal_access_tokens')->where('tokenable_type', 'App\\Models\\Contact')->update(['tokenable_type' => 'App\\Models\\Customer']);
        DB::table('notifications')->where('notifiable_type', 'App\\Models\\Contact')->update(['notifiable_type' => 'App\\Models\\Customer']);

        if (Schema::hasTable('permissions')) {
            foreach (DB::table('permissions')->where('name', 'like', '%Contact%')->get() as $row) {
                DB::table('permissions')->where('id', $row->id)->update([
                    'name' => str_replace('Contact', 'Customer', $row->name),
                ]);
            }
        }
    }

    private function updateMorphTypes(): void
    {
        foreach ([
            ['ticket_comments', 'author_type'],
            ['ticket_status_histories', 'actor_type'],
            ['personal_access_tokens', 'tokenable_type'],
            ['notifications', 'notifiable_type'],
        ] as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }
            DB::table($table)
                ->where($column, 'App\\Models\\Customer')
                ->update([$column => 'App\\Models\\Contact']);
        }
    }

    private function renameShieldPermissions(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')
            ->where('name', 'like', '%Customer%')
            ->orderBy('id')
            ->get()
            ->each(function ($row): void {
                DB::table('permissions')->where('id', $row->id)->update([
                    'name' => str_replace('Customer', 'Contact', $row->name),
                ]);
            });
    }

    /** @param  list<array{0: string, 1: string}>  $pairs */
    private function dropForeignKeys(array $pairs): void
    {
        foreach ($pairs as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $fks = DB::select(
                'select tc.constraint_name
                 from information_schema.table_constraints tc
                 join information_schema.key_column_usage kcu
                   on tc.constraint_name = kcu.constraint_name
                  and tc.table_schema = kcu.table_schema
                 where tc.constraint_type = ?
                   and tc.table_schema = current_schema()
                   and tc.table_name = ?
                   and kcu.column_name = ?',
                ['FOREIGN KEY', $table, $column]
            );

            foreach ($fks as $fk) {
                DB::statement(sprintf(
                    'alter table %s drop constraint if exists %s',
                    $table,
                    $fk->constraint_name
                ));
            }
        }
    }

    private function renameColumnIfExists(string $table, string $from, string $to): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $from)) {
            return;
        }
        if (Schema::hasColumn($table, $to)) {
            return;
        }

        DB::statement(sprintf('alter table %s rename column %s to %s', $table, $from, $to));
    }

    private function addForeign(string $table, string $column, string $refTable, string $onDelete): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $onDeleteSql = match ($onDelete) {
            'cascade' => 'on delete cascade',
            'set null' => 'on delete set null',
            default => '',
        };

        $name = sprintf('%s_%s_foreign', $table, $column);
        DB::statement(sprintf(
            'alter table %s add constraint %s foreign key (%s) references %s (id) %s',
            $table,
            $name,
            $column,
            $refTable,
            $onDeleteSql
        ));
    }

    private function renameIndexIfExists(string $table, string $from, string $to): void
    {
        $exists = DB::selectOne(
            'select 1 as ok from pg_indexes where schemaname = current_schema() and indexname = ?',
            [$from]
        );
        if (! $exists) {
            return;
        }

        DB::statement(sprintf('alter index if exists %s rename to %s', $from, $to));
    }
};

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeder for ai_table_meta
 *
 * This table stores metadata about each DB table (module).
 * - What the table represents (ai_purpose)
 * - How it connects to other tables (relationships)
 * - A human-friendly module name
 */
class AiTableMetaSeeder extends Seeder
{
    public function run(): void
    {
        $tables = [

            // =====================
            // Contacts
            // =====================
            'contacts' => [
                'module_name' => 'Contact',
                'relationships' => [
                    'belongs_to'   => ['organizations'],
                    'referenced_by'=> ['invoices', 'activities', 'comments'],
                    'has_one' => ['leads (if converted from lead)']
                ],
                'ai_purpose' => 'Stores people associated with organizations. Includes first/last name, phone number, and email. Contacts may be linked to invoices, activities, and can be converted from leads. Use soft delete flag (deleted).'
            ],

            // =====================
            // Leads
            // =====================
            'leads' => [
                'module_name' => 'Lead',
                'relationships' => [
                    'belongs_to' => ['organizations', 'users (creator)'],
                    'has_one' => ['contacts (if converted)']
                ],
                'ai_purpose' => 'Stores potential customers/prospects before conversion to contacts. Includes first/last name, phone, email, conversion tracking. Can be converted to contacts (is_converted flag). Use soft delete flag (deleted).'
            ],

            // =====================
            //  Products
            // =====================
            'products' => [
                'module_name' => 'Product',
                'relationships' => [
                    'belongs_to' => ['organizations', 'users (creator)'],
                    'referenced_by' => ['invoice_items']
                ],
                'ai_purpose' => 'Stores product/service catalog items. Includes name, type, SKU, pricing (cost/sale), tax rates, unit measurements. Has active/inactive status. Used in invoice line items. Use soft delete flag (deleted).'
            ],

            // =====================
            //  Invoices
            // =====================
            'invoices' => [
                'module_name' => 'Invoice',
                'relationships' => [
                    'belongs_to' => [
                        'organizations',
                        'contacts',
                        'users (creator)',
                        'users (assignee)'
                    ],
                    'has_many' => ['invoice_items'],
                    'referenced_by' => ['activities', 'comments']
                ],
                'ai_purpose' => 'Represents billing records for services/products. Includes invoice number, status (Draft/Sent/Paid/Cancelled), payment status (Paid/Unpaid/Partial), totals, taxes, discounts, notes, completion percentage, creator, and assignee. Use soft delete flag (deleted).'
            ],

            // =====================
            // Invoice Items 
            // =====================
            'invoice_items' => [
                'module_name' => 'InvoiceItem',
                'relationships' => [
                    'belongs_to' => [
                        'invoices',
                        'products',
                        'organizations',
                        'users (creator)'
                    ]
                ],
                'ai_purpose' => 'Line items belonging to invoices. Stores product/service details, quantity, unit price, tax, line total, and completion percentage. Linked to products catalog. Use soft delete flag (deleted).'
            ],

            // =====================
            //  Activities
            // =====================
            'activities' => [
                'module_name' => 'Activity',
                'relationships' => [
                    'belongs_to' => ['organizations', 'users (creator)'],
                    'has_many' => ['activity_relations'],
                    'related_to' => ['contacts', 'invoices', 'leads (via activity_relations)']
                ],
                'ai_purpose' => 'Stores scheduled activities like meetings, calls, tasks. Includes title, description, activity type (Meeting/Call/Task), start/end dates and times, status (Scheduled/Completed/Cancelled). Can be linked to any entity via activity_relations. Use soft delete flag (deleted).'
            ],

            // =====================
            // Activity Relations
            // =====================
            'activity_relations' => [
                'module_name' => 'ActivityRelation',
                'relationships' => [
                    'belongs_to' => ['activities', 'organizations'],
                    'polymorphic_to' => ['contacts', 'invoices', 'leads', 'any entity']
                ],
                'ai_purpose' => 'Junction table linking activities to other entities. Uses polymorphic pattern with entity_type (module name) and entity_id. Allows activities to be associated with contacts, invoices, leads, etc. Use soft delete flag (deleted).'
            ],

            // =====================
            // Comments
            // =====================
            'comments' => [
                'module_name' => 'Comment',
                'relationships' => [
                    'belongs_to' => ['organizations', 'users (creator)'],
                    'has_many' => ['comment_rel']
                ],
                'ai_purpose' => 'Stores comments/notes that can be attached to any record. Contains text content, creator, timestamps. Use soft delete flag (deleted).'
            ],

            // =====================
            //  Comment Relations
            // =====================
            'comment_rel' => [
                'module_name' => 'CommentRelation',
                'relationships' => [
                    'belongs_to' => ['comments'],
                    'polymorphic_to' => ['contacts', 'invoices', 'leads', 'any entity (via parent_module)']
                ],
                'ai_purpose' => 'Junction table linking comments to parent records. Uses parent_module (entity type) and parent_id (entity UUID). Allows comments on any module. No deleted flag - relies on comment deletion.'
            ],

            // =====================
            // Tasks 
            // =====================
            'tasks' => [
                'module_name' => 'Task',
                'relationships' => [
                    'belongs_to'   => ['users (creator)', 'users (assignee)', 'organizations'],
                    'may_reference'=> ['invoices', 'contacts', 'leads']
                ],
                'ai_purpose' => 'Represents to-do items or work assignments. Includes title, description, status (Pending/In Progress/Completed/Cancelled), priority (Low/Medium/High/Urgent), due date, creator, and assignee. May link to related records like invoices, contacts, or leads.'
            ],

            // =====================
            // Addresses
            // =====================
            'addresses' => [
                'module_name' => 'Address',
                'relationships' => [
                    'referenced_by'=> ['contacts', 'organizations']
                ],
                'ai_purpose' => 'Stores geographical or postal address information. Can be associated with organizations or contacts.'
            ],

            // =====================
            // Jobs (
            // =====================
            'jobs' => [
                'module_name' => 'Job',
                'relationships' => [],
                'ai_purpose' => 'System table for Laravel background job queue. Tracks queue name, payload, attempts, and timestamps. Not directly used in business workflows.'
            ],

            // =====================
            // Users 
            // =====================
            'users' => [
                'module_name' => 'User',
                'relationships' => [
                    'has_many' => ['contacts (creator)', 'invoices (creator/assignee)', 'tasks (creator/assignee)', 'leads (creator)', 'products (creator)', 'activities (creator)']
                ],
                'ai_purpose' => 'System users who can create and manage records. Includes first/last name, email, role, status. Use soft delete flag (deleted).'
            ],

            // =====================
            // Members 
            // =====================
            'members' => [
                'module_name' => 'Member',
                'relationships' => [],
                'ai_purpose' => 'Team members or external collaborators. Includes first/last name, email, status (Active/Inactive).'
            ],
        ];

        foreach ($tables as $tableName => $meta) {
            DB::table('ai_table_meta')->updateOrInsert(
                ['table_name' => $tableName],
                [
                    'module_name'   => $meta['module_name'],
                    'relationships' => json_encode($meta['relationships']),
                    'ai_purpose'    => $meta['ai_purpose'],
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]
            );
        }
    }
}
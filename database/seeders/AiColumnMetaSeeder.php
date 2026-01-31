<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeder for ai_column_meta
 *
 * Accurate, AI-safe metadata for each column of each module.
 * - semantic_role: high-level meaning (id, name, status, date, etc.)
 * - semantic_context: extra detail (references, picklists, descriptions, AI instructions)
 * - is_identifier: flag if primary key
 */
class AiColumnMetaSeeder extends Seeder
{
    public function run(): void
    {
        $modules = [

            // ========================
            // Contacts
            // ========================
            'contacts' => [
                'id' => [
                    'semantic_role' => 'unique_identifier',
                    'semantic_context' => ['description' => 'Primary key for contacts table']
                ],
                'identifier' => [
                    'semantic_role' => 'identifier',
                    'semantic_context' => ['description' => 'External contact identifier for integration or reference']
                ],
                'first_name' => [
                    'semantic_role' => 'given_name',
                    'semantic_context' => [
                        'description' => 'Contact first name',
                        'ai_instructions' => 'Use separately from last_name; NO single "name" column exists.'
                    ]
                ],
                'last_name' => [
                    'semantic_role' => 'family_name',
                    'semantic_context' => [
                        'description' => 'Contact last name',
                        'ai_instructions' => 'Combine with first_name if full name is required.'
                    ]
                ],
                'phone_number' => [
                    'semantic_role' => 'phone',
                    'semantic_context' => ['description' => 'Primary phone number for contact']
                ],
                'email' => [
                    'semantic_role' => 'email',
                    'semantic_context' => ['description' => 'Contact email for identification or communication']
                ],
                'organization_id' => [
                    'semantic_role' => 'reference',
                    'semantic_context' => [
                        'references' => 'organizations',
                        'primary_role' => 'organization_reference',
                        'description' => 'Organization this contact belongs to',
                        'ai_instructions' => 'MANDATORY: Always include organization_id when referencing a contact.'
                    ]
                ],
                'deleted' => [
                    'semantic_role' => 'soft_delete_flag',
                    'semantic_context' => ['description' => 'Soft delete flag: 0=active, 1=deleted']
                ],
                'created_by' => [
                    'semantic_role' => 'reference',
                    'semantic_context' => ['references' => 'users', 'description' => 'User who created this contact']
                ],
                'is_converted_from_lead' => [
                    'semantic_role' => 'flag',
                    'semantic_context' => [
                        'description' => 'Boolean flag indicating if contact was converted from a lead',
                        'ai_instructions' => 'Use to identify contacts that originated as leads'
                    ]
                ],
                'converted_lead_id' => [
                    'semantic_role' => 'reference',
                    'semantic_context' => [
                        'references' => 'leads',
                        'description' => 'UUID of the lead this contact was converted from',
                        'ai_instructions' => 'Only populated if is_converted_from_lead is true'
                    ]
                ],
                'created_at' => ['semantic_role' => 'timestamp'],
                'updated_at' => ['semantic_role' => 'timestamp'],
            ],

            // ========================
            // NEW: Leads
            // ========================
            'leads' => [
                'id' => [
                    'semantic_role' => 'unique_identifier',
                    'semantic_context' => ['description' => 'Primary key for leads table']
                ],
                'identifier' => [
                    'semantic_role' => 'identifier',
                    'semantic_context' => ['description' => 'External lead identifier']
                ],
                'first_name' => [
                    'semantic_role' => 'given_name',
                    'semantic_context' => [
                        'description' => 'Lead first name',
                        'ai_instructions' => 'Use separately from last_name; NO single "name" column exists.'
                    ]
                ],
                'last_name' => [
                    'semantic_role' => 'family_name',
                    'semantic_context' => ['description' => 'Lead last name (optional)']
                ],
                'phone_number' => [
                    'semantic_role' => 'phone',
                    'semantic_context' => ['description' => 'Lead phone number (required)']
                ],
                'email' => [
                    'semantic_role' => 'email',
                    'semantic_context' => ['description' => 'Lead email address (optional)']
                ],
                'organization_id' => [
                    'semantic_role' => 'reference',
                    'semantic_context' => [
                        'references' => 'organizations',
                        'primary_role' => 'organization_reference',
                        'description' => 'Organization this lead belongs to',
                        'ai_instructions' => 'MANDATORY: Always filter by organization_id'
                    ]
                ],
                'deleted' => [
                    'semantic_role' => 'soft_delete_flag',
                    'semantic_context' => ['description' => 'Soft delete flag: 0=active, 1=deleted']
                ],
                'created_by' => [
                    'semantic_role' => 'reference',
                    'semantic_context' => ['references' => 'users', 'description' => 'User who created this lead']
                ],
                'is_converted' => [
                    'semantic_role' => 'flag',
                    'semantic_context' => [
                        'description' => 'Conversion status: 0=not converted, 1=converted to contact',
                        'ai_instructions' => 'Use to filter active leads vs converted leads'
                    ]
                ],
                'converted_contact_id' => [
                    'semantic_role' => 'reference',
                    'semantic_context' => [
                        'references' => 'contacts',
                        'description' => 'UUID of contact created from this lead',
                        'ai_instructions' => 'Only populated if is_converted = 1'
                    ]
                ],
                'created_at' => ['semantic_role' => 'timestamp'],
                'updated_at' => ['semantic_role' => 'timestamp'],
            ],

            // ========================
            // NEW: Products
            // ========================
            'products' => [
                'id' => [
                    'semantic_role' => 'unique_identifier',
                    'semantic_context' => ['description' => 'Primary key for products table']
                ],
                'identifier' => [
                    'semantic_role' => 'identifier',
                    'semantic_context' => ['description' => 'External product identifier']
                ],
                'name' => [
                    'semantic_role' => 'title',
                    'semantic_context' => [
                        'description' => 'Product or service name',
                        'ai_instructions' => 'Use for searching products by name'
                    ]
                ],
                'type' => [
                    'semantic_role' => 'category',
                    'semantic_context' => [
                        'description' => 'Product type classification',
                        'ai_instructions' => 'Could be "Product" or "Service" or custom types'
                    ]
                ],
                'description' => [
                    'semantic_role' => 'text',
                    'semantic_context' => ['description' => 'Detailed product description']
                ],
                'sku_code' => [
                    'semantic_role' => 'identifier',
                    'semantic_context' => [
                        'description' => 'Stock Keeping Unit code',
                        'ai_instructions' => 'Use for product inventory tracking'
                    ]
                ],
                'cost_price' => [
                    'semantic_role' => 'currency',
                    'semantic_context' => ['description' => 'Cost price (what you pay)']
                ],
                'sale_price' => [
                    'semantic_role' => 'currency',
                    'semantic_context' => ['description' => 'Sale price (what customer pays)']
                ],
                'unit' => [
                    'semantic_role' => 'label',
                    'semantic_context' => [
                        'description' => 'Unit of measurement (e.g., piece, kg, hour)',
                        'ai_instructions' => 'Used for quantity calculations'
                    ]
                ],
                'tax_rate' => [
                    'semantic_role' => 'percentage',
                    'semantic_context' => ['description' => 'Default tax rate for this product']
                ],
                'is_active' => [
                    'semantic_role' => 'flag',
                    'semantic_context' => [
                        'description' => 'Active status: 1=active, 0=inactive',
                        'ai_instructions' => 'Filter by is_active = 1 for available products'
                    ]
                ],
                'created_by' => [
                    'semantic_role' => 'reference',
                    'semantic_context' => ['references' => 'users', 'description' => 'User who created product']
                ],
                'organization_id' => [
                    'semantic_role' => 'reference',
                    'semantic_context' => [
                        'references' => 'organizations',
                        'primary_role' => 'organization_reference',
                        'description' => 'Organization owning this product',
                        'ai_instructions' => 'MANDATORY: Always filter by organization_id'
                    ]
                ],
                'deleted' => [
                    'semantic_role' => 'soft_delete_flag',
                    'semantic_context' => ['description' => 'Soft delete flag: 0=active, 1=deleted']
                ],
                'created_at' => ['semantic_role' => 'timestamp'],
                'updated_at' => ['semantic_role' => 'timestamp'],
            ],

            // ========================
            // UPDATED: Invoices
            // ========================
            'invoices' => [
                'id' => [
                    'semantic_role' => 'unique_identifier',
                    'semantic_context' => ['description' => 'Primary key for invoices table']
                ],
                'identifier' => [
                    'semantic_role' => 'identifier',
                    'semantic_context' => ['description' => 'External invoice identifier']
                ],
                'organization_id' => [
                    'semantic_role' => 'reference',
                    'semantic_context' => [
                        'references' => 'organizations',
                        'primary_role' => 'organization_reference',
                        'description' => 'Organization issuing the invoice',
                        'ai_instructions' => 'Include organization_id for filtering.'
                    ]
                ],
                'contact_id' => [
                    'semantic_role' => 'reference',
                    'semantic_context' => [
                        'references' => 'contacts',
                        'description' => 'Customer associated with the invoice'
                    ]
                ],
                'job_date' => [
                    'semantic_role' => 'date',
                    'semantic_context' => ['description' => 'Invoice creation or job date']
                ],
                'status' => [
                    'semantic_role' => 'status',
                    'semantic_context' => [
                        'primary_role' => 'invoice_state',
                        'description' => 'Invoice workflow status',
                        'picklist_values' => ['Draft', 'Sent', 'Paid', 'Cancelled'],
                        'strict_picklist_match' => true
                    ]
                ],
                'payment_status' => [
                    'semantic_role' => 'status',
                    'semantic_context' => [
                        'primary_role' => 'payment_state',
                        'description' => 'Payment status of invoice',
                        'picklist_values' => ['Unpaid', 'Paid', 'Partial', 'Overdue'],
                        'strict_picklist_match' => false,
                        'ai_instructions' => 'Use for payment tracking queries'
                    ]
                ],
                'subtotal' => [
                    'semantic_role' => 'currency',
                    'semantic_context' => ['description' => 'Amount before tax and discount']
                ],
                'tax_amount' => [
                    'semantic_role' => 'currency',
                    'semantic_context' => ['description' => 'Total tax']
                ],
                'discount' => [
                    'semantic_role' => 'currency',
                    'semantic_context' => ['description' => 'Discount applied']
                ],
                'total' => [
                    'semantic_role' => 'currency',
                    'semantic_context' => ['description' => 'Final invoice total']
                ],
                'notes' => [
                    'semantic_role' => 'text',
                    'semantic_context' => ['description' => 'Additional notes']
                ],
                'completion_percentage' => [
                    'semantic_role' => 'progress',
                    'semantic_context' => ['description' => 'Completion of invoice tasks']
                ],
                'created_by' => [
                    'semantic_role' => 'reference',
                    'semantic_context' => ['references' => 'users', 'description' => 'User who created invoice']
                ],
                'assigned_to' => [
                    'semantic_role' => 'reference',
                    'semantic_context' => ['references' => 'users', 'description' => 'User assigned for follow-up']
                ],
                'deleted' => [
                    'semantic_role' => 'soft_delete_flag',
                    'semantic_context' => ['description' => 'Soft delete flag: 0=active, 1=deleted']
                ],
                'created_at' => ['semantic_role' => 'timestamp'],
                'updated_at' => ['semantic_role' => 'timestamp'],
            ],

            // ========================
            // Invoice Items (unchanged except deleted clarification)
            // ========================
            'invoice_items' => [
                'id' => ['semantic_role' => 'unique_identifier', 'semantic_context' => ['description' => 'Primary key']],
                'identifier' => [
                    'semantic_role' => 'identifier',
                    'semantic_context' => ['description' => 'External line item identifier']
                ],
                'invoice_id' => [
                    'semantic_role' => 'reference',
                    'semantic_context' => ['references' => 'invoices', 'description' => 'Invoice this item belongs to']
                ],
                'product_service_id' => [
                    'semantic_role' => 'reference',
                    'semantic_context' => ['references' => 'products', 'description' => 'Linked product or service']
                ],
                'name' => ['semantic_role' => 'title', 'semantic_context' => ['description' => 'Product/service name']],
                'description' => ['semantic_role' => 'text', 'semantic_context' => ['description' => 'Item description']],
                'unit_price' => ['semantic_role' => 'currency', 'semantic_context' => ['description' => 'Price per unit']],
                'quantity' => ['semantic_role' => 'numeric', 'semantic_context' => ['description' => 'Number of units']],
                'line_total' => ['semantic_role' => 'currency', 'semantic_context' => ['description' => 'Total line amount']],
                'tax_rate' => ['semantic_role' => 'percentage', 'semantic_context' => ['description' => 'Applicable tax percentage']],
                'completion_percentage' => ['semantic_role' => 'progress', 'semantic_context' => ['description' => 'Line completion']],
                'status' => [
                    'semantic_role' => 'status',
                    'semantic_context' => [
                        'primary_role' => 'line_item_state',
                        'picklist_values' => ['Draft', 'Active', 'Completed'],
                        'strict_picklist_match' => true
                    ]
                ],
                'organization_id' => [
                    'semantic_role' => 'reference',
                    'semantic_context' => [
                        'references' => 'organizations',
                        'primary_role' => 'organization_reference'
                    ]
                ],
                'created_by' => [
                    'semantic_role' => 'reference',
                    'semantic_context' => ['references' => 'users']
                ],
                'deleted' => [
                    'semantic_role' => 'soft_delete_flag',
                    'semantic_context' => ['description' => 'Soft delete flag: 0=active, 1=deleted']
                ],
                'created_at' => ['semantic_role' => 'timestamp'],
                'updated_at' => ['semantic_role' => 'timestamp'],
            ],

            // ========================
            // NEW: Activities
            // ========================
            'activities' => [
                'id' => [
                    'semantic_role' => 'unique_identifier',
                    'semantic_context' => ['description' => 'Primary key for activities table']
                ],
                'title' => [
                    'semantic_role' => 'title',
                    'semantic_context' => ['description' => 'Activity title/subject']
                ],
                'description' => [
                    'semantic_role' => 'text',
                    'semantic_context' => ['description' => 'Detailed activity description']
                ],
                'activity_type' => [
                    'semantic_role' => 'category',
                    'semantic_context' => [
                        'description' => 'Type of activity',
                        'picklist_values' => ['Meeting', 'Call', 'Task'],
                        'strict_picklist_match' => true,
                        'ai_instructions' => 'Use exact values: Meeting, Call, or Task'
                    ]
                ],
                'start_date' => [
                    'semantic_role' => 'date',
                    'semantic_context' => ['description' => 'Activity start date']
                ],
                'end_date' => [
                    'semantic_role' => 'date',
                    'semantic_context' => ['description' => 'Activity end date (optional)']
                ],
                'start_time' => [
                    'semantic_role' => 'time',
                    'semantic_context' => ['description' => 'Activity start time']
                ],
                'end_time' => [
                    'semantic_role' => 'time',
                    'semantic_context' => ['description' => 'Activity end time (optional)']
                ],
                'status' => [
                    'semantic_role' => 'status',
                    'semantic_context' => [
                        'primary_role' => 'activity_state',
                        'description' => 'Activity completion status',
                        'picklist_values' => ['Scheduled', 'Completed', 'Cancelled'],
                        'strict_picklist_match' => true
                    ]
                ],
                'created_by' => [
                    'semantic_role' => 'reference',
                    'semantic_context' => ['references' => 'users', 'description' => 'User who created activity']
                ],
                'organization_id' => [
                    'semantic_role' => 'reference',
                    'semantic_context' => [
                        'references' => 'organizations',
                        'primary_role' => 'organization_reference',
                        'ai_instructions' => 'MANDATORY: Always filter by organization_id'
                    ]
                ],
                'identifier' => [
                    'semantic_role' => 'identifier',
                    'semantic_context' => ['description' => 'External activity identifier']
                ],
                'deleted' => [
                    'semantic_role' => 'soft_delete_flag',
                    'semantic_context' => ['description' => 'Soft delete flag: false=active, true=deleted']
                ],
                'created_at' => ['semantic_role' => 'timestamp'],
                'updated_at' => ['semantic_role' => 'timestamp'],
            ],

            // ========================
            // NEW: Activity Relations
            // ========================
            'activity_relations' => [
                'id' => [
                    'semantic_role' => 'unique_identifier',
                    'semantic_context' => ['description' => 'Primary key']
                ],
                'activity_id' => [
                    'semantic_role' => 'reference',
                    'semantic_context' => [
                        'references' => 'activities',
                        'description' => 'Activity being linked'
                    ]
                ],
                'relation_type' => [
                    'semantic_role' => 'label',
                    'semantic_context' => [
                        'description' => 'Type of relationship (e.g., "primary", "related")',
                        'ai_instructions' => 'Describes how the activity relates to the entity'
                    ]
                ],
                'entity_type' => [
                    'semantic_role' => 'label',
                    'semantic_context' => [
                        'description' => 'Module name of related entity (e.g., "Contact", "Invoice")',
                        'ai_instructions' => 'Use to determine which table to JOIN for related records'
                    ]
                ],
                'entity_id' => [
                    'semantic_role' => 'reference',
                    'semantic_context' => [
                        'references' => 'polymorphic',
                        'description' => 'UUID of related entity',
                        'ai_instructions' => 'Combine with entity_type for polymorphic lookups'
                    ]
                ],
                'organization_id' => [
                    'semantic_role' => 'reference',
                    'semantic_context' => [
                        'references' => 'organizations',
                        'primary_role' => 'organization_reference'
                    ]
                ],
                'deleted' => [
                    'semantic_role' => 'soft_delete_flag',
                    'semantic_context' => ['description' => 'Soft delete flag']
                ],
                'created_at' => ['semantic_role' => 'timestamp'],
                'updated_at' => ['semantic_role' => 'timestamp'],
            ],

            // ========================
            // NEW: Comments
            // ========================
            'comments' => [
                'id' => [
                    'semantic_role' => 'unique_identifier',
                    'semantic_context' => ['description' => 'Primary key for comments table']
                ],
                'content' => [
                    'semantic_role' => 'text',
                    'semantic_context' => [
                        'description' => 'Comment text content',
                        'ai_instructions' => 'Use for full-text search in comments'
                    ]
                ],
                'deleted' => [
                    'semantic_role' => 'soft_delete_flag',
                    'semantic_context' => ['description' => 'Soft delete flag: 0=active, 1=deleted']
                ],
                'created_by' => [
                    'semantic_role' => 'reference',
                    'semantic_context' => ['references' => 'users', 'description' => 'User who created comment']
                ],
                'organization_id' => [
                    'semantic_role' => 'reference',
                    'semantic_context' => [
                        'references' => 'organizations',
                        'primary_role' => 'organization_reference'
                    ]
                ],
                'created_at' => ['semantic_role' => 'timestamp'],
                'updated_at' => ['semantic_role' => 'timestamp'],
            ],

            // ========================
            // NEW: Comment Relations
            // ========================
            'comment_rel' => [
                'id' => [
                    'semantic_role' => 'unique_identifier',
                    'semantic_context' => ['description' => 'Primary key']
                ],
                'comment_id' => [
                    'semantic_role' => 'reference',
                    'semantic_context' => [
                        'references' => 'comments',
                        'description' => 'Comment being linked'
                    ]
                ],
                'parent_id' => [
                    'semantic_role' => 'reference',
                    'semantic_context' => [
                        'references' => 'polymorphic',
                        'description' => 'UUID of parent entity (contact, invoice, etc.)',
                        'ai_instructions' => 'Combine with parent_module for lookups'
                    ]
                ],
                'parent_module' => [
                    'semantic_role' => 'label',
                    'semantic_context' => [
                        'description' => 'Module name of parent entity (e.g., "Contact", "Invoice")',
                        'ai_instructions' => 'Use to determine which table the comment is attached to'
                    ]
                ],
                'created_at' => ['semantic_role' => 'timestamp'],
                'updated_at' => ['semantic_role' => 'timestamp'],
            ],

            // ========================
            // Tasks (unchanged)
            // ========================
            'tasks' => [
                'id' => ['semantic_role' => 'unique_identifier'],
                'title' => ['semantic_role' => 'title'],
                'description' => ['semantic_role' => 'text'],
                'status' => [
                    'semantic_role' => 'status',
                    'semantic_context' => [
                        'primary_role' => 'task_state',
                        'picklist_values' => ['Pending', 'In Progress', 'Completed', 'Cancelled'],
                        'strict_picklist_match' => true
                    ]
                ],
                'priority' => [
                    'semantic_role' => 'priority',
                    'semantic_context' => ['picklist_values' => ['Low','Medium','High','Urgent']]
                ],
                'due_date' => ['semantic_role' => 'date'],
                'created_by' => ['semantic_role' => 'reference', 'semantic_context' => ['references' => 'users']],
                'assigned_to' => ['semantic_role' => 'reference', 'semantic_context' => ['references' => 'users']],
                'related_recordid' => ['semantic_role' => 'reference', 'semantic_context' => ['references' => 'generic_records']],
                'organization_id' => [
                    'semantic_role' => 'reference',
                    'semantic_context' => [
                        'references' => 'organizations',
                        'primary_role' => 'organization_reference'
                    ]
                ],
                'created_at' => ['semantic_role' => 'timestamp'],
                'updated_at' => ['semantic_role' => 'timestamp'],
            ],

            // ========================
            // Addresses (unchanged)
            // ========================
            'addresses' => [
                'id' => ['semantic_role' => 'unique_identifier'],
                'created_at' => ['semantic_role' => 'timestamp'],
                'updated_at' => ['semantic_role' => 'timestamp'],
            ],

            // ========================
            // Jobs (unchanged)
            // ========================
            'jobs' => [
                'id' => ['semantic_role' => 'unique_identifier'],
                'queue' => ['semantic_role' => 'label'],
                'payload' => ['semantic_role' => 'text'],
                'attempts' => ['semantic_role' => 'numeric'],
                'reserved_at' => ['semantic_role' => 'timestamp'],
                'available_at' => ['semantic_role' => 'timestamp'],
                'created_at' => ['semantic_role' => 'timestamp'],
            ],

            // ========================
            // Users (unchanged)
            // ========================
            'users' => [
                'id' => ['semantic_role' => 'unique_identifier'],
                'first_name' => ['semantic_role' => 'given_name'],
                'last_name' => ['semantic_role' => 'family_name'],
                'email' => ['semantic_role' => 'email'],
                'role' => ['semantic_role' => 'role'],
                'status' => [
                    'semantic_role' => 'status',
                    'semantic_context' => [
                        'picklist_values' => ['Active', 'Inactive'],
                        'strict_picklist_match' => true
                    ]
                ],
                'deleted' => ['semantic_role' => 'soft_delete_flag'],
                'created_at' => ['semantic_role' => 'timestamp'],
                'updated_at' => ['semantic_role' => 'timestamp'],
            ],

            // ========================
            // Members (unchanged)
            // ========================
            'members' => [
                'id' => ['semantic_role' => 'unique_identifier'],
                'first_name' => ['semantic_role' => 'given_name'],
                'last_name' => ['semantic_role' => 'family_name'],
                'email' => ['semantic_role' => 'email'],
                'status' => [
                    'semantic_role' => 'status',
                    'semantic_context' => [
                        'picklist_values' => ['Active','Inactive'],
                        'strict_picklist_match'=>true
                    ]
                ],
                'created_at' => ['semantic_role' => 'timestamp'],
                'updated_at' => ['semantic_role' => 'timestamp'],
            ],
        ];

        // Seed each column into ai_column_meta
        foreach ($modules as $tableName => $fields) {
            $tableId = DB::table('ai_table_meta')->where('table_name', $tableName)->value('id');

            foreach ($fields as $fieldName => $meta) {
                $crmFieldId = DB::table('crm_fields')
                    ->where('fieldname', $fieldName)
                    ->where('tablename', $tableName)
                    ->value('id');

                if (!$tableId || !$crmFieldId) {
                    echo "⚠️  Skipping {$tableName}.{$fieldName} - missing in crm_fields or ai_table_meta\n";
                    continue;
                }

                DB::table('ai_column_meta')->updateOrInsert(
                    ['table_id' => $tableId, 'crm_field_id' => $crmFieldId],
                    [
                        'semantic_role' => $meta['semantic_role'] ?? null,
                        'value_examples' => null,
                        'semantic_context' => isset($meta['semantic_context']) ? json_encode($meta['semantic_context']) : null,
                        'is_identifier' => $fieldName === 'id' ? 1 : 0,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]
                );
            }
        }
    }
}
<?php

namespace App\Modules\Api\V1\AISearch\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;

class AIAlterQueryController extends Controller
{
    public function processQuery(Request $request)
    {
        try {
            // Validate request - expecting selected fields
            $request->validate([
                'query_id' => 'required|uuid',
                'selected_fields' => 'required|array',
                'selected_fields.*.modulename' => 'required|string',
                'selected_fields.*.fieldname' => 'required|string',
            ]);

            $queryId = $request->input('query_id');
            $selectedFields = $request->input('selected_fields', []);

            $orgId = auth()->user()?->organization_id;
            if (!$orgId) {
                throw new \Exception("Organization ID not found.");
            }

            // Get stored query information
            $more_info = DB::table('ai_generated_queries')
                ->where('id', $queryId)
                ->where('deleted', 0)
                ->value('more_info');

            if (!$more_info) {
                throw new \Exception("Query not found.");
            }

            $moreInfoData = json_decode($more_info, true);
            $templateSql = $moreInfoData['query'] ?? null; // This has <<COLUMNS>> placeholder
            $tableAliases = collect($moreInfoData['tables'] ?? [])
                ->mapWithKeys(fn($t) => [$t['name'] => $t['alias'] ?? $t['name']]);

            if (!$templateSql) {
                throw new \Exception("Template query not found in stored data.");
            }

            Log::info('Template SQL retrieved', ['template' => $templateSql]);

            // Build selected columns based on user's choice
            $selectedColumns = [];
            foreach ($selectedFields as $field) {
                $moduleName = $field['modulename'];
                $apiFieldName = $field['fieldname'];

                // Get the actual database field name from crm_fields
                $crmField = DB::table('crm_fields')
                    ->where('modulename', $moduleName)
                    ->where('apifieldname', $apiFieldName)
                    ->first(['tablename', 'fieldname']);

                if ($crmField) {
                    $alias = $tableAliases[$crmField->tablename] ?? $crmField->tablename;
                    $selectedColumns[] = "{$alias}.{$crmField->fieldname} as {$apiFieldName}";
                } else {
                    Log::warning('Field not found in crm_fields', [
                        'modulename' => $moduleName,
                        'fieldname' => $apiFieldName
                    ]);
                }
            }

            if (empty($selectedColumns)) {
                throw new \Exception('No valid fields found to select.');
            }

            // Replace <<COLUMNS>> with user-selected columns
            $columnList = implode(', ', $selectedColumns);
            $sql = str_replace('<<COLUMNS>>', $columnList, $templateSql);
            
            // Replace organization placeholders
            $sql = str_replace(["'<<ORG_ID>>'", '<<ORG_ID>>'], "'{$orgId}'", $sql);
            
            // Clean up SQL
            $sql = preg_replace('/;+\s*$/', '', $sql);

            Log::info('Final SQL with selected columns', ['sql' => $sql]);

            // Execute query with pagination
            $perPage = $request->get('per_page', 20);
            $page = $request->get('page', 1);

            $paginator = DB::table(DB::raw("({$sql}) as sub"))
                ->paginate($perPage, ['*'], 'page', $page);

            // Transform results for consistent API field naming
            $results = collect($paginator->items())->map(function ($row) {
                return (array) $row; // Results already have API field names due to AS aliases
            })->toArray();

            return response()->json([
                'success' => true,
                'query_id' => $queryId,
                'sql' => $sql,
                'selected_fields' => $selectedFields,
                'data' => [
                    'records' => $results,
                    'meta' => [
                        'current_page' => $paginator->currentPage(),
                        'from' => $paginator->firstItem(),
                        'last_page' => $paginator->lastPage(),
                        'links' => $paginator->linkCollection(),
                        'path' => $paginator->path(),
                        'per_page' => $paginator->perPage(),
                        'to' => $paginator->lastItem(),
                        'total' => $paginator->total(),
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('AI Alter Query Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Query failed',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
<?php

namespace App\Modules\Api\V1\AISearch\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Modules\Api\V1\AISearch\Models\AiColumnMeta;
use App\Modules\Api\V1\AISearch\Models\AiGeneratedQuery;
use App\Modules\Api\V1\AISearch\Models\AiGeneratedQueryOrgRel;
use App\Services\OpenAIService;
use Illuminate\Support\Str;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Traits\ResultTrait;
use Illuminate\Validation\ValidationException;
class AIQueryController extends Controller
{
	use ResultTrait;
	protected OpenAIService $openAI;

	public function __construct(OpenAIService $openAI)
	{
		$this->openAI = $openAI;
	}

	public function generate(Request $request)
	{
		$request->validate(['query' => 'required|string|max:500']);
		try {
			$result = $this->openAI->generateSql($request->input('query'));

			return response()->json([
				'success' => true,
				'sql' => $result['sql'],
				'tokens' => $result['tokens'],
			]);
		} catch (\Exception $e) {
			Log::error("AI SQL Generate Error: " . $e->getMessage());

			return response()->json([
				'success' => false,
				'message' => 'Failed to generate SQL',
				'error' => config('app.debug') ? $e->getMessage() : null,
			], 500);
		}
	}

	public function processQuery(Request $request)
{
    $request->validate(['query' => 'required|string|max:500','query_id' => 'nullable|uuid']);

    try {
        $orgId = auth()->user()?->organization_id;
        if (!$orgId) {
            throw new \Exception("Organization ID not found.");
        }

        // 1. Generate & sanitize SQL
        $sql = $this->openAI->generateSql($request->input('query'), $request->input('query_id'));
        $safeSql = $this->sanitizeQuery($sql['sql'], $orgId);
        // 2. Store the generated query with proper expansion
        $queryId = $this->storeGeneratedQuery(
            $request->input('query'), 
            $safeSql, 
            $orgId, 
            $sql['tokens']
        );
        
        // 3. Replace ORG_ID placeholder and clean SQL
        $safeSql = str_replace(["'<<ORG_ID>>'", '<<ORG_ID>>'], "'{$orgId}'", $safeSql);
        $safeSql = preg_replace('/;+\s*$/', '', $safeSql);

        // 4. EXPAND COLUMNS: Replace SELECT invoices.* with actual fields
        $expandedSql = $this->expandSelectClause($safeSql, $orgId);

        // 5. Execute query for data
        $perPage = $request->get('per_page', 20);
        $paginator = DB::table(DB::raw("({$expandedSql}) AS sub"))->paginate($perPage);
        $rawResults = DB::select($expandedSql);

        // 6. Get available fields (same fields we're selecting)
        $availableFields = $this->getFieldsFromFiltersAndWhere($queryId, $orgId);
        // 7. Build column mapping for results
        $columnMap = $this->buildColumnMapping($expandedSql);

        // 8. Map results to use apifieldname as key
        $results = collect($rawResults)->map(function ($row) use ($columnMap) {
            $row = (array) $row;
            $mapped = [];

            foreach ($row as $key => $value) {
                $mappedKey = $columnMap[$key] ?? $key;
                $mapped[$mappedKey] = $value;
            }

            return $mapped;
        })->toArray();

        return response()->json([
            'success' => true,
            'query_id' => $queryId,
            'sql' => $expandedSql, // Return the expanded SQL
            'data' => [
                'details' => [
                    'records' => $results,
                    'available_fields' => $availableFields,
                    'modules_involved' => array_keys($availableFields),
                ],
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
        Log::error('AI Query Execution Error: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Query failed',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}

	/**
	 * Store the generated query in database
	 */
private function storeGeneratedQuery(string $prompt, string $sql, string $orgId, array $tokens): string
{
    try {
        $queryId = (string) Str::uuid();

        if (preg_match('/SELECT\s+(.+?)\s+FROM\s+(.+)$/is', $sql, $matches)) {
            $selectedColumns = $matches[1];
            $fromClause = $matches[2];
            $templateSql = "SELECT <<COLUMNS>> FROM {$fromClause}";
        } else {
            $templateSql = $sql;
        }

        $tableDetails = $this->extractDetailedTableInfo($sql);
        $whereConditions = $this->extractWhereConditionFields($sql);
        $filterDefaults = $this->getFilterDefaultsForModules($tableDetails, $orgId);

        $templateSql = str_replace("'{$orgId}'", '<<ORG_ID>>', $templateSql);

        $moreInfo = [
            'query_template' => $templateSql,
            'tables_involved' => $tableDetails,
            'where_conditions' => $whereConditions,
            'filter_defaults' => $filterDefaults,
            'query_metadata' => [
                'has_joins' => count($tableDetails) > 1,
                'join_types' => array_unique(array_column($tableDetails, 'join_type')),
                'main_table' => $tableDetails[0]['name'] ?? null,
                'generated_at' => now()->toISOString()
            ]
        ];

        AiGeneratedQuery::create([
            'id' => $queryId,
            'prompt' => $prompt,
            'query' => $sql,
            'more_info' => $moreInfo,
            'deleted' => 0,
        ]);
        AiGeneratedQueryOrgRel::create([
            'id' => (string) Str::uuid(),
            'query_id' => $queryId,
            'organization_id' => $orgId,
            'user_id' => auth()->user()->id,
            'more_info' => [
                'execution_context' => [
                    'user_id' => auth()->user()->id,
                    'executed_at' => now()->toISOString(),
                    'tokens_consumed' => $tokens['total_tokens'] ?? 0,
                ],
            ],
        ]);

        return $queryId;

    } catch (\Throwable $e) {
        Log::error('AI Query Store Error', [
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'prompt' => $prompt,
            'orgId' => $orgId
        ]);

        throw ValidationException::withMessages([
            'query' => 'Failed to store generated query.'. $e
        ]);
    }
}




	/**
	 * Extract table aliases from SQL
	 */
	private function extractTableAliases(string $sql): array
        {
            $tables = [];
            
            // Extract FROM clause with proper boundary checking
            if (preg_match('/\bFROM\s+([a-zA-Z0-9_]+)(?:\s+(?:(?!JOIN|WHERE|ORDER|GROUP|LIMIT)[a-zA-Z0-9_]+))?\s*(?=\s|$|JOIN|WHERE|ORDER|GROUP|LIMIT)/i', $sql, $match)) {
                $tableName = $match[1];
                // Always use table name as alias for FROM clause if no explicit alias
                $tables[] = [
                    'name' => $tableName,
                    'alias' => $tableName // Use table name itself as alias
                ];
            }
            
            // Extract JOIN clauses
            preg_match_all('/\b(?:INNER\s+|LEFT\s+|RIGHT\s+|FULL\s+)?JOIN\s+([a-zA-Z0-9_]+)(?:\s+(?:(?!ON)[a-zA-Z0-9_]+))?\s+ON\b/i', $sql, $matches, PREG_SET_ORDER);
            
            foreach ($matches as $match) {
                $tableName = $match[1];
                $tables[] = [
                    'name' => $tableName,
                    'alias' => $tableName // Use table name itself as alias
                ];
            }
            
            return $tables;
        }
	/**
	 * Extract selected columns from SQL
	 */
	private function extractSelectedColumns(string $sql): array
    {
    if (preg_match('/SELECT\s+(.+?)\s+FROM/is', $sql, $match)) {
        $columnsPart = trim($match[1]);
        
        if ($columnsPart === '*') {
            return ['*'];
        }
        
        // Handle table.* patterns (like invoices.*)
        if (preg_match('/^([a-zA-Z0-9_]+)\.\*$/', $columnsPart)) {
            // Get ALL tables from the query (including JOINs)
            $allTables = $this->extractAllTablesFromQuery($sql);
            $expandedColumns = [];
            
            foreach ($allTables as $table) {
                // Get actual columns for each table from crm_fields
                $columns = DB::table('crm_fields')
                    ->where('tablename', $table['name'])
                    ->where('displaytype', 1)
                    ->get(['fieldname', 'apifieldname']);
                
                foreach ($columns as $column) {
                    // EXCLUDE organization_id fields
                    if (!str_contains(strtolower($column->apifieldname), 'organization')) {
                        $expandedColumns[] = "{$table['alias']}.{$column->fieldname}";
                    }
                }
            }
            
            return $expandedColumns;
        }
        
        // Split by comma for explicit column lists
        $columns = preg_split('/,(?![^()]*\))/', $columnsPart);
        return array_map('trim', $columns);
    }
    
    return [];
}


/**
 * Helper: Extract all tables from SQL query (FROM + JOINs)
 */
private function extractAllTablesFromQuery(string $sql): array
{
    $tables = [];
    
    // Extract FROM table
    if (preg_match('/\bFROM\s+([a-zA-Z0-9_]+)(?:\s+(?:AS\s+)?([a-zA-Z0-9_]+))?\b/i', $sql, $match)) {
        $tableName = $match[1];
        $alias = (!empty($match[2]) && !in_array(strtoupper($match[2]), ['JOIN', 'LEFT', 'RIGHT', 'INNER'])) ? $match[2] : $tableName;
        
        $tables[] = [
            'name' => $tableName,
            'alias' => $alias
        ];
    }
    
    // Extract JOIN tables
    preg_match_all('/\b(?:INNER\s+|LEFT\s+|RIGHT\s+|FULL\s+)?JOIN\s+([a-zA-Z0-9_]+)(?:\s+(?:AS\s+)?([a-zA-Z0-9_]+))?\s+ON\b/i', $sql, $matches, PREG_SET_ORDER);
    
    foreach ($matches as $match) {
        $tableName = $match[1];
        $alias = (!empty($match[2]) && !in_array(strtoupper($match[2]), ['ON', 'WHERE'])) ? $match[2] : $tableName;
        
        $tables[] = [
            'name' => $tableName,
            'alias' => $alias
        ];
    }
    
    return $tables;
}
	/**
	 * Get schema context used in query
	 */
	private function getSchemaContextUsed(array $tables): array
	{
		$context = [];
		
		foreach ($tables as $table) {
			$fields = AiColumnMeta::with('crmField')
				->whereHas('crmField', fn($q) => $q->where('tablename', $table['name']))
				->get();
				
			$context[$table['name']] = [
				'alias' => $table['alias'],
				'fields_available' => $fields->pluck('crmField.fieldname')->filter()->toArray(),
				'semantic_roles' => $fields->mapWithKeys(function($field) {
					return [$field->crmField?->fieldname => $field->semantic_role];
				})->filter()->toArray()
			];
		}
		
		return $context;
	}

	// ... rest of your existing methods (sanitizeQuery, searchQuery, getQuickAccessQueries)

	private function sanitizeQuery(string $sql, string $organizationId): string
	{
		// 1. Remove trailing semicolons
		$sql = preg_replace('/;+\s*$/', '', $sql);
		$conditions = [];

		// 2. Extract FROM + JOIN tables with corrected parsing
		$tableAliases = [];
		
		// Extract FROM clause
		if (preg_match('/\bfrom\s+([a-zA-Z0-9_]+)(?:\s+(?:as\s+)?([a-zA-Z0-9_]+))?\b/i', $sql, $match)) {
			$tableName = $match[1];
			$alias = $match[2] ?? $tableName;
			$tableAliases[$tableName] = $alias;
		}
		
		// Extract JOIN clauses
		preg_match_all('/\b(?:inner\s+|left\s+|right\s+|full\s+)?join\s+([a-zA-Z0-9_]+)(?:\s+(?:as\s+)?([a-zA-Z0-9_]+))?\s+on\b/i', $sql, $matches, PREG_SET_ORDER);
		
		foreach ($matches as $match) {
			$tableName = $match[1];
			$alias = $match[2] ?? $tableName;
			$tableAliases[$tableName] = $alias;
		}

		Log::info('Sanitize Query - Tables found:', $tableAliases);

		foreach ($tableAliases as $table => $alias) {
			// Skip if alias contains invalid characters or is a SQL keyword
			if (!preg_match('/^[a-zA-Z0-9_]+$/', $alias) || 
				in_array(strtoupper($alias), ['JOIN', 'FROM', 'WHERE', 'SELECT', 'AND', 'OR'])) {
				Log::warning("Skipping invalid alias: $alias for table: $table");
				continue;
			}

			$fields = AiColumnMeta::with('crmField')
				->whereHas('crmField', fn($q) => $q->where('tablename', $table))
				->get();

			foreach ($fields as $field) {
				$column = $field->crmField?->fieldname;
				if (!$column) continue;

				// ----- Soft delete flag -----
				if ($field->semantic_role === 'soft_delete_flag') {
					$columnRef = "$alias.$column";
					// Check if this condition is already in the SQL
					if (!preg_match("/\b{$alias}\.{$column}\s*=\s*0\b/i", $sql) && 
						!preg_match("/\b{$column}\s*=\s*0\b/i", $sql)) {
						$conditions[] = "$columnRef = 0";
					}
				}

				// ----- Organization filter -----
				$ctx = $field->semantic_context;
				$ctx = $ctx ? (is_array($ctx) ? $ctx : json_decode($ctx, true)) : [];
				if (($ctx['references'] ?? null) === 'organizations' ||
					($ctx['primary_role'] ?? null) === 'organization_reference') {
					
					$columnRef = "$alias.$column";
					// Check if organization condition is already present
					if (!preg_match("/\b{$alias}\.{$column}\s*=\s*['\"]?{$organizationId}['\"]?\b/i", $sql) &&
						!preg_match("/\b{$column}\s*=\s*['\"]?{$organizationId}['\"]?\b/i", $sql)) {
						$conditions[] = "$columnRef = '$organizationId'";
					}
				}
			}
		}

		// 3. Append conditions safely
		if (!empty($conditions)) {
			Log::info('Sanitize Query - Adding conditions:', $conditions);
			// Check if WHERE clause already exists
			if (preg_match('/\bwhere\b/i', $sql)) {
				// WHERE already exists, add with AND
				$sql .= ' AND ' . implode(' AND ', $conditions);
			} else {
				// No WHERE clause, add one
				$sql .= ' WHERE ' . implode(' AND ', $conditions);
			}
		}

		Log::info('Sanitize Query - Final SQL:', ['sql' => $sql]);
		return $sql;
	}

	public function searchQuery(Request $request)
	{
		try {
			$term = $request->query('term');
			$orgId = auth()->user()?->organization_id;

			if (!$orgId) {
				throw new \Exception('Organization ID not found.');
			}

			$results = DB::table('ai_generated_queries')
				->rightJoin('ai_generated_queries_org_rel', 'ai_generated_queries.id', '=', 'ai_generated_queries_org_rel.query_id')
				->where('ai_generated_queries_org_rel.organization_id', $orgId)
				->where('ai_generated_queries.prompt', 'like', "%{$term}%")
				->where('ai_generated_queries.deleted', 0)
				->select(
					'ai_generated_queries.id as query_id',
					'ai_generated_queries.prompt',
				)
				->orderByDesc('ai_generated_queries_org_rel.updated_at')
				->get();

			return $this->success($results,'Success');
		} catch (\Exception $e) {
			Log::error("SearchQuery Error: " . $e->getMessage());
			return response()->json([
				'success' => false,
				'message' => 'Search failed',
				'error' => config('app.debug') ? $e->getMessage() : null,
			], 500);
		}
	}

	public function getQuickAccessQueries()
	{
		$results = DB::table('ai_quick_access_query as q')
			->leftJoin('ai_generated_queries as g', 'q.query_id', '=', 'g.id')
			->select('g.prompt', 'q.query_id')
			->get()
			->map(function ($row) {
				return [
					'query' => $row->prompt ?? '',
					'query_id' => $row->query_id,
				];
			});

		return $this->success($results);
	}

	/**
 * Get all available fields for a stored query
 * Add this method to your AIQueryController
 */
public function getAvailableFields(Request $request)
{
    $request->validate(['query_id' => 'required|uuid']);
    
    try {
        $queryId = $request->input('query_id');
        $orgId = auth()->user()?->organization_id;
        
        if (!$orgId) {
            throw new \Exception("Organization ID not found.");
        }

        $query = AiGeneratedQuery::where('id', $queryId)->where('deleted', 0)->first();
        
        if (!$query) {
            throw new \Exception("Query not found.");
        }
        
        $moreInfo = $query->more_info;
        $tablesInvolved = $moreInfo['tables_involved'] ?? [];
        
        // Build a map of currently displayed fields from more_info
        $displayedFieldsMap = $this->buildDisplayedFieldsMap($moreInfo);
        
        $availableFields = [];
        
        // For each involved module, get ALL fields from crm_fields
        foreach ($tablesInvolved as $table) {
            $moduleName = $table['module_name'];
            $tableName = $table['name'];
            
            if (!$moduleName) continue;
            
            // Get ALL displayable fields for this module
            $allFields = DB::table('crm_fields')
                ->where('modulename', $moduleName)
                ->where('tablename', $tableName)
                ->where('displaytype', 1)
                ->whereNotLike('apifieldname', '%organization%')
                ->whereNotLike('fieldname', '%deleted%')
                ->get(['fieldname', 'apifieldname', 'fieldlabel', 'fieldtype'])
                ->map(function($field) use ($displayedFieldsMap, $moduleName) {
                    
                    // Check if this field is already displayed
                    $isDisplayed = isset($displayedFieldsMap[$moduleName][$field->apifieldname]);
                    $source = $isDisplayed ? $displayedFieldsMap[$moduleName][$field->apifieldname] : null;
                    
                    return [
                        'fieldname' => $field->apifieldname,
                        'fieldlabel' => $field->fieldlabel,
                        'fieldtype' => $field->fieldtype,
                        'database_field' => $field->fieldname,
                        'is_currently_displayed' => $isDisplayed,
                        
                    ];
                })
                ->toArray();
            
            $availableFields[$moduleName] = $allFields;
        }
        
        $modulesInvolved = collect($tablesInvolved)->pluck('module_name')->filter()->unique()->values();

        return response()->json([
            'success' => true,
            'query_id' => $queryId,
            'available_fields' => $availableFields,
            'modules_involved' => $modulesInvolved,
        ]);

    } catch (\Exception $e) {
        Log::error('Get Available Fields Error: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Failed to get available fields',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}

/**
 * Build a map of currently displayed fields from more_info
 */
private function buildDisplayedFieldsMap(array $moreInfo): array
{
    $map = [];
    
    // Get fields from filter_defaults
    $filterDefaults = $moreInfo['filter_defaults'] ?? [];
    foreach ($filterDefaults as $moduleName => $moduleData) {
        if (!isset($map[$moduleName])) {
            $map[$moduleName] = [];
        }
        
        foreach ($moduleData['default_fields'] as $field) {
            $map[$moduleName][$field['api_field']] = 'filter_default';
        }
    }
    
    // Get fields from where_conditions
    $whereConditions = $moreInfo['where_conditions'] ?? [];
    foreach ($whereConditions as $condition) {
        $moduleName = $condition['module_name'];
        $apiField = $condition['api_field'];
        
        if (!isset($map[$moduleName])) {
            $map[$moduleName] = [];
        }
        
        // If not already added by filter, add from where condition
        if (!isset($map[$moduleName][$apiField])) {
            $map[$moduleName][$apiField] = 'where_condition';
        }
    }
    
    return $map;
}


public function getAvailableFieldsFromFilters(Request $request)
 {
    $request->validate(['query_id' => 'required|uuid']);
    
    try {
        $queryId = $request->input('query_id');
        $orgId = auth()->user()?->organization_id;
        
        $query = AiGeneratedQuery::where('id', $queryId)->where('deleted', 0)->first();
        if (!$query) {
            throw new \Exception("Query not found.");
        }
        
        $moreInfo = $query->more_info;
        $tables = $moreInfo['tables'] ?? [];
        $originalSql = $query->query; // Get the original executed SQL
        
        $availableFields = [];
        
        // Get involved module names
        $moduleNames = [];
        foreach ($tables as $table) {
            $tableName = $table['name'];
            
            // Get module name from crm_fields
            $moduleName = DB::table('crm_fields')
                ->where('tablename', $tableName)
                ->value('modulename');
                
            if ($moduleName && !in_array($moduleName, $moduleNames)) {
                $moduleNames[] = $moduleName;
            }
        }
        
        // Get default fields from filters table for each module
        foreach ($moduleNames as $moduleName) {
            $filter = DB::table('filters')
                ->where('module_name', $moduleName)
                ->where('organization_id', $orgId)
                ->where('is_default', 1)
                ->where('deleted', 0)
                ->first();
                
            if ($filter && $filter->header_details) {
                $headerDetails = json_decode($filter->header_details, true);
                $availableFields[$moduleName] = $headerDetails;
            }
        }
        
        // Extract fields from WHERE conditions and add them
        $whereFields = $this->extractWhereConditionFields($originalSql);
        foreach ($whereFields as $field) {
            $moduleName = $field['module'];
            $fieldData = $field['field'];
            
            if (!isset($availableFields[$moduleName])) {
                $availableFields[$moduleName] = [];
            }
            
            // Add if not already present
            $exists = false;
            foreach ($availableFields[$moduleName] as $existing) {
                if ($existing['fieldname'] === $fieldData['fieldname']) {
                    $exists = true;
                    break;
                }
            }
            
            if (!$exists) {
                $availableFields[$moduleName][] = $fieldData;
            }
        }
        
        return response()->json([
            'success' => true,
            'query_id' => $queryId,
            'available_fields' => $availableFields,
            'modules_involved' => $moduleNames,
        ]);
        
    } catch (\Exception $e) {
        Log::error('Get Available Fields From Filters Error: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Failed to get available fields',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}

/**
 * STEP 4: Extract fields from WHERE conditions
 */
/**
 * Extract fields from WHERE conditions - IMPROVED VERSION
 */
private function extractWhereConditionFields(string $sql): array
{
    $fields = [];
    
    if (preg_match('/WHERE\s+(.+?)(?:\s+ORDER\s+BY|\s+GROUP\s+BY|\s+LIMIT|\s*$)/is', $sql, $match)) {
        $whereClause = $match[1];
        
        Log::info('extractWhereConditionFields - WHERE clause:', ['clause' => $whereClause]);
        
        // Pattern to match table.field or just field with operators
        preg_match_all('/\b(?:([a-zA-Z_][a-zA-Z0-9_]*)\.)?([a-zA-Z_][a-zA-Z0-9_]*)\s*(?:LIKE|=|>|<|>=|<=|!=|<>)/i', $whereClause, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $tableAlias = $match[1] ?? null;
            $fieldName = $match[2];
            
            // Skip SQL keywords and system fields
            if (in_array(strtoupper($fieldName), ['AND', 'OR', 'NOT', 'NULL', 'CONCAT', 'DELETED', 'ORGANIZATION_ID'])) {
                continue;
            }
            
            Log::info('extractWhereConditionFields - Processing field:', [
                'table' => $tableAlias,
                'field' => $fieldName
            ]);
            
            // ✅ FIX: Use table alias to find correct module
            $crmField = null;
            
            if ($tableAlias) {
                // We have table alias (e.g., 'c' for contacts) - use it directly
                $crmField = DB::table('crm_fields')
                    ->where('tablename', $tableAlias)
                    ->where('fieldname', $fieldName)
                    ->first(['fieldname', 'apifieldname', 'fieldlabel', 'fieldtype', 'modulename', 'tablename']);
                
                Log::info('extractWhereConditionFields - Lookup with table alias:', [
                    'table' => $tableAlias,
                    'field' => $fieldName,
                    'found' => $crmField ? true : false
                ]);
            }
            
            // If not found with alias or no alias, try to find in query tables only
            if (!$crmField) {
                // Extract tables from this SQL to limit search scope
                $queryTables = $this->extractAllTablesFromQuery($sql);
                $tableNames = array_column($queryTables, 'name');
                
                Log::info('extractWhereConditionFields - Fallback search in query tables:', [
                    'tables' => $tableNames,
                    'field' => $fieldName
                ]);
                
                $crmField = DB::table('crm_fields')
                    ->where('fieldname', $fieldName)
                    ->whereIn('tablename', $tableNames) // ✅ Only search in tables from this query
                    ->first(['fieldname', 'apifieldname', 'fieldlabel', 'fieldtype', 'modulename', 'tablename']);
            }
            
            if ($crmField) {
                $databaseColumn = $tableAlias ? "{$tableAlias}.{$fieldName}" : "{$crmField->tablename}.{$fieldName}";
                
                $fields[] = [
                    'database_column' => $databaseColumn,
                    'api_field' => $crmField->apifieldname,
                    'field_label' => $crmField->fieldlabel,
                    'field_type' => $crmField->fieldtype,
                    'module_name' => $crmField->modulename,
                    'source' => 'where_condition'
                ];
                
                Log::info('extractWhereConditionFields - Added field:', [
                    'field' => $crmField->apifieldname,
                    'module' => $crmField->modulename,
                    'table' => $crmField->tablename
                ]);
            } else {
                Log::warning('extractWhereConditionFields - Field not found in crm_fields:', [
                    'field' => $fieldName,
                    'table' => $tableAlias
                ]);
            }
        }
    }
    
    Log::info('extractWhereConditionFields - Final fields:', $fields);
    
    return $fields;
}

/**
 * Get fields from filters table + WHERE conditions automatically
 */

private function getFieldsFromFiltersAndWhere(string $queryId, string $orgId): array
{
    $query = AiGeneratedQuery::where('id', $queryId)->where('deleted', 0)->first();
    if (!$query) {
        return [];
    }

    $moreInfo = $query->more_info;
    $availableFields = [];

    // Get all involved modules from tables_involved
    $tablesInvolved = $moreInfo['tables_involved'] ?? [];
    $moduleNames = [];
    
    foreach ($tablesInvolved as $table) {
        if (!empty($table['module_name'])) {
            $moduleNames[] = $table['module_name'];
        }
    }

    Log::info('getFieldsFromFiltersAndWhere - Modules found:', $moduleNames);

    // For each module, get ALL default filter fields
    foreach (array_unique($moduleNames) as $moduleName) {
        
        // Get the default filter for this module - try with org first, then fallback
        $filter = DB::table('filters')
            ->where('module_name', $moduleName)
            ->where('organization_id', $orgId)
            ->where('is_default', 1)
            ->where('deleted', 0)
            ->first();
            
        // Fallback to any default filter for this module
        if (!$filter) {
            $filter = DB::table('filters')
                ->where('module_name', $moduleName)
                ->where('is_default', 1)
                ->where('deleted', 0)
                ->first();
        }

        Log::info('getFieldsFromFiltersAndWhere - Filter for module:', [
            'module' => $moduleName,
            'filter_found' => $filter ? true : false,
            'filter_name' => $filter->name ?? null,
            'header_details' => $filter->header_details ?? null
        ]);

        if ($filter && $filter->header_details) {
            $headerDetails = json_decode($filter->header_details, true);
            
            // The header_details structure is: {"Contact":["first_name","last_name",...], "Invoice":["subtotal","total",...]}
            $moduleFields = $headerDetails[$moduleName] ?? [];
            
            Log::info('getFieldsFromFiltersAndWhere - Fields for module:', [
                'module' => $moduleName,
                'fields' => $moduleFields
            ]);

            if (!isset($availableFields[$moduleName])) {
                $availableFields[$moduleName] = [];
            }

            // Add each field from the filter
            foreach ($moduleFields as $apiFieldName) {
                // Skip organization fields
                if (str_contains(strtolower($apiFieldName), 'organization')) {
                    continue;
                }

                // Get field details from crm_fields
                $crmField = DB::table('crm_fields')
                    ->where('modulename', $moduleName)
                    ->where('apifieldname', $apiFieldName)
                    ->first(['fieldname', 'apifieldname', 'fieldlabel', 'fieldtype']);

                if ($crmField) {
                    $availableFields[$moduleName][] = [
                        'fieldname' => $crmField->apifieldname,
                        'fieldlabel' => $crmField->fieldlabel,
                        'fieldtype' => $crmField->fieldtype,
                        'source' => 'filter_default'
                    ];
                    
                    Log::info('getFieldsFromFiltersAndWhere - Added filter field:', [
                        'module' => $moduleName,
                        'field' => $crmField->apifieldname
                    ]);
                }
            }
        }
    }

    // 2. Add WHERE CONDITION FIELDS (if not already present)
    $whereConditions = $moreInfo['where_conditions'] ?? [];
    
    Log::info('getFieldsFromFiltersAndWhere - WHERE conditions:', $whereConditions);
    
    foreach ($whereConditions as $whereField) {
        $moduleName = $whereField['module_name'];
        
        if (!isset($availableFields[$moduleName])) {
            $availableFields[$moduleName] = [];
        }

        // Check for duplicates based on fieldname
        $exists = false;
        foreach ($availableFields[$moduleName] as $existing) {
            if ($existing['fieldname'] === $whereField['api_field']) {
                $exists = true;
                break;
            }
        }

        if (!$exists) {
            $availableFields[$moduleName][] = [
                'fieldname' => $whereField['api_field'],
                'fieldlabel' => $whereField['field_label'],
                'fieldtype' => $whereField['field_type'],
                'source' => 'where_condition'
            ];
            
            Log::info('getFieldsFromFiltersAndWhere - Added WHERE field:', [
                'module' => $moduleName,
                'field' => $whereField['api_field']
            ]);
        }
    }

    Log::info('getFieldsFromFiltersAndWhere - Final available fields:', $availableFields);
    
    return $availableFields;
}

private function extractWhereConditionFieldsForMainModules(string $sql): array
{
    $fields = [];
    
    if (preg_match('/WHERE\s+(.+?)(?:\s+ORDER\s+BY|\s+GROUP\s+BY|\s+LIMIT|\s*$)/is', $sql, $match)) {
        $whereClause = $match[1];
        
        // Only look for specific business fields we care about
        $businessFields = ['first_name', 'last_name', 'total', 'status', 'phone_number', 'email'];
        
        foreach ($businessFields as $fieldName) {
            if (preg_match("/\b{$fieldName}\b/i", $whereClause)) {
                $crmField = DB::table('crm_fields')
                    ->where('fieldname', $fieldName)
                    ->whereIn('modulename', ['Invoice', 'Contact']) // Only these modules
                    ->first(['modulename', 'apifieldname', 'fieldlabel', 'fieldtype']);
                
                if ($crmField) {
                    $fields[] = [
                        'module' => $crmField->modulename,
                        'field' => [
                            'fieldname' => $crmField->apifieldname,
                            'fieldlabel' => $crmField->fieldlabel,
                            'fieldtype' => $crmField->fieldtype
                        ]
                    ];
                }
            }
        }
    }
    
    return $fields;
}
// Add this method to your AIQueryController class

/**
 * Re-execute a previously stored query without calling OpenAI
 */
public function reExecuteQuery(Request $request)
{
    $request->validate(['query_id' => 'required|uuid']);
    
    try {
        $queryId = $request->input('query_id');
        $orgId = auth()->user()?->organization_id;
        
        if (!$orgId) {
            throw new \Exception("Organization ID not found.");
        }
        
        // Get stored query
        $storedQuery = AiGeneratedQuery::where('id', $queryId)->where('deleted', 0)->first();
        if (!$storedQuery) {
            throw new \Exception("Query not found.");
        }
        
        $moreInfo = $storedQuery->more_info;
        $templateSql = $moreInfo['query_template'] ?? null;

        if (!$templateSql) {
            throw new \Exception('Query template not found in stored query.');
        }

        // Build a temporary full SQL from the template to expand its columns.
        // The expandSelectClause method expects a complete query with a wildcard.
        $tempSqlForExpansion = str_replace('<<COLUMNS>>', '*', $templateSql);

        // Replace ORG_ID placeholder to provide context for the expansion logic.
        $tempSqlForExpansionWithOrg = str_replace(["'<<ORG_ID>>'", '<<ORG_ID>>'], "'{$orgId}'", $tempSqlForExpansion);

        // Expand columns using the same logic as the initial query to ensure consistency.
        $sql = $this->expandSelectClause($tempSqlForExpansionWithOrg, $orgId);
        
        // Final cleanup of the expanded SQL.
        $sql = preg_replace('/;+\s*$/', '', $sql);
        
        // Execute with pagination
        $perPage = $request->get('per_page', 20);
        $paginator = DB::table(DB::raw("({$sql}) AS sub"))->paginate($perPage);
        $rawResults = DB::select($sql);
        
        // Apply column mapping
        $columnMap = $this->buildColumnMapping($sql);
        $results = collect($rawResults)->map(function ($row) use ($columnMap) {
            $row = (array) $row;
            $mapped = [];
            foreach ($row as $key => $value) {
                $mappedKey = $columnMap[$key] ?? $key;
                $mapped[$mappedKey] = $value;
            }
            return $mapped;
        })->toArray();
        
        // Get available fields for this query
        $availableFields = $this->getFieldsFromFiltersAndWhere($queryId, $orgId);
        
        return response()->json([
            'success' => true,
            'reused_query_id' => $queryId,
            'original_prompt' => $storedQuery->prompt,
            'sql' => $sql,
            'data' => [
                'details' => [
                    'records' => $results,
                    'available_fields' => $availableFields,
                    'modules_involved' => array_keys($availableFields),
                ],
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'from' => $paginator->firstItem(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'to' => $paginator->lastItem(),
                    'total' => $paginator->total(),
                ],
            ],
        ]);
        
    } catch (\Exception $e) {
        Log::error('Re-execute Query Error: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Query re-execution failed',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}

/**
 * Extract detailed table info with JOIN types
 */
private function extractDetailedTableInfo(string $sql): array
{
    $tables = [];
    
    // Extract FROM table
    if (preg_match('/\bFROM\s+([a-zA-Z0-9_]+)(?:\s+(?:AS\s+)?([a-zA-Z0-9_]+))?\s*/i', $sql, $match)) {
        $tableName = $match[1];
        $alias = (!empty($match[2]) && !in_array(strtoupper($match[2]), ['JOIN', 'WHERE', 'ORDER', 'GROUP', 'LIMIT'])) ? $match[2] : $tableName;
        
        $moduleName = DB::table('crm_fields')->where('tablename', $tableName)->value('modulename');
        
        $tables[] = [
            'name' => $tableName,
            'alias' => $alias,
            'module_name' => $moduleName,
            'table_type' => 'main',
            'join_type' => null,
            'join_condition' => null
        ];
    }
    
    // SIMPLIFIED JOIN detection - just find the table name
    if (preg_match('/JOIN\s+([a-zA-Z0-9_]+)\s+ON/i', $sql, $match)) {
        $tableName = $match[1];
        $moduleName = DB::table('crm_fields')->where('tablename', $tableName)->value('modulename');
        
        $tables[] = [
            'name' => $tableName,
            'alias' => $tableName,
            'module_name' => $moduleName,
            'table_type' => 'joined',
            'join_type' => 'INNER',
            'join_condition' => 'extracted from ON clause'
        ];
    }
    
    return $tables;
}


/**
 * Extract detailed column info showing source (AI generated)
 */
private function extractDetailedColumnInfo(string $sql, array $tables): array
{
    $columnDetails = [];
    
    if (preg_match('/SELECT\s+(.+?)\s+FROM/is', $sql, $match)) {
        $selectPart = trim($match[1]);
        
        // ALWAYS expand to include fields from ALL tables (Invoice + Contact + WHERE condition fields)
        if ($selectPart === 'invoices.*' || preg_match('/^([a-zA-Z0-9_]+)\.\*$/', $selectPart) || $selectPart === '*') {
            
            // For each involved table, get default filter fields + WHERE condition fields
            foreach ($tables as $table) {
                $moduleName = $table['module_name'];
                $tableName = $table['name'];
                $alias = $table['alias'];
                
                // Get default filter fields for this module
                $defaultFields = $this->getDefaultFilterFieldsForModule($moduleName);
                
                foreach ($defaultFields as $apiFieldName) {
                    $crmField = DB::table('crm_fields')
                        ->where('modulename', $moduleName)
                        ->where('apifieldname', $apiFieldName)
                        ->where('displaytype', 1)
                        ->first(['fieldname', 'apifieldname', 'fieldlabel', 'fieldtype']);
                    
                    if ($crmField && !str_contains(strtolower($crmField->apifieldname), 'organization')) {
                        $columnDetails[] = [
                            'database_column' => "{$alias}.{$crmField->fieldname}",
                            'api_field' => $crmField->apifieldname,
                            'field_label' => $crmField->fieldlabel,
                            'field_type' => $crmField->fieldtype,
                            'table_name' => $tableName,
                            'table_alias' => $alias,
                            'module_name' => $moduleName,
                            'source' => 'filter_default'
                        ];
                    }
                }
                
                // Add WHERE condition specific fields if not already added
                $whereFields = $this->getWhereConditionFieldsForModule($sql, $moduleName);
                foreach ($whereFields as $fieldName) {
                    $crmField = DB::table('crm_fields')
                        ->where('modulename', $moduleName)
                        ->where('fieldname', $fieldName)
                        ->first(['fieldname', 'apifieldname', 'fieldlabel', 'fieldtype']);
                    
                    if ($crmField && !str_contains(strtolower($crmField->apifieldname), 'organization')) {
                        // Check if already added
                        $alreadyExists = collect($columnDetails)->contains(function ($col) use ($crmField) {
                            return $col['api_field'] === $crmField->apifieldname;
                        });
                        
                        if (!$alreadyExists) {
                            $columnDetails[] = [
                                'database_column' => "{$alias}.{$crmField->fieldname}",
                                'api_field' => $crmField->apifieldname,
                                'field_label' => $crmField->fieldlabel,
                                'field_type' => $crmField->fieldtype,
                                'table_name' => $tableName,
                                'table_alias' => $alias,
                                'module_name' => $moduleName,
                                'source' => 'where_condition'
                            ];
                        }
                    }
                }
            }
        } else {
            // Explicit column list - parse as before
            $columns = preg_split('/,(?![^()]*\))/', $selectPart);
            foreach ($columns as $col) {
                $col = trim($col);
                $columnDetails[] = [
                    'database_column' => $col,
                    'source' => 'ai_generated_explicit'
                ];
            }
        }
    }
    
    return $columnDetails;
}
private function expandColumnsWithFiltersAndWhere(string $sql, string $orgId): array
{
    $tables = $this->extractAllTablesFromQuery($sql);
    $columns = $this->extractSelectedColumns($sql);

    // 1. Collect module names from crm_fields
    $moduleNames = [];
    foreach ($tables as $table) {
        $module = DB::table('crm_fields')
            ->where('tablename', $table['name'])
            ->value('modulename');
        if ($module) $moduleNames[$table['name']] = $module;
    }

    // 2. Add default header fields from filters
    foreach ($moduleNames as $tableName => $moduleName) {
        $filter = DB::table('filters')
            ->where('module_name', $moduleName)
            ->where('organization_id', $orgId)
            ->where('is_default', 1)
            ->where('deleted', 0)
            ->first();

        if ($filter && $filter->header_details) {
            $headers = json_decode($filter->header_details, true);
            foreach ($headers as $h) {
                if (!str_contains(strtolower($h['fieldname']), 'organization')) {
                    $columns[] = "{$tableName}.{$h['fieldname']}";
                }
            }
        }
    }

    // 3. Add WHERE fields
    $whereFields = $this->extractWhereConditionFields($sql);
    foreach ($whereFields as $wf) {
        $table = $wf['table'];
        $field = $wf['field']['fieldname'];
        if ($table && $field && !str_contains(strtolower($field), 'organization')) {
            $columns[] = "{$table}.{$field}";
        }
    }

    return array_unique($columns);
}

/**
 * Extract WHERE condition details
 */
private function extractWhereConditionDetails(string $sql): array
{
    $whereDetails = [];
    
    if (preg_match('/WHERE\s+(.+?)(?:\s+ORDER\s+BY|\s+GROUP\s+BY|\s+LIMIT|\s*$)/is', $sql, $match)) {
        $whereClause = $match[1];
        
        // Find business fields only (exclude system fields)
        $businessFields = ['first_name', 'last_name', 'total', 'status', 'phone_number', 'email'];
        
        foreach ($businessFields as $fieldName) {
            if (preg_match("/\b{$fieldName}\b/i", $whereClause)) {
                $crmField = DB::table('crm_fields')
                    ->where('fieldname', $fieldName)
                    ->whereIn('modulename', ['Invoice', 'Contact'])
                    ->first(['modulename', 'fieldname', 'apifieldname', 'fieldlabel', 'fieldtype']);
                
                if ($crmField) {
                    $whereDetails[] = [
                        'database_field' => $crmField->fieldname,
                        'api_field' => $crmField->apifieldname,
                        'field_label' => $crmField->fieldlabel,
                        'field_type' => $crmField->fieldtype,
                        'module_name' => $crmField->modulename,
                        'source' => 'where_condition',
                        'used_in_search' => true
                    ];
                }
            }
        }
    }
    
    return $whereDetails;
}
/**
 * Get filter defaults for involved modules
 */
private function getFilterDefaultsForModules(array $tables, string $orgId): array
{
    $filterDefaults = [];
    
    foreach ($tables as $table) {
        $moduleName = $table['module_name'];
        
        if (!$moduleName || !in_array($moduleName, ['Invoice', 'Contact'])) {
            continue;
        }
        
        // REMOVE organization_id filter - just get default filter for module
        $filter = DB::table('filters')
            ->where('module_name', $moduleName)
            ->where('is_default', 1)
            ->where('deleted', 0)
            ->first();
        
        if ($filter && $filter->header_details) {
            $headerDetails = json_decode($filter->header_details, true);
            
            if (isset($headerDetails[$moduleName])) {
                $fieldDetails = [];
                foreach ($headerDetails[$moduleName] as $apiFieldName) {
                    $crmField = DB::table('crm_fields')
                        ->where('modulename', $moduleName)
                        ->where('apifieldname', $apiFieldName)
                        ->first(['fieldname', 'apifieldname', 'fieldlabel', 'fieldtype']);
                    
                    if ($crmField) {
                        $fieldDetails[] = [
                            'database_field' => $crmField->fieldname,
                            'api_field' => $crmField->apifieldname,
                            'field_label' => $crmField->fieldlabel,
                            'field_type' => $crmField->fieldtype,
                            'source' => 'filter_default'
                        ];
                    }
                }
                
                $filterDefaults[$moduleName] = [
                    'table_name' => $table['name'],
                    'module_name' => $moduleName,
                    'default_fields' => $fieldDetails,
                    'filter_name' => $filter->name
                ];
            }
        }
    }
    
    return $filterDefaults;
}

private function getDefaultFilterFieldsForModule(string $moduleName): array
{
    $filter = DB::table('filters')
        ->where('module_name', $moduleName)
        ->where('is_default', 1)
        ->where('deleted', 0)
        ->first();
    
    if ($filter && $filter->header_details) {
        $headerDetails = json_decode($filter->header_details, true);
        return $headerDetails[$moduleName] ?? [];
    }
    
    return [];
}


/**
 * Get WHERE condition fields for a specific module
 */
private function getWhereConditionFieldsForModule(string $sql, string $moduleName): array
{
    $fields = [];
    
    if (preg_match('/WHERE\s+(.+?)(?:\s+ORDER\s+BY|\s+GROUP\s+BY|\s+LIMIT|\s*$)/is', $sql, $match)) {
        $whereClause = $match[1];
        
        // Business fields that might appear in WHERE
        $businessFields = ['first_name', 'last_name', 'total', 'status', 'phone_number', 'email', 'invoice_no'];
        
        foreach ($businessFields as $fieldName) {
            if (preg_match("/\b{$fieldName}\b/i", $whereClause)) {
                // Check if this field belongs to the specified module
                $fieldExists = DB::table('crm_fields')
                    ->where('modulename', $moduleName)
                    ->where('fieldname', $fieldName)
                    ->exists();
                
                if ($fieldExists) {
                    $fields[] = $fieldName;
                }
            }
        }
    }
    
    return $fields;
}

private function expandSelectClause(string $sql, string $orgId): string
{
    Log::info('expandSelectClause - Original SQL:', ['sql' => $sql]);
    
    // Check if we need to expand (contains table.* or just *)
    if (!preg_match('/SELECT\s+([a-zA-Z0-9_]+\.\*|\*)\s+FROM/i', $sql)) {
        Log::info('expandSelectClause - No expansion needed, already has explicit columns');
        return $sql; // Already has explicit columns
    }

    // Get involved tables
    $tables = $this->extractAllTablesFromQuery($sql);
    Log::info('expandSelectClause - Tables found:', $tables);
    
    $allColumns = [];

    foreach ($tables as $table) {
        $tableName = $table['name'];
        $alias = $table['alias'];
        
        // Get module name
        $moduleName = DB::table('crm_fields')
            ->where('tablename', $tableName)
            ->value('modulename');

        Log::info('expandSelectClause - Processing table:', [
            'table' => $tableName, 
            'alias' => $alias, 
            'module' => $moduleName
        ]);

        if (!$moduleName) continue;

        // Try with specific organization_id first, then fallback
        $filter = DB::table('filters')
            ->where('module_name', $moduleName)
            ->where('organization_id', $orgId)
            ->where('is_default', 1)
            ->where('deleted', 0)
            ->first();
            
        // Fallback if not found
        if (!$filter) {
            $filter = DB::table('filters')
                ->where('module_name', $moduleName)
                ->where('is_default', 1)
                ->where('deleted', 0)
                ->first();
        }

        Log::info('expandSelectClause - Filter found:', [
            'module' => $moduleName,
            'filter_found' => $filter ? true : false,
            'header_details' => $filter ? $filter->header_details : null
        ]);

        if ($filter && $filter->header_details) {
            $headerDetails = json_decode($filter->header_details, true);
            $moduleFields = $headerDetails[$moduleName] ?? [];
            
            Log::info('expandSelectClause - Module fields from filter:', [
                'module' => $moduleName,
                'fields' => $moduleFields
            ]);

            foreach ($moduleFields as $apiFieldName) {
                // Skip organization fields
                if (str_contains(strtolower($apiFieldName), 'organization')) {
                    Log::info('expandSelectClause - Skipping organization field:', ['field' => $apiFieldName]);
                    continue;
                }
                
                // Get database field name from crm_fields
                $crmField = DB::table('crm_fields')
                    ->where('modulename', $moduleName)
                    ->where('apifieldname', $apiFieldName)
                    ->where('displaytype', 1)
                    ->first(['fieldname']);

                if ($crmField) {
                    $columnName = "{$alias}.{$crmField->fieldname}";
                    $allColumns[] = $columnName;
                    Log::info('expandSelectClause - Added filter column:', ['column' => $columnName]);
                } else {
                    Log::warning('expandSelectClause - CRM field not found:', [
                        'module' => $moduleName,
                        'api_field' => $apiFieldName
                    ]);
                }
            }
        } else {
            Log::warning('expandSelectClause - No filter found for module:', ['module' => $moduleName]);
        }
    }

    // Add WHERE condition fields that aren't already included
    $whereFields = $this->extractWhereConditionFields($sql);
    Log::info('expandSelectClause - WHERE fields found:', $whereFields);
    
    foreach ($whereFields as $whereField) {
        $fieldColumn = $whereField['database_column'];
        
        // Skip organization, deleted fields, and duplicates
        if (in_array($fieldColumn, $allColumns) ||
            str_contains(strtolower($fieldColumn), 'organization') ||
            str_contains(strtolower($fieldColumn), 'deleted')) {
            Log::info('expandSelectClause - Skipping WHERE field:', ['field' => $fieldColumn, 'reason' => 'duplicate or system field']);
            continue;
        }
        
        $allColumns[] = $fieldColumn;
        Log::info('expandSelectClause - Added WHERE field:', ['column' => $fieldColumn]);
    }

    Log::info('expandSelectClause - All columns collected:', $allColumns);

    // Replace SELECT clause
    if (!empty($allColumns)) {
        $columnsStr = implode(', ', array_unique($allColumns));
        $expandedSql = preg_replace(
            '/SELECT\s+([a-zA-Z0-9_]+\.\*|\*)\s+FROM/i', 
            "SELECT {$columnsStr} FROM", 
            $sql
        );
        
        Log::info('expandSelectClause - Final expanded SQL:', ['sql' => $expandedSql]);
        return $expandedSql;
    }

    Log::warning('expandSelectClause - No columns found, returning original SQL');
    return $sql;
}

public function executeWithSelectedFields(Request $request)
{
    $request->validate([
        'query_id' => 'required|uuid',
        'add_fields' => 'nullable|array',
        'add_fields.*.fieldname' => 'required_with:add_fields|string',
        'add_fields.*.module' => 'required_with:add_fields|string',
        'remove_fields' => 'nullable|array',
        'remove_fields.*.fieldname' => 'required_with:remove_fields|string',
        'remove_fields.*.module' => 'required_with:remove_fields|string'
    ]);
    
    try {
        $queryId = $request->input('query_id');
        $addFields = $request->input('add_fields', []);
        $removeFields = $request->input('remove_fields', []);
        $orgId = auth()->user()?->organization_id;
        
        if (!$orgId) {
            throw new \Exception("Organization ID not found.");
        }
        
        // Get stored query
        $query = AiGeneratedQuery::where('id', $queryId)->where('deleted', 0)->first();
        if (!$query) {
            throw new \Exception("Query not found.");
        }
        
        $moreInfo = $query->more_info;
        $tablesInvolved = $moreInfo['tables_involved'] ?? [];
        
        // Build table name to alias mapping
        $tableMap = [];
        foreach ($tablesInvolved as $table) {
            $tableMap[$table['module_name']] = [
                'table_name' => $table['name'],
                'alias' => $table['alias']
            ];
        }
        
        // STEP 1: Start with currently displayed fields from more_info
        $displayedFields = $this->getDisplayedFieldsFromMoreInfo($moreInfo);
        
        Log::info('executeWithSelectedFields - Initial displayed fields:', $displayedFields);
        
        // STEP 2: Remove fields that user wants to remove
        $fieldsToRemove = collect($removeFields)->map(function($field) {
            return $field['module'] . '.' . $field['fieldname'];
        })->toArray();
        
        $finalFields = collect($displayedFields)->reject(function($field) use ($fieldsToRemove) {
            $key = $field['module'] . '.' . $field['fieldname'];
            return in_array($key, $fieldsToRemove);
        })->values()->toArray();
        
        Log::info('executeWithSelectedFields - After removal:', [
            'removed' => $fieldsToRemove,
            'remaining' => $finalFields
        ]);
        
        // STEP 3: Add new fields that user wants to add
        foreach ($addFields as $newField) {
            $moduleName = $newField['module'];
            $apiFieldName = $newField['fieldname'];
            
            // Check if already exists
            $exists = collect($finalFields)->contains(function($f) use ($moduleName, $apiFieldName) {
                return $f['module'] === $moduleName && $f['fieldname'] === $apiFieldName;
            });
            
            if (!$exists) {
                // Get field details from crm_fields
                $crmField = DB::table('crm_fields')
                    ->where('modulename', $moduleName)
                    ->where('apifieldname', $apiFieldName)
                    ->first(['fieldname', 'apifieldname', 'tablename']);
                
                if ($crmField) {
                    $finalFields[] = [
                        'module' => $moduleName,
                        'fieldname' => $apiFieldName,
                        'database_field' => $crmField->fieldname,
                        'source' => 'user_added'
                    ];
                }
            }
        }
        
        Log::info('executeWithSelectedFields - After additions:', $finalFields);
        
        // STEP 4: Build SELECT clause
        $selectColumns = [];
        foreach ($finalFields as $field) {
            $moduleName = $field['module'];
            $databaseField = $field['database_field'];
            
            if (!isset($tableMap[$moduleName])) continue;
            
            $alias = $tableMap[$moduleName]['alias'];
            $selectColumns[] = "{$alias}.{$databaseField}";
        }
        
        if (empty($selectColumns)) {
            throw new \Exception("No fields to display after modifications.");
        }
        
        // STEP 5: Extract FROM and WHERE clauses from template
        $template = $moreInfo['query_template'];
        
        preg_match('/FROM\s+(.+?)(?:\s+WHERE|\s*$)/is', $template, $fromMatch);
        $fromClause = trim($fromMatch[1] ?? '');
        
        preg_match('/WHERE\s+(.+?)(?:\s+ORDER|\s+GROUP|\s+LIMIT|\s*$)/is', $template, $whereMatch);
        $whereClause = trim($whereMatch[1] ?? '');
        
        // STEP 6: Build final SQL
        $selectClause = implode(', ', array_unique($selectColumns));
        $sql = "SELECT {$selectClause} FROM {$fromClause}";
        
        if (!empty($whereClause)) {
            $sql .= " WHERE {$whereClause}";
        }
        
        // Replace quoted version first
        $sql = str_replace("'<<ORG_ID>>'", "'{$orgId}'", $sql);
        // Then replace unquoted version (if any remain)
        $sql = str_replace("<<ORG_ID>>", "'{$orgId}'", $sql);
        $sql = preg_replace('/;+\s*$/', '', $sql);
        
        Log::info('executeWithSelectedFields - Final SQL:', ['sql' => $sql]);
        
        // STEP 7: Execute with pagination
        $perPage = $request->get('per_page', 20);
        $paginator = DB::table(DB::raw("({$sql}) AS sub"))->paginate($perPage);
        $rawResults = DB::select($sql);
        
        // Build column mapping for results
        $columnMap = $this->buildColumnMapping($sql);
        
        // Map results to API field names
        $results = collect($rawResults)->map(function ($row) use ($columnMap) {
            $row = (array) $row;
            $mapped = [];
            foreach ($row as $key => $value) {
                $mappedKey = $columnMap[$key] ?? $key;
                $mapped[$mappedKey] = $value;
            }
            return $mapped;
        })->toArray();
        
        return response()->json([
            'success' => true,
            'query_id' => $queryId,
            'sql' => $sql,
            'fields_summary' => [
                'total_fields' => count($finalFields),
                'added_count' => count($addFields),
                'removed_count' => count($removeFields),
            ],
            'data' => [
                'details' => [
                    'records' => $results,
                    'displayed_fields' => $finalFields,
                    'modules_involved' => array_unique(array_column($finalFields, 'module')),
                ],
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'from' => $paginator->firstItem(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'to' => $paginator->lastItem(),
                    'total' => $paginator->total(),
                ],
            ],
        ]);
        
    } catch (\Exception $e) {
        Log::error('Execute with Selected Fields Error: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Query execution failed',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}

/**
 * Get currently displayed fields from more_info
 */
        private function getDisplayedFieldsFromMoreInfo(array $moreInfo): array
        {
            $fields = [];
            
            // Get fields from filter_defaults
            $filterDefaults = $moreInfo['filter_defaults'] ?? [];
            foreach ($filterDefaults as $moduleName => $moduleData) {
                foreach ($moduleData['default_fields'] as $field) {
                    $fields[] = [
                        'module' => $moduleName,
                        'fieldname' => $field['api_field'],
                        'database_field' => $field['database_field'],
                        'source' => 'filter_default'
                    ];
                }
            }
            
            // Get fields from where_conditions
            $whereConditions = $moreInfo['where_conditions'] ?? [];
            foreach ($whereConditions as $condition) {
                // CRITICAL FIX: Verify module_name is correct
                $moduleName = $condition['module_name'];
                
                // Check if already added
                $exists = collect($fields)->contains(function($f) use ($moduleName, $condition) {
                    return $f['module'] === $moduleName && 
                        $f['fieldname'] === $condition['api_field'];
                });
                
                if (!$exists) {
                    // Extract database field properly
                    if (str_contains($condition['database_column'], '.')) {
                        $parts = explode('.', $condition['database_column']);
                        $databaseField = end($parts);
                    } else {
                        $databaseField = $condition['database_column'];
                    }
                    
                    $fields[] = [
                        'module' => $moduleName,
                        'fieldname' => $condition['api_field'],
                        'database_field' => $databaseField,
                        'source' => 'where_condition'
                    ];
                }
            }
            
            Log::info('getDisplayedFieldsFromMoreInfo - Final fields:', $fields);
            
            return $fields;
        }
}
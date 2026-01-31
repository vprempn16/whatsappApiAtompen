<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Modules\Api\V1\AISearch\Models\AiTableMeta;
use App\Modules\Api\V1\AISearch\Models\AiColumnMeta;

class OpenAIService
{
    public function generateSql(string $userPrompt): array
    {
        $schemaContext = $this->buildSchemaContextForPrompt($userPrompt);
        $systemPrompt = $this->buildSystemPrompt($schemaContext);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.openai.api_key'),
            'Content-Type' => 'application/json',
        ])->post('https://api.openai.com/v1/chat/completions', [
            'model' => 'gpt-4o',
            'messages' => $messages,
            'temperature' => 0.3,
            'max_tokens' => 500,
        ]);

        if (!$response->ok()) {
            Log::error('OpenAI API Error', [
                'status' => $response->status(),
                'body' => $response->body(),
                'prompt' => $userPrompt,
                'schemaContext' => $schemaContext,
            ]);
            throw new \Exception("OpenAI API error: " . $response->status());
        }

        $data = $response->json();

        $sql = trim($data['choices'][0]['message']['content'] ?? '');
        $sql = preg_replace('/^```sql\s*/i', '', $sql);
        $sql = preg_replace('/```$/i', '', $sql);

        return [
            'sql' => $sql,
            'tokens' => [
                'prompt_tokens' => $data['usage']['prompt_tokens'] ?? 0,
                'completion_tokens' => $data['usage']['completion_tokens'] ?? 0,
                'total_tokens' => $data['usage']['total_tokens'] ?? 0,
            ]
        ];
    }

private function buildSystemPrompt(string $schema): string
{
    return <<<PROMPT
You are a MySQL expert. Based on the schema, return only a valid and secure SELECT query.

Schema:
{$schema}

Guidelines:
- Output only one SQL SELECT statement — no explanation.
- Avoid INSERT, UPDATE, DELETE, DROP, ALTER.
- If a table has `organization_id`, always add: organization_id = '<<ORG_ID>>'
- If a column is a soft delete flag, add: column = 0
- Use AS alias only for computed fields, not for direct columns.
- For contacts: Use first_name and last_name separately. There is NO single 'name' column.
- When searching for people by name, use: WHERE (first_name LIKE '%search%' OR last_name LIKE '%search%' OR CONCAT(first_name, ' ', last_name) LIKE '%search%')
- Always check the exact column names in the schema before writing queries.
PROMPT;
}

   private function buildSchemaContextForPrompt(string $userPrompt): string
{
    $likelyTables = $this->guessTablesFromPrompt($userPrompt);
    $context = [];

    foreach ($likelyTables as $table) {
        $fields = AiColumnMeta::with('crmField')
            ->whereHas('crmField', fn($q) => $q->where('tablename', $table))
            ->get();

        $columns = [];

        foreach ($fields as $field) {
            $info = [];

            if ($field->semantic_role === 'soft_delete_flag') {
                $info[] = 'soft_delete';
            }

            if (is_array($field->semantic_context)) {
                if (($field->semantic_context['primary_role'] ?? '') === 'organization_reference') {
                    $info[] = 'org_ref';
                }
                if (!empty($field->semantic_context['picklist_values'])) {
                    $info[] = 'values: ' . implode('|', $field->semantic_context['picklist_values']);
                }
            }

            // Fixed typo: was $field->colpaiumn_name, should be $field->crmField->fieldname
            $columnName = $field->crmField->fieldname ?? 'unknown_column';
            
            if ($info) {
                $columns[] = "$columnName (" . implode(', ', $info) . ")";
            } else {
                $columns[] = $columnName;
            }
        }

        if ($columns) {
            $context[] = "$table:\n- " . implode("\n- ", $columns);
        }
    }

    return implode("\n\n", $context);
}

    private function guessTablesFromPrompt(string $prompt): array
    {
        $prompt = strtolower($prompt);
        $words = preg_split('/[\s,]+/', $prompt);
        $matchedTables = [];

        $tableMetas = AiTableMeta::all();

        foreach ($tableMetas as $meta) {
            $purpose = strtolower($meta->ai_purpose ?? '');
            foreach ($words as $word) {
                if (str_contains($purpose, $word)) {
                    $matchedTables[] = $meta->table_name;
                    break;
                }
            }
        }

        return array_unique($matchedTables);
    }
}

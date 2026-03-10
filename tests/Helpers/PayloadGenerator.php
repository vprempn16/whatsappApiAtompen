<?php

namespace Tests\Helpers;

use App\Models\FieldModelManager;
use Illuminate\Support\Str;
use Faker\Factory as Faker;

class PayloadGenerator
{
    /**
     * Generate a valid payload for a module's Create/Update actions.
     * 
     * @param string $module
     * @param array $overrides Manual overrides for relations (e.g., ['assigned_to' => $userId])
     * @param bool $onlyMandatory If true, only generates mandatory fields
     * @return array
     */
    public static function generate(string $module, array $overrides = [], bool $onlyMandatory = false): array
    {
        $faker = Faker::create();
        // Use 'CreateView' to simulate creating a new record via the API
        // For admin users, this will fetch all fields
        $manager = FieldModelManager::make($module, 'CreateView');
        $fields = $manager->getApiFormFields();

        $payload = [];

        foreach ($fields as $field) {
            $apiName = $field['fieldname'];
            $type = strtolower($field['fieldtype']);
            $isMandatory = $field['mandatory'];

            // Skip ID and Org ID fields, these are usually handled by the system
            if (in_array($apiName, ['id', 'organization_id'], true)) {
                continue;
            }

            // If the test explicitly provides a value for this field, use it.
            // This is primarily for providing valid foreign keys to relationship fields (e.g. contact_id)
            if (array_key_exists($apiName, $overrides)) {
                $payload[$apiName] = $overrides[$apiName];
                continue;
            }

            // Skip optional fields if requested
            if ($onlyMandatory && !$isMandatory) {
                continue;
            }

            // Generate fake data based on FieldModel.php rules
            $value = null;

            // Picklist/Multiselects automatically pull from their defined database options
            if (in_array($type, ['picklist', 'multiselect']) && !empty($field['options'])) {
                $options = array_column($field['options'], 'value');
                $value = $faker->randomElement($options);
                if ($type === 'multiselect') {
                    $value = implode(',', $faker->randomElements($options, min(count($options), 2)));
                }
            } else {
                switch ($type) {
                    case 'email':
                        $value = $faker->unique()->safeEmail;
                        break;
                    case 'integer':
                        $value = $faker->numberBetween(1, 1000);
                        break;
                    case 'decimal':
                        $value = $faker->randomFloat(2, 1, 1000);
                        break;
                    case 'date':
                        $value = $faker->date('Y-m-d');
                        break;
                    case 'datetime':
                    case 'timestamp':
                        $value = $faker->dateTimeThisYear()->format('Y-m-d H:i:s');
                        break;
                    case 'boolean':
                        $value = $faker->boolean ? 1 : 0;
                        break;
                    case 'phone':
                        $value = $faker->numerify('##########'); // 10 digit phone per regex
                        break;
                    case 'uuid':
                    case 'relation':
                    case 'relationpicklist':
                        // Fallback UUID if no override provided.
                        // Some systems perform 'exists' checks, so if this fails validation,
                        // the test MUST provide the relation ID via the $overrides array.
                        $value = (string) Str::uuid();
                        break;
                    case 'string':
                        $value = substr($faker->sentence(2), 0, 255);
                        $value = rtrim($value, '. '); // remove punctuation
                        // Edge case enforced by FieldModel: currency code must be 3 letters
                        if ($apiName === 'currencyCode' || $apiName === 'currency_code') {
                            $value = 'USD';
                        }
                        break;
                    case 'text':
                    case 'textarea':
                        $value = substr($faker->paragraph, 0, 1000);
                        break;
                    default:
                        $value = $faker->word;
                        break;
                }
            }

            $payload[$apiName] = $value;
        }

        return $payload;
    }
}

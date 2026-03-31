<?php

namespace App\Models;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class FieldModel
{
	protected $data;

	public function __construct($crmFieldRow)
	{
		// Ensure is_custom_field key is always present
		if (!isset($crmFieldRow->is_custom_field)) {
			$crmFieldRow->is_custom_field = 0;
		}
		$this->data = $crmFieldRow;
	}

	public function getAPIName(): string
	{
		return $this->data->apifieldname ?? $this->data->fieldname;
	}

	public function getId(): string
	{
		return $this->data->id;
	}
	public function getDisplaytype()
	{
		return $this->data->displaytype;
	}
	public function getFieldName(): string
	{
		return $this->data->fieldname;
	}

	public function getTableName(): string
	{
		return $this->data->tablename;
	}

	public function getLabel(): string
	{
		return $this->data->fieldlabel;
	}

	public function getFieldType(): string
	{
		return $this->data->fieldtype;
	}

	public function isMandatory(): bool
	{
		return $this->data->mandatory;
	}

	public function isCustomField(): bool
	{
		return (bool) $this->data->is_custom_field;
	}

	public function validate($value)
	{
		$type = strtolower($this->getFieldType());




		// Picklist/multiselect value validation
		if (in_array($type, ['picklist', 'multiselect'], true)) {
			$options = \DB::table('picklist_values')
				->where('field_id', $this->getId())
				->where('status', 1)
				->pluck('value')
				->toArray();
			if ($type === 'multiselect') {
				$vals = is_array($value) ? $value : (is_string($value) ? explode(',', $value) : []);
				foreach ($vals as $v) {
					if (!in_array($v, $options)) {
						throw ValidationException::withMessages([$this->getAPIName() => "Invalid value for {$this->getLabel()}"]);
					}
				}
			} else {
				if (!in_array($value, $options)) {
					throw ValidationException::withMessages([$this->getAPIName() => "Invalid value for {$this->getLabel()}"]);
				}
			}
		}
		$rules = [];
		$apiField = $this->getAPIName();
		$label = $this->getLabel();

		// Required vs Nullable
		if ($this->isMandatory()) {
			$rules[] = 'required';
		} else {
			$rules[] = 'nullable';
		}

		// Skip validation for special fields
		if (in_array($this->getFieldName(), ['id', 'organization_id'], true)) {
			return;
		}

		switch ($type) {
			case 'email':
				$rules[] = 'email';
				break;

			case 'integer':
			case 'decimal':
				$rules[] = 'numeric';
				break;

			case 'date':
				$rules[] = 'date';
				break;

			case 'datetime':
			case 'timestamp':
				$rules[] = 'date'; // Laravel date works for datetime & timestamp too
				break;

			case 'boolean':
				$rules[] = 'boolean';
				break;

			case 'phone':
				$rules[] = 'regex:/^[0-9+\-\s]{6,20}$/';
				break;

			case 'uuid':
			case 'relation':
			case 'relationpicklist':
				$rules[] = 'uuid';
				break;

			case 'picklist':
			case 'string':
			case 'text':
			case 'textarea':
				$rules[] = 'string';
				break;

			default:
				$rules[] = 'string'; // Fallback
				break;
		}

		// Enforce 3-letter currency codes (e.g., USD)
		if ($apiField === 'currencyCode' || $this->getFieldName() === 'currency_code') {
			$rules[] = 'size:3';
		}
		if (in_array($type, ['decimal', 'integer'], true)) {
			if ($value === null || $value === '' || $value === false) {
				$value = 0;
			}

			if ($type === 'decimal') {
				$value = number_format((float) $value, 2, '.', '');
			} else {
				$value = (int) $value;
			}
		}
		$validator = Validator::make(
			[$apiField => $value],
			[$apiField => $rules],
			[$apiField . '.required' => "{$label} is required."]
		);

		if ($validator->fails()) {
			//	dd( $validator->errors()->toArray());
			throw new ValidationException($validator);
		}

		if ($value === '' || $value === null) {
			if (in_array($type, ['date', 'datetime', 'timestamp'], true)) {
				return null;
			}
			return '';
		}

		return $value;
	}
}

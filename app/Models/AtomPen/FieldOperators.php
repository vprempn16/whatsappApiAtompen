<?php

/**
 * Field Operators
 * Auto-generated from database dump
 * Defines operators for field filtering (equals, contains, greater than, etc.)
 */

return [
    ['id' => 1, 'field_type' => 'string', 'operator_key' => 'equals', 'operator_label' => 'Equals', 'operator_query' => '=', 'description' => 'Checks if the value is equal to the input'],
    ['id' => 2, 'field_type' => 'string', 'operator_key' => 'not_equals', 'operator_label' => 'Not Equals', 'operator_query' => '!=', 'description' => 'Checks if the value is not equal to the input'],
    ['id' => 3, 'field_type' => 'string', 'operator_key' => 'contains', 'operator_label' => 'Contains', 'operator_query' => 'LIKE', 'description' => 'Checks if the value contains the input'],
    ['id' => 4, 'field_type' => 'string', 'operator_key' => 'not_contains', 'operator_label' => 'Does Not Contain', 'operator_query' => 'NOT LIKE', 'description' => 'Checks if the value does not contain the input'],
    ['id' => 5, 'field_type' => 'string', 'operator_key' => 'starts_with', 'operator_label' => 'Starts With', 'operator_query' => 'LIKE', 'description' => 'Checks if the value starts with the input'],
    ['id' => 6, 'field_type' => 'string', 'operator_key' => 'ends_with', 'operator_label' => 'Ends With', 'operator_query' => 'LIKE', 'description' => 'Checks if the value ends with the input'],
    ['id' => 7, 'field_type' => 'string', 'operator_key' => 'is_null', 'operator_label' => 'Is Empty', 'operator_query' => 'IS NULL', 'description' => 'Checks if the value is empty'],
    ['id' => 8, 'field_type' => 'string', 'operator_key' => 'is_not_null', 'operator_label' => 'Is Not Empty', 'operator_query' => 'IS NOT NULL', 'description' => 'Checks if the value is not empty'],
    ['id' => 9, 'field_type' => 'number', 'operator_key' => 'equals', 'operator_label' => 'Equals', 'operator_query' => '=', 'description' => 'Checks if the number equals the input'],
    ['id' => 10, 'field_type' => 'number', 'operator_key' => 'not_equals', 'operator_label' => 'Not Equals', 'operator_query' => '!=', 'description' => 'Checks if the number is not equal to the input'],
    ['id' => 11, 'field_type' => 'number', 'operator_key' => 'greater_than', 'operator_label' => 'Greater Than', 'operator_query' => '>', 'description' => 'Checks if the number is greater than the input'],
    ['id' => 12, 'field_type' => 'number', 'operator_key' => 'less_than', 'operator_label' => 'Less Than', 'operator_query' => '<', 'description' => 'Checks if the number is less than the input'],
    ['id' => 13, 'field_type' => 'number', 'operator_key' => 'greater_than_or_equals', 'operator_label' => 'Greater Than or Equals', 'operator_query' => '>=', 'description' => 'Checks if the number is greater than or equal to the input'],
    ['id' => 14, 'field_type' => 'number', 'operator_key' => 'less_than_or_equals', 'operator_label' => 'Less Than or Equals', 'operator_query' => '<=', 'description' => 'Checks if the number is less than or equal to the input'],
    ['id' => 15, 'field_type' => 'number', 'operator_key' => 'between', 'operator_label' => 'Between', 'operator_query' => 'BETWEEN', 'description' => 'Checks if the number is between two values'],
    ['id' => 16, 'field_type' => 'number', 'operator_key' => 'not_between', 'operator_label' => 'Not Between', 'operator_query' => 'NOT BETWEEN', 'description' => 'Checks if the number is not between two values'],
    ['id' => 17, 'field_type' => 'date', 'operator_key' => 'equals', 'operator_label' => 'Equals', 'operator_query' => '=', 'description' => 'Checks if the date equals the input'],
    ['id' => 18, 'field_type' => 'date', 'operator_key' => 'not_equals', 'operator_label' => 'Not Equals', 'operator_query' => '!=', 'description' => 'Checks if the date is not equal to the input'],
    ['id' => 19, 'field_type' => 'date', 'operator_key' => 'after', 'operator_label' => 'After', 'operator_query' => '>', 'description' => 'Checks if the date is after the input'],
    ['id' => 20, 'field_type' => 'date', 'operator_key' => 'before', 'operator_label' => 'Before', 'operator_query' => '<', 'description' => 'Checks if the date is before the input'],
    ['id' => 21, 'field_type' => 'date', 'operator_key' => 'on_or_after', 'operator_label' => 'On or After', 'operator_query' => '>=', 'description' => 'Checks if the date is on or after the input'],
    ['id' => 22, 'field_type' => 'date', 'operator_key' => 'on_or_before', 'operator_label' => 'On or Before', 'operator_query' => '<=', 'description' => 'Checks if the date is on or before the input'],
    ['id' => 23, 'field_type' => 'date', 'operator_key' => 'between', 'operator_label' => 'Between', 'operator_query' => 'BETWEEN', 'description' => 'Checks if the date is between two values'],
    ['id' => 24, 'field_type' => 'date', 'operator_key' => 'not_between', 'operator_label' => 'Not Between', 'operator_query' => 'NOT BETWEEN', 'description' => 'Checks if the date is not between two values'],
    ['id' => 25, 'field_type' => 'date', 'operator_key' => 'is_null', 'operator_label' => 'Is Empty', 'operator_query' => 'IS NULL', 'description' => 'Checks if the date field is empty'],
    ['id' => 26, 'field_type' => 'date', 'operator_key' => 'is_not_null', 'operator_label' => 'Is Not Empty', 'operator_query' => 'IS NOT NULL', 'description' => 'Checks if the date field is not empty'],
    ['id' => 27, 'field_type' => 'boolean', 'operator_key' => 'equals', 'operator_label' => 'Equals', 'operator_query' => '=', 'description' => 'Checks if the value equals true or false'],
    ['id' => 28, 'field_type' => 'boolean', 'operator_key' => 'not_equals', 'operator_label' => 'Not Equals', 'operator_query' => '!=', 'description' => 'Checks if the value does not equal true or false'],
    ['id' => 29, 'field_type' => 'list', 'operator_key' => 'in', 'operator_label' => 'Matches Any Value', 'operator_query' => 'IN', 'description' => 'Checks if the value matches any value in the list'],
    ['id' => 30, 'field_type' => 'list', 'operator_key' => 'not_in', 'operator_label' => 'Does Not Match Any Value', 'operator_query' => 'NOT IN', 'description' => 'Checks if the value does not match any value in the list'],
    ['id' => 31, 'field_type' => 'ip', 'operator_key' => 'equals', 'operator_label' => 'Equals', 'operator_query' => '=', 'description' => 'Checks if the IP address equals the input'],
    ['id' => 32, 'field_type' => 'ip', 'operator_key' => 'not_equals', 'operator_label' => 'Not Equals', 'operator_query' => '!=', 'description' => 'Checks if the IP address is not equal to the input'],
    ['id' => 33, 'field_type' => 'ip', 'operator_key' => 'like', 'operator_label' => 'Matches Pattern', 'operator_query' => 'LIKE', 'description' => 'Checks if the IP address matches a pattern'],
    ['id' => 34, 'field_type' => 'json', 'operator_key' => 'json_contains', 'operator_label' => 'Contains Key/Value', 'operator_query' => 'JSON_CONTAINS', 'description' => 'Checks if the JSON contains the specified key or value'],
    ['id' => 35, 'field_type' => 'geolocation', 'operator_key' => 'within', 'operator_label' => 'Within Radius', 'operator_query' => 'WITHIN', 'description' => 'Checks if the location is within a specific radius (e.g., 50 km)'],
];

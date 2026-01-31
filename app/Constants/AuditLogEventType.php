<?php

namespace App\Constants;

/**
 * Audit Log Event Type Constants
 * 
 * Standardized event types for audit logging across the CRM system.
 * These constants ensure type safety and prevent typos when creating audit logs.
 */
class AuditLogEventType
{
    /** Record creation */
    public const CREATE = 'create';
    
    /** Record modification */
    public const UPDATE = 'update';
    
    /** Record deletion */
    public const DELETE = 'delete';
    
    /** Relationship creation */
    public const RELATE = 'relate';
    
    /** Email activity/communication */
    public const EMAIL = 'email';
    
    /** Call activity/communication */
    public const CALL = 'call';
    
    /** Meeting activity */
    public const MEETING = 'meeting';
    
    /** Record conversion/transfer (e.g., Quote → Invoice, Lead → Contact) */
    public const TRANSFER = 'transfer';
    
    /**
     * Get all valid event types
     * 
     * @return array
     */
    public static function all(): array
    {
        return [
            self::CREATE,
            self::UPDATE,
            self::DELETE,
            self::RELATE,
            self::EMAIL,
            self::CALL,
            self::MEETING,
            self::TRANSFER,
        ];
    }
    
    /**
     * Check if an event type is valid
     * 
     * @param string $eventType
     * @return bool
     */
    public static function isValid(string $eventType): bool
    {
        return in_array($eventType, self::all(), true);
    }
}

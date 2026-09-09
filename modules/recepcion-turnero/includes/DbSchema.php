<?php
/**
 * Utilidades de esquema idempotente para Recepción y Turnero.
 */

declare(strict_types=1);

final class RtDbSchema
{
    public static function tableExists(PDO $db, string $table): bool
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public static function columnExists(PDO $db, string $table, string $column): bool
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public static function foreignKeyExists(PDO $db, string $table, string $constraintName): bool
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = \'FOREIGN KEY\''
        );
        $stmt->execute([$table, $constraintName]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public static function addForeignKeyIfMissing(
        PDO $db,
        string $table,
        string $constraintName,
        string $ddl
    ): bool {
        if (self::foreignKeyExists($db, $table, $constraintName)) {
            return false;
        }
        $db->exec($ddl);
        return true;
    }
}

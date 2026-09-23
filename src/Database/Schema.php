<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Database;

use PDO;

final class Schema
{
    public static function create(PDO $pdo): void
    {
        $pdo->exec('PRAGMA foreign_keys = ON');

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS campaigns (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                starts_at TEXT NOT NULL,
                closes_at TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'draft'
                    CHECK (status IN ('draft', 'open', 'closed', 'matched')),
                matched_at TEXT NULL,
                created_at TEXT NOT NULL
            )
        SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS profiles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE
            )
        SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS groups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                capacity INTEGER NOT NULL CHECK (capacity >= 0)
            )
        SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS students (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                student_number TEXT NOT NULL UNIQUE,
                surname TEXT NOT NULL,
                first_name TEXT NOT NULL,
                profile_id INTEGER NOT NULL,
                initial_group_id INTEGER NOT NULL,
                FOREIGN KEY (profile_id) REFERENCES profiles(id),
                FOREIGN KEY (initial_group_id) REFERENCES groups(id)
            )
        SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS profile_groups (
                profile_id INTEGER NOT NULL,
                group_id INTEGER NOT NULL,
                PRIMARY KEY (profile_id, group_id),
                FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE,
                FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE
            )
        SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                campaign_id INTEGER NOT NULL,
                student_id INTEGER NOT NULL,
                first_submitted_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                withdrawn_at TEXT NULL,
                target_1_group_id INTEGER NOT NULL,
                target_2_group_id INTEGER NULL,
                target_3_group_id INTEGER NULL,
                FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
                FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
                FOREIGN KEY (target_1_group_id) REFERENCES groups(id),
                FOREIGN KEY (target_2_group_id) REFERENCES groups(id),
                FOREIGN KEY (target_3_group_id) REFERENCES groups(id),
                UNIQUE (campaign_id, student_id)
            )
        SQL);

        $pdo->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_requests_campaign
            ON requests(campaign_id)
        SQL);

        $pdo->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_requests_student
            ON requests(student_id)
        SQL);
    }
}

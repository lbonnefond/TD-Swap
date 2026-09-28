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

        /*
         * Snapshot of the groups used by a campaign.
         *
         * group_id points to the source/reference group.
         * id is the campaign-specific group identity used by requests
         * and campaign_students.
         */
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS campaign_groups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                campaign_id INTEGER NOT NULL,
                group_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                capacity INTEGER NOT NULL CHECK (capacity >= 0),

                UNIQUE (campaign_id, id),
                UNIQUE (campaign_id, group_id),
                UNIQUE (campaign_id, name),

                FOREIGN KEY (campaign_id)
                    REFERENCES campaigns(id)
                    ON DELETE CASCADE,

                FOREIGN KEY (group_id)
                    REFERENCES groups(id)
            )
        SQL);

        /*
         * Snapshot of the students participating in a campaign.
         *
         * initial_group_id points to the campaign-specific group snapshot,
         * not to the mutable global groups table.
         */
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS campaign_students (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                campaign_id INTEGER NOT NULL,
                student_id INTEGER NOT NULL,
                student_number TEXT NOT NULL,
                surname TEXT NOT NULL,
                first_name TEXT NOT NULL,
                profile_id INTEGER NOT NULL,
                initial_group_id INTEGER NOT NULL,

                UNIQUE (campaign_id, id),
                UNIQUE (campaign_id, student_id),
                UNIQUE (campaign_id, student_number),

                FOREIGN KEY (campaign_id)
                    REFERENCES campaigns(id)
                    ON DELETE CASCADE,

                FOREIGN KEY (student_id)
                    REFERENCES students(id),

                FOREIGN KEY (profile_id)
                    REFERENCES profiles(id),

                FOREIGN KEY (campaign_id, initial_group_id)
                    REFERENCES campaign_groups(campaign_id, id)
            )
        SQL);

        /*
         * Snapshot of the profile/group eligibility rules for a campaign.
         */
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS campaign_profile_groups (
                campaign_id INTEGER NOT NULL,
                profile_id INTEGER NOT NULL,
                campaign_group_id INTEGER NOT NULL,

                PRIMARY KEY (campaign_id, profile_id, campaign_group_id),

                FOREIGN KEY (campaign_id)
                    REFERENCES campaigns(id)
                    ON DELETE CASCADE,

                FOREIGN KEY (profile_id)
                    REFERENCES profiles(id),

                FOREIGN KEY (campaign_id, campaign_group_id)
                    REFERENCES campaign_groups(campaign_id, id)
            )
        SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                campaign_id INTEGER NOT NULL,
                campaign_student_id INTEGER NOT NULL,
                first_submitted_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                withdrawn_at TEXT NULL,
                target_1_campaign_group_id INTEGER NOT NULL,
                target_2_campaign_group_id INTEGER NULL,
                target_3_campaign_group_id INTEGER NULL,

                UNIQUE (campaign_id, campaign_student_id),

                FOREIGN KEY (campaign_id)
                    REFERENCES campaigns(id)
                    ON DELETE CASCADE,

                FOREIGN KEY (campaign_id, campaign_student_id)
                    REFERENCES campaign_students(campaign_id, id)
                    ON DELETE CASCADE,

                FOREIGN KEY (campaign_id, target_1_campaign_group_id)
                    REFERENCES campaign_groups(campaign_id, id),

                FOREIGN KEY (campaign_id, target_2_campaign_group_id)
                    REFERENCES campaign_groups(campaign_id, id),

                FOREIGN KEY (campaign_id, target_3_campaign_group_id)
                    REFERENCES campaign_groups(campaign_id, id)
            )
        SQL);

        $pdo->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_requests_campaign
            ON requests(campaign_id)
        SQL);

        $pdo->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_requests_student
            ON requests(campaign_student_id)
        SQL);

        $pdo->exec(<<<'SQL'
    CREATE TABLE IF NOT EXISTS matches (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        campaign_id INTEGER NOT NULL,
        student_id INTEGER NOT NULL,
        from_group_id INTEGER NOT NULL,
        to_group_id INTEGER NOT NULL,
        preference_rank INTEGER NOT NULL,
        created_at TEXT NOT NULL,

        FOREIGN KEY (campaign_id)
            REFERENCES campaigns(id)
            ON DELETE CASCADE,

        FOREIGN KEY (student_id)
            REFERENCES campaign_students(id)
            ON DELETE CASCADE,

        FOREIGN KEY (from_group_id)
            REFERENCES campaign_groups(id),

        FOREIGN KEY (to_group_id)
            REFERENCES campaign_groups(id)
    )
SQL);

        $pdo->exec(<<<'SQL'
    CREATE INDEX IF NOT EXISTS idx_matches_campaign
    ON matches(campaign_id)
SQL);
    }
}

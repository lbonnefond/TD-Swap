<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Database;

use PDO;

final class DatabaseFactory
{
    public static function create(string $path): PDO
    {
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA synchronous = NORMAL');

        Schema::create($pdo);

        return $pdo;
    }

    /**
     * Ouvre une BDD de travail existante (snapshot importé).
     * Ne crée pas de schéma : il doit déjà être présent.
     */
    public static function createForCampaign(string $dbPath): PDO
    {
        if (!is_file($dbPath)) {
            throw new \RuntimeException('BDD introuvable : ' . $dbPath);
        }
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        // Pas de Schema::create : le snapshot contient déjà le schéma complet.
        return $pdo;
    }
}

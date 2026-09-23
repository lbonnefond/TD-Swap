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

        Schema::create($pdo);

        return $pdo;
    }
}

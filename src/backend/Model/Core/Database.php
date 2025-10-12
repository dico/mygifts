<?php
// src/backend/Model/Core/Database.php
namespace App\Model\Core;

use Illuminate\Database\Capsule\Manager as Capsule;

class Database {
    protected static bool $initialized = false;

    public static function init(): void {
        if (self::$initialized) return;

        $capsule = new Capsule;

        $capsule->addConnection([
            'driver'    => getenv('DB_DRIVER') ?: 'sqlite',
            'host'      => getenv('DB_HOST') ?: '127.0.0.1',
            'database'  => getenv('DB_DATABASE') ?: '/var/www/database/workapps.db',
            'username'  => getenv('DB_USERNAME') ?: 'root',
            'password'  => getenv('DB_PASSWORD') ?: '',
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
        ]);

        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        self::$initialized = true;
    }

    public static function query($table = null) {
        self::init();
        return $table ? Capsule::table($table) : Capsule::connection();
    }
}
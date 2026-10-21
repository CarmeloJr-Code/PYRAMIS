<?php

namespace Tests\Feature;

use PDO;
use Tests\TestCase;

class DatabaseConnectionTest extends TestCase
{
    public function test_the_postgres_connection_is_not_persistent_unless_asked(): void
    {
        $this->assertFalse($this->pgsqlOptionsFor(null)[PDO::ATTR_PERSISTENT]);
    }

    public function test_it_is_persistent_when_the_deployment_asks(): void
    {
        // Production sets it (`render.yaml`): the database is a region away, and
        // a fresh connection pays the TCP, TLS and auth handshake every request.
        $this->assertTrue($this->pgsqlOptionsFor('true')[PDO::ATTR_PERSISTENT]);
    }

    public function test_an_explicit_false_turns_it_off(): void
    {
        $this->assertFalse($this->pgsqlOptionsFor('false')[PDO::ATTR_PERSISTENT]);
    }

    /**
     * The pgsql connection's PDO options as they resolve for a given setting.
     *
     * Read by evaluating the file rather than through config(), which holds
     * whatever was resolved when this test process booted.
     *
     * @return array<int, mixed>
     */
    private function pgsqlOptionsFor(?string $persistent): array
    {
        $original = $_SERVER['DB_PERSISTENT'] ?? null;

        if ($persistent === null) {
            unset($_SERVER['DB_PERSISTENT']);
        } else {
            $_SERVER['DB_PERSISTENT'] = $persistent;
        }

        try {
            return (require base_path('config/database.php'))['connections']['pgsql']['options'];
        } finally {
            if ($original === null) {
                unset($_SERVER['DB_PERSISTENT']);
            } else {
                $_SERVER['DB_PERSISTENT'] = $original;
            }
        }
    }
}

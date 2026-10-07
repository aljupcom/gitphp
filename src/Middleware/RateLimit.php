<?php

declare(strict_types=1);

namespace App\Middleware;

use App\App;

final class RateLimit
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Check whether the action is allowed within the rate limit.
     * @param int $maxAttempts  Maximum number of attempts within the decay window
     * @param int $decayMinutes Time window in minutes before attempts reset
     */
    public function check(string $key, int $maxAttempts = 5, int $decayMinutes = 15): bool
    {
        $data = $this->getAttempts($key);

        if ($data === null) return true;

        // If the decay window has passed, treat as fresh
        if (time() - $data['first_attempt'] > $decayMinutes * 60) {
            $this->reset($key);
            return true;
        }

        return $data['attempts'] < $maxAttempts;
    }

    /** Increment the attempt counter for the given key. */
    public function increment(string $key): void
    {
        $data = $this->getAttempts($key);

        if ($data === null) {
            $data = [
                'attempts'      => 0,
                'first_attempt' => time(),
            ];
        }

        $data['attempts']++;

        $this->saveAttempts($key, $data);
    }

    /** Reset the attempt counter for the given key. */
    public function reset(string $key): void
    {
        $settingKey = 'rate_limit_' . $key;

        $this->app->db()->execute(
            'DELETE FROM `settings` WHERE `setting_key` = :key',
            ['key' => $settingKey],
        );
    }

    /**
     * Retrieve attempt data from the settings table.
     * @return array{attempts: int, first_attempt: int}|null
     */
    private function getAttempts(string $key): ?array
    {
        $settingKey = 'rate_limit_' . $key;

        $row = $this->app->db()->fetchOne(
            'SELECT `setting_value` FROM `settings` WHERE `setting_key` = :key LIMIT 1',
            ['key' => $settingKey],
        );

        if ($row === false || $row['setting_value'] === null) return null;

        /** @var array{attempts: int, first_attempt: int}|null $data */
        $data = json_decode((string) $row['setting_value'], true);

        if (! is_array($data) || ! isset($data['attempts'], $data['first_attempt'])) return null;

        return $data;
    }

    /**
     * Persist attempt data to the settings table using INSERT … ON DUPLICATE KEY UPDATE.
     * @param array{attempts: int, first_attempt: int} $data
     */
    private function saveAttempts(string $key, array $data): void
    {
        $settingKey   = 'rate_limit_' . $key;
        $settingValue = json_encode($data, JSON_THROW_ON_ERROR);

        $this->app->db()->execute(
            'INSERT INTO `settings` (`setting_key`, `setting_value`)
             VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE `setting_value` = :value2',
            [
                'key'    => $settingKey,
                'value'  => $settingValue,
                'value2' => $settingValue,
            ],
        );
    }
}

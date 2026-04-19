<?php

namespace JutForm\Core;

use Redis;
use RedisException;

class RedisClient
{
    private static ?Redis $instance = null;

    public static function getInstance(): Redis
    {
        if (self::$instance === null) {
            $host = getenv('REDIS_HOST') ?: 'redis';
            $port = (int) (getenv('REDIS_PORT') ?: 6379);
            $redis = new Redis();
            try {
                $redis->connect($host, $port);
            } catch (RedisException $e) {
                throw $e;
            }
            self::$instance = $redis;
        }
        return self::$instance;
    }

    public static function resetForTesting(): void
    {
        self::$instance = null;
    }
}

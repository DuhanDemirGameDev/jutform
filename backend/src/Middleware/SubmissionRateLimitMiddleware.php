<?php

namespace JutForm\Middleware;

use JutForm\Core\MiddlewareInterface;
use JutForm\Core\RedisClient;
use JutForm\Core\Request;
use JutForm\Core\Response;

class SubmissionRateLimitMiddleware implements MiddlewareInterface
{
    private const LIMIT = 10;

    private const WINDOW_SECONDS = 5;

    public function handle(Request $request, callable $next): void
    {
        try {
            $redis = RedisClient::getInstance();
        } catch (\Throwable) {
            $next();
            return;
        }

        $ip = $request->ip();
        $window = intdiv(time(), self::WINDOW_SECONDS);
        $key = $this->keyFor($ip, $window);

        try {
            $count = (int) $redis->incr($key);
            $ttl = (int) $redis->ttl($key);
            if ($count === 1 || $ttl < 0) {
                $redis->expire($key, self::WINDOW_SECONDS);
            }
        } catch (\Throwable) {
            $next();
            return;
        }

        if ($count > self::LIMIT) {
            $retryAfter = self::retryAfterSeconds();
            Response::jsonWithHeaders([
                'error' => 'Too Many Requests',
            ], 429, [
                'Retry-After' => (string) $retryAfter,
            ]);
        }

        $next();
    }

    public static function keyForIp(string $ip, ?int $timestamp = null): string
    {
        $time = $timestamp ?? time();
        return self::keyFor($ip, intdiv($time, self::WINDOW_SECONDS));
    }

    private static function keyFor(string $ip, int $window): string
    {
        return 'rate_limit:submission:' . $window . ':' . sha1($ip);
    }

    private static function retryAfterSeconds(?int $timestamp = null): int
    {
        $time = $timestamp ?? time();
        $retryAfter = self::WINDOW_SECONDS - ($time % self::WINDOW_SECONDS);
        return max(1, $retryAfter);
    }
}

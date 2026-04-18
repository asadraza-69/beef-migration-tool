<?php

namespace Nudelsalat\ORM;

/**
 * Signal - Event dispatcher for model signals
 * 
 * Provides event-based signals:
 * - pre_init, post_init
 * - pre_save, post_save
 * - pre_delete, post_delete
 * - pre_migrate, post_migrate
 * 
 * Usage:
 * ```php
 * Signal::connect('post_save', function($model) {
 *     log('Saved: ' . $model);
 * });
 * ```
 */
class Signal
{
    /** @var array<string, array<callable>> */
    private static array $handlers = [];

    /**
     * Connect a handler to a signal.
     * 
     * @param string $signal Signal name
     * @param callable $handler Handler function
     * @param int $priority Higher priority runs first
     */
    public static function connect(string $signal, callable $handler, int $priority = 100): void
    {
        self::$handlers[$signal][] = ['handler' => $handler, 'priority' => $priority];
        
        usort(self::$handlers[$signal], function($a, $b) {
            return $b['priority'] <=> $a['priority'];
        });
    }

    /**
     * Disconnect a handler from a signal.
     * 
     * @param string $signal Signal name
     * @param callable $handler Handler function
     */
    public static function disconnect(string $signal, callable $handler): void
    {
        if (!isset(self::$handlers[$signal])) {
            return;
        }
        
        self::$handlers[$signal] = array_filter(
            self::$handlers[$signal],
            fn($h) => $h['handler'] !== $handler
        );
    }

    /**
     * Send a signal.
     * 
     * @param string $signal Signal name
     * @param array $args Arguments to pass to handlers
     * @return bool True if any handler returned false
     */
    public static function send(string $signal, array $args = []): bool
    {
        if (!isset(self::$handlers[$signal])) {
            return true;
        }
        
        $result = true;
        
        foreach (self::$handlers[$signal] as $item) {
            $handler = $item['handler'];
            $return = $handler(...$args);
            
            if ($return === false) {
                $result = false;
            }
        }
        
        return $result;
    }

    /**
     * Get handlers for a signal.
     * 
     * @param string $signal Signal name
     * @return array
     */
    public static function getHandlers(string $signal): array
    {
        return self::$handlers[$signal] ?? [];
    }

    /**
     * Clear all handlers.
     */
    public static function clear(): void
    {
        self::$handlers = [];
    }

    // ============================================================
    // Convenience methods for common signals
    // ============================================================

    /**
     * Connect to pre_save signal.
     */
    public static function pre_save(callable $handler): void
    {
        self::connect('pre_save', $handler, 100);
    }

    /**
     * Connect to post_save signal.
     */
    public static function post_save(callable $handler): void
    {
        self::connect('post_save', $handler, 100);
    }

    /**
     * Connect to pre_delete signal.
     */
    public static function pre_delete(callable $handler): void
    {
        self::connect('pre_delete', $handler, 100);
    }

    /**
     * Connect to post_delete signal.
     */
    public static function post_delete(callable $handler): void
    {
        self::connect('post_delete', $handler, 100);
    }

    /**
     * Connect to pre_init signal.
     */
    public static function pre_init(callable $handler): void
    {
        self::connect('pre_init', $handler, 100);
    }

    /**
     * Connect to post_init signal.
     */
    public static function post_init(callable $handler): void
    {
        self::connect('post_init', $handler, 100);
    }
}
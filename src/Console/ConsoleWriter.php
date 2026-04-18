<?php

namespace Nudelsalat\Console;

class ConsoleWriter
{
    private const COLORS = [
        'info'    => "\033[32m",    // Green
        'error'   => "\033[31m",    // Red
        'warning' => "\033[33m",    // Yellow
        'blue'    => "\033[34m",    // Blue
        'bold'    => "\033[1m",     // Bold
        'reset'   => "\033[0m",     // Reset
    ];

    public function info(string $msg): void
    {
        echo self::COLORS['info'] . $msg . self::COLORS['reset'] . PHP_EOL;
    }

    public function error(string $msg): void
    {
        echo self::COLORS['error'] . "Error: " . $msg . self::COLORS['reset'] . PHP_EOL;
    }

    public function warning(string $msg): void
    {
        echo self::COLORS['warning'] . "Warning: " . $msg . self::COLORS['reset'] . PHP_EOL;
    }

    public function write(string $msg, string $style = 'reset'): void
    {
        $color = self::COLORS[$style] ?? self::COLORS['reset'];
        echo $color . $msg . self::COLORS['reset'];
    }

    public function ask(string $question, string $default = ''): string
    {
        $prompt = $default !== '' ? " [{$default}]" : "";
        $this->write($question . $prompt . ": ", 'warning');
        
        $line = trim(fgets(STDIN));
        return $line === '' ? $default : $line;
    }

    public function confirm(string $question, bool $default = false): bool
    {
        $options = $default ? " [Y/n]" : " [y/N]";
        $this->write($question . $options . ": ", 'warning');
        
        $line = strtolower(trim(fgets(STDIN)));
        if ($line === '') return $default;
        
        return $line === 'y' || $line === 'yes';
    }
}

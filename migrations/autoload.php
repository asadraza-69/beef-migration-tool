<?php

spl_autoload_register(function ($class) {
    if (strpos($class, 'Nudelsalat\\') === 0) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 12)) . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }

        // Handle Field subdirectory
        if (str_starts_with($class, 'Nudelsalat\\Migrations\\Fields\\')) {
            require_once __DIR__ . '/src/Migrations/Fields/Field.php';
        }
        
        // Handle ORM subdirectory
        if (str_starts_with($class, 'Nudelsalat\\ORM\\')) {
            $ormPath = __DIR__ . '/src/ORM/';
            // Load Query.php first for helper functions (Sum, Avg, Count, etc.)
            if (str_contains($class, 'Query') || str_contains($class, 'Aggregation') || 
                str_contains($class, 'Paginator') || str_contains($class, 'Q\\') || 
                str_contains($class, 'F\\')) {
                require_once $ormPath . 'Query.php';
            }
            if (str_contains($class, 'QuerySet')) {
                require_once $ormPath . 'QuerySet.php';
            }
            if (str_contains($class, 'Signal')) {
                require_once $ormPath . 'Signals.php';
            }
            require_once $ormPath . 'Model.php';
        }
    }
});

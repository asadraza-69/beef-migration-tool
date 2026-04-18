# Nudelsalat 🐘👑

**State-based database migration engine for PHP.**

Nudelsalat is a professional-grade migration engine that brings framework-level features to any PHP project. It offers intelligent autodetection, version-resilient data migrations, and rock-solid integrity protection.

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-55%2B%20passing-green.svg)]()

## Why Nudelsalat?

Most PHP migration libraries are either too simple (manual SQL scripts) or too coupled to a specific framework. Nudelsalat provides a standalone, configuration-driven engine with "High-IQ" features:

*   **🧠 Intelligent Autodetector**: Say goodbye to manual migration writing. Nudelsalat scans your models and detects renames, type changes, and relationship shifts automatically.
*   **🛡️ The Integrity Shield**: Every migration has a SHA256 checksum. Nudelsalat prevents "migration drift" by blocking edits to already-applied history.
*   **🧬 Relational Mastery**: Automatic Many-to-Many junction table management and Foreign Key orchestration across MySQL, PostgreSQL, and SQLite.
*   **❄️ Frozen History**: Data migrations use a `StateRegistry` to access models exactly as they existed when the migration was written. Your history is indestructible.
*   **🔍 Database Introspect**: Already have a database? Use `./nudelsalat introspect` to automatically generate your PHP Model classes in seconds.

## Installation

### Via Composer (Recommended)

```bash
composer require nudelsalat/migrations
```

### Manual Installation

```bash
# Clone or download the package
git clone hhttps://github.com/asadraza-69/nudelsalat.git

# Install dependencies
cd nudelsalat
composer install
```

## Quick Start

### 1. Configuration

Create a `nudelsalat.config.php` in your project root:

```php
<?php
return [
    'database' => [
        'driver' => 'mysql',
        'dsn'    => 'mysql:host=localhost;dbname=myapp',
        'user'   => 'root',
        'pass'   => '',
    ],
    'apps' => [
        'core' => [
            'models'     => __DIR__ . '/src/Models',
            'migrations' => __DIR__ . '/database/migrations',
        ],
    ],
];
```

### 2. Define Models

Create your models in `src/Models/`:

```php
<?php
namespace App\Models;

use Nudelsalat\ORM\Model;
use Nudelsalat\Migrations\Fields\IntField;
use Nudelsalat\Migrations\Fields\StringField;

class User extends Model {
    public static function fields(): array {
        return [
            'id'    => new IntField(primaryKey: true, autoIncrement: true),
            'email' => new StringField(length: 200),
            'name'  => new StringField(length: 100, nullable: true),
        ];
    }
}
```

### 3. The Development Loop

1.  **Change your Model**: Add a field to one of your classes in `src/Models/`.
2.  **Make Migration**: Run `./nudelsalat makemigrations`. Nudelsalat detects the delta and creates a file.
3.  **Migrate**: Run `./nudelsalat migrate`. Your database is now in sync.

## Requirements

*   PHP 8.1 or higher
*   PDO extension with one of:
    *   `pdo_pgsql` - PostgreSQL
    *   `pdo_mysql` - MySQL/MariaDB
    *   `pdo_sqlite` - SQLite

## Commands

| Command | Description |
| :--- | :--- |
| `makemigrations` | Detect model changes and generate a new migration file. |
| `migrate` | Apply all pending migrations to the database. |
| `show` | List all historical and pending migrations. |
| `check` | **[CI/CD]** Verify that models and migrations are in sync. |
| `sqlmigrate` | Preview the raw SQL Nudelsalat will execute for a migration. |
| `introspect` | Scan your DB and generate `Model` classes automatically. |
| `optimize` | Optimize an existing migration file by reducing operations. |
| `squash` | Collapse multiple migrations into one. |
| `merge` | Resolve divergent migration branches. |

## Features

*   **26 Migration Operations** - CreateModel, DeleteModel, AddField, RemoveField, AlterField, RenameField, and more
*   **Model Options** - AlterModelTable, AlterModelOptions, AlterUniqueTogether, AlterIndexTogether, etc.
*   **State Registry** - HistoricalModel API for safe data migrations
*   **Multi-Database Routing** - AppLabelRouter, CompositeMigrationRouter
*   **Multi-Pass Optimizer** - Operation reduction for cleaner migrations
*   **Fake Initial** - Detect already-existing tables
*   **Replacement/Squash** - Full support for squashed migrations

## Testing

```bash
# Run tests locally
./test

# Run tests with PostgreSQL (Docker)
./test-docker
```

The test suite includes 55+ tests covering:
- Autodetector
- Graph/DAG operations
- Loader/Writer
- Executor integration
- Backend portability (MySQL, PostgreSQL, SQLite)
- Multi-database routing

## Documentation

For detailed guides, check out our library:

*   [Getting Started](docs/getting-started.md)
*   [CLI Command Encyclopedia](docs/commands.md)
*   [Relationships & Fields](docs/fields-and-relationships.md)
*   [Advanced Features](docs/advanced-features.md)

Or view the [Online Documentation](web/index.html).

---

*Built with ❤️ for the PHP Ecosystem.*
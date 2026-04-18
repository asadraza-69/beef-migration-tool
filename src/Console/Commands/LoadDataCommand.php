<?php

namespace Nudelsalat\Console\Commands;

class LoadDataCommand extends BaseCommand
{
    private array $exclude = [];
    private ?string $database = null;
    private bool $verbosity = true;
    private bool $ignoreNonexistent = false;
    private ?string $appFilter = null;

    public function execute(array $args): void
    {
        $this->database = $this->getOptionValue($args, '--database') ?: $this->bootstrap->getConfig()->getDefaultDatabaseAlias();
        $pdo = $this->bootstrap->getPdo($this->database);
        $config = $this->bootstrap->getConfig();
        $schema = $this->getOptionValue($args, '--schema');
        
        $this->exclude = array_filter(explode(',', $this->getOptionValue($args, '--exclude') ?? ''));
        $this->verbosity = !$this->hasFlag($args, '--quiet');
        $this->ignoreNonexistent = $this->hasFlag($args, '--ignorenonexistent') || $this->hasFlag($args, '-i');
        $this->appFilter = $this->getOptionValue($args, '--app');

        // Get positional args
        $files = $this->getPositionalArgs($args);

        // Handle stdin input
        if (!empty($files) && $files[0] === '-') {
            $this->loadFromStdin($pdo, $schema);
            return;
        }

        if (empty($files)) {
            $this->loadDefaultFixtures($pdo, $config, $schema);
            return;
        }

        $loaded = 0;
        foreach ($files as $file) {
            $loaded += $this->loadFile($pdo, $file, $config, $schema);
        }

        if ($this->verbosity) {
            $this->info("Loaded {$loaded} records");
        }
    }

    private function loadFromStdin(\PDO $pdo, ?string $schema = null): void
    {
        $content = file_get_contents('php://stdin');
        
        if (empty(trim($content))) {
            $this->error("No data received from stdin");
            return;
        }

        // Try to detect format from content
        $data = $this->parseContent($content);
        
        $loaded = $this->loadData($pdo, $data, $schema);
        
        if ($this->verbosity) {
            $this->info("Loaded {$loaded} records from stdin");
        }
    }

    private function parseContent(string $content): array
    {
        // Try JSON first
        $data = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $data;
        }

        // Try YAML
        if (function_exists('yaml_parse')) {
            $data = yaml_parse($content);
            if ($data !== false) {
                return $data;
            }
        }

        throw new \Exception("Unable to parse input format");
    }

    private function loadDefaultFixtures(\PDO $pdo, $config, ?string $schema = null): void
    {
        $baseDir = $config->getBaseDir();
        $fixtureDirs = [
            $baseDir . '/fixtures',
            $config->getFixturesDir(),
        ];

        $loaded = 0;
        foreach ($fixtureDirs as $dir) {
            if (!$dir || !is_dir($dir)) continue;
            
            // Find all fixture files (json, yaml, xml, gz, bz2, etc)
            $patterns = [
                $dir . '/*.json',
                $dir . '/*.json.gz',
                $dir . '/*.json.bz2',
                $dir . '/*.yaml',
                $dir . '/*.yml',
                $dir . '/*.xml',
            ];

            foreach ($patterns as $pattern) {
                foreach (glob($pattern) as $file) {
                    $loaded += $this->loadFile($pdo, $file, $config, $schema);
                }
            }
        }

        if ($loaded > 0 && $this->verbosity) {
            $this->info("Loaded {$loaded} records from fixtures directory");
        } else {
            $this->error("No fixture files found. Usage: ./nudelsalat loaddata <fixture_file>");
        }
    }

    private function loadFile(\PDO $pdo, string $file, $config, ?string $schema = null): int
    {
        // Resolve path
        if (!str_starts_with($file, '/') && !str_starts_with($file, '.') && !str_starts_with($file, '-')) {
            $file = $config->getBaseDir() . '/' . $file;
        }

        if (!file_exists($file)) {
            $this->error("File not found: {$file}");
            return 0;
        }

        if ($this->verbosity) {
            $this->info("Loading {$file}...");
        }

        try {
            return $this->loadFromPath($pdo, $file, $schema);
        } catch (\Exception $e) {
            $this->error("Error loading {$file}: " . $e->getMessage());
            return 0;
        }
    }

private function loadFromPath(\PDO $pdo, string $path, ?string $schema = null): int
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        
        // Handle compressed fixtures
        $content = match($extension) {
            'gz' => gzdecode(file_get_contents($path)),
            'bz2' => bzdecompress(file_get_contents($path)),
            'lzma' => lzma_decode(file_get_contents($path)),
            'xz' => $this->xzDecode(file_get_contents($path)),
            'zip' => $this->zipExtract(file_get_contents($path)),
            default => file_get_contents($path),
        };

        if ($content === false) {
            throw new \Exception("Failed to read file: {$path}");
        }

        // Determine format
        $baseName = pathinfo($path, PATHINFO_FILENAME);
        $format = pathinfo($path, PATHINFO_EXTENSION);
        
        // If compressed, the inner format is json
        if (in_array($format, ['gz', 'bz2', 'lzma', 'xz', 'zip'])) {
            $format = 'json';
        }

        $data = match($format) {
            'json' => $this->parseJson($content),
            'yaml', 'yml' => $this->parseYaml($content),
            'xml' => $this->parseXml($content),
            default => $this->parseJson($content),
        };

        return $this->loadData($pdo, $data, $schema);
    }

    private function xzDecode(string $data): string
    {
        if (!function_exists('xz_decodestring')) {
            // Try using shell if available
            if (function_exists('proc_open')) {
                $descriptors = [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ];
                $process = proc_open('xz -d', $descriptors, $pipes);
                if (is_resource($process)) {
                    fwrite($pipes[0], $data);
                    fclose($pipes[0]);
                    $output = stream_get_contents($pipes[1]);
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    proc_close($process);
                    if ($output !== false) {
                        return $output;
                    }
                }
            }
            throw new \Exception("xz decompression not available. Install php-xz or use .json.gz format.");
        }
        return xz_decodestring($data);
    }

    private function zipExtract(string $data): string
    {
        // Create temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'nudelsalat_zip_');
        file_put_contents($tempFile, $data);
        
        $zip = new \ZipArchive();
        if ($zip->open($tempFile) !== true) {
            unlink($tempFile);
            throw new \Exception("Failed to open zip file");
        }
        
        $content = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('/\.json$/', $name)) {
                $content = $zip->getFromIndex($i);
                break;
            }
        }
        
        $zip->close();
        unlink($tempFile);
        
        if (empty($content)) {
            throw new \Exception("No JSON file found in zip archive");
        }
        
        return $content;
    }

    private function parseJson(string $content): array
    {
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Invalid JSON: " . json_last_error_msg());
        }

        return $data;
    }

    private function parseYaml(string $content): array
    {
        if (!function_exists('yaml_parse')) {
            throw new \Exception("YAML extension not installed. Install php-yaml or use JSON fixtures.");
        }

        $data = yaml_parse($content);

        if ($data === false) {
            throw new \Exception("Invalid YAML");
        }

        return $data;
    }

    private function parseXml(string $content): array
    {
        $xml = simplexml_load_string($content);
        
        if ($xml === false) {
            throw new \Exception("Invalid XML");
        }

        $data = [];
        foreach ($xml->object as $obj) {
            $record = [
                'model' => (string)$obj['model'],
                'pk' => (string)$obj['pk'],
                'fields' => [],
            ];

            foreach ($obj->field as $field) {
                $value = (string)$field;
                $record['fields'][(string)$field['name']] = $value === 'null' ? null : $value;
            }

            $data[] = $record;
        }

        return $data;
    }

    private function loadData(\PDO $pdo, array $data, ?string $schema = null): int
    {
        if (empty($data)) return 0;

        // Handle single record format
        if (isset($data['model']) && isset($data['fields'])) {
            $data = [$data];
        }

        // Get available columns for each table to handle --ignorenonexistent
        $tableColumns = [];

        $loaded = 0;
        foreach ($data as $record) {
            if (!isset($record['model']) || !isset($record['fields'])) {
                continue;
            }

            // Apply app filter
            if ($this->appFilter) {
                $recordApp = explode('.', $record['model'])[0];
                if ($recordApp !== $this->appFilter) {
                    continue;
                }
            }

            // Check exclusions
            if (in_array($record['model'], $this->exclude)) {
                continue;
            }

            // Filter fields if --ignorenonexistent
            $fields = $record['fields'];
            if ($this->ignoreNonexistent) {
                $tableName = $this->modelToTable($record['model']);
                if (!isset($tableColumns[$tableName])) {
                    $tableColumns[$tableName] = $this->getTableColumns($pdo, $tableName, $schema);
                }
                $fields = array_intersect_key($fields, array_flip($tableColumns[$tableName]));
            }

            if (empty($fields)) continue;

            $tableName = $schema ? "{$schema}.{$this->modelToTable($record['model'])}" : $this->modelToTable($record['model']);
            $this->insertRecord($pdo, $tableName, $fields);
            $loaded++;
        }

        return $loaded;
    }

    private function getTableColumns(\PDO $pdo, string $tableName, ?string $schema = null): array
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        
        if ($driver === 'sqlite') {
            $stmt = $pdo->query("PRAGMA table_info({$this->quoteIdentifier($tableName)})");
            $columns = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $columns[] = $row['name'];
            }
            return $columns;
        } elseif ($driver === 'pgsql') {
            $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = '{$tableName}'");
            $columns = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $columns[] = $row['column_name'];
            }
            return $columns;
        } else {
            $stmt = $pdo->query("DESCRIBE {$this->quoteIdentifier($tableName)}");
            $columns = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $columns[] = $row['Field'];
            }
            return $columns;
        }
    }

    private function insertRecord(\PDO $pdo, string $table, array $fields): void
    {
        $columns = array_keys($fields);
        
        $sql = sprintf(
            "INSERT INTO %s (%s) VALUES (%s)",
            $this->quoteIdentifier($table),
            implode(', ', array_map([$this, 'quoteIdentifier'], $columns)),
            implode(', ', array_map(fn($col) => ':' . $col, $columns))
        );

        $stmt = $pdo->prepare($sql);
        
        $params = [];
        foreach ($fields as $key => $value) {
            $params[':' . $key] = $value;
        }

        $stmt->execute($params);
    }

    private function modelToTable(string $model): string
    {
        $parts = explode('.', $model);
        $name = end($parts);
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }

    private function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
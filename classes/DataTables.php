<?php

namespace UKModules\ADIF;

use REDCap;

// Mixed into UKModules\ADIF\ADIF alongside Proxy. Lets a project upload a CSV (or another delimited
// text file) and use it as a data source, managed on the Data Sources page
// (pages/data_sources/tables.php).
//
// A table answers requests the way a Socrata dataset does, so everything built for API sources works
// on it unchanged: its source id ("table:<id>") goes wherever an API source's index does, its fields
// are offered on the Mapping page by getApiFields(), and dispatchApiRequest() sends its requests here.
// A request is a query string of column filters, "?geoid=21067000100", and the response is the list
// of matching rows as objects keyed by column name, so mapping paths such as [0].column or
// [measure=X].value read it like any JSON response.
//
// The uploaded file is kept as a REDCap edoc (converted to UTF-8 first) and read on each import.
// Its settings are kept in the "data-tables" project setting, keyed by table id:
//   name           - shown on the Mapping page as "Table: <name>"; fixed once uploaded, since saved
//                    mappings are matched by it
//   key_column     - the column rows are looked up by
//   placeholder    - the name of the record value matched against it, as in ?key_column=[placeholder]
//   loose_match    - compare ignoring case, surrounding spaces and leading zeros
//   layout         - "wide" (one row per key) or "long" (several rows per key, told apart by
//                    measure_column, whose distinct values are kept in measure_values)
trait DataTables
{
    private static $dataTableSourcePrefix = 'table:';
    private static $dataTableExtensions = ['csv', 'tsv', 'tab', 'txt'];
    private static $dataTableDelimiters = [',', "\t", ';', '|'];
    // Defaults for the system settings capping uploads, in MB (see config.json)
    private static $defaultDataTableFileMb = 8;
    private static $defaultDataTableProjectMb = 32;
    // A request without a $limit returns at most this many rows, as Socrata does
    private static $defaultDataTableLimit = 1000;
    // A long table's measure column with more distinct values than this isn't naming measures
    private static $maxMeasureValues = 1000;
    private static $maxDataTableFields = 500;

    // File contents by edoc id, so an import touching a table several times reads it once
    private static $dataTableContents = [];

    public function getDataTables()
    {
        $tables = $this->getProjectSetting('data-tables') ?: [];
        return is_array($tables) ? $tables : [];
    }

    public function getDataTable($id)
    {
        $tables = $this->getDataTables();
        if (!isset($tables[$id])) {
            throw new \Exception("No data table with the id \"$id\" exists in this project.");
        }
        return $tables[$id];
    }

    private function saveDataTables($tables)
    {
        $this->setProjectSetting('data-tables', $tables);
    }

    public function isDataTableSource($source)
    {
        return is_string($source) && str_starts_with($source, self::$dataTableSourcePrefix);
    }

    public function dataTableSource($id)
    {
        return self::$dataTableSourcePrefix . $id;
    }

    private function dataTableIdFromSource($source)
    {
        return substr($source, strlen(self::$dataTableSourcePrefix));
    }

    // The label a table's fields are grouped under on the Mapping page, and matched by on import
    public function dataTableEndpoint($table)
    {
        return 'Table: ' . $table['name'];
    }

    // What's requested to look a record up, e.g. ?geoid=[census_tract_geoid]
    public function dataTablePath($table)
    {
        return '?' . rawurlencode($table['key_column']) . '=[' . $table['placeholder'] . ']';
    }

    // The largest file a project may upload: the module's per-file limit, or the server's own PHP
    // upload limits when they're lower
    public function maxDataTableUploadBytes()
    {
        $limits = [$this->dataTableFileLimitBytes()];
        foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
            $bytes = $this->iniBytes(ini_get($setting));
            if ($bytes > 0) {
                $limits[] = $bytes;
            }
        }
        return min($limits);
    }

    // The per-file limit set by an administrator ("adif-table-max-file-mb"), else 8 MB
    public function dataTableFileLimitBytes()
    {
        return $this->dataTableLimitSetting('adif-table-max-file-mb', self::$defaultDataTableFileMb);
    }

    // The most one project may keep across all its tables ("adif-table-max-project-mb"), else 32 MB
    public function dataTableProjectLimitBytes()
    {
        return $this->dataTableLimitSetting('adif-table-max-project-mb', self::$defaultDataTableProjectMb);
    }

    // A size setting in MB, as bytes. Blank, zero, negative or not a number means the default.
    private function dataTableLimitSetting($key, $defaultMb)
    {
        $mb = trim((string) $this->getSystemSetting($key));
        $mb = is_numeric($mb) && (float) $mb > 0 ? (float) $mb : $defaultMb;
        return (int) round($mb * 1048576);
    }

    // Bytes stored across this project's tables, leaving out $exceptId (a table being replaced)
    public function dataTableStoredBytes($exceptId = null)
    {
        $total = 0;
        foreach ($this->getDataTables() as $id => $table) {
            if ($exceptId === null || (string) $id !== (string) $exceptId) {
                $total += (int) ($table['bytes'] ?? 0);
            }
        }
        return $total;
    }

    // What's left of the project's allowance, never below zero. With $exceptId, what a new file
    // for that table could use, since its current file stops counting once it's replaced.
    public function dataTableSpaceLeftBytes($exceptId = null)
    {
        return max(0, $this->dataTableProjectLimitBytes() - $this->dataTableStoredBytes($exceptId));
    }

    // Stops a file that would take the project's tables past their total allowance. $replacing is
    // the id of the table the file replaces.
    private function checkDataTableProjectLimit($bytes, $replacing = null)
    {
        $limit = $this->dataTableProjectLimitBytes();
        $used = $this->dataTableStoredBytes($replacing);
        if ($used + $bytes <= $limit) {
            return;
        }

        $size = fn($b) => $this->formatDataTableBytes($b);
        $left = $size(max(0, $limit - $used));
        $inUse = $replacing === null
            ? "{$size($used)} is already in use, leaving $left"
            : "the project's other tables use {$size($used)}, leaving $left for this one";
        throw new \Exception("This file ({$size($bytes)}) would take this project's data tables past their "
            . "{$size($limit)} limit: $inUse. Delete or replace a table that's no longer needed, or ask a "
            . 'REDCap administrator to raise the limit.');
    }

    private function formatDataTableBytes($bytes)
    {
        return $bytes >= 1048576 || $bytes === 0
            ? round($bytes / 1048576, 1) . ' MB'
            : max(1, (int) round($bytes / 1024)) . ' KB';
    }

    private function iniBytes($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return 0;
        }
        $number = (float) $value;
        switch (strtolower(substr($value, -1))) {
            case 'g': $number *= 1024;
            case 'm': $number *= 1024;
            case 'k': $number *= 1024;
        }
        return (int) $number;
    }

    // ---- Adding, changing and removing tables ----
    // Each returns ['table' => <settings>, 'notices' => [things the user should know]].

    public function addDataTable($upload, $name)
    {
        $file = $this->acceptDataTableUpload($upload);
        $this->checkDataTableProjectLimit($file['bytes']);

        $name = trim(preg_replace('/\s+/', ' ', (string) $name));
        if ($name === '') {
            $name = trim(preg_replace('/[_\s]+/', ' ', pathinfo($file['filename'], PATHINFO_FILENAME)));
        }
        if (mb_strlen($name) > 100) {
            throw new \Exception('Please give the table a name of 100 characters or fewer.');
        }

        $tables = $this->getDataTables();
        foreach ($tables as $existing) {
            if (strcasecmp($existing['name'], $name) === 0) {
                throw new \Exception("There's already a table named \"$name\". Choose another name, or use "
                    . "Replace file on that table to update its data.");
            }
        }

        do {
            $id = bin2hex(random_bytes(4));
        } while (isset($tables[$id]));

        $columns = $file['inspection']['columns'];
        $key = $this->suggestDataTableKey($columns);
        $table = [
            'id' => $id,
            'name' => $name,
            'edoc_id' => $this->storeDataTableFile($file),
            'filename' => $file['filename'],
            'bytes' => $file['bytes'],
            'delimiter' => $file['delimiter'],
            'columns' => $columns,
            'row_count' => $file['inspection']['row_count'],
            'key_column' => $key,
            'placeholder' => $this->placeholderName($key),
            'loose_match' => true,
            'layout' => 'wide',
            'measure_column' => '',
            'measure_values' => [],
            'uploaded_by' => $this->currentUsername(),
            'uploaded_at' => date('Y-m-d H:i'),
        ];

        $tables[$id] = $table;
        $this->saveDataTables($tables);

        return ['table' => $table, 'notices' => $file['inspection']['warnings']];
    }

    // Swaps in a new version of a table's file (e.g. next year's release), keeping its name, so
    // mappings to columns the new file still has keep working.
    public function replaceDataTableFile($id, $upload)
    {
        $table = $this->getDataTable($id);
        $file = $this->acceptDataTableUpload($upload);
        $this->checkDataTableProjectLimit($file['bytes'], $id);
        $notices = $file['inspection']['warnings'];
        $columns = $file['inspection']['columns'];

        $missing = array_values(array_diff($table['columns'], $columns));
        if (!empty($missing)) {
            $notices[] = 'The new file doesn\'t have these columns, so fields mapped from them will be skipped on import: '
                . implode(', ', $missing) . '.';
        }

        $oldEdoc = $table['edoc_id'];
        $table['edoc_id'] = $this->storeDataTableFile($file);
        $table['filename'] = $file['filename'];
        $table['bytes'] = $file['bytes'];
        $table['delimiter'] = $file['delimiter'];
        $table['columns'] = $columns;
        $table['row_count'] = $file['inspection']['row_count'];
        $table['uploaded_by'] = $this->currentUsername();
        $table['uploaded_at'] = date('Y-m-d H:i');

        if (!in_array($table['key_column'], $columns, true)) {
            $table['key_column'] = $this->suggestDataTableKey($columns);
            $notices[] = "Rows are now looked up by \"{$table['key_column']}\", since the new file has no column the "
                . 'previous one was looked up by. Check the table\'s settings.';
        }
        if ($table['layout'] === 'long') {
            if (!in_array($table['measure_column'], $columns, true) || $table['measure_column'] === $table['key_column']) {
                $table['layout'] = 'wide';
                $table['measure_column'] = '';
                $table['measure_values'] = [];
                $notices[] = 'The table is now treated as one row per key, since the new file has no column the '
                    . 'previous one used to tell rows apart.';
            } else {
                $table['measure_values'] = $this->distinctColumnValues($table, $table['measure_column']);
            }
        }

        $tables = $this->getDataTables();
        $tables[$id] = $table;
        $this->saveDataTables($tables);
        $this->deleteDataTableEdoc($oldEdoc);

        return ['table' => $table, 'notices' => $notices];
    }

    public function updateDataTableSettings($id, $input)
    {
        $table = $this->getDataTable($id);
        $notices = [];

        $key = (string) ($input['key_column'] ?? '');
        if (!in_array($key, $table['columns'], true)) {
            throw new \Exception('Choose one of the table\'s columns to look rows up by.');
        }

        $placeholder = strtolower(trim((string) ($input['placeholder'] ?? '')));
        if ($placeholder === '') {
            $placeholder = $this->placeholderName($key);
        }
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $placeholder)) {
            throw new \Exception('A placeholder must start with a letter and use only lowercase letters, numbers and underscores.');
        }

        $layout = ($input['layout'] ?? 'wide') === 'long' ? 'long' : 'wide';
        $measure = '';
        $measureValues = [];
        if ($layout === 'long') {
            $measure = (string) ($input['measure_column'] ?? '');
            if (!in_array($measure, $table['columns'], true) || $measure === $key) {
                throw new \Exception('Choose the column that tells a key\'s rows apart, other than the one rows are looked up by.');
            }
            if (strpos($measure, '=') !== false) {
                throw new \Exception("\"$measure\" can't tell rows apart, since a mapping path can't name a column containing \"=\".");
            }
            $measureValues = $this->distinctColumnValues($table, $measure);
            if (empty($measureValues)) {
                throw new \Exception("The \"$measure\" column is empty.");
            }
        }

        // A lookup field chosen on the Mapping page follows the placeholder to its new name
        $oldPlaceholder = $table['placeholder'];
        if ($placeholder !== $oldPlaceholder) {
            $lookups = $this->getProjectSetting('lookup-fields') ?: [];
            $source = $this->dataTableSource($id);
            if (isset($lookups[$source][$oldPlaceholder]) && !isset($lookups[$source][$placeholder])) {
                $lookups[$source][$placeholder] = $lookups[$source][$oldPlaceholder];
                unset($lookups[$source][$oldPlaceholder]);
                $this->setProjectSetting('lookup-fields', $lookups);
            }
        }

        $wasLong = $table['layout'] === 'long';
        $table['key_column'] = $key;
        $table['placeholder'] = $placeholder;
        $table['loose_match'] = !empty($input['loose_match']);
        $table['layout'] = $layout;
        $table['measure_column'] = $measure;
        $table['measure_values'] = $measureValues;

        if ($wasLong !== ($layout === 'long')) {
            $notices[] = 'Changing how rows are laid out changes the fields this table offers, so re-check any fields '
                . 'already mapped from it on the Mapping page.';
        }
        $offered = count($this->dataTableFields($table));
        if ($layout === 'long' && $offered >= self::$maxDataTableFields) {
            $notices[] = 'Only the first ' . self::$maxDataTableFields . ' fields are offered on the Mapping page.';
        }

        $tables = $this->getDataTables();
        $tables[$id] = $table;
        $this->saveDataTables($tables);

        return ['table' => $table, 'notices' => $notices];
    }

    public function deleteDataTable($id)
    {
        $table = $this->getDataTable($id);

        $tables = $this->getDataTables();
        unset($tables[$id]);
        $this->saveDataTables($tables);

        $lookups = $this->getProjectSetting('lookup-fields') ?: [];
        if (isset($lookups[$this->dataTableSource($id)])) {
            unset($lookups[$this->dataTableSource($id)]);
            $this->setProjectSetting('lookup-fields', $lookups);
        }

        $this->deleteDataTableEdoc($table['edoc_id']);

        return ['table' => $table, 'notices' => []];
    }

    // ---- Fields and requests, used by Proxy ----

    // Every table's fields, in the form Proxy::getApiFields() returns
    public function getDataTableFields()
    {
        $fields = [];
        foreach ($this->getDataTables() as $table) {
            array_push($fields, ...$this->dataTableFields($table));
        }
        return $fields;
    }

    public function dataTableFields($table)
    {
        $source = $this->dataTableSource($table['id']);
        $endpoint = $this->dataTableEndpoint($table);
        $path = $this->dataTablePath($table);

        $paths = [];
        if ($table['layout'] === 'long' && $table['measure_column'] !== '') {
            // [measureid=DEPRESSION].data_value: one field per measure and other column
            $others = array_diff($table['columns'], [$table['key_column'], $table['measure_column']]);
            foreach ($table['measure_values'] as $value) {
                $filter = '[' . addcslashes($table['measure_column'] . '=' . $value, '\\]') . ']';
                foreach ($others as $column) {
                    $paths[] = $this->joinSchemaPath($filter, $column);
                }
            }
        } else {
            foreach ($table['columns'] as $column) {
                $paths[] = $this->joinSchemaPath('[0]', $column);
            }
        }

        $fields = [];
        foreach (array_filter($paths, 'is_string') as $field) {
            $fields[] = ['source' => $source, 'field' => $field, 'endpoint' => $endpoint, 'path' => $path];
            if (count($fields) >= self::$maxDataTableFields) {
                break;
            }
        }
        return $fields;
    }

    // Answers a request to a table, returning what Proxy::sendApiRequest() would for an API.
    // $path is a query string of column=value filters (all must match) and an optional $limit.
    public function queryDataTable($source, $path)
    {
        $table = $this->getDataTable($this->dataTableIdFromSource($source));
        $start = microtime(true);

        $result = [
            'requestUrl' => $this->dataTableEndpoint($table) . ' ' . $path,
            'requestMethod' => 'GET',
            'requestHeaders' => [],
            'success' => false,
            'statusCode' => null,
            'reasonPhrase' => null,
            'responseHeaders' => [],
            'body' => null,
            'bodyJson' => null,
            'elapsedMs' => null,
            'byteSize' => null,
            'error' => null,
        ];

        try {
            [$filters, $limit] = $this->parseDataTableQuery($table, $path);
            $loose = !empty($table['loose_match']);
            $expected = [];
            foreach ($filters as $column => $value) {
                $expected[$column] = $this->dataTableMatchValue($value, $loose);
            }

            $rows = [];
            $this->eachDataTableRow($table, function ($row) use (&$rows, $expected, $limit, $loose) {
                foreach ($expected as $column => $value) {
                    if ($this->dataTableMatchValue($row[$column] ?? '', $loose) !== $value) {
                        return true;
                    }
                }
                $rows[] = $row;
                return count($rows) < $limit;
            });
        } catch (\Throwable $e) {
            $result['elapsedMs'] = round((microtime(true) - $start) * 1000, 1);
            $result['statusCode'] = 400;
            $result['reasonPhrase'] = 'Bad Request';
            $result['error'] = $e->getMessage();
            return $result;
        }

        $body = json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $result['success'] = true;
        $result['statusCode'] = 200;
        $result['reasonPhrase'] = 'OK';
        $result['responseHeaders'] = ['X-ADIF-Rows-Matched' => [(string) count($rows)]];
        $result['body'] = $body;
        $result['bodyJson'] = $rows;
        $result['byteSize'] = strlen($body);
        $result['elapsedMs'] = round((microtime(true) - $start) * 1000, 1);
        return $result;
    }

    // Returns [[column => value], limit]. Column names are matched case-insensitively.
    private function parseDataTableQuery($table, $path)
    {
        $path = ltrim(trim((string) $path), '/');
        if ($path !== '' && $path[0] !== '?') {
            throw new \Exception('A table is queried with column filters only, such as ?'
                . rawurlencode($table['key_column']) . '=value');
        }

        $filters = [];
        $limit = self::$defaultDataTableLimit;
        foreach (explode('&', substr($path, 1)) as $pair) {
            if ($pair === '') {
                continue;
            }
            $parts = explode('=', $pair, 2);
            $name = rawurldecode($parts[0]);
            $value = rawurldecode($parts[1] ?? '');

            if ($name === '$limit') {
                if (!ctype_digit($value) || (int) $value < 1) {
                    throw new \Exception('$limit must be a whole number of rows.');
                }
                $limit = (int) $value;
                continue;
            }

            $column = $this->findDataTableColumn($table, $name);
            if ($column === null) {
                throw new \Exception("The table \"{$table['name']}\" has no column named \"$name\".");
            }
            $filters[$column] = $value;
        }

        return [$filters, $limit];
    }

    private function findDataTableColumn($table, $name)
    {
        if (in_array($name, $table['columns'], true)) {
            return $name;
        }
        foreach ($table['columns'] as $column) {
            if (strcasecmp($column, $name) === 0) {
                return $column;
            }
        }
        return null;
    }

    // Loose matching ignores case, surrounding spaces, and leading zeros, which spreadsheet programs
    // often drop from IDs such as FIPS codes (01001020100 saved as 1001020100)
    private function dataTableMatchValue($value, $loose)
    {
        $value = (string) $value;
        if (!$loose) {
            return $value;
        }
        $value = mb_strtolower(trim($value));
        $trimmed = ltrim($value, '0');
        return ($trimmed === '' && $value !== '') ? '0' : $trimmed;
    }

    // ---- Reading files ----

    // Calls $onRow([column => value]) for each data row of a stored table, until it returns false
    private function eachDataTableRow($table, callable $onRow)
    {
        $this->eachDelimitedRow($this->dataTableContents($table), $table['delimiter'], function ($row) use ($onRow) {
            return $onRow($row);
        });
    }

    public function dataTablePreview($table, $count = 10)
    {
        $rows = [];
        $this->eachDataTableRow($table, function ($row) use (&$rows, $count) {
            $rows[] = $row;
            return count($rows) < $count;
        });
        return $rows;
    }

    private function distinctColumnValues($table, $column)
    {
        $values = [];
        $this->eachDataTableRow($table, function ($row) use (&$values, $column, $table) {
            $value = trim((string) ($row[$column] ?? ''));
            if ($value !== '') {
                $values[$value] = true;
            }
            if (count($values) > self::$maxMeasureValues) {
                throw new \Exception("\"$column\" has more than " . self::$maxMeasureValues . ' different values, so it '
                    . 'doesn\'t look like a column naming what each row measures.');
            }
            return true;
        });
        return array_keys($values);
    }

    private function dataTableContents($table)
    {
        $edocId = (int) $table['edoc_id'];
        if (!isset(self::$dataTableContents[$edocId])) {
            $file = REDCap::getFile($edocId);
            if (!is_array($file) || !isset($file[2])) {
                throw new \Exception("The file for the table \"{$table['name']}\" could not be read. Try uploading it again.");
            }
            self::$dataTableContents[$edocId] = $file[2];
        }
        return self::$dataTableContents[$edocId];
    }

    // Parses delimited text, calling $onRow([column => value], $ragged) for each non-blank row after the
    // header, until it returns false. $ragged is true when the row had a different number of fields than
    // the header (it's padded or cut to fit). Returns the cleaned column names.
    private function eachDelimitedRow($contents, $delimiter, callable $onRow)
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $contents);
        rewind($handle);

        try {
            $header = fgetcsv($handle, 0, $delimiter, '"', '');
            if ($header === false) {
                throw new \Exception('The file is empty.');
            }
            $columns = $this->cleanDataTableColumns($header);
            $width = count($columns);

            while (($fields = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                if ($fields === [null] || (count($fields) === 1 && trim((string) $fields[0]) === '')) {
                    continue;
                }
                $ragged = count($fields) !== $width;
                $fields = array_slice(array_pad($fields, $width, ''), 0, $width);
                if ($onRow(array_combine($columns, array_map('strval', $fields)), $ragged) === false) {
                    break;
                }
            }
        } finally {
            fclose($handle);
        }

        return $columns;
    }

    // Blank headings become column_<n>, and repeated ones get _2, _3, ...
    private function cleanDataTableColumns($header)
    {
        $columns = [];
        $seen = [];
        foreach ($header as $position => $column) {
            $column = trim((string) $column);
            if ($column === '') {
                $column = 'column_' . ($position + 1);
            }
            $base = $column;
            for ($n = 2; isset($seen[mb_strtolower($column)]); $n++) {
                $column = "{$base}_$n";
            }
            $seen[mb_strtolower($column)] = true;
            $columns[] = $column;
        }
        return $columns;
    }

    // Checks an upload from $_FILES and reads it. Returns ['filename', 'bytes', 'contents' (UTF-8),
    // 'delimiter', 'inspection' => ['columns', 'row_count', 'warnings']].
    private function acceptDataTableUpload($upload)
    {
        if (!is_array($upload) || !isset($upload['error'])) {
            throw new \Exception('Choose a file to upload.');
        }

        $maxMb = round($this->maxDataTableUploadBytes() / 1048576, 1);
        switch ($upload['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_NO_FILE:
                throw new \Exception('Choose a file to upload.');
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new \Exception("The file is larger than this server accepts ($maxMb MB).");
            case UPLOAD_ERR_PARTIAL:
                throw new \Exception('The upload was interrupted. Please try again.');
            default:
                throw new \Exception("The server couldn't save the upload (error {$upload['error']}).");
        }
        if (!is_uploaded_file($upload['tmp_name'])) {
            throw new \Exception('The upload could not be verified. Please try again.');
        }

        $filename = basename((string) $upload['name']);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, self::$dataTableExtensions, true)) {
            throw new \Exception('Upload a CSV or another delimited text file (.' . implode(', .', self::$dataTableExtensions)
                . '). A spreadsheet can be saved as CSV from Excel with File > Save As.');
        }
        if ($upload['size'] > $this->maxDataTableUploadBytes()) {
            throw new \Exception("Data table files can be up to $maxMb MB, and this one is "
                . $this->formatDataTableBytes((int) $upload['size']) . '.');
        }

        $contents = file_get_contents($upload['tmp_name']);
        if ($contents === false || trim($contents) === '') {
            throw new \Exception('The file is empty.');
        }

        // Excel writes CSVs in Windows-1252 unless told otherwise, so anything that isn't UTF-8 is read as that
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        if (!mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }
        // Old Mac line endings
        $contents = preg_replace('/\r(?!\n)/', "\n", $contents);

        $delimiter = $this->detectDelimiter($contents, $extension);
        $inspection = $this->inspectDelimitedContents($contents, $delimiter);

        return [
            'filename' => $filename,
            'bytes' => strlen($contents),
            'contents' => $contents,
            'delimiter' => $delimiter,
            'inspection' => $inspection,
        ];
    }

    // The candidate appearing most often in the header line, outside quotes
    private function detectDelimiter($contents, $extension)
    {
        $line = strtok($contents, "\n");
        $unquoted = preg_replace('/"[^"]*"/', '', (string) $line);

        $best = in_array($extension, ['tsv', 'tab'], true) ? "\t" : ',';
        $bestCount = substr_count($unquoted, $best);
        foreach (self::$dataTableDelimiters as $candidate) {
            $count = substr_count($unquoted, $candidate);
            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }
        return $best;
    }

    private function inspectDelimitedContents($contents, $delimiter)
    {
        $rowCount = 0;
        $raggedRows = 0;
        $columns = $this->eachDelimitedRow($contents, $delimiter, function ($row, $ragged) use (&$rowCount, &$raggedRows) {
            $rowCount++;
            if ($ragged) {
                $raggedRows++;
            }
            return true;
        });

        if ($rowCount === 0) {
            throw new \Exception('The file has a header row but no data rows.');
        }
        if (count($columns) < 2) {
            throw new \Exception('Only one column was found. Check that the file is comma, tab, semicolon or pipe separated, '
                . 'with a header row naming each column.');
        }

        $warnings = [];
        if ($raggedRows > 0) {
            $warnings[] = "$raggedRows row(s) had a different number of values than the header row, and were padded or "
                . 'cut to fit. Check the preview.';
        }
        $unusable = array_filter($columns, function ($column) {
            return strpos($column, '=') !== false;
        });
        if (!empty($unusable)) {
            $warnings[] = 'These columns can\'t be mapped, since a mapping path can\'t name a column containing "=": '
                . implode(', ', $unusable) . '.';
        }

        return ['columns' => $columns, 'row_count' => $rowCount, 'warnings' => $warnings];
    }

    // The column most likely to identify a place or a record: a geographic ID, then anything ending in id
    private function suggestDataTableKey($columns)
    {
        foreach (['/geoid|fips|tract|zcta|zip/i', '/(^|[_\s])id$/i'] as $pattern) {
            foreach ($columns as $column) {
                if (preg_match($pattern, $column) && strpos($column, '=') === false) {
                    return $column;
                }
            }
        }
        return $columns[0];
    }

    // ---- Storage ----

    // Saves the (UTF-8) contents as an edoc under its original file name and returns the edoc id
    private function storeDataTableFile($file)
    {
        $tempRoot = defined('APP_PATH_TEMP') ? APP_PATH_TEMP : sys_get_temp_dir();
        $directory = rtrim($tempRoot, '/\\') . DIRECTORY_SEPARATOR . 'adif_' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700)) {
            throw new \Exception('The file could not be saved: the server\'s temp folder isn\'t writable.');
        }

        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $file['filename']);
        $path = $directory . DIRECTORY_SEPARATOR . $safeName;
        try {
            if (file_put_contents($path, $file['contents']) === false) {
                throw new \Exception('The file could not be saved: the server\'s temp folder isn\'t writable.');
            }
            $edocId = $this->saveFile($path, $this->getProjectId());
        } finally {
            @unlink($path);
            @rmdir($directory);
        }

        if (empty($edocId)) {
            throw new \Exception('REDCap could not store the file.');
        }
        return (int) $edocId;
    }

    // Marks the edoc deleted, as REDCap does for its own files; its cron removes it later
    private function deleteDataTableEdoc($edocId)
    {
        unset(self::$dataTableContents[(int) $edocId]);
        $this->query(
            'UPDATE redcap_edocs_metadata SET delete_date = NOW() WHERE doc_id = ? AND project_id = ? AND delete_date IS NULL',
            [(int) $edocId, $this->getProjectId()]
        );
    }

    private function currentUsername()
    {
        try {
            $user = $this->getUser();
            return $user ? $user->getUsername() : '';
        } catch (\Throwable $e) {
            return '';
        }
    }
}

<?php

namespace UKModules\ADIF;

// Used by DataTables. Reads the uploaded-table formats that aren't delimited text, JSON and Excel
// workbooks, into CSV, which is what's stored and read on import. Converting once on upload means
// lookups, previews, the Mapping page's fields and replacing a file all work the same whatever the
// file was: only the card says what it was uploaded as.
//
// JSON (.json, .geojson, and .jsonl / .ndjson with one object per line) can be:
//   - a list of objects, one per row:              [{"geoid": "...", "svi": 0.4}, ...]
//   - a list of lists, the first naming columns:   [["NAME", "B01001_001E"], ["...", "..."]] (Census API)
//   - an object holding such a list under a key:   {"data": [...]} (the longest list is read)
//   - a GeoJSON FeatureCollection, read from each feature's properties
//   - an object of objects keyed by id:            {"21067000100": {"svi": 0.4}, ...} (the id is a "key" column)
// Nested objects become dotted columns ("properties.svi"); nested lists are kept as JSON text.
//
// An Excel workbook (.xlsx) is read without any library, with PHP's zip and XML readers. Each visible
// sheet that reads as a table becomes its own table; its first non-blank row names the columns.
// Dates are written as YYYY-MM-DD (with the time when there is one), formulas as their saved results,
// error values (#N/A, #DIV/0!, ...) as blanks, and numbers to 15 significant digits, as Excel shows them.
trait DataTableFormats
{
    private static $dataTableJsonExtensions = ['json', 'geojson', 'jsonl', 'ndjson'];
    private static $dataTableExcelExtensions = ['xlsx'];
    // A workbook part larger than this many times the per-file limit, once unzipped, isn't read: a
    // sheet holding a table within the limit is never near it, and a crafted file could be
    private static $maxXlsxPartRatio = 25;

    // 'delimited', 'json' or 'xlsx', from the file's extension; null when it's none of them
    private function dataTableFormat($extension)
    {
        if (in_array($extension, self::$dataTableExtensions, true)) {
            return 'delimited';
        }
        if (in_array($extension, self::$dataTableJsonExtensions, true)) {
            return 'json';
        }
        if (in_array($extension, self::$dataTableExcelExtensions, true)) {
            return 'xlsx';
        }
        return null;
    }

    // How a table's file is described on its card, e.g. "Excel workbook, sheet "Tracts""
    public function dataTableFormatLabel($table)
    {
        switch ($table['format'] ?? 'delimited') {
            case 'json':
                return 'JSON, stored as CSV';
            case 'xlsx':
                return 'Excel workbook, sheet "' . ($table['sheet'] ?? '') . '", stored as CSV';
            default:
                $names = [',' => 'comma', "\t" => 'tab', ';' => 'semicolon', '|' => 'pipe'];
                return ($names[$table['delimiter'] ?? ','] ?? 'custom') . ' separated';
        }
    }

    // ---- CSV out ----

    // Writes a header and rows (lists of strings, header order) as CSV, read back by eachDelimitedRow().
    // Stops with an error once it passes $limitBytes, so a huge conversion fails early.
    private function writeDataTableCsv(array $columns, iterable $rows, $limitBytes, $what)
    {
        $handle = fopen('php://temp', 'r+');
        try {
            fputcsv($handle, $columns, ',', '"', '', "\n");
            foreach ($rows as $row) {
                fputcsv($handle, $row, ',', '"', '', "\n");
                if (ftell($handle) > $limitBytes) {
                    throw new \Exception("$what is more than " . $this->formatDataTableBytes($limitBytes)
                        . ' once read as a table, the most a table can hold. Upload a smaller part of it, such as one '
                        . 'state\'s rows.');
                }
            }
            rewind($handle);
            return stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
    }

    // A number as Excel shows it: whole numbers in full (so an 11-digit GEOID stays 21067000100, not
    // 2.1067E+10), others to 15 significant digits (0.304, not 0.30399999999999999)
    private function dataTableNumber($value)
    {
        $number = (float) $value;
        if (!is_finite($number)) {
            return (string) $value;
        }
        if ($number == 0) {
            return '0';
        }
        if (floor($number) == $number && abs($number) < 1e15) {
            return sprintf('%.0f', $number);
        }
        return sprintf('%.15G', $number);
    }

    // ---- JSON ----

    // Returns ['csv' => text, 'warnings' => [...]]
    private function convertJsonDataTable($contents, $limitBytes)
    {
        $warnings = [];
        $data = json_decode($contents, true, 64, JSON_BIGINT_AS_STRING);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $error = json_last_error_msg();
            $data = $this->decodeJsonLines($contents);
            if ($data === null) {
                throw new \Exception("The file isn't valid JSON ($error).");
            }
        }

        $rows = $this->jsonDataTableRows($data, $warnings);

        // Columns in the order first seen, so a list of objects keeps its fields' order
        $columns = [];
        $flat = [];
        $skipped = 0;
        foreach ($rows as $row) {
            if (!is_array($row) || (array_is_list($row) && !empty($row))) {
                $skipped++;
                continue;
            }
            $values = [];
            $this->flattenJsonRow($row, '', $values);
            foreach ($values as $column => $value) {
                $columns[$column] = true;
            }
            $flat[] = $values;
        }
        if ($skipped > 0) {
            $warnings[] = "$skipped item(s) in the list weren't objects, so they were left out.";
        }
        if (empty($flat)) {
            throw new \Exception('No rows were found in the file. A JSON table should be a list of objects, one per row, '
                . 'or a list of lists whose first names the columns.');
        }

        $columns = array_map('strval', array_keys($columns));
        $csvRows = (function () use ($flat, $columns) {
            foreach ($flat as $values) {
                $row = [];
                foreach ($columns as $column) {
                    $row[] = $values[$column] ?? '';
                }
                yield $row;
            }
        })();
        // Blank and repeated names were kept apart with a "\0..." marker; cleanDataTableColumns() renames them
        $header = array_map(fn($column) => preg_replace('/\0[^.]*/', '', $column), $columns);

        return ['csv' => $this->writeDataTableCsv($header, $csvRows, $limitBytes, 'The file'), 'warnings' => $warnings];
    }

    // JSON Lines: one JSON value per line. Null unless every non-blank line decodes.
    private function decodeJsonLines($contents)
    {
        $items = [];
        foreach (preg_split('/\r?\n/', $contents) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $item = json_decode($line, true, 64, JSON_BIGINT_AS_STRING);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }
            $items[] = $item;
        }
        return count($items) > 0 ? $items : null;
    }

    // The rows a decoded JSON document holds, each an object (array keyed by column)
    private function jsonDataTableRows($data, array &$warnings)
    {
        if (!is_array($data)) {
            throw new \Exception('The file holds a single value, not a table. A JSON table should be a list of objects, '
                . 'one per row.');
        }

        if (array_is_list($data)) {
            // [["NAME", "B01001_001E", "state"], ["Kentucky", "4505836", "21"], ...]: the Census API's form
            if (!empty($data) && is_array($data[0]) && array_is_list($data[0]) && !empty($data[0])) {
                $header = $data[0];
                foreach ($header as $name) {
                    if (is_array($name)) {
                        throw new \Exception('The file is a list of lists, but the first list doesn\'t name the columns.');
                    }
                }
                $header = array_map(fn($name) => $this->jsonScalar($name), $header);
                $rows = [];
                $ragged = 0;
                foreach (array_slice($data, 1) as $values) {
                    if (!is_array($values) || !array_is_list($values)) {
                        continue;
                    }
                    if (count($values) !== count($header)) {
                        $ragged++;
                    }
                    $values = array_slice(array_pad($values, count($header), null), 0, count($header));
                    $row = [];
                    foreach ($header as $i => $name) {
                        // Repeated or blank names are made unique later (cleanDataTableColumns); keep them apart here
                        $row[$name === '' || isset($row[$name]) ? "$name\0$i" : $name] = $values[$i];
                    }
                    $rows[] = $row;
                }
                if ($ragged > 0) {
                    $warnings[] = "$ragged row(s) had a different number of values than the first list names, and were "
                        . 'padded or cut to fit. Check the preview.';
                }
                return $rows;
            }
            return $data;
        }

        // A GeoJSON FeatureCollection: one row per feature, from its properties
        if (($data['type'] ?? null) === 'FeatureCollection' && is_array($data['features'] ?? null)) {
            $rows = [];
            foreach ($data['features'] as $feature) {
                $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
                if (isset($feature['id']) && !is_array($feature['id']) && !array_key_exists('id', $properties)) {
                    $properties = ['id' => $feature['id']] + $properties;
                }
                $rows[] = $properties;
            }
            $warnings[] = 'Read each feature\'s properties; the shapes (geometry) were left out.';
            return $rows;
        }

        // {"data": [...], "meta": {...}}: the longest list of objects or lists under a key
        $best = null;
        foreach ($data as $key => $value) {
            if (is_array($value) && array_is_list($value) && !empty($value) && is_array($value[0])
                && ($best === null || count($value) > count($data[$best]))) {
                $best = $key;
            }
        }
        if ($best !== null) {
            if (count($data) > 1) {
                $warnings[] = "Read the rows from \"$best\"; the file's other top-level entries were left out.";
            }
            return $this->jsonDataTableRows($data[$best], $warnings);
        }

        // {"21067000100": {"svi": 0.4}, ...}: rows keyed by id
        $objects = array_filter($data, fn($value) => is_array($value) && !array_is_list($value));
        if (count($data) > 1 && count($objects) === count($data)) {
            $rows = [];
            foreach ($data as $key => $value) {
                $rows[] = ["key\0" => (string) $key] + $value;
            }
            $warnings[] = 'Each entry\'s name was read into a column called "key".';
            return $rows;
        }

        throw new \Exception('No list of rows was found in the file. A JSON table should be a list of objects, one per row '
            . '(or an object holding one, such as {"data": [...]}).');
    }

    // Nested objects become dotted columns; lists are kept as JSON text
    private function flattenJsonRow(array $row, $prefix, array &$out, $depth = 0)
    {
        foreach ($row as $key => $value) {
            $column = $prefix === '' ? (string) $key : "$prefix.$key";
            if (is_array($value) && !array_is_list($value) && $depth < 5) {
                $this->flattenJsonRow($value, $column, $out, $depth + 1);
            } else {
                // A name already used (as "a.b" can be both a key and a nested one) is kept apart with a marker
                $out[isset($out[$column]) ? $column . "\0" . count($out) : $column] = $this->jsonScalar($value);
            }
        }
    }

    private function jsonScalar($value)
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_float($value)) {
            return $this->dataTableNumber($value);
        }
        if (is_array($value)) {
            return empty($value) ? '' : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return (string) $value;
    }

    // ---- Excel workbooks ----

    // Reads each visible sheet of a workbook. Returns ['sheets' => [['sheet' => name, 'csv' => text or null,
    // 'warnings' => [...], 'problem' => why it couldn't be read, or null], ...], 'notices' => [...]].
    private function convertXlsxDataTable($path, $limitBytes)
    {
        if (!class_exists('ZipArchive') || !class_exists('XMLReader') || !class_exists('DOMDocument')) {
            throw new \Exception('This server can\'t read Excel workbooks (PHP\'s zip and xml extensions are needed). Save '
                . 'the sheet as CSV from Excel with File > Save As, and upload that instead.');
        }
        $start = (string) file_get_contents($path, false, null, 0, 8);
        if (str_starts_with($start, "\xD0\xCF\x11\xE0")) {
            throw new \Exception('This is an older Excel workbook (.xls) or a password-protected one. Open it in Excel and '
                . 'save it as .xlsx (with no password) or as CSV.');
        }
        $zip = new \ZipArchive();
        if (!str_starts_with($start, "PK") || $zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw new \Exception('The file isn\'t a readable Excel workbook. Open it in Excel and save it as .xlsx or CSV.');
        }

        try {
            $partLimit = $limitBytes * self::$maxXlsxPartRatio;
            $workbookPath = $this->xlsxMainPart($zip);
            $workbook = $this->xlsxDom($zip, $workbookPath, $partLimit);
            $rels = $this->xlsxRelationships($zip, $workbookPath, $partLimit);

            $date1904 = false;
            foreach ($workbook->getElementsByTagNameNS('*', 'workbookPr') as $properties) {
                $date1904 = in_array(strtolower($properties->getAttribute('date1904')), ['1', 'true'], true);
            }

            $strings = [];
            $dateStyles = [];
            foreach ($rels as $rel) {
                if (str_ends_with($rel['type'], '/sharedStrings')) {
                    $strings = $this->xlsxSharedStrings($zip, $rel['target'], $partLimit);
                } elseif (str_ends_with($rel['type'], '/styles')) {
                    $dateStyles = $this->xlsxDateStyles($this->xlsxDom($zip, $rel['target'], $partLimit));
                }
            }

            $sheets = [];
            $hidden = [];
            foreach ($workbook->getElementsByTagNameNS('*', 'sheet') as $sheet) {
                $name = $sheet->getAttribute('name');
                $relId = '';
                foreach ($sheet->attributes as $attribute) {
                    if ($attribute->localName === 'id' && str_contains((string) $attribute->namespaceURI, 'relationships')) {
                        $relId = $attribute->value;
                    }
                }
                if (in_array($sheet->getAttribute('state'), ['hidden', 'veryHidden'], true)) {
                    $hidden[] = $name;
                    continue;
                }
                $rel = $rels[$relId] ?? null;
                if ($rel === null || !str_ends_with($rel['type'], '/worksheet')) {
                    continue;   // a chart sheet or dialog sheet: nothing to read
                }
                try {
                    $read = $this->xlsxSheetToCsv($zip, $rel['target'], $strings, $dateStyles, $date1904, $limitBytes,
                        $partLimit, $name);
                    $sheets[] = ['sheet' => $name, 'csv' => $read['csv'], 'warnings' => $read['warnings'], 'problem' => null];
                } catch (\Throwable $e) {
                    $sheets[] = ['sheet' => $name, 'csv' => null, 'warnings' => [], 'problem' => $e->getMessage()];
                }
            }
        } finally {
            $zip->close();
        }

        if (empty($sheets)) {
            throw new \Exception('The workbook has no visible sheets to read'
                . (!empty($hidden) ? ' (hidden: ' . implode(', ', $hidden) . ')' : '') . '.');
        }
        return ['sheets' => $sheets,
            'notices' => empty($hidden) ? [] : ['Hidden sheets weren\'t read: ' . implode(', ', $hidden) . '.']];
    }

    // The workbook part's path, from the package's relationships (normally xl/workbook.xml)
    private function xlsxMainPart(\ZipArchive $zip)
    {
        $rels = $zip->getFromName('_rels/.rels');
        if ($rels !== false) {
            $dom = $this->xlsxLoadXml($rels);
            foreach ($dom->getElementsByTagNameNS('*', 'Relationship') as $rel) {
                if (str_ends_with($rel->getAttribute('Type'), '/officeDocument')) {
                    return ltrim($rel->getAttribute('Target'), '/');
                }
            }
        }
        if ($zip->locateName('xl/workbook.xml') !== false) {
            return 'xl/workbook.xml';
        }
        throw new \Exception('The file isn\'t a readable Excel workbook. Open it in Excel and save it as .xlsx or CSV.');
    }

    // A part's relationships: [id => ['type' => ..., 'target' => path in the zip]]
    private function xlsxRelationships(\ZipArchive $zip, $partPath, $partLimit)
    {
        $directory = dirname($partPath);
        $relsPath = ($directory === '.' ? '' : "$directory/") . '_rels/' . basename($partPath) . '.rels';
        if ($zip->locateName($relsPath) === false) {
            return [];
        }
        $rels = [];
        foreach ($this->xlsxDom($zip, $relsPath, $partLimit)->getElementsByTagNameNS('*', 'Relationship') as $rel) {
            if ($rel->getAttribute('TargetMode') === 'External') {
                continue;
            }
            $target = $rel->getAttribute('Target');
            $target = str_starts_with($target, '/') ? ltrim($target, '/') : $this->xlsxJoinPath($directory, $target);
            $rels[$rel->getAttribute('Id')] = ['type' => $rel->getAttribute('Type'), 'target' => $target];
        }
        return $rels;
    }

    private function xlsxJoinPath($directory, $relative)
    {
        $parts = $directory === '.' || $directory === '' ? [] : explode('/', $directory);
        foreach (explode('/', $relative) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '.' && $part !== '') {
                $parts[] = $part;
            }
        }
        return implode('/', $parts);
    }

    private function xlsxCheckPartSize(\ZipArchive $zip, $name, $partLimit)
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            throw new \Exception("The workbook is missing a part ($name). Open it in Excel and save it again.");
        }
        if ($stat['size'] > $partLimit) {
            throw new \Exception('The workbook is too large to read. Upload a smaller part of it, or save the sheet as CSV.');
        }
    }

    private function xlsxDom(\ZipArchive $zip, $name, $partLimit)
    {
        $this->xlsxCheckPartSize($zip, $name, $partLimit);
        return $this->xlsxLoadXml((string) $zip->getFromName($name));
    }

    private function xlsxLoadXml($xml)
    {
        $dom = new \DOMDocument();
        // No network, and no entity substitution or DTD loading (the defaults)
        if (!@$dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new \Exception('The workbook couldn\'t be read. Open it in Excel and save it again as .xlsx or CSV.');
        }
        return $dom;
    }

    private function xlsxOpenReader(\ZipArchive $zip, $name, $partLimit)
    {
        $this->xlsxCheckPartSize($zip, $name, $partLimit);
        $reader = new \XMLReader();
        if (!$reader->open('zip://' . $zip->filename . '#' . $name, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new \Exception('The workbook couldn\'t be read. Open it in Excel and save it again as .xlsx or CSV.');
        }
        return $reader;
    }

    // The shared strings, in order. Each <si> is plain (<t>) or rich text (<r><t>), with any phonetic
    // guide (<rPh>) left out.
    private function xlsxSharedStrings(\ZipArchive $zip, $name, $partLimit)
    {
        $reader = $this->xlsxOpenReader($zip, $name, $partLimit);
        $strings = [];
        try {
            $ok = $reader->read();
            while ($ok) {
                if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'si') {
                    $strings[] = $this->xlsxText($reader->expand());
                    $ok = $reader->next();
                    continue;
                }
                $ok = $reader->read();
            }
        } finally {
            $reader->close();
        }
        return $strings;
    }

    // The text of an <si> or <is>: its <t> elements, outside <rPh>
    private function xlsxText($node)
    {
        if (!$node instanceof \DOMNode) {
            return '';
        }
        $text = '';
        foreach ($node->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            if ($child->localName === 't') {
                $text .= $child->textContent;
            } elseif ($child->localName === 'r') {
                $text .= $this->xlsxText($child);
            }
        }
        return $text;
    }

    // The cell styles (by index) whose number format shows a date or time: [index => true]
    private function xlsxDateStyles(\DOMDocument $styles)
    {
        $custom = [];
        foreach ($styles->getElementsByTagNameNS('*', 'numFmt') as $format) {
            $custom[(int) $format->getAttribute('numFmtId')] = $format->getAttribute('formatCode');
        }

        $dateStyles = [];
        foreach ($styles->getElementsByTagNameNS('*', 'cellXfs') as $cellXfs) {
            $index = 0;
            foreach ($cellXfs->childNodes as $xf) {
                if ($xf->nodeType !== XML_ELEMENT_NODE || $xf->localName !== 'xf') {
                    continue;
                }
                $id = (int) $xf->getAttribute('numFmtId');
                // Excel's built-in date and time formats, including the East Asian ones
                $isDate = ($id >= 14 && $id <= 22) || ($id >= 27 && $id <= 36) || ($id >= 45 && $id <= 47)
                    || ($id >= 50 && $id <= 58);
                if (!$isDate && isset($custom[$id])) {
                    $isDate = $this->xlsxIsDateFormat($custom[$id]);
                }
                if ($isDate) {
                    $dateStyles[$index] = true;
                }
                $index++;
            }
            break;
        }
        return $dateStyles;
    }

    // A custom format shows a date when, outside quoted text, escapes and [colour]/[$-locale] sections,
    // it uses day, month, year, hour or second codes
    private function xlsxIsDateFormat($code)
    {
        $code = preg_replace('/"[^"]*"|\\\\.|_.|\*./', '', (string) $code);
        $code = preg_replace('/\[(?!h+\]|m+\]|s+\])[^\]]*\]/i', '', $code);
        $code = explode(';', $code)[0];
        return strcasecmp(trim($code), 'General') !== 0 && preg_match('/[dmyhs]/i', $code) === 1;
    }

    // Excel's day number as a date: YYYY-MM-DD, HH:MM:SS, or both
    private function xlsxDate($serial, $date1904)
    {
        $serial = (float) $serial;
        $days = floor($serial);
        $seconds = (int) round(($serial - $days) * 86400);
        if ($seconds >= 86400) {
            $days++;
            $seconds -= 86400;
        }
        // Day 25569 is 1970-01-01 (counting from 1900, with Excel's 29 February 1900); 1904 workbooks are 1462 days behind
        $timestamp = (int) (($days + ($date1904 ? 1462 : 0) - 25569) * 86400) + $seconds;
        if ($days == 0 && !$date1904) {
            return gmdate('H:i:s', $seconds);
        }
        return $seconds === 0 ? gmdate('Y-m-d', $timestamp) : gmdate('Y-m-d H:i:s', $timestamp);
    }

    // Column letters as a 0-based index: A = 0, Z = 25, AA = 26
    private function xlsxColumnIndex($reference)
    {
        if (!preg_match('/^([A-Z]+)/i', (string) $reference, $match)) {
            return null;
        }
        $index = 0;
        foreach (str_split(strtoupper($match[1])) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }
        return $index - 1;
    }

    // Reads one sheet as CSV: its first non-blank row names the columns (from the leftmost to the
    // rightmost non-blank cell in it), and blank rows are skipped. Returns ['csv', 'warnings'].
    private function xlsxSheetToCsv(\ZipArchive $zip, $name, array $strings, array $dateStyles, $date1904, $limitBytes,
                                    $partLimit, $sheetName)
    {
        $reader = $this->xlsxOpenReader($zip, $name, $partLimit);
        $header = null;      // [column index => name]
        $first = $last = 0;  // the header's leftmost and rightmost column indexes
        $rows = [];
        $bytes = 0;
        $beyond = 0;         // rows with values right of the last named column
        $errors = 0;         // cells holding an error value
        $warnings = [];

        try {
            $ok = $reader->read();
            while ($ok) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'row') {
                    $ok = $reader->read();
                    continue;
                }
                $cells = [];
                $next = 0;
                foreach ($reader->expand()->childNodes as $cell) {
                    if ($cell->nodeType !== XML_ELEMENT_NODE || $cell->localName !== 'c') {
                        continue;
                    }
                    $index = $this->xlsxColumnIndex($cell->getAttribute('r')) ?? $next;
                    $next = $index + 1;
                    $value = $this->xlsxCellValue($cell, $strings, $dateStyles, $date1904, $errors);
                    if ($value !== '') {
                        $cells[$index] = $value;
                    }
                }
                $ok = $reader->next();

                if (empty($cells)) {
                    continue;
                }
                if ($header === null) {
                    $header = $cells;
                    $first = min(array_keys($cells));
                    $last = max(array_keys($cells));
                    continue;
                }
                if (max(array_keys($cells)) > $last) {
                    $beyond++;
                }
                $row = [];
                for ($i = $first; $i <= $last; $i++) {
                    $row[] = $cells[$i] ?? '';
                }
                if (implode('', $row) === '') {
                    continue;
                }
                $rows[] = $row;
                $bytes += strlen(implode(',', $row)) + 1;
                if ($bytes > $limitBytes) {
                    throw new \Exception("The sheet \"$sheetName\" is more than " . $this->formatDataTableBytes($limitBytes)
                        . ' once read as a table, the most a table can hold. Upload a smaller part of it, such as one '
                        . 'state\'s rows.');
                }
            }
        } finally {
            $reader->close();
        }

        if ($header === null) {
            throw new \Exception('The sheet is empty.');
        }
        $columns = [];
        for ($i = $first; $i <= $last; $i++) {
            $columns[] = $header[$i] ?? '';
        }
        if ($beyond > 0) {
            $warnings[] = "$beyond row(s) had values to the right of the last named column, which were left out.";
        }
        if ($errors > 0) {
            $warnings[] = "$errors cell(s) held an Excel error such as #N/A or #DIV/0!, and were read as blank.";
        }

        return ['csv' => $this->writeDataTableCsv($columns, $rows, $limitBytes, "The sheet \"$sheetName\""),
            'warnings' => $warnings];
    }

    private function xlsxCellValue(\DOMElement $cell, array $strings, array $dateStyles, $date1904, &$errors)
    {
        $type = $cell->getAttribute('t') ?: 'n';
        $v = null;
        $inline = null;
        foreach ($cell->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            if ($child->localName === 'v') {
                $v = $child->textContent;
            } elseif ($child->localName === 'is') {
                $inline = $child;
            }
        }

        switch ($type) {
            case 's':
                return $v === null ? '' : (string) ($strings[(int) $v] ?? '');
            case 'inlineStr':
                return $this->xlsxText($inline);
            case 'str':
                return (string) $v;
            case 'b':
                return $v === null ? '' : ($v === '1' ? 'TRUE' : 'FALSE');
            case 'e':
                $errors++;
                return '';
            case 'd':
                // An ISO 8601 date, which Excel itself doesn't write but other programs may
                return preg_replace('/T00:00:00(\.0+)?Z?$/', '', (string) $v);
            default:
                if ($v === null || trim($v) === '') {
                    return '';
                }
                if (!is_numeric($v)) {
                    return $v;
                }
                if (isset($dateStyles[(int) $cell->getAttribute('s')]) && (float) $v >= 0) {
                    return $this->xlsxDate($v, $date1904);
                }
                return $this->dataTableNumber($v);
        }
    }
}

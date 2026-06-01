<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

function fixture_fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function fixture_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fixture_fail($message);
    }
}

function load_fixture_json(string $path): array
{
    fixture_assert(is_file($path), "Missing fixture file: {$path}");

    $raw = file_get_contents($path);
    fixture_assert($raw !== false, "Unable to read fixture file: {$path}");

    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    fixture_assert(is_array($decoded), "Fixture JSON did not decode to an object: {$path}");

    return $decoded;
}

function validate_source_assertions(string $repoRoot, array $assertions): int
{
    $sourceCache = [];
    $checked = 0;

    foreach ($assertions as $index => $assertion) {
        fixture_assert(is_array($assertion), "Source assertion {$index} must be an object.");

        $relativeFile = (string)($assertion['file'] ?? '');
        $needle = (string)($assertion['contains'] ?? '');

        fixture_assert($relativeFile !== '', "Source assertion {$index} is missing file.");
        fixture_assert($needle !== '', "Source assertion {$index} is missing contains.");

        $path = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeFile);
        if (!array_key_exists($path, $sourceCache)) {
            fixture_assert(is_file($path), "Source file not found for assertion: {$relativeFile}");
            $content = file_get_contents($path);
            fixture_assert($content !== false, "Unable to read source file: {$relativeFile}");
            $sourceCache[$path] = $content;
        }

        fixture_assert(
            str_contains($sourceCache[$path], $needle),
            "Source assertion failed for {$relativeFile}: missing [{$needle}]"
        );
        $checked++;
    }

    return $checked;
}

function format_fixture_amount(float $amount): string
{
    return number_format($amount, 2, '.', '');
}

function parse_credit_card_fixture(string $path, int $accountId): array
{
    fixture_assert(is_file($path), "Missing CSV fixture: {$path}");

    $handle = fopen($path, 'r');
    fixture_assert($handle !== false, "Unable to open CSV fixture: {$path}");

    $header = fgetcsv($handle);
    fixture_assert(is_array($header), "CSV fixture is missing a header row.");

    $header = array_map(static fn($value): string => trim((string)$value), $header);
    $expectedLength = count($header);
    $merchantIndex = array_search('Merchant', $header, true);

    $rows = [];
    $rowsRepaired = 0;
    $rowsMalformed = 0;
    $rowsNonBilled = 0;

    while (($rawRow = fgetcsv($handle)) !== false) {
        if ($rawRow === [null] || count(array_filter($rawRow, static fn($cell): bool => trim((string)$cell) !== '')) === 0) {
            continue;
        }

        $rowValues = array_map(static fn($value): string => (string)$value, $rawRow);

        if (count($rowValues) > $expectedLength) {
            if ($merchantIndex === false) {
                $rowsMalformed++;
                continue;
            }

            $extraColumns = count($rowValues) - $expectedLength;
            $rowValues = array_merge(
                array_slice($rowValues, 0, (int)$merchantIndex),
                [implode(',', array_slice($rowValues, (int)$merchantIndex, $extraColumns + 1))],
                array_slice($rowValues, (int)$merchantIndex + $extraColumns + 1)
            );
            $rowsRepaired++;
        }

        $rowValues = array_map(static fn($value): string => trim((string)$value), $rowValues);

        if (count($rowValues) !== $expectedLength) {
            $rowsMalformed++;
            continue;
        }

        $row = array_combine($header, $rowValues);
        fixture_assert(is_array($row), "Unable to combine CSV header and row values.");

        if (strtoupper((string)($row['Status'] ?? '')) !== 'BILLED') {
            $rowsNonBilled++;
            continue;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($row['Transaction Date'] ?? ''));
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($dateErrors) && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
            $rowsMalformed++;
            continue;
        }

        $rawAmount = (string)($row['Billing Amount'] ?? '');
        if ($rawAmount === '' || !is_numeric($rawAmount)) {
            $rowsMalformed++;
            continue;
        }

        $amount = (float)$rawAmount;
        $transactionType = strtoupper((string)($row['Debit or Credit'] ?? ''));
        $signedAmount = $transactionType === 'CRDT' ? abs($amount) : -abs($amount);

        $rows[] = [
            'account_id' => $accountId,
            'date' => $date->format('Y-m-d'),
            'description' => (string)($row['Merchant'] ?? ''),
            'amount' => format_fixture_amount($signedAmount),
        ];
    }

    fclose($handle);

    return [
        'summary' => [
            'rows_parsed' => count($rows),
            'rows_repaired' => $rowsRepaired,
            'rows_malformed' => $rowsMalformed,
            'rows_non_billed' => $rowsNonBilled,
        ],
        'rows' => $rows,
    ];
}

function validate_review_fixtures(array $fixture): int
{
    fixture_assert(($fixture['version'] ?? null) === 1, 'Review fixture version must be 1.');
    fixture_assert(isset($fixture['cases']) && is_array($fixture['cases']), 'Review fixture cases must be an array.');
    fixture_assert(count($fixture['cases']) >= 5, 'Expected at least five Review workflow fixture cases.');

    foreach ($fixture['cases'] as $index => $case) {
        fixture_assert(is_array($case), "Review case {$index} must be an object.");
        fixture_assert((string)($case['name'] ?? '') !== '', "Review case {$index} is missing name.");
        fixture_assert((string)($case['workflow'] ?? '') !== '', "Review case {$index} is missing workflow.");
        fixture_assert(isset($case['setup']) && is_array($case['setup']), "Review case {$index} is missing setup.");
        fixture_assert(isset($case['post']) && is_array($case['post']), "Review case {$index} is missing post.");
        fixture_assert(isset($case['expect']) && is_array($case['expect']), "Review case {$index} is missing expect.");
        fixture_assert(isset($case['expect']['guards']) && is_array($case['expect']['guards']), "Review case {$index} is missing guard list.");
    }

    return count($fixture['cases']);
}

function validate_import_fixture(string $fixtureDir, array $expected): array
{
    fixture_assert(($expected['version'] ?? null) === 1, 'Import fixture version must be 1.');

    $fixtureName = (string)($expected['fixture'] ?? '');
    fixture_assert($fixtureName !== '', 'Import expected fixture is missing fixture name.');

    $accountId = (int)($expected['account_id'] ?? 0);
    fixture_assert($accountId > 0, 'Import expected fixture must provide a positive account_id.');

    $actual = parse_credit_card_fixture($fixtureDir . DIRECTORY_SEPARATOR . $fixtureName, $accountId);

    foreach (($expected['expected_summary'] ?? []) as $key => $expectedValue) {
        fixture_assert(
            array_key_exists($key, $actual['summary']),
            "Import fixture actual summary is missing {$key}."
        );
        fixture_assert(
            $actual['summary'][$key] === $expectedValue,
            "Import fixture {$key} expected {$expectedValue}, got {$actual['summary'][$key]}."
        );
    }

    $expectedRows = $expected['expected_rows'] ?? null;
    fixture_assert(is_array($expectedRows), 'Import expected rows must be an array.');
    fixture_assert(count($actual['rows']) === count($expectedRows), 'Import parsed row count did not match expected rows.');

    foreach ($expectedRows as $index => $expectedRow) {
        fixture_assert($actual['rows'][$index] === $expectedRow, "Import parsed row {$index} did not match expected data.");
    }

    return $actual['summary'];
}

try {
    $repoRoot = dirname(__DIR__, 2);

    $reviewFixture = load_fixture_json($repoRoot . '/tests/fixtures/review/review_workflows.json');
    $importExpected = load_fixture_json($repoRoot . '/tests/fixtures/import/credit_card_sample.expected.json');

    $reviewCases = validate_review_fixtures($reviewFixture);
    $importSummary = validate_import_fixture($repoRoot . '/tests/fixtures/import', $importExpected);

    $sourceAssertions = 0;
    $sourceAssertions += validate_source_assertions($repoRoot, $reviewFixture['source_assertions'] ?? []);
    $sourceAssertions += validate_source_assertions($repoRoot, $importExpected['source_assertions'] ?? []);

    echo "Review fixture cases: {$reviewCases}\n";
    echo "Import fixture rows parsed: {$importSummary['rows_parsed']}\n";
    echo "Source assertions checked: {$sourceAssertions}\n";
    echo "All Review/import regression fixture checks passed.\n";
    exit(0);
} catch (JsonException $e) {
    fixture_fail('Invalid fixture JSON: ' . $e->getMessage());
} catch (Throwable $e) {
    fixture_fail($e->getMessage());
}

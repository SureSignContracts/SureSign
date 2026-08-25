<?php

/**
 * Standalone helper for AiAnalysisConcurrencyMysqlTest — deliberately NOT a
 * Laravel-bootstrapped script (raw PDO only) so it starts fast as its own
 * independent OS process, spawned via proc_open() from the test. This is
 * "Execution B": it attempts to acquire the SAME parent-row lock the main
 * test process ("Execution A") is holding, proving real MySQL/InnoDB
 * row-level lock contention rather than merely sequential ordering.
 *
 * Usage:
 *   php attempt_claim.php <host> <port> <database> <username> <password> \
 *       <mode: contract|package> <parentId> <createdByUserId>
 *
 * Emits one line of JSON to stdout:
 *   {"created": bool, "wait_seconds": float, "error": string|null}
 *
 * "wait_seconds" is the time between issuing the FOR UPDATE lock request and
 * it actually being granted — the direct evidence that this process was
 * genuinely blocked by the other connection's held lock, not merely slow to
 * start.
 */

[, $host, $port, $database, $username, $password, $mode, $parentId, $createdBy] = $argv;

// Same fail-safe as AiAnalysisConcurrencyMysqlTest's own guard, enforced
// independently here too (defense-in-depth) — this process performs a
// real row lock + insert and must never trust the caller's arguments
// blindly. Never loosen this to a pattern/prefix match.
const SAFE_TEST_DATABASE = 'suresign_concurrency_test';

$result = ['created' => false, 'wait_seconds' => null, 'error' => null];

if ($database !== SAFE_TEST_DATABASE) {
    $result['error'] = "Refusing to run — database '{$database}' is not the dedicated "
        . "test schema '" . SAFE_TEST_DATABASE . "'.";
    echo json_encode($result);
    exit(1);
}

$parentTable   = $mode === 'contract' ? 'contracts' : 'trade_packages';
$analysisTable = $mode === 'contract' ? 'contract_ai_analyses' : 'trade_package_ai_analyses';
$parentIdCol   = $mode === 'contract' ? 'contract_id' : 'trade_package_id';

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->beginTransaction();

    $timeBefore = microtime(true);

    $lockStmt = $pdo->prepare("SELECT id, organization_id, project_id FROM {$parentTable} WHERE id = :id FOR UPDATE");
    $lockStmt->execute(['id' => $parentId]);
    $parent = $lockStmt->fetch(PDO::FETCH_ASSOC);

    $timeAfter = microtime(true);
    $result['wait_seconds'] = $timeAfter - $timeBefore;

    $activeStmt = $pdo->prepare(
        "SELECT COUNT(*) AS c FROM {$analysisTable} WHERE {$parentIdCol} = :parentId AND status IN ('pending', 'processing')"
    );
    $activeStmt->execute(['parentId' => $parentId]);
    $activeCount = (int) $activeStmt->fetch(PDO::FETCH_ASSOC)['c'];

    if ($activeCount === 0) {
        $insertStmt = $pdo->prepare(
            "INSERT INTO {$analysisTable}
                ({$parentIdCol}, organization_id, project_id, status, created_by, telemetry_schema_version, created_at, updated_at)
             VALUES
                (:parentId, :orgId, :projectId, 'pending', :createdBy, 2, NOW(), NOW())"
        );
        $insertStmt->execute([
            'parentId'  => $parentId,
            'orgId'     => $parent['organization_id'],
            'projectId' => $parent['project_id'],
            'createdBy' => $createdBy,
        ]);
        $result['created'] = true;
    }

    $pdo->commit();
} catch (\Throwable $e) {
    $result['error'] = $e->getMessage();
}

echo json_encode($result);

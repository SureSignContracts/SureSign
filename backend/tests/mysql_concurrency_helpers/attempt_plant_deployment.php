<?php

/**
 * Standalone helper for PlantDeploymentConcurrencyMysqlTest — mirrors
 * attempt_claim.php's exact mechanism (deliberately NOT Laravel-bootstrapped,
 * raw PDO only, spawned via proc_open() as a genuinely separate OS process).
 * This is "Execution B": it attempts to acquire the SAME parent PlantItem
 * row lock the main test process ("Execution A") is holding, then runs the
 * identical overlap check App\Services\Plant\PlantDeploymentService uses,
 * proving real MySQL/InnoDB row-level lock contention serializes concurrent
 * deployment mutations for the SAME plant item.
 *
 * Usage:
 *   php attempt_plant_deployment.php <host> <port> <database> <username> <password> \
 *       <plantItemId> <projectId> <orgId> <createdByUserId> <onSiteFrom> <offSiteAtOrEmpty>
 *
 * Emits one line of JSON to stdout:
 *   {"created": bool, "wait_seconds": float, "error": string|null}
 */

[, $host, $port, $database, $username, $password, $plantItemId, $projectId, $orgId, $createdBy, $onSiteFrom, $offSiteAt] = $argv;

// Same fail-safe as the AI Analysis concurrency harness's own guard,
// enforced independently here too (defense-in-depth) — this process
// performs a real row lock + insert and must never trust the caller's
// arguments blindly. Never loosen this to a pattern/prefix match.
const SAFE_TEST_DATABASE = 'suresign_concurrency_test';

$result = ['created' => false, 'wait_seconds' => null, 'error' => null];

if ($database !== SAFE_TEST_DATABASE) {
    $result['error'] = "Refusing to run — database '{$database}' is not the dedicated "
        . "test schema '" . SAFE_TEST_DATABASE . "'.";
    echo json_encode($result);
    exit(1);
}

$offSiteAt = $offSiteAt === '' ? null : $offSiteAt;

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->beginTransaction();

    $timeBefore = microtime(true);

    $lockStmt = $pdo->prepare('SELECT id FROM plant_items WHERE id = :id FOR UPDATE');
    $lockStmt->execute(['id' => $plantItemId]);
    $lockStmt->fetch();

    $timeAfter = microtime(true);
    $result['wait_seconds'] = $timeAfter - $timeBefore;

    // The exact same interval-overlap formula PlantDeploymentService uses.
    $effectiveEnd = $offSiteAt ?? '9999-12-31';
    $overlapStmt = $pdo->prepare(
        'SELECT COUNT(*) AS c FROM plant_deployments
         WHERE plant_item_id = :plantItemId
           AND deleted_at IS NULL
           AND on_site_from <= :effectiveEnd
           AND (off_site_at IS NULL OR off_site_at >= :onSiteFrom)'
    );
    $overlapStmt->execute([
        'plantItemId'  => $plantItemId,
        'effectiveEnd' => $effectiveEnd,
        'onSiteFrom'   => $onSiteFrom,
    ]);
    $overlapCount = (int) $overlapStmt->fetch(PDO::FETCH_ASSOC)['c'];

    if ($overlapCount === 0) {
        $insertStmt = $pdo->prepare(
            'INSERT INTO plant_deployments
                (organization_id, project_id, plant_item_id, created_by, on_site_from, off_site_at, created_at, updated_at)
             VALUES
                (:orgId, :projectId, :plantItemId, :createdBy, :onSiteFrom, :offSiteAt, NOW(), NOW())'
        );
        $insertStmt->execute([
            'orgId'       => $orgId,
            'projectId'   => $projectId,
            'plantItemId' => $plantItemId,
            'createdBy'   => $createdBy,
            'onSiteFrom'  => $onSiteFrom,
            'offSiteAt'   => $offSiteAt,
        ]);
        $result['created'] = true;
    }

    $pdo->commit();
} catch (\Throwable $e) {
    $result['error'] = $e->getMessage();
}

echo json_encode($result);

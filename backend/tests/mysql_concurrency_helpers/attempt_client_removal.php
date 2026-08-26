<?php

/**
 * Standalone helper for UserRemovalConcurrencyMysqlTest — deliberately NOT a
 * Laravel-bootstrapped script (raw PDO only), same pattern as
 * attempt_claim.php (AI Analysis TOCTOU). This is "Execution B": it attempts
 * to acquire the SAME organisation-row lock ("SELECT ... FOR UPDATE") the
 * main test process ("Execution A") is holding, proving real MySQL/InnoDB
 * row-level lock contention for the Remove & Detach Concurrency Hardening
 * fix (UserController::withOrganizationLock(), now extracted to
 * App\Support\Users\OrganizationRemovalLock). `detach` mode's lock/decision
 * query shape is identical to AuthController::deleteAccount()'s Client
 * branch (Self-Service Account Deletion) — both now call the exact same
 * shared lock class and last-Client counting query — so this same mode also
 * stands in for a concurrent self-delete in
 * test_self_delete_racing_an_admin_initiated_detach_never_both_succeed_unconfirmed().
 *
 * Usage:
 *   php attempt_client_removal.php <host> <port> <database> <username> <password> \
 *       <mode: detach|normal_remove> <organizationId> <targetUserId> <confirmLastClient: 0|1>
 *
 * Mirrors the exact queries UserController::removeAndDetach() /
 * removeUserWithinOrganizationLock() perform, so this proves the real
 * production locking strategy, not a hand-invented one:
 *   - detach: lock organisation row -> re-fetch target -> count other
 *     non-deleted Client users in this organisation -> reject (no mutation)
 *     if last-Client and not confirmed, else revoke tokens + clear
 *     organization_id + soft-delete.
 *   - normal_remove: lock organisation row -> revoke tokens + soft-delete
 *     only (organization_id untouched, no last-Client check at all — the
 *     lock here exists only to serialize against a concurrent detach's
 *     read, per the approved invariant).
 *
 * Emits one line of JSON to stdout:
 *   {"error": string|null, "wait_seconds": float, "rejected": bool,
 *    "mutated": bool, "remaining_clients": int|null}
 */

[, $host, $port, $database, $username, $password, $mode, $organizationId, $targetUserId, $confirmLastClient] = $argv;

const SAFE_TEST_DATABASE = 'suresign_concurrency_test';
const USER_MODEL_TYPE = 'App\\Models\\User';
const CLIENT_ROLE = 'Client';

$result = ['error' => null, 'wait_seconds' => null, 'rejected' => false, 'mutated' => false, 'remaining_clients' => null];

if ($database !== SAFE_TEST_DATABASE) {
    $result['error'] = "Refusing to run — database '{$database}' is not the dedicated "
        . 'test schema \'' . SAFE_TEST_DATABASE . "'.";
    echo json_encode($result);
    exit(1);
}

$confirm = $confirmLastClient === '1';

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->beginTransaction();

    $timeBefore = microtime(true);
    $lockStmt = $pdo->prepare('SELECT id FROM organizations WHERE id = :id FOR UPDATE');
    $lockStmt->execute(['id' => $organizationId]);
    $lockStmt->fetch();
    $timeAfter = microtime(true);
    $result['wait_seconds'] = $timeAfter - $timeBefore;

    if ($mode === 'detach') {
        $isClientStmt = $pdo->prepare(
            "SELECT COUNT(*) AS c FROM users u
             JOIN model_has_roles mhr ON mhr.model_id = u.id AND mhr.model_type = :modelType
             JOIN roles r ON r.id = mhr.role_id AND r.name = :role
             WHERE u.id = :id AND u.organization_id = :orgId AND u.deleted_at IS NULL"
        );
        $isClientStmt->execute(['modelType' => USER_MODEL_TYPE, 'role' => CLIENT_ROLE, 'id' => $targetUserId, 'orgId' => $organizationId]);
        $eligible = (int) $isClientStmt->fetch(PDO::FETCH_ASSOC)['c'] > 0;

        if (!$eligible) {
            $result['error'] = 'target not an eligible org-attached Client at lock time';
            $pdo->rollBack();
            echo json_encode($result);
            exit(0);
        }

        $othersStmt = $pdo->prepare(
            "SELECT COUNT(*) AS c FROM users u
             JOIN model_has_roles mhr ON mhr.model_id = u.id AND mhr.model_type = :modelType
             JOIN roles r ON r.id = mhr.role_id AND r.name = :role
             WHERE u.organization_id = :orgId AND u.id != :id AND u.deleted_at IS NULL"
        );
        $othersStmt->execute(['modelType' => USER_MODEL_TYPE, 'role' => CLIENT_ROLE, 'orgId' => $organizationId, 'id' => $targetUserId]);
        $remaining = (int) $othersStmt->fetch(PDO::FETCH_ASSOC)['c'];
        $result['remaining_clients'] = $remaining;
        $isLastClient = $remaining === 0;

        if ($isLastClient && !$confirm) {
            $result['rejected'] = true;
            $pdo->rollBack();
            echo json_encode($result);
            exit(0);
        }

        $pdo->prepare('DELETE FROM personal_access_tokens WHERE tokenable_type = :type AND tokenable_id = :id')
            ->execute(['type' => USER_MODEL_TYPE, 'id' => $targetUserId]);
        $pdo->prepare('UPDATE users SET organization_id = NULL, deleted_at = NOW() WHERE id = :id')
            ->execute(['id' => $targetUserId]);
        $pdo->commit();
        $result['mutated'] = true;
    } elseif ($mode === 'normal_remove') {
        $pdo->prepare('DELETE FROM personal_access_tokens WHERE tokenable_type = :type AND tokenable_id = :id')
            ->execute(['type' => USER_MODEL_TYPE, 'id' => $targetUserId]);
        $pdo->prepare('UPDATE users SET deleted_at = NOW() WHERE id = :id')
            ->execute(['id' => $targetUserId]);
        $pdo->commit();
        $result['mutated'] = true;
    } else {
        $result['error'] = "unknown mode '{$mode}'";
        $pdo->rollBack();
    }
} catch (\Throwable $e) {
    $result['error'] = $e->getMessage();
}

echo json_encode($result);

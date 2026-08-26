<?php

/**
 * Standalone helper for LastActiveSuperAdminConcurrencyMysqlTest —
 * deliberately NOT a Laravel-bootstrapped script (raw PDO only), same
 * pattern as attempt_claim.php / attempt_client_removal.php. This is
 * "Execution B": it attempts to acquire the SAME 'Super Admin' roles-row
 * lock ("SELECT ... FOR UPDATE") the main test process ("Execution A") is
 * holding, proving real MySQL/InnoDB row-level lock contention for the
 * Last Active Super Admin Concurrency Hardening fix
 * (App\Support\Auth\SuperAdminGuard::guardedMutate()).
 *
 * Usage:
 *   php attempt_super_admin_removal.php <host> <port> <database> <username> \
 *       <password> <mode: remove|deactivate> <targetUserId>
 *
 * Mirrors the exact queries SuperAdminGuard::isLastActive()/guardedMutate()
 * and UserController::destroy()/update() perform:
 *   - lock the 'Super Admin' roles row
 *   - count ACTIVE Super Admins (role = Super Admin, is_active = true,
 *     banned_at IS NULL, deleted_at IS NULL — deleted_at is implicit via
 *     Eloquent's own SoftDeletes global scope in the real code; explicit
 *     here since this is raw PDO)
 *   - reject (no mutation) if that count is <= 1
 *   - otherwise perform the mutation: remove (soft-delete + revoke tokens)
 *     or deactivate (is_active = false + revoke tokens)
 *
 * Emits one line of JSON to stdout:
 *   {"error": string|null, "wait_seconds": float, "rejected": bool,
 *    "mutated": bool, "active_count_seen": int|null}
 */

[, $host, $port, $database, $username, $password, $mode, $targetUserId] = $argv;

const SAFE_TEST_DATABASE = 'suresign_concurrency_test';
const USER_MODEL_TYPE = 'App\\Models\\User';
const SUPER_ADMIN_ROLE = 'Super Admin';

$result = ['error' => null, 'wait_seconds' => null, 'rejected' => false, 'mutated' => false, 'active_count_seen' => null];

if ($database !== SAFE_TEST_DATABASE) {
    $result['error'] = "Refusing to run — database '{$database}' is not the dedicated "
        . 'test schema \'' . SAFE_TEST_DATABASE . "'.";
    echo json_encode($result);
    exit(1);
}

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->beginTransaction();

    $timeBefore = microtime(true);
    $lockStmt = $pdo->prepare("SELECT id FROM roles WHERE name = :name AND guard_name = 'web' FOR UPDATE");
    $lockStmt->execute(['name' => SUPER_ADMIN_ROLE]);
    $lockStmt->fetch();
    $timeAfter = microtime(true);
    $result['wait_seconds'] = $timeAfter - $timeBefore;

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) AS c FROM users u
         JOIN model_has_roles mhr ON mhr.model_id = u.id AND mhr.model_type = :modelType
         JOIN roles r ON r.id = mhr.role_id AND r.name = :role
         WHERE u.is_active = 1 AND u.banned_at IS NULL AND u.deleted_at IS NULL"
    );
    $countStmt->execute(['modelType' => USER_MODEL_TYPE, 'role' => SUPER_ADMIN_ROLE]);
    $activeCount = (int) $countStmt->fetch(PDO::FETCH_ASSOC)['c'];
    $result['active_count_seen'] = $activeCount;

    if ($activeCount <= 1) {
        $result['rejected'] = true;
        $pdo->rollBack();
        echo json_encode($result);
        exit(0);
    }

    if ($mode === 'remove') {
        $pdo->prepare('DELETE FROM personal_access_tokens WHERE tokenable_type = :type AND tokenable_id = :id')
            ->execute(['type' => USER_MODEL_TYPE, 'id' => $targetUserId]);
        $pdo->prepare('UPDATE users SET deleted_at = NOW() WHERE id = :id')
            ->execute(['id' => $targetUserId]);
    } elseif ($mode === 'deactivate') {
        $pdo->prepare('DELETE FROM personal_access_tokens WHERE tokenable_type = :type AND tokenable_id = :id')
            ->execute(['type' => USER_MODEL_TYPE, 'id' => $targetUserId]);
        $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = :id')
            ->execute(['id' => $targetUserId]);
    } else {
        $result['error'] = "unknown mode '{$mode}'";
        $pdo->rollBack();
        echo json_encode($result);
        exit(1);
    }

    $pdo->commit();
    $result['mutated'] = true;
} catch (\Throwable $e) {
    $result['error'] = $e->getMessage();
}

echo json_encode($result);

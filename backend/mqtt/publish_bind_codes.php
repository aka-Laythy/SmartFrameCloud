<?php
/**
 * Batch publisher for dynamic bind codes.
 *
 * Usage:
 *   php backend/mqtt/publish_bind_codes.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run in CLI mode.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/functions.php';

const BIND_CODE_EXPIRES_IN = 300;
const BIND_CODE_REFRESH_AHEAD = 60;
const BIND_CODE_BATCH_SIZE = 100;
const BIND_CODE_PUBLISH_DELAY_US = 50000;

function logBindCodePublisher(string $message): void
{
    $timestamp = date('Y-m-d H:i:s');
    fwrite(STDOUT, "[{$timestamp}] {$message}\n");
}

function fetchDevicesDueForBindCode($db): array
{
    $stmt = $db->prepare("
        SELECT *
        FROM devices
        WHERE user_id IS NULL
          AND status = 1
          AND (
              dyn_bound_code IS NULL
              OR dyn_bound_code_expires_at IS NULL
              OR dyn_bound_code_expires_at <= DATE_ADD(NOW(), INTERVAL ? SECOND)
          )
        ORDER BY last_online_at DESC, id ASC
        LIMIT ?
    ");
    $stmt->bindValue(1, BIND_CODE_REFRESH_AHEAD, PDO::PARAM_INT);
    $stmt->bindValue(2, BIND_CODE_BATCH_SIZE, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function assignAndPublishBindCode($db, array $device): void
{
    $bindCode = issueUniqueDynamicBindCode($db);
    $expiresAt = date('Y-m-d H:i:s', time() + BIND_CODE_EXPIRES_IN);

    $stmt = $db->prepare("
        UPDATE devices
        SET dyn_bound_code = ?,
            dyn_bound_code_issued_at = NOW(),
            dyn_bound_code_expires_at = ?
        WHERE id = ?
    ");
    $stmt->execute([$bindCode, $expiresAt, $device['id']]);

    $published = publishDynamicBindCodeToDevice($device['device_uid'], $bindCode, BIND_CODE_EXPIRES_IN);
    if (!$published) {
        $rollbackStmt = $db->prepare("
            UPDATE devices
            SET dyn_bound_code = NULL,
                dyn_bound_code_issued_at = NULL,
                dyn_bound_code_expires_at = NULL
            WHERE id = ?
        ");
        $rollbackStmt->execute([$device['id']]);
        throw new Exception('MQTT发布失败');
    }
}

try {
    $db = getDB();
    $devices = fetchDevicesDueForBindCode($db);

    if (empty($devices)) {
        logBindCodePublisher('No online unbound devices need a new dynamic bind code.');
        exit(0);
    }

    logBindCodePublisher('Processing ' . count($devices) . ' device(s).');

    $successCount = 0;
    foreach ($devices as $device) {
        try {
            assignAndPublishBindCode($db, $device);
            $successCount++;
            logBindCodePublisher('Published dynamic bind code to ' . strtoupper($device['device_uid']));
        } catch (Throwable $e) {
            logBindCodePublisher('Failed for ' . strtoupper($device['device_uid']) . ': ' . $e->getMessage());
        }

        usleep(BIND_CODE_PUBLISH_DELAY_US);
    }

    logBindCodePublisher('Done. Successful publishes: ' . $successCount . '/' . count($devices));
} catch (Throwable $e) {
    logBindCodePublisher('Publisher error: ' . $e->getMessage());
    exit(1);
}

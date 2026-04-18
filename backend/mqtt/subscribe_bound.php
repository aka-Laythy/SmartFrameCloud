<?php
/**
 * MQTT subscriber for device/<DEVICE_UID>/bound messages.
 *
 * Usage:
 *   php backend/mqtt/subscribe_bound.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run in CLI mode.\n");
    exit(1);
}

require_once __DIR__ . '/../config/database.php';

const MQTT_KEEPALIVE = 60;
const MQTT_RECONNECT_DELAY = 5;
const MQTT_TOPIC_FILTER = 'device/+/bound';

function logMessage(string $message): void
{
    $timestamp = date('Y-m-d H:i:s');
    fwrite(STDOUT, "[{$timestamp}] {$message}\n");
}

function encodeMqttString(string $value): string
{
    return pack('n', strlen($value)) . $value;
}

function encodeRemainingLengthCli(int $length): string
{
    $result = '';
    do {
        $encodedByte = $length % 128;
        $length = intdiv($length, 128);
        if ($length > 0) {
            $encodedByte |= 0x80;
        }
        $result .= chr($encodedByte);
    } while ($length > 0);

    return $result;
}

function readExactBytes($socket, int $length): string
{
    $buffer = '';

    while (strlen($buffer) < $length) {
        $chunk = fread($socket, $length - strlen($buffer));
        if ($chunk === false) {
            $meta = stream_get_meta_data($socket);
            if (!empty($meta['timed_out'])) {
                continue;
            }

            throw new RuntimeException('Failed to read from MQTT socket.');
        }

        if ($chunk === '') {
            $meta = stream_get_meta_data($socket);
            if (!empty($meta['timed_out'])) {
                continue;
            }

            throw new RuntimeException('MQTT socket closed while reading packet.');
        }

        $buffer .= $chunk;
    }

    return $buffer;
}

function decodeRemainingLengthCli($socket): int
{
    $multiplier = 1;
    $value = 0;

    do {
        $encodedByte = ord(readExactBytes($socket, 1));
        $value += ($encodedByte & 127) * $multiplier;
        $multiplier *= 128;

        if ($multiplier > 128 * 128 * 128 * 128) {
            throw new RuntimeException('Malformed MQTT remaining length.');
        }
    } while (($encodedByte & 128) !== 0);

    return $value;
}

function openMqttSocket()
{
    $socket = @fsockopen(MQTT_HOST, MQTT_PORT, $errno, $errstr, 5);
    if (!$socket) {
        throw new RuntimeException("Unable to connect to MQTT broker: {$errstr} ({$errno})");
    }

    stream_set_timeout($socket, 1);
    stream_set_blocking($socket, true);

    return $socket;
}

function mqttConnect($socket, string $clientId): void
{
    $hasUser = defined('MQTT_USER') && MQTT_USER !== '';
    $hasPass = defined('MQTT_PASS') && MQTT_PASS !== '';

    $connectFlags = 0x02;
    if ($hasUser) {
        $connectFlags |= 0x80;
    }
    if ($hasPass) {
        $connectFlags |= 0x40;
    }

    $variableHeader =
        pack('n', 4) .
        'MQTT' .
        chr(4) .
        chr($connectFlags) .
        pack('n', MQTT_KEEPALIVE);

    $payload = encodeMqttString($clientId);
    if ($hasUser) {
        $payload .= encodeMqttString(MQTT_USER);
    }
    if ($hasPass) {
        $payload .= encodeMqttString(MQTT_PASS);
    }

    $packet = chr(0x10) .
        encodeRemainingLengthCli(strlen($variableHeader) + strlen($payload)) .
        $variableHeader .
        $payload;

    fwrite($socket, $packet);

    $header = readExactBytes($socket, 1);
    if (ord($header) !== 0x20) {
        throw new RuntimeException('Unexpected MQTT packet while waiting for CONNACK.');
    }

    $remainingLength = decodeRemainingLengthCli($socket);
    $body = readExactBytes($socket, $remainingLength);
    if (strlen($body) !== 2 || ord($body[1]) !== 0x00) {
        $code = strlen($body) >= 2 ? ord($body[1]) : -1;
        throw new RuntimeException("MQTT CONNACK rejected with code {$code}.");
    }
}

function mqttSubscribe($socket, string $topicFilter, int $packetId = 1): void
{
    $payload = encodeMqttString($topicFilter) . chr(0x00);
    $variableHeader = pack('n', $packetId);
    $packet = chr(0x82) .
        encodeRemainingLengthCli(strlen($variableHeader) + strlen($payload)) .
        $variableHeader .
        $payload;

    fwrite($socket, $packet);

    $header = readExactBytes($socket, 1);
    if (ord($header) !== 0x90) {
        throw new RuntimeException('Unexpected MQTT packet while waiting for SUBACK.');
    }

    $remainingLength = decodeRemainingLengthCli($socket);
    $body = readExactBytes($socket, $remainingLength);

    if (strlen($body) < 3) {
        throw new RuntimeException('Malformed SUBACK packet.');
    }

    $ackPacketId = unpack('npacket_id', substr($body, 0, 2))['packet_id'];
    $returnCode = ord($body[2]);
    if ($ackPacketId !== $packetId || $returnCode === 0x80) {
        throw new RuntimeException('MQTT subscribe rejected by broker.');
    }
}

function mqttSendPing($socket): void
{
    fwrite($socket, chr(0xC0) . chr(0x00));
}

function mqttSendPubAck($socket, int $packetId): void
{
    fwrite($socket, chr(0x40) . chr(0x02) . pack('n', $packetId));
}

function upsertDeviceByBoundMessage(string $deviceUid): void
{
    $db = getDB();
    $stmt = $db->prepare("
        INSERT INTO devices (device_uid, user_id, status, last_online_at)
        VALUES (?, NULL, 1, NOW())
        ON DUPLICATE KEY UPDATE
            device_uid = VALUES(device_uid),
            status = 1,
            last_online_at = NOW()
    ");
    $stmt->execute([$deviceUid]);
}

function isValidNewDevicePayload(array $decoded, string $deviceUid): bool
{
    if (($decoded['event'] ?? '') !== 'reg_new_device') {
        return false;
    }

    if (!isset($decoded['device_uid']) || strtoupper((string) $decoded['device_uid']) !== $deviceUid) {
        return false;
    }

    if (!isset($decoded['timestamp']) || !is_numeric($decoded['timestamp'])) {
        return false;
    }

    return true;
}

function handleBoundMessage(string $topic, string $payload): void
{
    if (!preg_match('#^device/([A-Fa-f0-9]{16})/bound$#', $topic, $matches)) {
        logMessage("Ignored topic: {$topic}");
        return;
    }

    $deviceUid = strtoupper($matches[1]);
    $payloadPreview = trim($payload);
    if ($payloadPreview === '') {
        $payloadPreview = '(empty payload)';
    }

    $decoded = json_decode($payload, true);
    if (is_array($decoded) && ($decoded['event'] ?? '') === 'dyn_bound_code') {
        logMessage("Ignored cloud downlink on {$topic}");
        return;
    }

    if (!is_array($decoded)) {
        logMessage("Ignored invalid JSON payload on {$topic}: {$payloadPreview}");
        return;
    }

    if (!isValidNewDevicePayload($decoded, $deviceUid)) {
        logMessage("Ignored unsupported bound payload on {$topic}: {$payloadPreview}");
        return;
    }

    upsertDeviceByBoundMessage($deviceUid);
    logMessage("Stored device heartbeat/bound uplink for {$deviceUid}: {$payloadPreview}");
}

function handlePublishPacket($socket, int $firstByte, string $body): void
{
    $offset = 0;
    $topicLength = unpack('ntopic_length', substr($body, $offset, 2))['topic_length'];
    $offset += 2;

    $topic = substr($body, $offset, $topicLength);
    $offset += $topicLength;

    $qos = ($firstByte >> 1) & 0x03;
    $packetId = null;
    if ($qos > 0) {
        $packetId = unpack('npacket_id', substr($body, $offset, 2))['packet_id'];
        $offset += 2;
    }

    $payload = substr($body, $offset);
    handleBoundMessage($topic, $payload);

    if ($qos === 1 && $packetId !== null) {
        mqttSendPubAck($socket, $packetId);
    }
}

function runSubscriber(): void
{
    $clientId = 'smartframe_bound_sub_' . bin2hex(random_bytes(4));

    while (true) {
        $socket = null;

        try {
            logMessage('Connecting to MQTT broker...');
            $socket = openMqttSocket();
            mqttConnect($socket, $clientId);
            mqttSubscribe($socket, MQTT_TOPIC_FILTER);
            logMessage('Subscribed to ' . MQTT_TOPIC_FILTER);

            $lastActivityAt = time();
            $lastPingAt = 0;

            while (true) {
                $firstByte = fread($socket, 1);
                if ($firstByte === false) {
                    $meta = stream_get_meta_data($socket);

                    if (!empty($meta['timed_out'])) {
                        $now = time();
                        if ($now - $lastActivityAt >= (int) (MQTT_KEEPALIVE / 2) && $now - $lastPingAt >= (int) (MQTT_KEEPALIVE / 2)) {
                            mqttSendPing($socket);
                            $lastPingAt = $now;
                        }
                        continue;
                    }

                    throw new RuntimeException('Failed to read MQTT packet header.');
                }

                if ($firstByte === '') {
                    $meta = stream_get_meta_data($socket);

                    if (!empty($meta['timed_out'])) {
                        $now = time();
                        if ($now - $lastActivityAt >= (int) (MQTT_KEEPALIVE / 2) && $now - $lastPingAt >= (int) (MQTT_KEEPALIVE / 2)) {
                            mqttSendPing($socket);
                            $lastPingAt = $now;
                        }
                        continue;
                    }

                    if (!empty($meta['eof'])) {
                        throw new RuntimeException('MQTT broker closed the connection.');
                    }

                    continue;
                }

                $firstByteValue = ord($firstByte);
                $remainingLength = decodeRemainingLengthCli($socket);
                $body = readExactBytes($socket, $remainingLength);
                $packetType = $firstByteValue & 0xF0;
                $lastActivityAt = time();

                switch ($packetType) {
                    case 0x30:
                        handlePublishPacket($socket, $firstByteValue, $body);
                        break;

                    case 0xD0:
                        // PINGRESP
                        break;

                    default:
                        logMessage('Ignored MQTT packet type: 0x' . strtoupper(str_pad(dechex($packetType), 2, '0', STR_PAD_LEFT)));
                        break;
                }
            }
        } catch (Throwable $e) {
            logMessage('Subscriber error: ' . $e->getMessage());
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }

        logMessage('Reconnecting in ' . MQTT_RECONNECT_DELAY . 's...');
        sleep(MQTT_RECONNECT_DELAY);
    }
}

runSubscriber();

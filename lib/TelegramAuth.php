<?php
/**
 * lib/TelegramAuth.php
 * ENG_251885 APP — Kids Store TMA
 *
 * Validates the `initData` string a Telegram Mini App sends with every
 * request, per the official verification algorithm:
 * https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app
 */

declare(strict_types=1);

final class TelegramAuth
{
    /**
     * Verifies the signature of a raw initData query string and returns the
     * decoded user payload on success, or null if the data is missing,
     * malformed, expired, or fails HMAC verification.
     *
     * @return array{telegram_id:int, username:?string, first_name:string, last_name:?string,
     *               language_code:?string, is_premium:bool, photo_url:?string}|null
     */
    public static function verify(string $initData, string $botToken, int $maxAgeSeconds): ?array
    {
        if ($initData === '' || $botToken === '') {
            return null;
        }

        parse_str($initData, $pairs);
        if (!isset($pairs['hash']) || !isset($pairs['auth_date'])) {
            return null;
        }

        $receivedHash = $pairs['hash'];
        unset($pairs['hash']);

        // Reject stale auth payloads to mitigate replay attacks.
        $authDate = (int) $pairs['auth_date'];
        if ($authDate <= 0 || (time() - $authDate) > $maxAgeSeconds) {
            return null;
        }

        // Build the data-check-string: alphabetically sorted key=value pairs
        // joined with \n, per Telegram's spec.
        ksort($pairs);
        $dataCheckArr = [];
        foreach ($pairs as $key => $value) {
            $dataCheckArr[] = $key . '=' . $value;
        }
        $dataCheckString = implode("\n", $dataCheckArr);

        // secret_key = HMAC_SHA256(bot_token, "WebAppData")
        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $computedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (!hash_equals($computedHash, $receivedHash)) {
            return null;
        }

        $userJson = $pairs['user'] ?? null;
        if ($userJson === null) {
            return null;
        }

        $user = json_decode($userJson, true);
        if (!is_array($user) || !isset($user['id'])) {
            return null;
        }

        return [
            'telegram_id'   => (int) $user['id'],
            'username'      => $user['username'] ?? null,
            'first_name'    => $user['first_name'] ?? '',
            'last_name'     => $user['last_name'] ?? null,
            'language_code' => $user['language_code'] ?? 'en',
            'is_premium'    => (bool) ($user['is_premium'] ?? false),
            'photo_url'     => $user['photo_url'] ?? null,
        ];
    }

    /**
     * Reads initData from the `X-Telegram-Init-Data` request header, verifies
     * it, and upserts the user into the database. Returns the verified
     * telegram_id, or null (caller should respond 401) if verification fails.
     */
    public static function authenticateRequest(PDO $pdo): ?int
    {
        $initData = $_SERVER['HTTP_X_TELEGRAM_INIT_DATA'] ?? '';
        $verified = self::verify($initData, TELEGRAM_BOT_TOKEN, TELEGRAM_AUTH_MAX_AGE);

        if ($verified === null) {
            return null;
        }

        $stmt = $pdo->prepare('
            INSERT INTO users (telegram_id, username, first_name, last_name, language_code, is_premium, photo_url)
            VALUES (:telegram_id, :username, :first_name, :last_name, :language_code, :is_premium, :photo_url)
            ON DUPLICATE KEY UPDATE
                username      = VALUES(username),
                first_name    = VALUES(first_name),
                last_name     = VALUES(last_name),
                language_code = VALUES(language_code),
                is_premium    = VALUES(is_premium),
                photo_url     = VALUES(photo_url)
        ');
        $stmt->execute([
            'telegram_id'   => $verified['telegram_id'],
            'username'      => $verified['username'],
            'first_name'    => $verified['first_name'],
            'last_name'     => $verified['last_name'],
            'language_code' => $verified['language_code'],
            'is_premium'    => $verified['is_premium'] ? 1 : 0,
            'photo_url'     => $verified['photo_url'],
        ]);

        return $verified['telegram_id'];
    }
}

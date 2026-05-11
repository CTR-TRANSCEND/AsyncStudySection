<?php
/**
 * Multi-Factor Authentication Management Class
 * SPEC-AUTH-001.1: MFA System Architecture
 *
 * Features:
 * - TOTP-based MFA using RFC 6238
 * - QR code generation for enrollment
 * - Backup codes for recovery
 * - MFA verification
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * Manages TOTP-based multi-factor authentication enrollment, verification, and backup codes.
 */
class MFA {
    private $db;
    private $encryptionKey;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        // In production, this should be from environment variable
        $this->encryptionKey = hash('sha256', defined('MFA_ENCRYPTION_KEY') ? MFA_ENCRYPTION_KEY : 'default-key-change-in-production');
    }

    /**
     * Generate TOTP secret for a user
     * @param int $userId User ID
     * @return string|false Secret or false on failure
     */
    public function generateSecret(int $userId) {
        try {
            // Generate 20-byte base32 secret
            $secret = $this->generateBase32Secret();

            // Encrypt secret for storage
            $encryptedSecret = $this->encrypt($secret);

            // Store in database
            $stmt = $this->db->prepare("
                UPDATE users
                SET mfa_secret = ?, mfa_enabled = FALSE
                WHERE id = ?
            ");
            $stmt->execute([$encryptedSecret, $userId]);

            return $secret;
        } catch (PDOException $e) {
            error_log("MFA secret generation failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Enable MFA for a user after verification
     * @param int $userId User ID
     * @param string $totpCode TOTP code to verify
     * @return array|false Result with backup codes or false on failure
     */
    public function enableMFA(int $userId, string $totpCode) {
        try {
            // Get encrypted secret
            $stmt = $this->db->prepare("SELECT mfa_secret FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            if (!$user || !$user['mfa_secret']) {
                return false;
            }

            // Decrypt secret
            $secret = $this->decrypt($user['mfa_secret']);

            // Verify TOTP code
            if (!$this->verifyTOTP($secret, $totpCode)) {
                return false;
            }

            // Generate backup codes
            $backupCodes = $this->generateBackupCodes();
            $hashedBackupCodes = array_map(function($code) {
                return password_hash($code, PASSWORD_DEFAULT);
            }, $backupCodes);

            // Enable MFA and store backup codes
            $stmt = $this->db->prepare("
                UPDATE users
                SET mfa_enabled = TRUE, mfa_backup_codes = ?
                WHERE id = ?
            ");
            $stmt->execute([json_encode($hashedBackupCodes), $userId]);

            return [
                'success' => true,
                'backup_codes' => $backupCodes
            ];
        } catch (PDOException $e) {
            error_log("MFA enablement failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Disable MFA for a user
     * @param int $userId User ID
     * @return bool Success status
     */
    public function disableMFA(int $userId): bool {
        try {
            $stmt = $this->db->prepare("
                UPDATE users
                SET mfa_enabled = FALSE, mfa_secret = NULL, mfa_backup_codes = NULL
                WHERE id = ?
            ");
            $stmt->execute([$userId]);

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("MFA disablement failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Verify TOTP code for a user
     * @param int $userId User ID
     * @param string $totpCode TOTP code to verify
     * @return bool Valid or not
     */
    public function verifyTOTPForUser(int $userId, string $totpCode): bool {
        try {
            $stmt = $this->db->prepare("SELECT mfa_secret, mfa_backup_codes FROM users WHERE id = ? AND mfa_enabled = TRUE");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            if (!$user || !$user['mfa_secret']) {
                return false;
            }

            // Check if it's a backup code
            $backupCodes = json_decode($user['mfa_backup_codes'] ?? '[]', true);
            if (!is_array($backupCodes)) {
                $backupCodes = [];
            }
            $matchFound = false;
            foreach ($backupCodes as $index => $hashedCode) {
                if (password_verify($totpCode, $hashedCode)) {
                    $matchFound = true;
                    break;
                }
            }
            if ($matchFound) {
                // Atomically re-fetch and update backup codes using SELECT FOR UPDATE
                $this->db->beginTransaction();
                try {
                    $stmt = $this->db->prepare("SELECT mfa_backup_codes FROM users WHERE id = ? FOR UPDATE");
                    $stmt->execute([$userId]);
                    $freshRow = $stmt->fetch();
                    $freshCodes = json_decode($freshRow['mfa_backup_codes'] ?? '[]', true);
                    if (!is_array($freshCodes)) {
                        $freshCodes = [];
                    }
                    // Re-verify against fresh data to prevent race conditions
                    foreach ($freshCodes as $idx => $hash) {
                        if (password_verify($totpCode, $hash)) {
                            unset($freshCodes[$idx]);
                            $stmt2 = $this->db->prepare("UPDATE users SET mfa_backup_codes = ? WHERE id = ?");
                            $stmt2->execute([json_encode(array_values($freshCodes)), $userId]);
                            $this->db->commit();
                            return true;
                        }
                    }
                    // Code was already used by another concurrent request
                    $this->db->rollBack();
                } catch (\Exception $e) {
                    if ($this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    throw $e;
                }
            }

            // Verify TOTP
            $secret = $this->decrypt($user['mfa_secret']);
            return $this->verifyTOTP($secret, $totpCode);
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Verify TOTP code against secret
     * @param string $secret TOTP secret
     * @param string $code Code to verify
     * @return bool Valid or not
     */
    private function verifyTOTP(string $secret, string $code): bool {
        // Verify current, previous, and next time steps (30 second window)
        for ($i = -1; $i <= 1; $i++) {
            if ($this->generateTOTP($secret, $i) === $code) {
                return true;
            }
        }
        return false;
    }

    /**
     * Generate TOTP code for given time offset
     * @param string $secret TOTP secret
     * @param int $timeOffset Time offset from current (0 = current, -1 = previous, 1 = next)
     * @return string TOTP code
     */
    private function generateTOTP(string $secret, int $timeOffset = 0): string {
        $timeStep = 30; // 30 seconds
        $currentTime = time() + ($timeOffset * $timeStep);
        $timeCounter = floor($currentTime / $timeStep);

        // Convert to 8-byte big-endian integer
        $timeBytes = pack('J', $timeCounter);

        // Decode base32 secret
        $secretBytes = $this->base32Decode($secret);

        // Calculate HMAC-SHA1
        $hmac = hash_hmac('sha1', $timeBytes, $secretBytes, true);

        // Dynamic truncation
        $offset = ord($hmac[strlen($hmac) - 1]) & 0x0F;
        $binary = (
            ((ord($hmac[$offset]) & 0x7F) << 24) |
            ((ord($hmac[$offset + 1]) & 0xFF) << 16) |
            ((ord($hmac[$offset + 2]) & 0xFF) << 8) |
            (ord($hmac[$offset + 3]) & 0xFF)
        );

        $otp = $binary % pow(10, 6);

        return str_pad((string) $otp, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Generate QR code URI for authenticator apps
     * @param string $secret TOTP secret
     * @param string $username Username
     * @param string $issuer Issuer name
     * @return string QR code URI
     */
    public function getQRCodeUri(string $secret, string $username, string $issuer = 'GrantReview'): string {
        $encodedIssuer = urlencode($issuer);
        $encodedUsername = urlencode($username);
        $encodedSecret = urlencode($secret);

        return "otpauth://totp/{$encodedIssuer}:{$encodedUsername}?secret={$encodedSecret}&issuer={$encodedIssuer}";
    }

    /**
     * Generate backup codes
     * @return array List of backup codes
     */
    private function generateBackupCodes(): array {
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            // Generate 6-character alphanumeric code
            $code = substr(strtoupper(bin2hex(random_bytes(4))), 0, 6);
            $codes[] = $code;
        }
        return $codes;
    }

    /**
     * Generate base32 secret
     * @return string Base32 encoded secret
     */
    private function generateBase32Secret(): string {
        $bytes = random_bytes(20); // 160 bits
        return $this->base32Encode($bytes);
    }

    /**
     * Encode binary data to base32
     * @param string $data Binary data
     * @return string Base32 encoded
     */
    private function base32Encode(string $data): string {
        $base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $output = '';
        $bitLength = strlen($data) * 8;

        for ($i = 0; $i + 5 <= $bitLength; $i += 5) {
            $byte = $i >> 3;
            $bit = $i % 8;

            // Extract 5 bits
            $index = 0;
            if ($bit <= 3) {
                $index = (ord($data[$byte]) >> (3 - $bit)) & 0x1F;
                if ($bit > 0 || $i + 8 < $bitLength) {
                    $nextByte = $byte + 1;
                    if ($nextByte < strlen($data)) {
                        $index = ($index << (5 - (3 - $bit))) | (ord($data[$nextByte]) >> (8 - (5 - (3 - $bit))));
                    }
                }
            }

            $output .= $base32Chars[$index];
        }

        return $output;
    }

    /**
     * Decode base32 to binary
     * @param string $data Base32 encoded data
     * @return string Binary data
     */
    private function base32Decode(string $data): string {
        $base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $data = strtoupper(str_replace(' ', '', $data));
        $output = '';
        $bitBuffer = 0;
        $bitCount = 0;

        for ($i = 0; $i < strlen($data); $i++) {
            $char = $data[$i];
            $index = strpos($base32Chars, $char);

            if ($index === false) {
                continue; // Skip padding and invalid chars
            }

            $bitBuffer = ($bitBuffer << 5) | $index;
            $bitCount += 5;

            if ($bitCount >= 8) {
                $output .= chr(($bitBuffer >> ($bitCount - 8)) & 0xFF);
                $bitCount -= 8;
            }
        }

        return $output;
    }

    /**
     * Encrypt data using AES-256-GCM
     * @param string $data Data to encrypt
     * @return string Encrypted data (base64 encoded)
     */
    private function encrypt(string $data): string {
        $iv = random_bytes(12);
        $tag = '';
        $encrypted = openssl_encrypt($data, 'aes-256-gcm', $this->encryptionKey, OPENSSL_RAW_DATA, $iv, $tag);

        return base64_encode($iv . $tag . $encrypted);
    }

    /**
     * Decrypt data using AES-256-GCM
     * @param string $encryptedData Encrypted data (base64 encoded)
     * @return string Decrypted data
     */
    private function decrypt(string $encryptedData): string {
        $data = base64_decode($encryptedData);
        $iv = substr($data, 0, 12);
        $tag = substr($data, 12, 16);
        $encrypted = substr($data, 28);

        $result = openssl_decrypt($encrypted, 'aes-256-gcm', $this->encryptionKey, OPENSSL_RAW_DATA, $iv, $tag);
        if ($result === false) {
            throw new \RuntimeException('Failed to decrypt MFA data');
        }
        return $result;
    }

    /**
     * Check if user has MFA enabled
     * @param int $userId User ID
     * @return bool Enabled or not
     */
    public function isMFAEnabled(int $userId): bool {
        try {
            $stmt = $this->db->prepare("SELECT mfa_enabled FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            return $user && ($user['mfa_enabled'] ?? false);
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Get MFA status for user
     * @param int $userId User ID
     * @return array MFA status info
     */
    public function getMFAStatus(int $userId): array {
        try {
            $stmt = $this->db->prepare("
                SELECT mfa_enabled, mfa_backup_codes
                FROM users
                WHERE id = ?
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            if (!$user) {
                return ['enabled' => false];
            }

            $backupCodes = json_decode($user['mfa_backup_codes'] ?? '[]', true);
            if (!is_array($backupCodes)) {
                $backupCodes = [];
            }

            return [
                'enabled' => (bool) ($user['mfa_enabled'] ?? false),
                'backup_codes_remaining' => count($backupCodes)
            ];
        } catch (PDOException $e) {
            return ['enabled' => false];
        }
    }
}

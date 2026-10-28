<?php

namespace MintyMetrics\Tests\Integration;

use MintyMetrics\Tests\IntegrationTestCase;

class AuthTest extends IntegrationTestCase
{
    private function globalFailures(): int
    {
        return (int) self::$testDb->querySingle("SELECT failures FROM login_attempts WHERE client_key = 'global'");
    }

    // ─── Password Management ────────────────────────────────────────────

    public function testAuthSetPasswordCreatesRecord(): void
    {
        \MintyMetrics\auth_set_password('TestPassword123');

        $row = self::$testDb->querySingle('SELECT * FROM auth LIMIT 1', true);
        $this->assertNotNull($row);
        $this->assertTrue(\password_verify('TestPassword123', $row['password_hash']));
    }

    public function testAuthSetPasswordUsesCorrectBcrypt(): void
    {
        \MintyMetrics\auth_set_password('SecurePass');

        $row = self::$testDb->querySingle('SELECT password_hash FROM auth LIMIT 1', true);
        // Bcrypt hashes start with $2y$
        $this->assertStringStartsWith('$2y$', $row['password_hash']);
    }

    public function testAuthHasPasswordReturnsFalseInitially(): void
    {
        $this->assertFalse(\MintyMetrics\auth_has_password());
    }

    public function testAuthHasPasswordReturnsTrueAfterSet(): void
    {
        \MintyMetrics\auth_set_password('Password123');
        $this->assertTrue(\MintyMetrics\auth_has_password());
    }

    public function testAuthSetPasswordUpdatesExisting(): void
    {
        \MintyMetrics\auth_set_password('FirstPass');
        \MintyMetrics\auth_set_password('SecondPass');

        // Should still have only one auth record
        $count = self::$testDb->querySingle('SELECT COUNT(*) FROM auth');
        $this->assertSame(1, $count);

        // New password should work
        $row = self::$testDb->querySingle('SELECT password_hash FROM auth LIMIT 1', true);
        $this->assertTrue(\password_verify('SecondPass', $row['password_hash']));
        $this->assertFalse(\password_verify('FirstPass', $row['password_hash']));
    }

    // ─── Login ──────────────────────────────────────────────────────────

    public function testAuthLoginSuccessful(): void
    {
        \MintyMetrics\auth_set_password('CorrectHorse');

        $result = \MintyMetrics\auth_login('CorrectHorse');
        $this->assertTrue($result);
        $this->assertTrue($_SESSION['_mm_authenticated']);
    }

    public function testAuthLoginWrongPassword(): void
    {
        \MintyMetrics\auth_set_password('CorrectHorse');

        $result = \MintyMetrics\auth_login('WrongPassword');
        $this->assertFalse($result);
        $this->assertArrayNotHasKey('_mm_authenticated', $_SESSION);
    }

    public function testAuthLoginNoPasswordSet(): void
    {
        $result = \MintyMetrics\auth_login('anything');
        $this->assertFalse($result);
    }

    public function testAuthLoginTracksFailedAttempts(): void
    {
        \MintyMetrics\auth_set_password('RealPassword');

        // Fail once
        \MintyMetrics\auth_login('wrong1');
        $this->assertSame(1, $this->globalFailures());

        // Fail again
        \MintyMetrics\auth_login('wrong2');
        $this->assertSame(2, $this->globalFailures());
    }

    public function testAuthLoginResetsFailedAttemptsOnSuccess(): void
    {
        \MintyMetrics\auth_set_password('MyPass');

        \MintyMetrics\auth_login('wrong');
        \MintyMetrics\auth_login('wrong');

        // Verify attempts accumulated
        $this->assertSame(2, $this->globalFailures());

        // Successful login clears this client's failures; the global counter only
        // drops the reservation for the successful attempt itself
        $this->assertTrue(\MintyMetrics\auth_login('MyPass'));
        $this->assertSame(0, $this->countRows('login_attempts', "client_key != 'global'"));
        $this->assertSame(2, $this->globalFailures());
    }

    public function testAuthLoginLockoutAfterMaxAttempts(): void
    {
        \MintyMetrics\auth_set_password('MyPass');

        // Exhaust all attempts (LOGIN_MAX_ATTEMPTS = 5)
        for ($i = 0; $i < \MintyMetrics\LOGIN_MAX_ATTEMPTS; $i++) {
            \MintyMetrics\auth_login('wrong');
        }

        // Verify this client is locked
        $row = self::$testDb->querySingle('SELECT locked_until FROM login_attempts LIMIT 1', true);
        $this->assertGreaterThan(time(), (int) $row['locked_until']);

        // Even correct password should fail during lockout
        $result = \MintyMetrics\auth_login('MyPass');
        $this->assertFalse($result);
    }

    // ─── Auth Verify Password (without login side effects) ────────────

    public function testAuthVerifyPasswordCorrect(): void
    {
        \MintyMetrics\auth_set_password('VerifyMe');
        $this->assertTrue(\MintyMetrics\auth_verify_password('VerifyMe'));
    }

    public function testAuthVerifyPasswordWrong(): void
    {
        \MintyMetrics\auth_set_password('VerifyMe');
        $this->assertFalse(\MintyMetrics\auth_verify_password('WrongPass'));
    }

    public function testAuthVerifyPasswordNoPasswordSet(): void
    {
        $this->assertFalse(\MintyMetrics\auth_verify_password('anything'));
    }

    public function testAuthVerifyPasswordDoesNotAffectLoginState(): void
    {
        \MintyMetrics\auth_set_password('NoSideEffects');

        // Fail some login attempts first
        \MintyMetrics\auth_login('wrong');
        $this->assertSame(1, $this->globalFailures());

        // auth_verify_password should not reset failed_attempts
        \MintyMetrics\auth_verify_password('NoSideEffects');
        $this->assertSame(1, $this->globalFailures(), 'auth_verify_password should not reset failed_attempts');
    }

    // ─── Auth Check ─────────────────────────────────────────────────────

    public function testAuthCheckReturnsFalseWhenNotAuthenticated(): void
    {
        $this->assertFalse(\MintyMetrics\auth_check());
    }

    public function testAuthCheckReturnsTrueWhenAuthenticated(): void
    {
        \MintyMetrics\auth_set_password('Password123');
        $this->assertTrue(\MintyMetrics\auth_login('Password123'));
        $this->assertTrue(\MintyMetrics\auth_check());
    }

    public function testSessionWithoutCredentialVersionIsRejected(): void
    {
        \MintyMetrics\auth_set_password('Password123');
        $_SESSION['_mm_authenticated'] = true;
        $this->assertFalse(\MintyMetrics\auth_check());
    }

    public function testPasswordChangeSignsOutExistingSessions(): void
    {
        \MintyMetrics\auth_set_password('Password123');
        $this->assertTrue(\MintyMetrics\auth_login('Password123'));
        $stolen = $_SESSION;

        \MintyMetrics\auth_set_password('NewPassword456');

        $_SESSION = $stolen;
        $this->assertFalse(\MintyMetrics\auth_check(), 'A session from before the change must stop working');
    }

    public function testLockedOutClientDoesNotLockOutOthers(): void
    {
        \MintyMetrics\auth_set_password('MyPass');
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        for ($i = 0; $i < \MintyMetrics\LOGIN_MAX_ATTEMPTS + 3; $i++) {
            \MintyMetrics\auth_login('wrong');
        }
        $this->assertFalse(\MintyMetrics\auth_login('MyPass'), 'The attacking client stays locked');

        $_SERVER['REMOTE_ADDR'] = '198.51.100.20';
        $this->assertTrue(\MintyMetrics\auth_login('MyPass'), 'The admin from another address can still sign in');
    }

    public function testGlobalLimitStopsDistributedGuessing(): void
    {
        \MintyMetrics\auth_set_password('MyPass');
        for ($i = 0; $i < \MintyMetrics\LOGIN_GLOBAL_MAX_ATTEMPTS; $i++) {
            $_SERVER['REMOTE_ADDR'] = '203.0.113.' . ($i % 250);
            \MintyMetrics\auth_login('wrong');
        }
        $_SERVER['REMOTE_ADDR'] = '198.51.100.20';
        $this->assertFalse(\MintyMetrics\auth_login('MyPass'));
    }

    public function testLoginThrottleKeysDoNotStoreRawIps(): void
    {
        \MintyMetrics\auth_set_password('MyPass');
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        \MintyMetrics\auth_login('wrong');
        $key = self::$testDb->querySingle('SELECT client_key FROM login_attempts');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $key);
        $this->assertNotSame(hash('sha256', '203.0.113.7'), $key, 'Keys are HMACs with a per-install secret');
    }

    public function testLoginNeverSignsInAPreExistingSessionId(): void
    {
        $dir = sys_get_temp_dir() . '/mm_sess_' . uniqid();
        mkdir($dir);
        $dbPath = $dir . '/db.sqlite';
        try {
            $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../fixtures/login_fixation.php')
                . ' ' . escapeshellarg($dbPath) . ' ' . escapeshellarg($dir) . ' 2>&1');
            $r = json_decode((string) $out, true);
            $this->assertIsArray($r, (string) $out);
            $this->assertTrue($r['ok']);
            $this->assertTrue($r['rotated']);
            $this->assertFalse($r['old_signed_in'], 'The pre-login session ID must not carry the signed-in state');
            $this->assertFalse($r['old_exists'], 'The pre-login session is destroyed');
            $this->assertTrue($r['new_signed_in']);
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            @rmdir($dir);
        }
    }

    public function testSessionPinnedToVerifiedHashGoesStaleOnConcurrentChange(): void
    {
        \MintyMetrics\auth_set_password('OldPassword1');
        $oldHash = self::$testDb->querySingle('SELECT password_hash FROM auth');
        // A password change lands between verifying the old password and creating the session
        \MintyMetrics\auth_set_password('NewPassword2');
        \MintyMetrics\auth_establish_session($oldHash);
        $this->assertFalse(\MintyMetrics\auth_check());
    }

    public function testSetPasswordReturnsTheExactStoredHash(): void
    {
        $hash = \MintyMetrics\auth_set_password('Fresh1234');
        $this->assertSame(self::$testDb->querySingle('SELECT password_hash FROM auth'), $hash);

        // A concurrent reset after this write must invalidate a session pinned to $hash
        \MintyMetrics\auth_set_password('ResetByCli1');
        \MintyMetrics\auth_establish_session($hash);
        $this->assertFalse(\MintyMetrics\auth_check());
    }

    public function testGlobalCounterIsAFixedWindow(): void
    {
        \MintyMetrics\auth_set_password('MyPass');
        // 49 old failures whose window started 16 minutes ago
        self::$testDb->exec("INSERT INTO login_attempts (client_key, failures, window_start, locked_until)
            VALUES ('global', 49, " . (time() - 16 * 60) . ", 0)");
        \MintyMetrics\auth_login('wrong');
        $row = self::$testDb->querySingle("SELECT failures, locked_until FROM login_attempts WHERE client_key = 'global'", true);
        $this->assertSame(1, (int) $row['failures'], 'A new window starts after 15 minutes');
        $this->assertSame(0, (int) $row['locked_until']);
    }

    public function testCorrectPasswordOnLimitBoundaryDoesNotLockEveryone(): void
    {
        \MintyMetrics\auth_set_password('MyPass');
        self::$testDb->exec("INSERT INTO login_attempts (client_key, failures, window_start, locked_until)
            VALUES ('global', " . (\MintyMetrics\LOGIN_GLOBAL_MAX_ATTEMPTS - 1) . ", " . time() . ", 0)");

        $this->assertTrue(\MintyMetrics\auth_login('MyPass'), 'The 50th attempt with the right password succeeds');

        $row = self::$testDb->querySingle("SELECT failures, locked_until FROM login_attempts WHERE client_key = 'global'", true);
        $this->assertSame(\MintyMetrics\LOGIN_GLOBAL_MAX_ATTEMPTS - 1, (int) $row['failures']);
        $this->assertSame(0, (int) $row['locked_until'], 'Its own reservation must not leave a global lock behind');
        $_SERVER['REMOTE_ADDR'] = '198.51.100.99';
        $this->assertTrue(\MintyMetrics\auth_login('MyPass'));
    }

    public function testCliResetClearsLoginLimits(): void
    {
        \MintyMetrics\auth_set_password('MyPass');
        for ($i = 0; $i < \MintyMetrics\LOGIN_MAX_ATTEMPTS; $i++) {
            \MintyMetrics\auth_login('wrong');
        }
        \MintyMetrics\auth_clear_login_limits();
        $this->assertTrue(\MintyMetrics\auth_login('MyPass'));
    }

    // ─── CSRF ───────────────────────────────────────────────────────────

    public function testCsrfTokenGeneration(): void
    {
        $token = \MintyMetrics\csrf_token();
        $this->assertNotEmpty($token);
        $this->assertSame(64, strlen($token)); // 32 bytes = 64 hex chars
    }

    public function testCsrfTokenConsistentWithinSession(): void
    {
        $token1 = \MintyMetrics\csrf_token();
        $token2 = \MintyMetrics\csrf_token();
        $this->assertSame($token1, $token2);
    }

    public function testCsrfFieldContainsToken(): void
    {
        $field = \MintyMetrics\csrf_field();
        $token = \MintyMetrics\csrf_token();

        $this->assertStringContainsString('type="hidden"', $field);
        $this->assertStringContainsString('name="_csrf"', $field);
        $this->assertStringContainsString($token, $field);
    }

    // ─── CSP Nonce ──────────────────────────────────────────────────────

    public function testCspNonceGeneration(): void
    {
        $nonce = \MintyMetrics\csp_nonce();
        $this->assertNotEmpty($nonce);
        $this->assertSame(32, strlen($nonce)); // 16 bytes = 32 hex chars
    }

    public function testCspNonceConsistentPerRequest(): void
    {
        $nonce1 = \MintyMetrics\csp_nonce();
        $nonce2 = \MintyMetrics\csp_nonce();
        $this->assertSame($nonce1, $nonce2);
    }
}

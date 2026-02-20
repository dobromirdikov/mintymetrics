<?php

namespace MintyMetrics\Tests\Integration;

use MintyMetrics\Tests\IntegrationTestCase;

class AuthTest extends IntegrationTestCase
{
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
        $row = self::$testDb->querySingle('SELECT failed_attempts FROM auth LIMIT 1', true);
        $this->assertSame(1, (int) $row['failed_attempts']);

        // Fail again
        \MintyMetrics\auth_login('wrong2');
        $row = self::$testDb->querySingle('SELECT failed_attempts FROM auth LIMIT 1', true);
        $this->assertSame(2, (int) $row['failed_attempts']);
    }

    public function testAuthLoginResetsFailedAttemptsOnSuccess(): void
    {
        \MintyMetrics\auth_set_password('MyPass');

        \MintyMetrics\auth_login('wrong');
        \MintyMetrics\auth_login('wrong');

        // Verify attempts accumulated
        $row = self::$testDb->querySingle('SELECT failed_attempts FROM auth LIMIT 1', true);
        $this->assertSame(2, (int) $row['failed_attempts']);

        // Successful login
        \MintyMetrics\auth_login('MyPass');
        $row = self::$testDb->querySingle('SELECT failed_attempts FROM auth LIMIT 1', true);
        $this->assertSame(0, (int) $row['failed_attempts']);
    }

    public function testAuthLoginLockoutAfterMaxAttempts(): void
    {
        \MintyMetrics\auth_set_password('MyPass');

        // Exhaust all attempts (LOGIN_MAX_ATTEMPTS = 5)
        for ($i = 0; $i < \MintyMetrics\LOGIN_MAX_ATTEMPTS; $i++) {
            \MintyMetrics\auth_login('wrong');
        }

        // Verify lockout is set
        $row = self::$testDb->querySingle('SELECT lockout_until FROM auth LIMIT 1', true);
        $this->assertNotNull($row['lockout_until']);
        $this->assertGreaterThan(time(), (int) $row['lockout_until']);

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
        $row = self::$testDb->querySingle('SELECT failed_attempts FROM auth LIMIT 1', true);
        $this->assertSame(1, (int) $row['failed_attempts']);

        // auth_verify_password should not reset failed_attempts
        \MintyMetrics\auth_verify_password('NoSideEffects');
        $row = self::$testDb->querySingle('SELECT failed_attempts FROM auth LIMIT 1', true);
        $this->assertSame(1, (int) $row['failed_attempts'], 'auth_verify_password should not reset failed_attempts');
    }

    // ─── Auth Check ─────────────────────────────────────────────────────

    public function testAuthCheckReturnsFalseWhenNotAuthenticated(): void
    {
        $this->assertFalse(\MintyMetrics\auth_check());
    }

    public function testAuthCheckReturnsTrueWhenAuthenticated(): void
    {
        $_SESSION['_mm_authenticated'] = true;
        $this->assertTrue(\MintyMetrics\auth_check());
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

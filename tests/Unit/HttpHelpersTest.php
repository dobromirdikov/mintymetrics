<?php

namespace MintyMetrics\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class HttpHelpersTest extends TestCase
{
    private array $serverBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        foreach (['HTTPS', 'REQUEST_SCHEME', 'SERVER_PORT', 'REMOTE_ADDR', 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_SSL'] as $k) {
            unset($_SERVER[$k]);
        }
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    public static function httpsProvider(): array
    {
        return [
            'plain http' => [['SERVER_PORT' => '80', 'REMOTE_ADDR' => '203.0.113.5'], false],
            'HTTPS on' => [['HTTPS' => 'on'], true],
            'HTTPS off' => [['HTTPS' => 'off', 'SERVER_PORT' => '80'], false],
            'port 443' => [['SERVER_PORT' => '443'], true],
            'proxy on loopback' => [['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https'], true],
            'proxy on private network' => [['REMOTE_ADDR' => '10.0.0.4', 'HTTP_X_FORWARDED_PROTO' => 'https, http'], true],
            'spoofed header from the internet' => [['REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_FORWARDED_PROTO' => 'https'], false],
        ];
    }

    public function testJsStringEscapesBreakouts(): void
    {
        $out = \MintyMetrics\js_string("x'</script><script>alert(1)//\\\n");
        $this->assertStringNotContainsString("'", $out);
        $this->assertStringNotContainsString('<', $out);
        $this->assertStringNotContainsString("\n", $out);
        $this->assertSame("x'</script><script>alert(1)//\\\n", json_decode('"' . $out . '"'));
    }

    #[DataProvider('httpsProvider')]
    public function testRequestIsHttps(array $server, bool $expected): void
    {
        $_SERVER = array_merge($_SERVER, $server);
        $this->assertSame($expected, \MintyMetrics\request_is_https());
    }
}

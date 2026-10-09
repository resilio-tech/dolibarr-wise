<?php
/* Copyright (C) 2026 Resilio SA
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file       test/phpunit/WiseClientTest.php
 * \ingroup    wise
 * \brief      PHPUnit tests for the Wise API calls and the SCA signature.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../class/WiseClient.class.php';

/**
 * Class WiseClientTest
 */
class WiseClientTest extends TestCase
{
	/** @var array<int, array{url:string, headers:string[]}> */
	private $requests = array();

	/**
	 * Client whose transport plays the given responses in order.
	 *
	 * @param array<int, array{code:int, headers:array<string,string>, body:string, error:string}> $responses Responses
	 * @param string                                                                         $key       Private key
	 * @return WiseClient
	 */
	private function client(array $responses, string $key = ''): WiseClient
	{
		$this->requests = array();
		$requests = &$this->requests;

		return new WiseClient(WiseClient::URL_SANDBOX, 'token-123', $key, function ($url, $headers) use (&$responses, &$requests) {
			$requests[] = array('url' => $url, 'headers' => $headers);
			return array_shift($responses);
		});
	}

	/**
	 * A response.
	 *
	 * @param int                  $code    HTTP status
	 * @param string               $body    Body
	 * @param array<string,string> $headers Lower case headers
	 * @return array{code:int, headers:array<string,string>, body:string, error:string}
	 */
	private static function response(int $code, string $body, array $headers = array()): array
	{
		return array('code' => $code, 'headers' => $headers, 'body' => $body, 'error' => '');
	}

	/**
	 * The 403 Wise answers on an SCA protected endpoint.
	 *
	 * @return array{code:int, headers:array<string,string>, body:string, error:string}
	 */
	private static function challenge(): array
	{
		return self::response(403, '{"status":403,"error":"Forbidden","message":"You are forbidden to send this request"}', array(
			'x-2fa-approval-result' => 'REJECTED',
			'x-2fa-approval' => 'be2f6579-9426-480b-9cb7-d8f1116cc8b9',
		));
	}

	/**
	 * A fresh RSA key pair.
	 *
	 * @return string[] Private and public key, PEM
	 */
	private static function keyPair(): array
	{
		$key = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
		$private = '';
		openssl_pkey_export($key, $private);
		$details = openssl_pkey_get_details($key);

		return array($private, $details['key']);
	}

	/**
	 * Value of a request header.
	 *
	 * @param string[] $headers Headers
	 * @param string   $name    Name
	 * @return string|null
	 */
	private static function header(array $headers, string $name)
	{
		foreach ($headers as $header) {
			if (stripos($header, $name.':') === 0) {
				return trim(substr($header, strlen($name) + 1));
			}
		}

		return null;
	}

	/**
	 * The signature is SHA256 with RSA in base64, checkable with the public key.
	 *
	 * @return void
	 */
	public function testSignatureMatchesThePublicKey(): void
	{
		list($private, $public) = self::keyPair();

		$signature = WiseClient::sign('be2f6579-9426-480b-9cb7-d8f1116cc8b9', $private);

		$this->assertNotNull($signature);
		$this->assertSame(1, openssl_verify('be2f6579-9426-480b-9cb7-d8f1116cc8b9', base64_decode($signature), $public, OPENSSL_ALGO_SHA256));
	}

	/**
	 * A text that is not a key cannot sign.
	 *
	 * @return void
	 */
	public function testAnInvalidKeyCannotSign(): void
	{
		$this->assertNull(WiseClient::sign('ott', 'not a key'));
	}

	/**
	 * The challenge is signed once and the request replayed with the token and the signature.
	 *
	 * @return void
	 */
	public function testTheScaChallengeIsSignedAndReplayed(): void
	{
		list($private, $public) = self::keyPair();
		$xml = (string) file_get_contents(__DIR__.'/fixtures/statement.xml');
		$client = $this->client(array(self::challenge(), self::response(200, $xml, array('x-2fa-approval-result' => 'APPROVED'))), $private);

		$content = $client->fetchStatement(12345, 200001, 'CHF', '2026-10-08');

		$this->assertSame($xml, $content);
		$this->assertSame(WiseClient::SCA_APPROVED, $client->sca);
		$this->assertCount(2, $this->requests);
		$this->assertSame($this->requests[0]['url'], $this->requests[1]['url']);
		$this->assertNull(self::header($this->requests[0]['headers'], 'X-Signature'));
		$this->assertSame('be2f6579-9426-480b-9cb7-d8f1116cc8b9', self::header($this->requests[1]['headers'], 'X-2FA-Approval'));
		$signature = (string) self::header($this->requests[1]['headers'], 'X-Signature');
		$this->assertSame(1, openssl_verify('be2f6579-9426-480b-9cb7-d8f1116cc8b9', base64_decode($signature), $public, OPENSSL_ALGO_SHA256));
		$this->assertSame('Bearer token-123', self::header($this->requests[1]['headers'], 'Authorization'));
	}

	/**
	 * Without a key the challenge is not answered and nothing is replayed.
	 *
	 * @return void
	 */
	public function testWithoutKeyTheChallengeIsReported(): void
	{
		$client = $this->client(array(self::challenge()));

		$this->assertNull($client->fetchStatement(12345, 200001, 'CHF', '2026-10-08'));
		$this->assertSame(WiseClient::SCA_NO_KEY, $client->sca);
		$this->assertCount(1, $this->requests);
	}

	/**
	 * A refused signature is reported as such, without a second attempt.
	 *
	 * @return void
	 */
	public function testARefusedSignatureIsReported(): void
	{
		list($private) = self::keyPair();
		$client = $this->client(array(self::challenge(), self::challenge()), $private);

		$this->assertNull($client->fetchStatement(12345, 200001, 'CHF', '2026-10-08'));
		$this->assertSame(WiseClient::SCA_REJECTED, $client->sca);
		$this->assertSame(403, $client->httpCode);
		$this->assertCount(2, $this->requests);
	}

	/**
	 * A plain 403 without challenge is an access error, not an SCA one.
	 *
	 * @return void
	 */
	public function testAForbiddenWithoutChallengeIsAnAccessError(): void
	{
		list($private) = self::keyPair();
		$client = $this->client(array(self::response(403, '{"error":"forbidden"}')), $private);

		$this->assertNull($client->listBalances(12345));
		$this->assertSame(WiseClient::SCA_NONE, $client->sca);
		$this->assertStringContainsString('HTTP 403', $client->error);
		$this->assertCount(1, $this->requests);
	}

	/**
	 * The statement request carries the currency, the day bounds and asks for CAMT.053.
	 *
	 * @return void
	 */
	public function testStatementRequest(): void
	{
		$client = $this->client(array(self::response(200, (string) file_get_contents(__DIR__.'/fixtures/statement.xml'))));

		$client->fetchStatement(12345, 200001, 'CHF', '2026-10-08');

		$url = parse_url($this->requests[0]['url']);
		parse_str($url['query'], $query);
		$this->assertSame('api.wise-sandbox.com', $url['host']);
		$this->assertSame('/v1/profiles/12345/balance-statements/200001/statement.xml', $url['path']);
		$this->assertSame(array('currency' => 'CHF', 'intervalStart' => '2026-10-07T22:00:00.000Z', 'intervalEnd' => '2026-10-08T21:59:59.999Z', 'type' => 'COMPACT'), $query);
		$this->assertSame(WiseClient::SCA_NONE, $client->sca);
	}

	/**
	 * An answer that is not CAMT.053 is not taken as a statement.
	 *
	 * @return void
	 */
	public function testAnAnswerThatIsNotCamt053IsRefused(): void
	{
		$client = $this->client(array(self::response(200, '{"transactions":[]}')));

		$this->assertNull($client->fetchStatement(12345, 200001, 'CHF', '2026-10-08'));
		$this->assertStringContainsString('CAMT.053', $client->error);
	}

	/**
	 * A rate limit says how long to wait.
	 *
	 * @return void
	 */
	public function testRateLimit(): void
	{
		$client = $this->client(array(self::response(429, '', array('retry-after' => '5'))));

		$this->assertNull($client->listProfiles());
		$this->assertSame(429, $client->httpCode);
		$this->assertStringContainsString('5 s', $client->error);
	}

	/**
	 * The balances are read from the list Wise returns.
	 *
	 * @return void
	 */
	public function testBalances(): void
	{
		$client = $this->client(array(self::response(200, (string) file_get_contents(__DIR__.'/fixtures/balances.json'))));

		$balances = $client->listBalances(12345);

		$this->assertSame('https://api.wise-sandbox.com/v4/profiles/12345/balances?types=STANDARD', $this->requests[0]['url']);
		$this->assertSame(array(200001, 200002), array_column($balances, 'id'));
	}

	/**
	 * A Swiss day in UTC, in summer, in winter and on the day the clock changes.
	 *
	 * @return void
	 */
	public function testDayInterval(): void
	{
		$this->assertSame(array('2026-06-30T22:00:00.000Z', '2026-07-01T21:59:59.999Z'), WiseClient::dayInterval('2026-07-01'));
		$this->assertSame(array('2026-01-14T23:00:00.000Z', '2026-01-15T22:59:59.999Z'), WiseClient::dayInterval('2026-01-15'));
		$this->assertSame(array('2026-03-28T23:00:00.000Z', '2026-03-29T21:59:59.999Z'), WiseClient::dayInterval('2026-03-29'));
	}
}

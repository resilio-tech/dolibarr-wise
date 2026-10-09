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
 * \file       class/WiseClient.class.php
 * \ingroup    wise
 * \brief      Calls to the Wise API with a personal token, answering the SCA challenge.
 */

/**
 * Class WiseClient
 */
class WiseClient
{
	const URL_LIVE = 'https://api.wise.com';
	const URL_SANDBOX = 'https://api.wise-sandbox.com';

	const SCA_NONE = 'none';
	const SCA_APPROVED = 'approved';
	const SCA_REJECTED = 'rejected';
	const SCA_NO_KEY = 'nokey';

	/** @var string */
	private $baseUrl;

	/** @var string */
	private $token;

	/** @var string */
	private $privateKey;

	/** @var callable */
	private $transport;

	/** @var string SCA outcome of the last request, one of the SCA_* constants */
	public $sca = self::SCA_NONE;

	/** @var string Error of the last request, empty when it succeeded */
	public $error = '';

	/** @var int HTTP status of the last request, 0 when it never reached Wise */
	public $httpCode = 0;

	/**
	 * Constructor
	 *
	 * @param string        $baseUrl    API base URL, URL_LIVE or URL_SANDBOX
	 * @param string        $token      Personal API token
	 * @param string        $privateKey PEM private key whose public key is uploaded in Wise, empty if none
	 * @param callable|null $transport  function (string $url, string[] $headers): array{code:int, headers:array<string,string>, body:string, error:string}
	 */
	public function __construct(string $baseUrl, string $token, string $privateKey = '', $transport = null)
	{
		$this->baseUrl = rtrim($baseUrl, '/');
		$this->token = $token;
		$this->privateKey = $privateKey;
		$this->transport = $transport === null ? array($this, 'curlGet') : $transport;
	}

	/**
	 * List the profiles the token gives access to.
	 *
	 * @return array<int, array<string, mixed>>|null Null on error
	 */
	public function listProfiles()
	{
		return $this->getJson('/v2/profiles');
	}

	/**
	 * List the standard balances of a profile.
	 *
	 * @param int $profileId Profile id
	 * @return array<int, array<string, mixed>>|null Null on error
	 */
	public function listBalances(int $profileId)
	{
		return $this->getJson('/v4/profiles/'.$profileId.'/balances?types=STANDARD');
	}

	/**
	 * Fetch the CAMT.053 statement of a balance for one day.
	 *
	 * @param int    $profileId Profile id
	 * @param int    $balanceId Balance id
	 * @param string $currency  Balance currency
	 * @param string $day       Day as YYYY-MM-DD, Swiss time
	 * @return string|null CAMT.053 content, null on error
	 */
	public function fetchStatement(int $profileId, int $balanceId, string $currency, string $day)
	{
		$interval = self::dayInterval($day);
		$query = http_build_query(array(
			'currency' => $currency,
			'intervalStart' => $interval[0],
			'intervalEnd' => $interval[1],
			'type' => 'COMPACT',
		));

		$body = $this->get('/v1/profiles/'.$profileId.'/balance-statements/'.$balanceId.'/statement.xml?'.$query);
		if ($body === null) {
			return null;
		}
		if (strpos($body, 'urn:iso:std:iso:20022:tech:xsd:camt.053') === false) {
			$this->error = 'the answer is not a CAMT.053 statement';
			return null;
		}

		return $body;
	}

	/**
	 * Bounds of a Swiss day in UTC, as Wise expects them.
	 *
	 * @param string $day Day as YYYY-MM-DD
	 * @return string[] Start and end of the day
	 */
	public static function dayInterval(string $day): array
	{
		$utc = new DateTimeZone('UTC');
		$start = new DateTime($day.' 00:00:00', new DateTimeZone('Europe/Zurich'));
		$end = clone $start;
		$end->modify('+1 day');

		return array(
			$start->setTimezone($utc)->format('Y-m-d\TH:i:s.000\Z'),
			$end->setTimezone($utc)->modify('-1 second')->format('Y-m-d\TH:i:s.999\Z'),
		);
	}

	/**
	 * Sign a one-time token the way Wise checks it: SHA256 with RSA, base64.
	 *
	 * @param string $oneTimeToken Value of the x-2fa-approval header
	 * @param string $privateKey   PEM private key
	 * @return string|null Signature, null if the key cannot sign
	 */
	public static function sign(string $oneTimeToken, string $privateKey)
	{
		$key = @openssl_pkey_get_private($privateKey);
		if ($key === false) {
			return null;
		}
		$signature = '';
		if (!openssl_sign($oneTimeToken, $signature, $key, OPENSSL_ALGO_SHA256)) {
			return null;
		}

		return base64_encode($signature);
	}

	/**
	 * GET a path and decode its JSON answer.
	 *
	 * @param string $path Path with query string
	 * @return array<int, array<string, mixed>>|null
	 */
	private function getJson(string $path)
	{
		$body = $this->get($path);
		if ($body === null) {
			return null;
		}
		$data = json_decode($body, true);
		if (!is_array($data)) {
			$this->error = 'the answer is not valid JSON';
			return null;
		}

		return $data;
	}

	/**
	 * GET a path, signing the SCA challenge once if Wise asks for one.
	 *
	 * @param string $path Path with query string
	 * @return string|null Body, null on error
	 */
	private function get(string $path)
	{
		$this->sca = self::SCA_NONE;
		$this->error = '';

		$url = $this->baseUrl.$path;
		$headers = array('Authorization: Bearer '.$this->token);
		$response = call_user_func($this->transport, $url, $headers);

		$challenge = self::header($response, 'x-2fa-approval');
		if ($response['code'] == 403 && $challenge !== '' && strtoupper(self::header($response, 'x-2fa-approval-result')) === 'REJECTED') {
			$signature = $this->privateKey === '' ? null : self::sign($challenge, $this->privateKey);
			if ($signature === null) {
				$this->sca = self::SCA_NO_KEY;
				$this->httpCode = 403;
				$this->error = 'Wise asks for a strong authentication and no valid private key is configured';
				return null;
			}
			$headers[] = 'X-2FA-Approval: '.$challenge;
			$headers[] = 'X-Signature: '.$signature;
			$response = call_user_func($this->transport, $url, $headers);
			$this->sca = ($response['code'] == 200) ? self::SCA_APPROVED : self::SCA_REJECTED;
		}

		$this->httpCode = (int) $response['code'];
		if ($this->httpCode == 200) {
			return (string) $response['body'];
		}

		if ($response['error'] !== '') {
			$this->error = $response['error'];
		} elseif ($this->sca === self::SCA_REJECTED) {
			$this->error = 'Wise refused the signed strong authentication (HTTP '.$this->httpCode.')';
		} elseif ($this->httpCode == 429) {
			$this->error = 'rate limited by Wise, retry after '.self::header($response, 'retry-after').' s';
		} else {
			$this->error = 'HTTP '.$this->httpCode.' '.self::shorten((string) $response['body']);
		}

		return null;
	}

	/**
	 * Value of a response header, empty if missing.
	 *
	 * @param array{code:int, headers:array<string,string>, body:string, error:string} $response Response
	 * @param string                                                                $name     Lower case header name
	 * @return string
	 */
	private static function header(array $response, string $name): string
	{
		return isset($response['headers'][$name]) ? trim($response['headers'][$name]) : '';
	}

	/**
	 * Shorten a response body for an error message.
	 *
	 * @param string $text Text
	 * @return string
	 */
	private static function shorten(string $text): string
	{
		$text = trim((string) preg_replace('/\s+/', ' ', strip_tags($text)));

		return strlen($text) > 200 ? substr($text, 0, 200).'...' : $text;
	}

	/**
	 * Send a GET request with curl, TLS verified.
	 *
	 * @param string   $url     URL
	 * @param string[] $headers Request headers
	 * @return array{code:int, headers:array<string,string>, body:string, error:string}
	 */
	public function curlGet(string $url, array $headers): array
	{
		$responseHeaders = array();
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT => 60,
			CURLOPT_USERAGENT => 'Dolibarr Wise module',
			CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$responseHeaders) {
				$parts = explode(':', $line, 2);
				if (count($parts) == 2) {
					$responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
				}
				return strlen($line);
			},
		));
		$body = curl_exec($ch);
		$error = ($body === false) ? curl_error($ch) : '';
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		return array('code' => $code, 'headers' => $responseHeaders, 'body' => (string) $body, 'error' => $error);
	}
}


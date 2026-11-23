<?php

declare(strict_types=1);

namespace Punga\Component\PungaAnalytics\Administrator\Service;

use Joomla\Http\HttpFactory;

\defined('_JEXEC') or die;

/**
 * Downloads and compiles the monthly DB-IP ASN Lite database.
 */
final class AsnDatabaseService
{
	private const URL_TEMPLATE = 'https://download.db-ip.com/free/dbip-asn-lite-%s.csv.gz';
	private const IPV4_RECORD_SIZE = 12;
	private const IPV6_RECORD_SIZE = 36;

	/**
	 * Downloads and compiles the current ASN database.
	 *
	 * @return array{ipv4_count:int, ipv6_count:int, organization_count:int, release:string}
	 */
	public function update(): array
	{
		@set_time_limit(300);

		if (!function_exists('gzopen'))
		{
			throw new \RuntimeException('The PHP zlib extension is required to read the DB-IP ASN download.');
		}

		$directory = $this->getStorageDirectory();

		if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory))
		{
			throw new \RuntimeException('Could not create the Punga Analytics cache directory.');
		}

		[$downloadPath, $sourceUrl, $release] = $this->downloadCurrentRelease($directory);
		$ipv4Temp = $directory . '/asn-ipv4.bin.tmp';
		$ipv6Temp = $directory . '/asn-ipv6.bin.tmp';
		$organizationsTemp = $directory . '/asn-organizations.json.tmp';
		$automationSignalsTemp = $directory . '/asn-automation-signals.json.tmp';
		$result = [
			'ipv4_count' => 0,
			'ipv6_count' => 0,
			'organization_count' => 0,
		];

		try
		{
			$result = $this->compile($downloadPath, $ipv4Temp, $ipv6Temp, $organizationsTemp);
			$this->writeAutomationSignals($organizationsTemp, $automationSignalsTemp);
			$this->activateFile($ipv4Temp, $directory . '/asn-ipv4.bin');
			$this->activateFile($ipv6Temp, $directory . '/asn-ipv6.bin');
			$this->activateFile($organizationsTemp, $directory . '/asn-organizations.json');
			$this->activateFile($automationSignalsTemp, $directory . '/asn-automation-signals.json');

			$metadata = [
				'updated_at' => gmdate('c'),
				'release' => $release,
				'ipv4_count' => $result['ipv4_count'],
				'ipv6_count' => $result['ipv6_count'],
				'organization_count' => $result['organization_count'],
				'source' => $sourceUrl,
				'provider' => 'DB-IP ASN Lite',
				'automation_signals' => true,
			];
			$this->writeAtomically(
				$directory . '/asn-metadata.json',
				json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}'
			);
		}
		finally
		{
			@unlink($downloadPath);
			@unlink($ipv4Temp);
			@unlink($ipv6Temp);
			@unlink($organizationsTemp);
			@unlink($automationSignalsTemp);
		}

		return [
			'ipv4_count' => $result['ipv4_count'],
			'ipv6_count' => $result['ipv6_count'],
			'organization_count' => $result['organization_count'],
			'release' => $release,
		];
	}

	/**
	 * Returns information about the installed ASN database.
	 *
	 * @return array<string, mixed>
	 */
	public function getStatus(): array
	{
		$directory = $this->getStorageDirectory();
		$path = $directory . '/asn-metadata.json';

		if (!is_file($path))
		{
			return [];
		}

		$data = json_decode((string) file_get_contents($path), true);

		if (!is_array($data))
		{
			return [];
		}

		$data['files_ready'] = $this->isCompiledFileReady(
			$directory . '/asn-ipv4.bin',
			self::IPV4_RECORD_SIZE,
			(int) ($data['ipv4_count'] ?? 0)
		) && $this->isCompiledFileReady(
			$directory . '/asn-ipv6.bin',
			self::IPV6_RECORD_SIZE,
			(int) ($data['ipv6_count'] ?? 0)
		) && is_file($directory . '/asn-organizations.json')
			&& is_readable($directory . '/asn-organizations.json');

		return $data;
	}

	/**
	 * Returns the current organization name for an AS number.
	 *
	 * @param int $asn Autonomous System number.
	 *
	 * @return string Organization name, or an empty string when unknown.
	 */
	public function getOrganization(int $asn): string
	{
		if ($asn <= 0)
		{
			return '';
		}

		$organizations = $this->loadOrganizations();
		$value = $organizations[(string) $asn] ?? '';

		return is_string($value) ? $value : '';
	}

	/**
	 * Returns all installed ASN-to-organization mappings.
	 *
	 * @return array<string, string>
	 */
	public function getOrganizations(): array
	{
		return $this->loadOrganizations();
	}

	/**
	 * Checks that one compiled lookup file is readable and matches metadata.
	 *
	 * @param string $path       Compiled file path.
	 * @param int    $recordSize Fixed record size.
	 * @param int    $count      Expected record count.
	 *
	 * @return bool
	 */
	private function isCompiledFileReady(string $path, int $recordSize, int $count): bool
	{
		if ($count < 1 || !is_file($path) || !is_readable($path))
		{
			return false;
		}

		$size = filesize($path);

		return $size !== false && $size === $count * $recordSize;
	}

	/**
	 * Downloads the newest available monthly release, falling back when needed.
	 *
	 * @param string $directory Storage directory.
	 *
	 * @return array{0:string, 1:string, 2:string}
	 */
	private function downloadCurrentRelease(string $directory): array
	{
		$base = new \DateTimeImmutable('first day of this month', new \DateTimeZone('UTC'));
		$errors = [];

		for ($offset = 0; $offset < 3; $offset++)
		{
			$releaseDate = $base->modify('-' . $offset . ' months');
			$release = $releaseDate->format('Y-m');
			$url = sprintf(self::URL_TEMPLATE, $release);
			$destination = $directory . '/dbip-asn-lite-' . $release . '.csv.gz.tmp';

			try
			{
				$this->downloadToFile($url, $destination);

				return [$destination, $url, $release];
			}
			catch (\Throwable $exception)
			{
				@unlink($destination);
				$errors[] = $release . ': ' . $exception->getMessage();
			}
		}

		throw new \RuntimeException('Could not download a current DB-IP ASN Lite release. ' . implode(' | ', $errors));
	}

	/**
	 * Downloads a URL to a local file without sending visitor data.
	 *
	 * @param string $url         Source URL.
	 * @param string $destination Destination path.
	 *
	 * @return void
	 */
	private function downloadToFile(string $url, string $destination): void
	{
		$source = @fopen($url, 'rb');

		if ($source !== false)
		{
			$target = fopen($destination, 'wb');

			if ($target === false)
			{
				fclose($source);
				throw new \RuntimeException('Could not create the temporary DB-IP ASN file.');
			}

			try
			{
				$bytes = stream_copy_to_stream($source, $target);
			}
			finally
			{
				fclose($source);
				fclose($target);
			}

			if ($bytes !== false && $bytes > 1024)
			{
				return;
			}

			@unlink($destination);
		}

		$response = HttpFactory::getHttp()->get($url, ['User-Agent' => 'Joomla Punga Analytics/0.12.1'], 90);

		if ($response->code < 200 || $response->code >= 300)
		{
			throw new \RuntimeException('ASN database download failed with HTTP status ' . $response->code . '.');
		}

		$body = (string) $response->body;

		if (strlen($body) <= 1024 || file_put_contents($destination, $body, LOCK_EX) === false)
		{
			throw new \RuntimeException('The downloaded ASN database is empty or could not be stored.');
		}
	}

	/**
	 * Compiles the CSV into sorted fixed-record IPv4/IPv6 files plus an ASN-name map.
	 *
	 * DB-IP ASN Lite CSV rows contain: ip_start, ip_end, as_number, as_organization.
	 * The range files contain only packed addresses and a big-endian uint32 ASN.
	 * Organization names are stored once in a separate JSON map and are never
	 * duplicated into event rows.
	 *
	 * @param string $sourcePath       Gzip-compressed CSV path.
	 * @param string $ipv4Path         IPv4 output path.
	 * @param string $ipv6Path         IPv6 output path.
	 * @param string $organizationsPath Organization-map output path.
	 *
	 * @return array{ipv4_count:int, ipv6_count:int, organization_count:int}
	 */
	private function compile(
		string $sourcePath,
		string $ipv4Path,
		string $ipv6Path,
		string $organizationsPath
	): array
	{
		$source = gzopen($sourcePath, 'rb');
		$ipv4 = fopen($ipv4Path, 'wb');
		$ipv6 = fopen($ipv6Path, 'wb');

		if ($source === false || $ipv4 === false || $ipv6 === false)
		{
			if (is_resource($source))
			{
				gzclose($source);
			}
			if (is_resource($ipv4))
			{
				fclose($ipv4);
			}
			if (is_resource($ipv6))
			{
				fclose($ipv6);
			}
			throw new \RuntimeException('Could not open the ASN database for compilation.');
		}

		$counts = ['ipv4_count' => 0, 'ipv6_count' => 0, 'organization_count' => 0];
		$previous = ['ipv4' => null, 'ipv6' => null];
		$organizations = [];
		$totalRows = 0;
		$invalidRows = 0;

		try
		{
			while (($row = fgetcsv($source, 8192, ',', '"', '')) !== false)
			{
				$totalRows++;

				if (count($row) < 4)
				{
					$invalidRows++;
					continue;
				}

				$startText = ltrim(trim((string) $row[0]), "\xEF\xBB\xBF");
				$endText = trim((string) $row[1]);
				$asnText = strtoupper(trim((string) $row[2]));
				$organization = trim((string) $row[3]);
				$asnText = preg_replace('/^AS/i', '', $asnText) ?? '';

				if ($asnText === '' || preg_match('/^\d{1,10}$/', $asnText) !== 1)
				{
					$invalidRows++;
					continue;
				}

				$asn = (int) $asnText;

				if ($asn <= 0 || $asn > 4294967295)
				{
					$invalidRows++;
					continue;
				}

				$start = inet_pton($startText);
				$end = inet_pton($endText);

				if ($start === false || $end === false || strlen($start) !== strlen($end))
				{
					$invalidRows++;
					continue;
				}

				$family = strlen($start) === 4 ? 'ipv4' : (strlen($start) === 16 ? 'ipv6' : '');

				if ($family === '' || strcmp($start, $end) > 0)
				{
					$invalidRows++;
					continue;
				}

				if ($previous[$family] !== null && strcmp($start, $previous[$family]) < 0)
				{
					throw new \RuntimeException('The downloaded DB-IP ASN ranges are not sorted as expected.');
				}

				$record = $start . $end . pack('N', $asn);
				$target = $family === 'ipv4' ? $ipv4 : $ipv6;
				$expectedSize = $family === 'ipv4' ? self::IPV4_RECORD_SIZE : self::IPV6_RECORD_SIZE;

				if (strlen($record) !== $expectedSize || fwrite($target, $record) !== $expectedSize)
				{
					throw new \RuntimeException('Could not write the compiled ASN database.');
				}

				$counts[$family . '_count']++;
				$previous[$family] = $start;

				if ($organization !== '')
				{
					$organizations[(string) $asn] = mb_substr($organization, 0, 255);
				}
			}
		}
		finally
		{
			gzclose($source);
			fclose($ipv4);
			fclose($ipv6);
		}

		if ($counts['ipv4_count'] < 1000 || $counts['ipv6_count'] < 1000)
		{
			throw new \RuntimeException(
				'The compiled DB-IP ASN database contains unexpectedly few records '
				. sprintf(
					'(read %d rows, accepted %d IPv4 and %d IPv6, rejected %d).',
					$totalRows,
					$counts['ipv4_count'],
					$counts['ipv6_count'],
					$invalidRows
				)
			);
		}

		ksort($organizations, SORT_NUMERIC);
		$counts['organization_count'] = count($organizations);
		$json = json_encode($organizations, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		if ($json === false || file_put_contents($organizationsPath, $json, LOCK_EX) === false)
		{
			throw new \RuntimeException('Could not write the ASN organization database.');
		}

		return $counts;
	}


	/**
	 * Builds a compact ASN set used only as one automation-classification signal.
	 *
	 * Hosting/cloud membership is deliberately not a bot verdict. The runtime
	 * classifier combines it with behavioral evidence before traffic is removed
	 * from human statistics.
	 *
	 * @param string $organizationsPath ASN-to-organization JSON map.
	 * @param string $destination       Signal-map output path.
	 *
	 * @return void
	 */
	private function writeAutomationSignals(string $organizationsPath, string $destination): void
	{
		$data = json_decode((string) file_get_contents($organizationsPath), true);
		$signals = [];

		if (is_array($data))
		{
			foreach ($data as $asn => $organization)
			{
				if (!ctype_digit((string) $asn) || !is_string($organization))
				{
					continue;
				}

				if ($this->isLikelyHostingOrganization($organization))
				{
					$signals[(string) $asn] = 1;
				}
			}
		}

		ksort($signals, SORT_NUMERIC);
		$json = json_encode($signals, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		if ($json === false || file_put_contents($destination, $json, LOCK_EX) === false)
		{
			throw new \RuntimeException('Could not write the ASN automation-signal database.');
		}
	}

	/**
	 * Returns whether an ASN organization name strongly suggests hosting/cloud use.
	 *
	 * The result is intentionally conservative and is only worth two points in
	 * the separate behavioral classifier. CDN/privacy-relay providers are excluded
	 * because their infrastructure can legitimately carry ordinary human traffic.
	 *
	 * @param string $organization DB-IP organization name.
	 *
	 * @return bool
	 */
	private function isLikelyHostingOrganization(string $organization): bool
	{
		$value = mb_strtolower(trim($organization), 'UTF-8');

		if ($value === '' || preg_match('/cloudflare|akamai|fastly|private relay|apple relay/u', $value) === 1)
		{
			return false;
		}

		$providers = [
			'amazon',
			'aws',
			'microsoft',
			'google cloud',
			'digitalocean',
			'hetzner',
			'ovh',
			'linode',
			'vultr',
			'choopa',
			'tencent',
			'alibaba',
			'oracle cloud',
			'contabo',
			'leaseweb',
			'scaleway',
			'upcloud',
			'rackspace',
			'hostinger',
			'netcup',
			'ionos',
		];

		foreach ($providers as $provider)
		{
			if (str_contains($value, $provider))
			{
				return true;
			}
		}

		return preg_match('/\b(hosting|hoster|cloud|vps|compute|servers?|datacent(?:er|re)|data\s+cent(?:er|re)|colocation)\b/u', $value) === 1;
	}

	/**
	 * Loads the organization map.
	 *
	 * @return array<string, string>
	 */
	private function loadOrganizations(): array
	{
		$path = $this->getStorageDirectory() . '/asn-organizations.json';

		if (!is_file($path) || !is_readable($path))
		{
			return [];
		}

		$data = json_decode((string) file_get_contents($path), true);

		return is_array($data) ? array_filter($data, 'is_string') : [];
	}

	/**
	 * Activates a completed temporary file.
	 *
	 * @param string $temporary   Temporary path.
	 * @param string $destination Destination path.
	 *
	 * @return void
	 */
	private function activateFile(string $temporary, string $destination): void
	{
		if (!is_file($temporary) || filesize($temporary) === 0)
		{
			throw new \RuntimeException('A compiled ASN database file is missing or empty.');
		}

		if (@rename($temporary, $destination))
		{
			return;
		}

		if (is_file($destination) && !@unlink($destination))
		{
			throw new \RuntimeException('Could not replace ' . basename($destination) . '.');
		}

		if (!@rename($temporary, $destination))
		{
			throw new \RuntimeException('Could not activate ' . basename($destination) . '.');
		}
	}

	/**
	 * Writes a small file through a temporary replacement.
	 *
	 * @param string $path    Destination path.
	 * @param string $content File content.
	 *
	 * @return void
	 */
	private function writeAtomically(string $path, string $content): void
	{
		$temp = $path . '.tmp';
		$bytes = file_put_contents($temp, $content, LOCK_EX);

		if ($bytes === false || $bytes !== strlen($content))
		{
			@unlink($temp);
			throw new \RuntimeException('Could not write ' . basename($path) . '.');
		}

		$this->activateFile($temp, $path);
	}

	/**
	 * Returns the local database directory.
	 *
	 * @return string
	 */
	private function getStorageDirectory(): string
	{
		return JPATH_ROOT . '/cache/com_pungaanalytics';
	}
}

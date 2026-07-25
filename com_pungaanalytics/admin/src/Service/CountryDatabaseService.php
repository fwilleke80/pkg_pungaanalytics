<?php

declare(strict_types=1);

namespace Punga\Component\PungaAnalytics\Administrator\Service;

use Joomla\Http\HttpFactory;

\defined('_JEXEC') or die;

/**
 * Downloads and compiles the monthly DB-IP Lite country database.
 */
final class CountryDatabaseService
{
	private const URL_TEMPLATE = 'https://download.db-ip.com/free/dbip-country-lite-%s.csv.gz';
	private const IPV4_RECORD_SIZE = 10;
	private const IPV6_RECORD_SIZE = 34;

	/**
	 * Downloads and compiles the current country database.
	 *
	 * @return array{ipv4_count:int, ipv6_count:int, release:string}
	 */
	public function update(): array
	{
		@set_time_limit(300);

		if (!function_exists('gzopen'))
		{
			throw new \RuntimeException('The PHP zlib extension is required to read the DB-IP download.');
		}

		$directory = $this->getStorageDirectory();

		if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory))
		{
			throw new \RuntimeException('Could not create the Punga Analytics cache directory.');
		}

		[$downloadPath, $sourceUrl, $release] = $this->downloadCurrentRelease($directory);
		$ipv4Temp = $directory . '/country-ipv4.bin.tmp';
		$ipv6Temp = $directory . '/country-ipv6.bin.tmp';

		try
		{
			$result = $this->compile($downloadPath, $ipv4Temp, $ipv6Temp);
			$this->activateFile($ipv4Temp, $directory . '/country-ipv4.bin');
			$this->activateFile($ipv6Temp, $directory . '/country-ipv6.bin');
			@unlink($directory . '/de-ipv4.bin');
			@unlink($directory . '/de-ipv6.bin');

			$metadata = [
				'updated_at' => gmdate('c'),
				'release' => $release,
				'ipv4_count' => $result['ipv4_count'],
				'ipv6_count' => $result['ipv6_count'],
				'source' => $sourceUrl,
				'provider' => 'DB-IP Lite',
			];
			$this->writeAtomically(
				$directory . '/metadata.json',
				json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}'
			);
		}
		finally
		{
			@unlink($downloadPath);
			@unlink($ipv4Temp);
			@unlink($ipv6Temp);
		}

		return [
			'ipv4_count' => $result['ipv4_count'],
			'ipv6_count' => $result['ipv6_count'],
			'release' => $release,
		];
	}

	/**
	 * Returns information about the installed country database.
	 *
	 * @return array<string, mixed>
	 */
	public function getStatus(): array
	{
		$directory = $this->getStorageDirectory();
		$path = $directory . '/metadata.json';

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
			$directory . '/country-ipv4.bin',
			self::IPV4_RECORD_SIZE,
			(int) ($data['ipv4_count'] ?? 0)
		) && $this->isCompiledFileReady(
			$directory . '/country-ipv6.bin',
			self::IPV6_RECORD_SIZE,
			(int) ($data['ipv6_count'] ?? 0)
		);

		return $data;
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
			$destination = $directory . '/dbip-country-lite-' . $release . '.csv.gz.tmp';

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

		throw new \RuntimeException('Could not download a current DB-IP Lite release. ' . implode(' | ', $errors));
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
				throw new \RuntimeException('Could not create the temporary DB-IP file.');
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

		$response = HttpFactory::getHttp()->get($url, ['User-Agent' => 'Joomla Punga Analytics/0.7.5'], 90);

		if ($response->code < 200 || $response->code >= 300)
		{
			throw new \RuntimeException('Database download failed with HTTP status ' . $response->code . '.');
		}

		$body = (string) $response->body;

		if (strlen($body) <= 1024 || file_put_contents($destination, $body, LOCK_EX) === false)
		{
			throw new \RuntimeException('The downloaded country database is empty or could not be stored.');
		}
	}

	/**
	 * Compiles the CSV into sorted fixed-record IPv4 and IPv6 files.
	 *
	 * @param string $sourcePath Gzip-compressed CSV path.
	 * @param string $ipv4Path   IPv4 output path.
	 * @param string $ipv6Path   IPv6 output path.
	 *
	 * @return array{ipv4_count:int, ipv6_count:int}
	 */
	private function compile(string $sourcePath, string $ipv4Path, string $ipv6Path): array
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
			throw new \RuntimeException('Could not open the country database for compilation.');
		}

		$counts = ['ipv4_count' => 0, 'ipv6_count' => 0];
		$previous = ['ipv4' => null, 'ipv6' => null];
		$totalRows = 0;
		$invalidRows = 0;

		try
		{
			while (($row = fgetcsv($source, 4096, ',', '"', '')) !== false)
			{
				$totalRows++;

				// DB-IP Country Lite CSV rows contain exactly: ip_start, ip_end, country.
				if (count($row) < 3)
				{
					$invalidRows++;
					continue;
				}

				$startText = ltrim(trim((string) $row[0]), "\xEF\xBB\xBF");
				$endText = trim((string) $row[1]);
				$country = strtoupper(trim((string) $row[2]));

				if (preg_match('/^[A-Z]{2}$/', $country) !== 1)
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
					throw new \RuntimeException('The downloaded DB-IP ranges are not sorted as expected.');
				}

				$record = $start . $end . $country;
				$target = $family === 'ipv4' ? $ipv4 : $ipv6;
				$expectedSize = $family === 'ipv4' ? self::IPV4_RECORD_SIZE : self::IPV6_RECORD_SIZE;

				if (strlen($record) !== $expectedSize || fwrite($target, $record) !== $expectedSize)
				{
					throw new \RuntimeException('Could not write the compiled country database.');
				}

				$counts[$family . '_count']++;
				$previous[$family] = $start;
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
				'The compiled DB-IP database contains unexpectedly few records '
				. sprintf(
					'(read %d rows, accepted %d IPv4 and %d IPv6, rejected %d).',
					$totalRows,
					$counts['ipv4_count'],
					$counts['ipv6_count'],
					$invalidRows
				)
			);
		}

		return $counts;
	}

	/**
	 * Activates a completed temporary file.
	 *
	 * @param string $temporary  Temporary path.
	 * @param string $destination Destination path.
	 *
	 * @return void
	 */
	private function activateFile(string $temporary, string $destination): void
	{
		if (!is_file($temporary) || filesize($temporary) === 0)
		{
			throw new \RuntimeException('A compiled country database file is missing or empty.');
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

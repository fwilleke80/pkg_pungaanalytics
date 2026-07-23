<?php

declare(strict_types=1);

namespace FrankWilleke\Plugin\System\Simplestats\Service;

\defined('_JEXEC') or die;

/**
 * Performs binary searches in the locally compiled DB-IP country files.
 */
final class CountryDatabaseMatcher
{
	private const IPV4_RECORD_SIZE = 10;
	private const IPV6_RECORD_SIZE = 34;

	/**
	 * Resolves an IP address to an ISO 3166-1 alpha-2 country code.
	 *
	 * @param string $ipAddress IP address.
	 *
	 * @return string Two-letter country code or ZZ when unknown.
	 */
	public function lookup(string $ipAddress): string
	{
		$ipAddress = $this->normaliseIpAddress($ipAddress);

		if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false)
		{
			return $this->match($ipAddress, 'country-ipv4.bin', self::IPV4_RECORD_SIZE, 4);
		}

		if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false)
		{
			return $this->match($ipAddress, 'country-ipv6.bin', self::IPV6_RECORD_SIZE, 16);
		}

		return 'ZZ';
	}

	/**
	 * Converts IPv4-mapped IPv6 addresses to ordinary IPv4 notation.
	 *
	 * Some reverse proxies expose addresses such as ::ffff:203.0.113.10.
	 * DB-IP stores those visitors in its IPv4 table, so they must be
	 * normalised before choosing the compiled lookup file.
	 *
	 * @param string $ipAddress IP address supplied by the web server.
	 *
	 * @return string Normalised IP address.
	 */
	private function normaliseIpAddress(string $ipAddress): string
	{
		$packed = @inet_pton($ipAddress);
		$mappedPrefix = str_repeat("\0", 10) . "\xff\xff";

		if ($packed !== false && strlen($packed) === 16 && substr($packed, 0, 12) === $mappedPrefix)
		{
			$ipv4 = @inet_ntop(substr($packed, 12, 4));

			if ($ipv4 !== false)
			{
				return $ipv4;
			}
		}

		return $ipAddress;
	}

	/**
	 * Performs a fixed-record binary range lookup.
	 *
	 * @param string $ipAddress  IP address.
	 * @param string $filename   Compiled database filename.
	 * @param int    $recordSize Fixed record size.
	 * @param int    $addressSize Packed address size.
	 *
	 * @return string
	 */
	private function match(string $ipAddress, string $filename, int $recordSize, int $addressSize): string
	{
		$path = $this->getStorageDirectory() . '/' . $filename;
		$fileSize = is_file($path) ? filesize($path) : false;

		if ($fileSize === false || $fileSize < $recordSize || $fileSize % $recordSize !== 0)
		{
			return 'ZZ';
		}

		$needle = inet_pton($ipAddress);

		if ($needle === false)
		{
			return 'ZZ';
		}

		$handle = fopen($path, 'rb');

		if ($handle === false)
		{
			return 'ZZ';
		}

		try
		{
			$low = 0;
			$high = (int) ($fileSize / $recordSize) - 1;

			while ($low <= $high)
			{
				$middle = intdiv($low + $high, 2);

				if (fseek($handle, $middle * $recordSize) !== 0)
				{
					return 'ZZ';
				}

				$record = fread($handle, $recordSize);

				if ($record === false || strlen($record) !== $recordSize)
				{
					return 'ZZ';
				}

				$start = substr($record, 0, $addressSize);
				$end = substr($record, $addressSize, $addressSize);

				if (strcmp($needle, $start) < 0)
				{
					$high = $middle - 1;
				}
				elseif (strcmp($needle, $end) > 0)
				{
					$low = $middle + 1;
				}
				else
				{
					$country = strtoupper(substr($record, $addressSize * 2, 2));

					return preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : 'ZZ';
				}
			}
		}
		finally
		{
			fclose($handle);
		}

		return 'ZZ';
	}

	/**
	 * Returns the local database directory.
	 *
	 * @return string
	 */
	private function getStorageDirectory(): string
	{
		return JPATH_ROOT . '/cache/com_simplestats';
	}
}

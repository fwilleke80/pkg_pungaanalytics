<?php

declare(strict_types=1);

namespace Punga\Plugin\System\PungaAnalytics\Service;

\defined('_JEXEC') or die;

/**
 * Performs binary searches in the locally compiled DB-IP ASN files.
 */
final class AsnDatabaseMatcher
{
	private const IPV4_RECORD_SIZE = 12;
	private const IPV6_RECORD_SIZE = 36;

	/**
	 * Resolves an IP address to an Autonomous System number.
	 *
	 * @param string $ipAddress IP address.
	 *
	 * @return int AS number, or zero when unknown.
	 */
	public function lookup(string $ipAddress): int
	{
		$ipAddress = $this->normaliseIpAddress($ipAddress);

		if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false)
		{
			return $this->match($ipAddress, 'asn-ipv4.bin', self::IPV4_RECORD_SIZE, 4);
		}

		if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false)
		{
			return $this->match($ipAddress, 'asn-ipv6.bin', self::IPV6_RECORD_SIZE, 16);
		}

		return 0;
	}

	/**
	 * Converts IPv4-mapped IPv6 addresses to ordinary IPv4 notation.
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
	 * @return int
	 */
	private function match(string $ipAddress, string $filename, int $recordSize, int $addressSize): int
	{
		$path = $this->getStorageDirectory() . '/' . $filename;
		$fileSize = is_file($path) ? filesize($path) : false;

		if ($fileSize === false || $fileSize < $recordSize || $fileSize % $recordSize !== 0)
		{
			return 0;
		}

		$needle = inet_pton($ipAddress);

		if ($needle === false)
		{
			return 0;
		}

		$handle = fopen($path, 'rb');

		if ($handle === false)
		{
			return 0;
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
					return 0;
				}

				$record = fread($handle, $recordSize);

				if ($record === false || strlen($record) !== $recordSize)
				{
					return 0;
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
					$unpacked = unpack('Nasn', substr($record, $addressSize * 2, 4));

					return (int) ($unpacked['asn'] ?? 0);
				}
			}
		}
		finally
		{
			fclose($handle);
		}

		return 0;
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

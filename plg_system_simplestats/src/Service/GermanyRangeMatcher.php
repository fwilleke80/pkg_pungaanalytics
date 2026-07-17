<?php

declare(strict_types=1);

namespace FrankWilleke\Plugin\System\Simplestats\Service;

\defined('_JEXEC') or die;

/**
 * Performs binary searches in the locally compiled German IP range files.
 */
final class GermanyRangeMatcher
{
	/**
	 * Tests whether an IP address is covered by the German range database.
	 *
	 * @param string $ipAddress IP address.
	 *
	 * @return bool
	 */
	public function isGerman(string $ipAddress): bool
	{
		if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false)
		{
			return $this->matchIpv4($ipAddress);
		}

		if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false)
		{
			return $this->matchIpv6($ipAddress);
		}

		return false;
	}

	/**
	 * Performs an IPv4 range lookup.
	 *
	 * @param string $ipAddress IPv4 address.
	 *
	 * @return bool
	 */
	private function matchIpv4(string $ipAddress): bool
	{
		$path = $this->getStorageDirectory() . '/de-ipv4.bin';

		if (!is_file($path) || filesize($path) < 8)
		{
			return false;
		}

		$packed = inet_pton($ipAddress);
		$unpacked = $packed === false ? false : unpack('Nvalue', $packed);
		$needle = (int) ($unpacked['value'] ?? -1);
		$handle = fopen($path, 'rb');

		if ($handle === false)
		{
			return false;
		}

		try
		{
			$low = 0;
			$high = (int) (filesize($path) / 8) - 1;

			while ($low <= $high)
			{
				$middle = intdiv($low + $high, 2);
				fseek($handle, $middle * 8);
				$record = fread($handle, 8);

				if ($record === false || strlen($record) !== 8)
				{
					return false;
				}

				$range = unpack('Nstart/Nend', $record);
				$start = (int) ($range['start'] ?? 0);
				$end = (int) ($range['end'] ?? 0);

				if ($needle < $start)
				{
					$high = $middle - 1;
				}
				elseif ($needle > $end)
				{
					$low = $middle + 1;
				}
				else
				{
					return true;
				}
			}
		}
		finally
		{
			fclose($handle);
		}

		return false;
	}

	/**
	 * Performs an IPv6 range lookup.
	 *
	 * @param string $ipAddress IPv6 address.
	 *
	 * @return bool
	 */
	private function matchIpv6(string $ipAddress): bool
	{
		$path = $this->getStorageDirectory() . '/de-ipv6.bin';

		if (!is_file($path) || filesize($path) < 32)
		{
			return false;
		}

		$needle = inet_pton($ipAddress);

		if ($needle === false)
		{
			return false;
		}

		$handle = fopen($path, 'rb');

		if ($handle === false)
		{
			return false;
		}

		try
		{
			$low = 0;
			$high = (int) (filesize($path) / 32) - 1;

			while ($low <= $high)
			{
				$middle = intdiv($low + $high, 2);
				fseek($handle, $middle * 32);
				$record = fread($handle, 32);

				if ($record === false || strlen($record) !== 32)
				{
					return false;
				}

				$start = substr($record, 0, 16);
				$end = substr($record, 16, 16);

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
					return true;
				}
			}
		}
		finally
		{
			fclose($handle);
		}

		return false;
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

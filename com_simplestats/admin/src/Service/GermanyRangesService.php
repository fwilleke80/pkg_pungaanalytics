<?php

declare(strict_types=1);

namespace FrankWilleke\Component\Simplestats\Administrator\Service;

use Joomla\Http\HttpFactory;

\defined('_JEXEC') or die;

/**
 * Downloads and compiles German IPv4 and IPv6 CIDR ranges.
 */
final class GermanyRangesService
{
	private const IPV4_URL = 'https://www.ipdeny.com/ipblocks/data/aggregated/de-aggregated.zone';
	private const IPV6_URL = 'https://www.ipdeny.com/ipv6/ipaddresses/aggregated/de-aggregated.zone';

	/**
	 * Downloads and compiles both range files.
	 *
	 * @return array{ipv4_count:int, ipv6_count:int}
	 */
	public function update(): array
	{
		@set_time_limit(120);

		$directory = $this->getStorageDirectory();

		if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory))
		{
			throw new \RuntimeException('Could not create the Simple Stats cache directory.');
		}

		$ipv4Text = $this->download(self::IPV4_URL);
		$ipv6Text = $this->download(self::IPV6_URL);
		$ipv4Records = $this->compileIpv4($ipv4Text);
		$ipv6Records = $this->compileIpv6($ipv6Text);

		$this->writeAtomically($directory . '/de-ipv4.bin', implode('', $ipv4Records));
		$this->writeAtomically($directory . '/de-ipv6.bin', implode('', $ipv6Records));

		$metadata = [
			'updated_at' => gmdate('c'),
			'ipv4_count' => count($ipv4Records),
			'ipv6_count' => count($ipv6Records),
			'ipv4_source' => self::IPV4_URL,
			'ipv6_source' => self::IPV6_URL,
		];
		$this->writeAtomically(
			$directory . '/metadata.json',
			json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}'
		);

		return [
			'ipv4_count' => count($ipv4Records),
			'ipv6_count' => count($ipv6Records),
		];
	}

	/**
	 * Returns information about the installed range database.
	 *
	 * @return array<string, mixed>
	 */
	public function getStatus(): array
	{
		$path = $this->getStorageDirectory() . '/metadata.json';

		if (!is_file($path))
		{
			return [];
		}

		$data = json_decode((string) file_get_contents($path), true);

		return is_array($data) ? $data : [];
	}

	/**
	 * Downloads a source file over HTTPS.
	 *
	 * @param string $url Source URL.
	 *
	 * @return string
	 */
	private function download(string $url): string
	{
		$response = HttpFactory::getHttp()->get($url, ['User-Agent' => 'Joomla Simple Stats/0.1.2'], 30);

		if ($response->code < 200 || $response->code >= 300)
		{
			throw new \RuntimeException('Range download failed with HTTP status ' . $response->code . '.');
		}

		$body = (string) $response->body;

		if (trim($body) === '')
		{
			throw new \RuntimeException('The downloaded range file is empty.');
		}

		return $body;
	}

	/**
	 * Compiles IPv4 CIDR ranges into fixed eight-byte records.
	 *
	 * @param string $text CIDR list.
	 *
	 * @return array<int, string>
	 */
	private function compileIpv4(string $text): array
	{
		$ranges = [];

		foreach (preg_split('/\R/', $text) ?: [] as $line)
		{
			$line = trim($line);

			if ($line === '' || str_starts_with($line, '#'))
			{
				continue;
			}

			[$address, $prefixText] = array_pad(explode('/', $line, 2), 2, null);
			$prefix = filter_var($prefixText, FILTER_VALIDATE_INT);

			if ($prefix === false || $prefix < 0 || $prefix > 32 || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)
			{
				continue;
			}

			$unpacked = unpack('Nvalue', inet_pton($address));
			$value = (int) ($unpacked['value'] ?? 0);
			$mask = $prefix === 0 ? 0 : ((0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF);
			$start = $value & $mask;
			$end = $start | (~$mask & 0xFFFFFFFF);
			$ranges[] = [$start, $end];
		}

		usort(
			$ranges,
			static fn(array $left, array $right): int => $left[0] <=> $right[0]
		);

		return array_map(
			static fn(array $range): string => pack('NN', $range[0], $range[1]),
			$ranges
		);
	}

	/**
	 * Compiles IPv6 CIDR ranges into fixed 32-byte records.
	 *
	 * @param string $text CIDR list.
	 *
	 * @return array<int, string>
	 */
	private function compileIpv6(string $text): array
	{
		$ranges = [];

		foreach (preg_split('/\R/', $text) ?: [] as $line)
		{
			$line = trim($line);

			if ($line === '' || str_starts_with($line, '#'))
			{
				continue;
			}

			[$address, $prefixText] = array_pad(explode('/', $line, 2), 2, null);
			$prefix = filter_var($prefixText, FILTER_VALIDATE_INT);

			if ($prefix === false || $prefix < 0 || $prefix > 128 || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false)
			{
				continue;
			}

			$packed = inet_pton($address);

			if ($packed === false)
			{
				continue;
			}

			[$start, $end] = $this->ipv6Bounds($packed, $prefix);
			$ranges[] = [$start, $end];
		}

		usort(
			$ranges,
			static fn(array $left, array $right): int => strcmp($left[0], $right[0])
		);

		return array_map(
			static fn(array $range): string => $range[0] . $range[1],
			$ranges
		);
	}

	/**
	 * Calculates the first and last packed address for an IPv6 prefix.
	 *
	 * @param string $packed Packed IPv6 address.
	 * @param int    $prefix Prefix length.
	 *
	 * @return array{0:string, 1:string}
	 */
	private function ipv6Bounds(string $packed, int $prefix): array
	{
		$bytes = array_values(unpack('C*', $packed) ?: []);
		$start = [];
		$end = [];
		$remaining = $prefix;

		foreach ($bytes as $byte)
		{
			if ($remaining >= 8)
			{
				$start[] = $byte;
				$end[] = $byte;
				$remaining -= 8;
				continue;
			}

			if ($remaining <= 0)
			{
				$start[] = 0;
				$end[] = 255;
				continue;
			}

			$mask = (0xFF << (8 - $remaining)) & 0xFF;
			$start[] = $byte & $mask;
			$end[] = ($byte & $mask) | (~$mask & 0xFF);
			$remaining = 0;
		}

		return [pack('C*', ...$start), pack('C*', ...$end)];
	}

	/**
	 * Writes a file through a temporary replacement.
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

		if (!@rename($temp, $path))
		{
			if (is_file($path) && !@unlink($path))
			{
				@unlink($temp);
				throw new \RuntimeException('Could not replace ' . basename($path) . '.');
			}

			if (!@rename($temp, $path))
			{
				@unlink($temp);
				throw new \RuntimeException('Could not activate ' . basename($path) . '.');
			}
		}
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

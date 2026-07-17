<?php

declare(strict_types=1);

namespace FrankWilleke\Plugin\System\Simplestats\Extension;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;
use FrankWilleke\Plugin\System\Simplestats\Service\GermanyRangeMatcher;

\defined('_JEXEC') or die;

/**
 * Privacy-conscious server-side statistics collector.
 */
final class Simplestats extends CMSPlugin implements SubscriberInterface
{
	use DatabaseAwareTrait;

	/** @var bool */
	protected $autoloadLanguage = true;

	/**
	 * Declares subscribed Joomla events.
	 *
	 * @return array<string, string>
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'onAfterRoute' => 'onAfterRoute',
		];
	}

	/**
	 * Records a frontend page view after Joomla routing has completed.
	 *
	 * @return void
	 */
	public function onAfterRoute(): void
	{
		try
		{
			$this->collect();
		}
		catch (\Throwable $exception)
		{
			Log::add(
				'Simple Stats collection failed: ' . $exception->getMessage(),
				Log::WARNING,
				'plg_system_simplestats'
			);
		}
	}

	/**
	 * Performs the actual collection operation.
	 *
	 * @return void
	 */
	private function collect(): void
	{
		$app = $this->getApplication();

		if (!$app->isClient('site'))
		{
			return;
		}

		$params = ComponentHelper::getParams('com_simplestats');

		if (!(bool) $params->get('enabled', 1))
		{
			return;
		}

		$input = $app->input;
		$method = strtoupper($input->server->getString('REQUEST_METHOD', 'GET'));

		if ($method !== 'GET')
		{
			return;
		}

		if ($input->getCmd('task', '') !== '')
		{
			return;
		}

		$format = strtolower($input->getCmd('format', 'html'));

		if (!\in_array($format, ['', 'html'], true))
		{
			return;
		}

		if ((bool) $params->get('respect_dnt', 1) && $input->server->getString('HTTP_DNT') === '1')
		{
			return;
		}

		if ((bool) $params->get('exclude_logged_in', 1) && !$app->getIdentity()->guest)
		{
			return;
		}

		$component = $input->getCmd('option', '');
		$excludedComponents = $this->parseCommaList((string) $params->get('exclude_components', 'com_ajax,com_users,com_simplestats'));

		if ($component !== '' && \in_array(strtolower($component), $excludedComponents, true))
		{
			return;
		}

		$uri = Uri::getInstance();
		$path = '/' . ltrim((string) $uri->getPath(), '/');

		if ($this->isExcludedPath($path, (string) $params->get('exclude_paths', "/administrator\n/api")))
		{
			return;
		}

		if ((bool) $params->get('store_query', 0))
		{
			$path = $this->appendSafeQuery($path, $uri);
		}

		$ipAddress = $this->getClientIp((string) $params->get('trusted_ip_header', ''));
		$userAgent = substr($input->server->getString('HTTP_USER_AGENT', ''), 0, 2048);
		[$isBot, $botName] = $this->classifyBot($userAgent);
		$siteTimezone = (string) $app->get('offset', 'UTC');
		$localDate = new Date('now', $siteTimezone);
		$visitDate = $localDate->format('Y-m-d');
		$secret = (string) $app->get('secret', '');
		$visitorHash = substr(hash_hmac('sha256', $ipAddress . "\n" . $userAgent . "\n" . $visitDate, $secret), 0, 32);

		$row = (object) [
			'visited_at' => Factory::getDate('now', 'UTC')->toSql(),
			'visit_date' => $visitDate,
			'visitor_hash' => $visitorHash,
			'path' => mb_substr($path, 0, 1024),
			'component' => mb_substr($component, 0, 100),
			'view_name' => mb_substr($input->getCmd('view', ''), 0, 100),
			'referrer_host' => $this->getExternalReferrerHost($uri),
			'country_code' => $this->getCountryCode($ipAddress, (string) $params->get('country_detection', 'local_de'), (string) $params->get('trusted_country_header', 'HTTP_CF_IPCOUNTRY')),
			'language_code' => $this->getPrimaryLanguage(),
			'device_type' => $this->classifyDevice($userAgent, $isBot),
			'browser_family' => $this->classifyBrowser($userAgent),
			'is_bot' => $isBot ? 1 : 0,
			'bot_name' => mb_substr($botName, 0, 64),
			'event_type' => 'pageview',
		];

		$this->getDatabase()->insertObject('#__simplestats_events', $row);
		$this->maybePurgeExpired((int) $params->get('retention_days', 180), (int) $params->get('cleanup_probability', 2));
	}

	/**
	 * Returns the request IP, optionally from one explicitly trusted proxy header.
	 *
	 * @param string $trustedHeader Server variable name.
	 *
	 * @return string
	 */
	private function getClientIp(string $trustedHeader): string
	{
		$input = $this->getApplication()->input;
		$candidate = $input->server->getString('REMOTE_ADDR', '0.0.0.0');
		$trustedHeader = strtoupper(trim($trustedHeader));

		if ($trustedHeader !== '')
		{
			$headerValue = $input->server->getString($trustedHeader, '');
			$firstValue = trim(explode(',', $headerValue, 2)[0]);

			if (filter_var($firstValue, FILTER_VALIDATE_IP) !== false)
			{
				$candidate = $firstValue;
			}
		}

		return filter_var($candidate, FILTER_VALIDATE_IP) !== false ? $candidate : '0.0.0.0';
	}

	/**
	 * Returns a two-letter country code without calling an external API.
	 *
	 * @param string $ipAddress    Visitor IP address.
	 * @param string $method       Detection method.
	 * @param string $headerName   Trusted country server variable.
	 *
	 * @return string
	 */
	private function getCountryCode(string $ipAddress, string $method, string $headerName): string
	{
		if ($method === 'header')
		{
			$value = strtoupper(trim($this->getApplication()->input->server->getString(strtoupper(trim($headerName)), '')));

			return preg_match('/^[A-Z]{2}$/', $value) === 1 ? $value : 'ZZ';
		}

		if ($method === 'local_de')
		{
			return (new GermanyRangeMatcher())->isGerman($ipAddress) ? 'DE' : 'ZZ';
		}

		return 'ZZ';
	}

	/**
	 * Returns the first browser language token.
	 *
	 * @return string
	 */
	private function getPrimaryLanguage(): string
	{
		$header = strtolower($this->getApplication()->input->server->getString('HTTP_ACCEPT_LANGUAGE', ''));
		$token = trim(explode(',', $header, 2)[0]);
		$token = trim(explode(';', $token, 2)[0]);

		return preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/', $token) === 1 ? substr($token, 0, 16) : '';
	}

	/**
	 * Detects common bots and crawlers.
	 *
	 * @param string $userAgent User-Agent header.
	 *
	 * @return array{0:bool, 1:string}
	 */
	private function classifyBot(string $userAgent): array
	{
		$rules = [
			'googlebot' => 'Googlebot',
			'bingbot' => 'Bingbot',
			'duckduckbot' => 'DuckDuckBot',
			'baiduspider' => 'Baiduspider',
			'yandexbot' => 'YandexBot',
			'applebot' => 'Applebot',
			'facebookexternalhit' => 'Facebook',
			'twitterbot' => 'Twitterbot',
			'linkedinbot' => 'LinkedInBot',
			'chatgpt-user' => 'ChatGPT',
			'gptbot' => 'GPTBot',
			'claudebot' => 'ClaudeBot',
			'perplexitybot' => 'PerplexityBot',
			'ahrefsbot' => 'AhrefsBot',
			'semrushbot' => 'SemrushBot',
			'mj12bot' => 'MJ12bot',
		];
		$lower = strtolower($userAgent);

		foreach ($rules as $needle => $name)
		{
			if (str_contains($lower, $needle))
			{
				return [true, $name];
			}
		}

		if ($lower === '' || preg_match('/bot|crawler|spider|slurp|headless|python-requests|curl\/|wget\//i', $userAgent) === 1)
		{
			return [true, 'Other bot'];
		}

		return [false, ''];
	}

	/**
	 * Returns a broad browser family without storing the complete User-Agent.
	 *
	 * @param string $userAgent User-Agent header.
	 *
	 * @return string
	 */
	private function classifyBrowser(string $userAgent): string
	{
		$rules = [
			'/Edg\//i' => 'Edge',
			'/OPR\//i' => 'Opera',
			'/Firefox\//i' => 'Firefox',
			'/CriOS\//i' => 'Chrome iOS',
			'/Chrome\//i' => 'Chrome',
			'/FxiOS\//i' => 'Firefox iOS',
			'/Safari\//i' => 'Safari',
		];

		foreach ($rules as $pattern => $name)
		{
			if (preg_match($pattern, $userAgent) === 1)
			{
				return $name;
			}
		}

		return 'Other';
	}

	/**
	 * Returns a broad device category.
	 *
	 * @param string $userAgent User-Agent header.
	 * @param bool   $isBot     Whether the request is a bot.
	 *
	 * @return string
	 */
	private function classifyDevice(string $userAgent, bool $isBot): string
	{
		if ($isBot)
		{
			return 'bot';
		}

		if (preg_match('/iPad|Tablet|Nexus 7|Nexus 9|SM-T|Kindle/i', $userAgent) === 1)
		{
			return 'tablet';
		}

		if (preg_match('/Mobile|Android|iPhone|iPod|IEMobile|Opera Mini/i', $userAgent) === 1)
		{
			return 'mobile';
		}

		return 'desktop';
	}

	/**
	 * Returns the external referrer hostname only.
	 *
	 * @param Uri $currentUri Current request URI.
	 *
	 * @return string
	 */
	private function getExternalReferrerHost(Uri $currentUri): string
	{
		$referrer = $this->getApplication()->input->server->getString('HTTP_REFERER', '');
		$host = strtolower((string) parse_url($referrer, PHP_URL_HOST));
		$currentHost = strtolower((string) $currentUri->getHost());

		if ($host === '' || $host === $currentHost)
		{
			return '';
		}

		return mb_substr($host, 0, 255);
	}

	/**
	 * Tests path exclusions.
	 *
	 * @param string $path       Request path.
	 * @param string $configured Newline-separated prefixes.
	 *
	 * @return bool
	 */
	private function isExcludedPath(string $path, string $configured): bool
	{
		foreach (preg_split('/\R/', $configured) ?: [] as $prefix)
		{
			$prefix = trim($prefix);

			if ($prefix !== '' && str_starts_with($path, $prefix))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Parses a lower-case comma-separated list.
	 *
	 * @param string $value Configured value.
	 *
	 * @return array<int, string>
	 */
	private function parseCommaList(string $value): array
	{
		return array_values(array_filter(array_map(
			static fn(string $item): string => strtolower(trim($item)),
			explode(',', $value)
		)));
	}

	/**
	 * Appends a query string after removing sensitive parameter names.
	 *
	 * @param string $path Path without query.
	 * @param Uri    $uri  Current URI.
	 *
	 * @return string
	 */
	private function appendSafeQuery(string $path, Uri $uri): string
	{
		$query = $uri->getQuery(true);
		$sensitive = ['token', 'password', 'passwd', 'pass', 'secret', 'key', 'api_key', 'apikey', 'auth', 'authorization'];

		foreach (array_keys($query) as $name)
		{
			if (\in_array(strtolower((string) $name), $sensitive, true))
			{
				unset($query[$name]);
			}
		}

		$queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

		return $queryString === '' ? $path : $path . '?' . $queryString;
	}

	/**
	 * Occasionally deletes rows older than the configured retention period.
	 *
	 * @param int $retentionDays Retention in days.
	 * @param int $probability   Percentage chance per request.
	 *
	 * @return void
	 */
	private function maybePurgeExpired(int $retentionDays, int $probability): void
	{
		$probability = max(0, min(100, $probability));

		if ($probability === 0 || random_int(1, 100) > $probability)
		{
			return;
		}

		$cutoff = Factory::getDate('now', 'UTC')->modify('-' . max(1, $retentionDays) . ' days')->toSql();
		$db = $this->getDatabase();
		$query = $db->getQuery(true)
			->delete($db->quoteName('#__simplestats_events'))
			->where($db->quoteName('visited_at') . ' < :cutoff')
			->bind(':cutoff', $cutoff);
		$db->setQuery($query)->execute();
	}
}

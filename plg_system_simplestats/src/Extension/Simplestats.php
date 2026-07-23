<?php

declare(strict_types=1);

namespace Willeke\Plugin\System\Simplestats\Extension;

use Willeke\Component\Simplestats\Administrator\Service\StatisticsArchiveService;
use Willeke\Plugin\System\Simplestats\Service\CountryDatabaseMatcher;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;

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
			'onSimpleStatsRecord' => 'onSimpleStatsRecord',
		];
	}

	/**
	 * Records an eligible frontend page view after Joomla routing.
	 *
	 * @return void
	 */
	public function onAfterRoute(): void
	{
		try
		{
			$this->collectPageView();
		}
		catch (\Throwable $exception)
		{
			$this->logFailure($exception);
		}
	}

	/**
	 * Records a custom event emitted by another Joomla extension.
	 *
	 * Supported arguments:
	 * - event_type: required machine name, for example audio.play
	 * - component: source component, for example com_audioarchive
	 * - view_name: optional Joomla view name
	 * - path: optional public page path associated with the event
	 * - item_type: optional entity type, for example audioarchive.clip
	 * - item_id: optional stable entity identifier
	 * - item_title: optional human-readable entity title
	 *
	 * @param Event $event Joomla event.
	 *
	 * @return void
	 */
	public function onSimpleStatsRecord(Event $event): void
	{
		try
		{
			$this->collectCustomEvent($event->getArguments());
		}
		catch (\Throwable $exception)
		{
			$this->logFailure($exception);
		}
	}

	/**
	 * Performs page-view collection.
	 *
	 * @return void
	 */
	private function collectPageView(): void
	{
		$app = $this->getApplication();

		if (!$app->isClient('site'))
		{
			return;
		}

		$params = ComponentHelper::getParams('com_simplestats');

		if (!$this->isRequestTrackable($params, true))
		{
			return;
		}

		$input = $app->input;
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

		$this->insertEvent(
			$params,
			[
				'event_type' => 'pageview',
				'path' => $path,
				'component' => $component,
				'view_name' => $input->getCmd('view', ''),
				'item_type' => '',
				'item_id' => '',
				'item_title' => '',
			],
			true
		);
	}

	/**
	 * Performs custom-event collection.
	 *
	 * @param array<string, mixed> $arguments Event arguments.
	 *
	 * @return void
	 */
	private function collectCustomEvent(array $arguments): void
	{
		$app = $this->getApplication();

		if (!$app->isClient('site'))
		{
			return;
		}

		$params = ComponentHelper::getParams('com_simplestats');

		if (!$this->isRequestTrackable($params, false))
		{
			return;
		}

		$eventType = strtolower(trim((string) ($arguments['event_type'] ?? '')));

		if ($eventType === '' || $eventType === 'pageview' || preg_match('/^[a-z][a-z0-9._-]{0,63}$/', $eventType) !== 1)
		{
			return;
		}

		$input = $app->input;
		$path = $this->normaliseEventPath((string) ($arguments['path'] ?? ''));

		if ($path === '')
		{
			$path = $this->getInternalReferrerPath();
		}

		if ($path === '')
		{
			$path = '/' . ltrim((string) Uri::getInstance()->getPath(), '/');
		}

		$this->insertEvent(
			$params,
			[
				'event_type' => $eventType,
				'path' => $path,
				'component' => (string) ($arguments['component'] ?? $input->getCmd('option', '')),
				'view_name' => (string) ($arguments['view_name'] ?? $input->getCmd('view', '')),
				'item_type' => (string) ($arguments['item_type'] ?? ''),
				'item_id' => (string) ($arguments['item_id'] ?? ''),
				'item_title' => (string) ($arguments['item_title'] ?? ''),
			],
			false
		);
	}

	/**
	 * Returns whether collection is allowed for the current request and user.
	 *
	 * @param Registry $params          Component parameters.
	 * @param bool     $requirePageView Whether ordinary page-view request rules apply.
	 *
	 * @return bool
	 */
	private function isRequestTrackable(Registry $params, bool $requirePageView): bool
	{
		if (!(bool) $params->get('enabled', 1))
		{
			return false;
		}

		$app = $this->getApplication();
		$input = $app->input;

		if ((bool) $params->get('respect_dnt', 1) && $input->server->getString('HTTP_DNT') === '1')
		{
			return false;
		}

		$user = $app->getIdentity();

		if (!$user->guest)
		{
			if (!(bool) $params->get('track_authenticated', 1))
			{
				return false;
			}

			$excludedUserIds = $this->parseIntegerList((string) $params->get('excluded_user_ids', ''));

			if (\in_array((int) $user->id, $excludedUserIds, true))
			{
				return false;
			}
		}

		if (!$requirePageView)
		{
			return true;
		}

		$method = strtoupper($input->server->getString('REQUEST_METHOD', 'GET'));

		if ($method !== 'GET' || $input->getCmd('task', '') !== '')
		{
			return false;
		}

		$format = strtolower($input->getCmd('format', 'html'));

		return \in_array($format, ['', 'html'], true);
	}

	/**
	 * Inserts one event using privacy-minimal request context.
	 *
	 * @param Registry             $params          Component parameters.
	 * @param array<string,string> $eventData       Event-specific values.
	 * @param bool                 $includeReferrer Whether to store an external referrer.
	 *
	 * @return void
	 */
	private function insertEvent(Registry $params, array $eventData, bool $includeReferrer): void
	{
		$app = $this->getApplication();
		$input = $app->input;
		$ipAddress = $this->getClientIp((string) $params->get('trusted_ip_header', ''));
		$userAgent = substr($input->server->getString('HTTP_USER_AGENT', ''), 0, 2048);
		[$isBot, $botName] = $this->classifyBot($userAgent);
		$siteTimezone = (string) $app->get('offset', 'UTC');
		$localDate = new Date('now', $siteTimezone);
		$visitDate = $localDate->format('Y-m-d');
		$secret = (string) $app->get('secret', '');
		$visitorHash = substr(hash_hmac('sha256', $ipAddress . "\n" . $userAgent . "\n" . $visitDate, $secret), 0, 32);
		$currentUri = Uri::getInstance();

		$row = (object) [
			'visited_at' => Factory::getDate('now', 'UTC')->toSql(),
			'visit_date' => $visitDate,
			'visitor_hash' => $visitorHash,
			'path' => mb_substr((string) $eventData['path'], 0, 1024),
			'component' => mb_substr((string) $eventData['component'], 0, 100),
			'view_name' => mb_substr((string) $eventData['view_name'], 0, 100),
			'referrer_host' => $includeReferrer ? $this->getExternalReferrerHost($currentUri) : '',
			'country_code' => $this->getCountryCode(
				$ipAddress,
				(string) $params->get('country_detection', 'local_dbip'),
				(string) $params->get('trusted_country_header', 'HTTP_CF_IPCOUNTRY')
			),
			'language_code' => $this->getPrimaryLanguage(),
			'device_type' => $this->classifyDevice($userAgent, $isBot),
			'browser_family' => $this->classifyBrowser($userAgent),
			'is_authenticated' => $app->getIdentity()->guest ? 0 : 1,
			'is_bot' => $isBot ? 1 : 0,
			'bot_name' => mb_substr($botName, 0, 64),
			'event_type' => mb_substr((string) $eventData['event_type'], 0, 64),
			'item_type' => mb_substr((string) $eventData['item_type'], 0, 64),
			'item_id' => mb_substr((string) $eventData['item_id'], 0, 128),
			'item_title' => mb_substr((string) $eventData['item_title'], 0, 255),
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
		elseif (!$this->isPublicIp($candidate))
		{
			$forwarded = $input->server->getString('HTTP_X_FORWARDED_FOR', '');

			foreach (array_reverse(explode(',', $forwarded)) as $forwardedAddress)
			{
				$forwardedAddress = trim($forwardedAddress);

				if ($this->isPublicIp($forwardedAddress))
				{
					$candidate = $forwardedAddress;
					break;
				}
			}
		}

		return filter_var($candidate, FILTER_VALIDATE_IP) !== false ? $candidate : '0.0.0.0';
	}

	/**
	 * Returns whether an address is publicly routable.
	 *
	 * The automatic X-Forwarded-For fallback is used only when Joomla sees a
	 * private or reserved reverse-proxy address as REMOTE_ADDR. Sites whose
	 * public proxy address supplies a client-IP header must still explicitly
	 * configure that trusted header.
	 *
	 * @param string $ipAddress Candidate IP address.
	 *
	 * @return bool
	 */
	private function isPublicIp(string $ipAddress): bool
	{
		return filter_var(
			$ipAddress,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		) !== false;
	}

	/**
	 * Returns a two-letter country code without calling an external API.
	 *
	 * @param string $ipAddress  Visitor IP address.
	 * @param string $method     Detection method.
	 * @param string $headerName Trusted country server variable.
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

		if (\in_array($method, ['local_dbip', 'local_de'], true))
		{
			return (new CountryDatabaseMatcher())->lookup($ipAddress);
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
	 * Returns the public path of an internal referrer.
	 *
	 * @return string
	 */
	private function getInternalReferrerPath(): string
	{
		$referrer = $this->getApplication()->input->server->getString('HTTP_REFERER', '');

		if ($referrer === '')
		{
			return '';
		}

		$host = strtolower((string) parse_url($referrer, PHP_URL_HOST));
		$currentHost = strtolower((string) Uri::getInstance()->getHost());

		if ($host === '' || $host !== $currentHost)
		{
			return '';
		}

		$path = (string) parse_url($referrer, PHP_URL_PATH);

		return $path === '' ? '/' : '/' . ltrim($path, '/');
	}

	/**
	 * Sanitises a page path supplied with a custom event.
	 *
	 * @param string $value Supplied path or same-site URL.
	 *
	 * @return string
	 */
	private function normaliseEventPath(string $value): string
	{
		$value = trim($value);

		if ($value === '')
		{
			return '';
		}

		$host = (string) parse_url($value, PHP_URL_HOST);

		if ($host !== '' && strtolower($host) !== strtolower((string) Uri::getInstance()->getHost()))
		{
			return '';
		}

		$path = (string) parse_url($value, PHP_URL_PATH);

		if ($path === '')
		{
			$path = $value;
		}

		return '/' . ltrim($path, '/');
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
	 * Parses a comma-separated positive integer list.
	 *
	 * @param string $value Configured value.
	 *
	 * @return array<int, int>
	 */
	private function parseIntegerList(string $value): array
	{
		$result = [];

		foreach (preg_split('/[\s,;]+/', trim($value)) ?: [] as $item)
		{
			$id = (int) $item;

			if ($id > 0)
			{
				$result[] = $id;
			}
		}

		return array_values(array_unique($result));
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
	 * Occasionally archives and removes raw rows older than the retention period.
	 *
	 * @param int $retentionDays Retention in days.
	 * @param int $probability   Percentage chance per recorded event.
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

		(new StatisticsArchiveService($this->getDatabase()))->archiveExpired(
			$retentionDays,
			(string) $this->getApplication()->get('offset', 'UTC')
		);
	}

	/**
	 * Writes a non-fatal collection error to Joomla logging.
	 *
	 * @param \Throwable $exception Failure.
	 *
	 * @return void
	 */
	private function logFailure(\Throwable $exception): void
	{
		Log::add(
			'Simple Stats collection failed: ' . $exception->getMessage(),
			Log::WARNING,
			'plg_system_simplestats'
		);
	}
}

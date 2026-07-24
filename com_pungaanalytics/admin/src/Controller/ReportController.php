<?php

declare(strict_types=1);

namespace Punga\Component\PungaAnalytics\Administrator\Controller;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;

\defined('_JEXEC') or die;

/**
 * Full report actions.
 */
final class ReportController extends BaseController
{
	/**
	 * Downloads one complete report as a UTF-8 CSV file.
	 *
	 * @return void
	 */
	public function exportCsv(): void
	{
		if (!Session::checkToken('get'))
		{
			throw new \RuntimeException(Text::_('JINVALID_TOKEN'), 403);
		}

		$this->assertManagePermission();
		$allowedDays = [7, 30, 90, 365, 0];
		$requestedDays = $this->input->getInt('days', 30);
		$days = \in_array($requestedDays, $allowedDays, true) ? $requestedDays : 30;
		$report = strtolower($this->input->getCmd('report', 'pages'));
		$eventType = strtolower($this->input->getCmd('event_type', ''));
		$sort = strtolower($this->input->getCmd('sort', ''));
		$direction = strtolower($this->input->getCmd('direction', ''));

		/** @var \Punga\Component\PungaAnalytics\Administrator\Model\ReportModel $model */
		$model = $this->getModel('Report');

		if (!$model->isSupportedReport($report, $eventType))
		{
			throw new \InvalidArgumentException(Text::_('COM_PUNGAANALYTICS_REPORT_INVALID'), 404);
		}

		$data = $model->getExportData($report, $days, $sort, $direction, $eventType);
		$csv = $this->createCsv($data);

		$filename = sprintf(
			'punga-analytics-%s-%s-%s.csv',
			$report === 'event' ? 'event-' . str_replace('.', '-', $eventType) : $report,
			(string) $data['from'],
			(string) $data['to']
		);
		$app = Factory::getApplication();
		$app->setHeader('Content-Type', 'text/csv; charset=utf-8', true);
		$app->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"', true);
		$app->setHeader('Content-Length', (string) strlen($csv), true);
		$app->setHeader('Cache-Control', 'private, no-store, max-age=0', true);
		$app->sendHeaders();
		echo $csv;
		$app->close();
	}

	/**
	 * Downloads every core CSV and configured item-ranking CSV in one ZIP.
	 *
	 * @return void
	 */
	public function exportAllCsv(): void
	{
		if (!Session::checkToken('get'))
		{
			throw new \RuntimeException(Text::_('JINVALID_TOKEN'), 403);
		}

		$this->assertManagePermission();

		if (!class_exists(\ZipArchive::class))
		{
			throw new \RuntimeException(Text::_('COM_PUNGAANALYTICS_ZIP_UNAVAILABLE'));
		}

		$allowedDays = [7, 30, 90, 365, 0];
		$requestedDays = $this->input->getInt('days', 30);
		$days = \in_array($requestedDays, $allowedDays, true) ? $requestedDays : 30;

		/** @var \Punga\Component\PungaAnalytics\Administrator\Model\ReportModel $model */
		$model = $this->getModel('Report');
		$requests = [
			['activity', ''],
			['hours', ''],
			['weekdays', ''],
			['pages', ''],
			['countries', ''],
			['referrers', ''],
			['languages', ''],
			['devices', ''],
			['browsers', ''],
			['bots', ''],
			['events', ''],
		];
		$activityData = $model->getExportData('activity', $days, '', '');

		foreach ($activityData['customEventDefinitions'] ?? [] as $definition)
		{
			if ((bool) ($definition['show_ranking'] ?? false))
			{
				$requests[] = ['event', (string) $definition['event_type']];
			}
		}

		$temporaryPath = tempnam(JPATH_CACHE, 'punga-analytics-');

		if ($temporaryPath === false)
		{
			throw new \RuntimeException(Text::_('COM_PUNGAANALYTICS_ZIP_FAILED'));
		}

		$zip = new \ZipArchive();
		$opened = $zip->open($temporaryPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
		$zipIsOpen = $opened === true;

		if (!$zipIsOpen)
		{
			@unlink($temporaryPath);
			throw new \RuntimeException(Text::_('COM_PUNGAANALYTICS_ZIP_FAILED'));
		}

		try
		{
			foreach ($requests as [$report, $eventType])
			{
				$data = $report === 'activity' && $eventType === ''
					? $activityData
					: $model->getExportData($report, $days, '', '', $eventType);
				$identifier = $report === 'event'
					? 'event-' . $this->sanitiseFilenamePart($eventType)
					: $report;
				$filename = sprintf(
					'punga-analytics-%s-%s-%s.csv',
					$identifier,
					(string) $data['from'],
					(string) $data['to']
				);

				if (!$zip->addFromString($filename, $this->createCsv($data)))
				{
					throw new \RuntimeException(Text::_('COM_PUNGAANALYTICS_ZIP_FAILED'));
				}
			}

			$closed = $zip->close();
			$zipIsOpen = false;

			if (!$closed)
			{
				throw new \RuntimeException(Text::_('COM_PUNGAANALYTICS_ZIP_FAILED'));
			}

			$filename = sprintf(
				'punga-analytics-csv-%s-%s.zip',
				(string) $activityData['from'],
				(string) $activityData['to']
			);
			$size = filesize($temporaryPath);
			$app = Factory::getApplication();
			$app->setHeader('Content-Type', 'application/zip', true);
			$app->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"', true);

			if ($size !== false)
			{
				$app->setHeader('Content-Length', (string) $size, true);
			}

			$app->setHeader('Cache-Control', 'private, no-store, max-age=0', true);
			$app->sendHeaders();
			readfile($temporaryPath);
			@unlink($temporaryPath);
			$app->close();
		}
		catch (\Throwable $exception)
		{
			if ($zipIsOpen)
			{
				$zip->close();
			}

			@unlink($temporaryPath);
			throw $exception;
		}
	}

	/**
	 * Creates one complete UTF-8 CSV document.
	 *
	 * @param array<string, mixed> $data Report data.
	 *
	 * @return string
	 */
	private function createCsv(array $data): string
	{
		$stream = fopen('php://temp', 'w+b');

		if ($stream === false)
		{
			throw new \RuntimeException(Text::_('COM_PUNGAANALYTICS_CSV_FAILED'));
		}

		fwrite($stream, "\xEF\xBB\xBF");
		$this->writeCsvRows($stream, $data);
		rewind($stream);
		$csv = stream_get_contents($stream);
		fclose($stream);

		if ($csv === false)
		{
			throw new \RuntimeException(Text::_('COM_PUNGAANALYTICS_CSV_FAILED'));
		}

		return $csv;
	}

	/**
	 * Converts an event identifier into a safe filename segment.
	 *
	 * @param string $value Event identifier.
	 *
	 * @return string
	 */
	private function sanitiseFilenamePart(string $value): string
	{
		$safe = (string) preg_replace('/[^a-z0-9._-]+/', '-', strtolower($value));

		return trim($safe, '.-') ?: 'custom';
	}

	/**
	 * Writes report-specific CSV headings and rows.
	 *
	 * @param resource             $stream Writable stream.
	 * @param array<string, mixed> $data   Report data.
	 *
	 * @return void
	 */
	private function writeCsvRows($stream, array $data): void
	{
		$kind = (string) $data['kind'];

		if (\in_array($kind, ['trend', 'hour', 'weekday'], true))
		{
			$weekdayLabels = [
				1 => Text::_('COM_PUNGAANALYTICS_WEEKDAY_1'),
				2 => Text::_('COM_PUNGAANALYTICS_WEEKDAY_2'),
				3 => Text::_('COM_PUNGAANALYTICS_WEEKDAY_3'),
				4 => Text::_('COM_PUNGAANALYTICS_WEEKDAY_4'),
				5 => Text::_('COM_PUNGAANALYTICS_WEEKDAY_5'),
				6 => Text::_('COM_PUNGAANALYTICS_WEEKDAY_6'),
				7 => Text::_('COM_PUNGAANALYTICS_WEEKDAY_7'),
			];
			$definitionFlag = $kind === 'trend' ? 'show_trend' : 'show_time';
			$definitions = array_values(array_filter(
				$data['customEventDefinitions'] ?? [],
				static fn(array $definition): bool => (bool) ($definition[$definitionFlag] ?? false)
			));
			$headings = [
				match ($kind)
				{
					'hour' => Text::_('COM_PUNGAANALYTICS_HOUR'),
					'weekday' => Text::_('COM_PUNGAANALYTICS_WEEKDAY'),
					default => Text::_('COM_PUNGAANALYTICS_PERIOD'),
				},
				Text::_('COM_PUNGAANALYTICS_VISITS'),
				Text::_('COM_PUNGAANALYTICS_PAGEVIEWS'),
			];

			foreach ($definitions as $definition)
			{
				$headings[] = (string) $definition['title'];
			}

			$headings[] = Text::_('COM_PUNGAANALYTICS_BOTS');
			fputcsv($stream, $headings);

			foreach ($data['rows'] as $row)
			{
				$label = match ($kind)
				{
					'hour' => sprintf('%02d:00–%02d:00', (int) $row->bucket, ((int) $row->bucket + 1) % 24),
					'weekday' => $weekdayLabels[(int) $row->bucket] ?? (string) $row->bucket,
					default => (string) $row->period_label,
				};
				$values = [
					$label,
					(int) $row->visits,
					(int) $row->pageviews,
				];

				foreach ($definitions as $definition)
				{
					$values[] = (int) (($row->events ?? [])[(string) $definition['event_type']] ?? 0);
				}

				$values[] = (int) $row->bots;
				fputcsv($stream, $values);
			}

			return;
		}

		if ($kind === 'items')
		{
			fputcsv($stream, [
				Text::_('COM_PUNGAANALYTICS_CSV_TITLE'),
				Text::_('COM_PUNGAANALYTICS_CSV_ITEM_ID'),
				Text::_('COM_PUNGAANALYTICS_CSV_ITEM_TYPE'),
				Text::_('COM_PUNGAANALYTICS_CSV_PATH'),
				Text::_('COM_PUNGAANALYTICS_CSV_COUNT'),
			]);

			foreach ($data['rows'] as $row)
			{
				fputcsv($stream, [
					(string) $row->item_title,
					(string) $row->item_id,
					(string) $row->item_type,
					(string) $row->path,
					(int) $row->count,
				]);
			}

			return;
		}

		fputcsv($stream, [
			$kind === 'country' ? Text::_('COM_PUNGAANALYTICS_CSV_COUNTRY_CODE') : Text::_('COM_PUNGAANALYTICS_CSV_LABEL'),
			Text::_('COM_PUNGAANALYTICS_CSV_COUNT'),
		]);

		foreach ($data['rows'] as $row)
		{
			fputcsv($stream, [(string) $row->label, (int) $row->count]);
		}
	}

	/**
	 * Verifies component management permission.
	 *
	 * @return void
	 */
	private function assertManagePermission(): void
	{
		if (!Factory::getApplication()->getIdentity()->authorise('core.manage', 'com_pungaanalytics'))
		{
			throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}
	}
}

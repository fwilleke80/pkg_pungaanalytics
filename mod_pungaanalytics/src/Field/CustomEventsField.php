<?php

declare(strict_types=1);

namespace Punga\Module\PungaAnalytics\Administrator\Field;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\Registry\Registry;
use Punga\Component\PungaAnalytics\Administrator\Service\CustomEventDefinitionService;

\defined('_JEXEC') or die;

/**
 * Displays the globally configured Punga Analytics events as checkboxes.
 */
final class CustomEventsField extends FormField
{
	/** @var string */
	protected $type = 'CustomEvents';

	/**
	 * Builds the checkbox list.
	 *
	 * @return string
	 */
	protected function getInput(): string
	{
		$definitions = $this->getDefinitions();
		$selected = array_fill_keys($this->getSelectedEventTypes($definitions), true);
		$inputName = str_ends_with($this->name, '[]')
			? $this->name
			: $this->name . '[]';
		$baseName = preg_replace('/\[\]$/', '', $inputName) ?: $this->name;
		$html = [
			'<input type="hidden" name="' . $this->escape($baseName) . '" value="">',
		];

		if ($definitions === [])
		{
			$html[] = '<div class="form-text">'
				. $this->escape(Text::_('MOD_PUNGAANALYTICS_NO_CUSTOM_EVENTS'))
				. '</div>';

			return implode('', $html);
		}

		$html[] = '<div id="' . $this->escape($this->id) . '" class="d-grid gap-2">';

		foreach ($definitions as $index => $definition)
		{
			$eventType = (string) $definition['event_type'];
			$optionId = $this->id . '-' . $index;
			$checked = isset($selected[$eventType]) ? ' checked' : '';
			$disabled = $this->disabled ? ' disabled' : '';
			$label = Text::sprintf(
				'MOD_PUNGAANALYTICS_CUSTOM_EVENT_OPTION',
				(string) $definition['title'],
				$eventType
			);
			$html[] = '<div class="form-check">'
				. '<input class="form-check-input" type="checkbox"'
				. ' name="' . $this->escape($inputName) . '"'
				. ' id="' . $this->escape($optionId) . '"'
				. ' value="' . $this->escape($eventType) . '"'
				. $checked
				. $disabled
				. '>'
				. '<label class="form-check-label" for="' . $this->escape($optionId) . '">'
				. $this->escape($label)
				. '</label>'
				. '</div>';
		}

		$html[] = '</div>';

		return implode('', $html);
	}

	/**
	 * Returns all custom-event definitions from the component configuration.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function getDefinitions(): array
	{
		try
		{
			Factory::getApplication()->bootComponent('com_pungaanalytics');

			return (new CustomEventDefinitionService())->getDefinitions(
				ComponentHelper::getParams('com_pungaanalytics')
			);
		}
		catch (\Throwable)
		{
			return [];
		}
	}

	/**
	 * Returns the selected events, including the legacy Overview fallback.
	 *
	 * @param array<int, array<string, mixed>> $definitions Available definitions.
	 *
	 * @return array<int, string>
	 */
	private function getSelectedEventTypes(array $definitions): array
	{
		$data = $this->form->getData();
		$configured = (bool) $data->get('params.custom_events_configured', 0);

		if (!$configured)
		{
			if (!(bool) $data->get('params.show_custom_events', 1))
			{
				return [];
			}

			return array_values(array_map(
				static fn(array $definition): string => (string) $definition['event_type'],
				array_filter(
					$definitions,
					static fn(array $definition): bool => (bool) ($definition['show_summary'] ?? false)
				)
			));
		}

		$value = $this->value;

		if ($value instanceof Registry)
		{
			$value = $value->toArray();
		}
		elseif (\is_object($value))
		{
			$value = get_object_vars($value);
		}
		elseif (\is_string($value))
		{
			$decoded = json_decode($value, true);
			$value = \is_array($decoded) ? $decoded : ($value === '' ? [] : [$value]);
		}

		if (!\is_array($value))
		{
			return [];
		}

		$available = array_fill_keys(
			array_map(
				static fn(array $definition): string => (string) $definition['event_type'],
				$definitions
			),
			true
		);
		$selected = [];

		foreach ($value as $eventType)
		{
			$eventType = strtolower(trim((string) $eventType));

			if (isset($available[$eventType]))
			{
				$selected[$eventType] = true;
			}
		}

		return array_keys($selected);
	}

	/**
	 * Escapes text for HTML attributes and content.
	 *
	 * @param mixed $value Value to escape.
	 *
	 * @return string
	 */
	private function escape(mixed $value): string
	{
		return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}

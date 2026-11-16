/**
 * Synchronises the Joomla dashboard and child tabs with the dashboard URL.
 *
 * @return {void}
 */
const initialisePungaAnalyticsDashboardTabs = () =>
{
	const dashboardTabs = document.getElementById('pungaAnalyticsDashboardTabs');

	const dashboardViews = {
		'pa-dashboard-overview': 'overview',
		'pa-dashboard-traffic': 'traffic',
		'pa-dashboard-content': 'content',
		'pa-dashboard-audience': 'audience',
		'pa-dashboard-quality': 'quality',
		'pa-dashboard-system': 'system',
	};
	const childTabs = {
		traffic: {
			element: document.getElementById('pungaAnalyticsTrafficTabs'),
			parameter: 'trafficview',
			defaultView: 'activity',
			views: {
				'pa-traffic-activity': 'activity',
				'pa-traffic-sessions': 'sessions',
				'pa-traffic-acquisition': 'acquisition',
			},
		},
		content: {
			element: document.getElementById('pungaAnalyticsContentTabs'),
			parameter: 'contentview',
			defaultView: 'pages',
			views: {
				'pa-content-pages': 'pages',
				'pa-content-engagement': 'engagement',
				'pa-content-events': 'events',
			},
		},
		audience: {
			element: document.getElementById('pungaAnalyticsAudienceTabs'),
			parameter: 'audienceview',
			defaultView: 'countries',
			views: {
				'pa-audience-countries': 'countries',
				'pa-audience-networks': 'networks',
				'pa-audience-languages': 'languages',
				'pa-audience-devices': 'devices',
				'pa-audience-browsers': 'browsers',
			},
		},
		quality: {
			element: document.getElementById('pungaAnalyticsQualityTabs'),
			parameter: 'qualityview',
			defaultView: 'automated',
			views: {
				'pa-quality-automated': 'automated',
				'pa-quality-bots': 'bots',
			},
		},
	};
	const currentUrl = new URL(window.location.href);
	let dashboardView = currentUrl.searchParams.get('dashboardview') || 'overview';
	const selectedChildViews = {};

	Object.entries(childTabs).forEach(([view, definition]) =>
	{
		selectedChildViews[view] = currentUrl.searchParams.get(definition.parameter)
			|| definition.defaultView;
	});

	/**
	 * Stores the selected dashboard state in a URL.
	 *
	 * @param {URL} url URL to update.
	 *
	 * @return {void}
	 */
	const applyStateToUrl = (url) =>
	{
		url.searchParams.set('dashboardview', dashboardView);

		Object.entries(childTabs).forEach(([view, definition]) =>
		{
			if (dashboardView === view)
			{
				url.searchParams.set(definition.parameter, selectedChildViews[view]);
			}
			else
			{
				url.searchParams.delete(definition.parameter);
			}
		});
	};

	/**
	 * Stores the selected view in the current URL without reloading the page.
	 *
	 * @return {void}
	 */
	const updateCurrentUrl = () =>
	{
		const url = new URL(window.location.href);
		applyStateToUrl(url);
		window.history.replaceState({}, '', url);
	};

	if (dashboardTabs)
	{
		dashboardTabs.addEventListener('click', (event) =>
		{
			const button = event.target.closest('button[aria-controls]');

			if (!button || button.closest('joomla-tab') !== dashboardTabs)
			{
				return;
			}

			const selectedView = dashboardViews[button.getAttribute('aria-controls')];

			if (!selectedView)
			{
				return;
			}

			dashboardView = selectedView;
			updateCurrentUrl();
		});
	}

	Object.entries(childTabs).forEach(([parentView, definition]) =>
	{
		if (!definition.element)
		{
			return;
		}

		definition.element.addEventListener('click', (event) =>
		{
			const button = event.target.closest('button[aria-controls]');

			if (!button || button.closest('joomla-tab') !== definition.element)
			{
				return;
			}

			const selectedView = definition.views[button.getAttribute('aria-controls')];

			if (!selectedView)
			{
				return;
			}

			dashboardView = parentView;
			selectedChildViews[parentView] = selectedView;
			updateCurrentUrl();
		});
	});

	document.querySelectorAll('[data-pa-dashboard-task]').forEach((button) =>
	{
		button.addEventListener('click', () =>
		{
			const confirmation = button.dataset.paConfirm || '';

			if (confirmation !== '' && !window.confirm(confirmation))
			{
				return;
			}

			Joomla.submitbutton(button.dataset.paDashboardTask);
		});
	});

	document.querySelectorAll('.pa-range-picker__menu a').forEach((link) =>
	{
		link.addEventListener('click', () =>
		{
			const url = new URL(link.href);
			applyStateToUrl(url);
			link.href = url.toString();
		});
	});
};

if (document.readyState === 'loading')
{
	document.addEventListener('DOMContentLoaded', initialisePungaAnalyticsDashboardTabs);
}
else
{
	initialisePungaAnalyticsDashboardTabs();
}

/**
 * Synchronises the native Joomla dashboard tabs with the dashboard URL.
 *
 * @return {void}
 */
const initialisePungaAnalyticsDashboardTabs = () =>
{
	const dashboardTabs = document.getElementById('pungaAnalyticsDashboardTabs');
	const audienceTabs = document.getElementById('pungaAnalyticsAudienceTabs');

	if (!dashboardTabs)
	{
		return;
	}

	const dashboardViews = {
		'pa-dashboard-overview': 'overview',
		'pa-dashboard-traffic': 'traffic',
		'pa-dashboard-content': 'content',
		'pa-dashboard-engagement': 'engagement',
		'pa-dashboard-acquisition': 'acquisition',
		'pa-dashboard-audience': 'audience',
		'pa-dashboard-system': 'system',
	};
	const audienceViews = {
		'pa-audience-countries': 'countries',
		'pa-audience-languages': 'languages',
		'pa-audience-devices': 'devices',
		'pa-audience-browsers': 'browsers',
		'pa-audience-bots': 'bots',
		'pa-audience-events': 'events',
	};
	const currentUrl = new URL(window.location.href);
	let dashboardView = currentUrl.searchParams.get('dashboardview') || 'overview';
	let audienceView = currentUrl.searchParams.get('audienceview') || 'countries';

	/**
	 * Stores the selected view in the current URL without reloading the page.
	 *
	 * @return {void}
	 */
	const updateCurrentUrl = () =>
	{
		const url = new URL(window.location.href);
		url.searchParams.set('dashboardview', dashboardView);

		if (dashboardView === 'audience')
		{
			url.searchParams.set('audienceview', audienceView);
		}
		else
		{
			url.searchParams.delete('audienceview');
		}

		window.history.replaceState({}, '', url);
	};

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

	if (audienceTabs)
	{
		audienceTabs.addEventListener('click', (event) =>
		{
			const button = event.target.closest('button[aria-controls]');

			if (!button || button.closest('joomla-tab') !== audienceTabs)
			{
				return;
			}

			const selectedView = audienceViews[button.getAttribute('aria-controls')];

			if (!selectedView)
			{
				return;
			}

			dashboardView = 'audience';
			audienceView = selectedView;
			updateCurrentUrl();
		});
	}

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
			url.searchParams.set('dashboardview', dashboardView);

			if (dashboardView === 'audience')
			{
				url.searchParams.set('audienceview', audienceView);
			}
			else
			{
				url.searchParams.delete('audienceview');
			}

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

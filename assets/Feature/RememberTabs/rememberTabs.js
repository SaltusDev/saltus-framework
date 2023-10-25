saltusRememberTabs = {};

/**
 * Try to initialize remember tab feature
 */
saltusRememberTabs.init = function () {
	try {
		saltusRememberTabs.rememberTabInit();
	} catch (err) {
		console.log('Error: will not remember tab;');
	}
}

/**
 * Simulates click on tab to open it
 * @param {int} index tab that needs to be opened
 */
saltusRememberTabs.hitTab = function (index) {
	setTimeout(function () {
		let tab = document.querySelectorAll('.csf-nav-metabox ul li a');
		if (tab) {
			tab[index].click();
		}
	}, 1000);
}

/**
 * Creates the logic to check if we need to open a tab
 * @returns 
 */
saltusRememberTabs.rememberTabInit = function () {

	// check if URL contains tab parameter
	let referer = document.querySelector('#referredby');
	let refererUrl = new URL(window.location.origin + referer.value);

	if (typeof refererUrl.origin === 'undefined') {
		return;
	}
	if (refererUrl.searchParams.get('tab')) {
		saltusRememberTabs.hitTab(refererUrl.searchParams.get('tab'));
	} else {
		let currentUrl = new URL(window.location.href);
		if (currentUrl.searchParams.get('tab')) {
			saltusRememberTabs.hitTab(currentUrl.searchParams.get('tab'));
		}
	}
	// currently considers all tabs on page
	let tabs = document.querySelectorAll('.csf-nav-metabox ul li');
	let currentURL = window.location.href;
	let url = new URL(currentURL);
	let search_params = url.searchParams;

	tabs.forEach(function (tab, index) {
		tab.addEventListener('click', function () {
			search_params.set('tab', index);
			const nextTitle = document.title;
			const nextState = {
				additionalInformation: 'Updated the URL with JS'
			};
			window.history.replaceState(nextState, nextTitle, url.toString());
		});
	});
}

saltusRememberTabs.init();
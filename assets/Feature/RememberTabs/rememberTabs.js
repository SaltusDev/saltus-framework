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
		if (tab && tab[index]) {
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
	const referer = document.querySelector('#referredby');

	let tabIndex = null;
	if ( referer && referer.value ) {
		try {
			let refererUrl = new URL(window.location.origin + referer.value);
			tabIndex = refererUrl.searchParams.get('tab');
		} catch (e) {
			// Invalid referer, ignore
		}
	}

	let currentUrl = new URL(window.location.href);
	if ( ! tabIndex ) {
		tabIndex = currentUrl.searchParams.get('tab');
	}
	if ( tabIndex ) {
		saltusRememberTabs.hitTab( parseInt(tabIndex, 10 ) );
	}

	// currently considers all tabs on page
	let tabs = document.querySelectorAll('.csf-nav-metabox ul li');
	let search_params = currentUrl.searchParams;

	tabs.forEach(function (tab, index) {
		tab.addEventListener('click', function () {
			search_params.set('tab', index);
			const nextTitle = document.title;
			const nextState = {
				additionalInformation: 'Updated the URL with JS'
			};
			window.history.replaceState(nextState, nextTitle, currentUrl.toString());
		});
	});
}

saltusRememberTabs.init();
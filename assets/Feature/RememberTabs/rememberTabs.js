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
			let refererUrl = new URL(referer.value, window.location.href);
			tabIndex = refererUrl.searchParams.get('tab');
		} catch (e) {
			// Invalid referer, ignore
		}
	}

	let currentUrl = new URL(window.location.href);
	if ( tabIndex === null ) {
		tabIndex = currentUrl.searchParams.get('tab');
	}

	if ( tabIndex ) {
		tabIndex = Number( tabIndex );
		if ( ! isNaN( tabIndex ) ) {
			saltusRememberTabs.hitTab( tabIndex );
		}
	}
}


saltusRememberTabs.attachTabListeners = function () {
	let tabs = document.querySelectorAll('.csf-nav-metabox ul li');

	tabs.forEach(function (tab, index) {
		tab.addEventListener('click', function () {

			const currentUrl = new URL(window.location.href);
			const search_params = currentUrl.searchParams;
			search_params.set('tab', index);

			const nextTitle = document.title;
			const nextState = {
				additionalInformation: 'Updated the URL with JS'
			};
			window.history.replaceState(nextState, nextTitle, currentUrl.toString());
		});
	});
};


// Run on DOM ready
document.addEventListener('DOMContentLoaded', function () {

	// Initialize the feature
	saltusRememberTabs.init();

	// Attach listeners to tabs
	saltusRememberTabs.attachTabListeners();
})
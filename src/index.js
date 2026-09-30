import { createRoot, useState, useEffect } from '@wordpress/element';
import { __, setLocaleData, getLocale } from '@wordpress/i18n';
import Dashboard from './pages/Dashboard';
import Geo from './pages/Geo';
import Generate from './pages/Generate';
import Keywords from './pages/Keywords';
import Queue from './pages/Queue';
import Opportunities from './pages/Opportunities';
import Settings from './pages/Settings';
import './style.scss';

const ROUTE_KEYS = [
	{ hash: '#/dashboard', key: 'Dashboard' },
	{ hash: '#/geo', key: 'GEO' },
	{ hash: '#/generate', key: 'Generate' },
	{ hash: '#/keywords', key: 'Keywords' },
	{ hash: '#/queue', key: 'Queue' },
	{ hash: '#/opportunities', key: 'Opportunities' },
	{ hash: '#/settings', key: 'Settings' },
];

const getRoute = () =>
	ROUTE_KEYS.some( ( r ) => r.hash === window.location.hash )
		? window.location.hash
		: '#/dashboard';

const App = () => {
	const [ route, setRoute ] = useState( getRoute() );

	useEffect( () => {
		const onHashChange = () => setRoute( getRoute() );
		window.addEventListener( 'hashchange', onHashChange );
		return () => window.removeEventListener( 'hashchange', onHashChange );
	}, [] );

	return (
		<div className="scp-app">
			<div className="scp-header">
				<h1>SEO Content Pilot</h1>
				<nav className="scp-nav">
					{ ROUTE_KEYS.map( ( { hash, key } ) => (
						<a
							key={ hash }
							href={ hash }
							className={ route === hash ? 'is-active' : '' }
						>
							{ __( key, 'seo-content-pilot' ) }
						</a>
					) ) }
				</nav>
			</div>
			{ route === '#/dashboard' && <Dashboard /> }
			{ route === '#/geo' && <Geo /> }
			{ route === '#/settings' && <Settings /> }
			{ route === '#/keywords' && <Keywords /> }
			{ route === '#/queue' && <Queue /> }
			{ route === '#/opportunities' && <Opportunities /> }
			{ route === '#/generate' && <Generate /> }
		</div>
	);
};

const container = document.getElementById( 'scp-root' );
if ( container ) {
	// Resolve the active WP locale (e.g. "zh_CN") and load the matching
	// JED data built by scripts/build-translations.js. Falls back silently
	// to the source strings when no locale file is bundled.
	const locale = getLocale();
	const normalized = ( locale || 'en' ).replace( /_/g, '-' );

	import( /* webpackChunkName: "scp-i18n-[request]" */ `./languages/${ normalized }.js` )
		.then( ( mod ) => {
			if ( mod && mod.default ) {
				setLocaleData( mod.default, 'seo-content-pilot' );
			}
		} )
		.catch( () => {
			// No translation for this locale — keep source strings.
		} )
		.finally( () => {
			createRoot( container ).render( <App /> );
		} );
}

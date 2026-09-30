import { createRoot, useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import Dashboard from './pages/Dashboard';
import Geo from './pages/Geo';
import Generate from './pages/Generate';
import Keywords from './pages/Keywords';
import Queue from './pages/Queue';
import Opportunities from './pages/Opportunities';
import Settings from './pages/Settings';
import './style.scss';

const ROUTES = {
	'#/dashboard': __( 'Dashboard', 'seo-content-pilot' ),
	'#/geo': __( 'GEO', 'seo-content-pilot' ),
	'#/generate': __( 'Generate', 'seo-content-pilot' ),
	'#/keywords': __( 'Keywords', 'seo-content-pilot' ),
	'#/queue': __( 'Queue', 'seo-content-pilot' ),
	'#/opportunities': __( 'Opportunities', 'seo-content-pilot' ),
	'#/settings': __( 'Settings', 'seo-content-pilot' ),
};

const getRoute = () =>
	Object.keys( ROUTES ).includes( window.location.hash )
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
					{ Object.entries( ROUTES ).map( ( [ hash, label ] ) => (
						<a
							key={ hash }
							href={ hash }
							className={ route === hash ? 'is-active' : '' }
						>
							{ label }
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
	createRoot( container ).render( <App /> );
}

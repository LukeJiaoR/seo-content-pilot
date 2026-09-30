import apiFetch from '@wordpress/api-fetch';

const { root, nonce } = window.scpAdmin || {};

if ( root ) {
	apiFetch.use( apiFetch.createRootURLMiddleware( root ) );
}
if ( nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );
}

export const getSettings = () => apiFetch( { path: '/settings' } );

export const saveSettings = ( data ) =>
	apiFetch( {
		path: '/settings',
		method: 'POST',
		data,
	} );

export const generateArticle = ( keyword ) =>
	apiFetch( {
		path: '/generate',
		method: 'POST',
		data: { keyword },
	} );

export const getKeywords = ( status = '' ) =>
	apiFetch( { path: `/keywords?per_page=200${ status ? `&status=${ status }` : '' }` } );

export const expandSeeds = ( seeds ) =>
	apiFetch( {
		path: '/keywords/expand',
		method: 'POST',
		data: { seeds },
	} );

export const fetchMetrics = ( ids ) =>
	apiFetch( {
		path: '/keywords/metrics',
		method: 'POST',
		data: { ids },
	} );

export const getProviders = () => apiFetch( { path: '/keywords/providers' } );

export const addKeywords = ( keywords, extra = {} ) =>
	apiFetch( {
		path: '/keywords',
		method: 'POST',
		data: { keywords, ...extra },
	} );

export const deleteKeywords = ( ids ) =>
	apiFetch( {
		path: '/keywords',
		method: 'DELETE',
		data: { ids },
	} );

export const getQueue = () => apiFetch( { path: '/queue' } );

export const enqueueKeywords = ( keyword_ids ) =>
	apiFetch( {
		path: '/queue',
		method: 'POST',
		data: { keyword_ids },
	} );

export const retryJob = ( id ) =>
	apiFetch( {
		path: `/queue/${ id }/retry`,
		method: 'POST',
	} );

// GSC
export const getGscStatus = () => apiFetch( { path: '/gsc/status' } );

export const getGscConnectUrl = () => apiFetch( { path: '/gsc/connect' } );

export const getGscSites = () => apiFetch( { path: '/gsc/sites' } );

export const saveGscSite = ( site ) =>
	apiFetch( {
		path: '/gsc/site',
		method: 'POST',
		data: { site },
	} );

export const disconnectGsc = () =>
	apiFetch( { path: '/gsc/disconnect', method: 'POST' } );

export const syncGscNow = () =>
	apiFetch( { path: '/gsc/sync', method: 'POST' } );

export const getGscOverview = ( range = '28d' ) =>
	apiFetch( { path: `/gsc/overview?range=${ range }` } );

export const getGscOpportunities = () =>
	apiFetch( { path: '/gsc/opportunities' } );

// GEO
export const getGeoOverview = () => apiFetch( { path: '/geo/overview' } );

export const runAioCheck = ( keyword_ids = [] ) =>
	apiFetch( {
		path: '/geo/aio/run',
		method: 'POST',
		data: { keyword_ids },
	} );

export const getGeoTracking = () => apiFetch( { path: '/geo/tracking' } );

export const setGeoTracking = ( keyword_ids, track ) =>
	apiFetch( {
		path: '/geo/tracking',
		method: 'POST',
		data: { keyword_ids, track },
	} );

export const getGeoChecks = ( engine = '' ) =>
	apiFetch( { path: `/geo/checks?limit=50${ engine ? `&engine=${ engine }` : '' }` } );

export const runGeoCheck = ( keyword_ids = [], engines = [] ) =>
	apiFetch( {
		path: '/geo/run',
		method: 'POST',
		data: { keyword_ids, engines },
	} );

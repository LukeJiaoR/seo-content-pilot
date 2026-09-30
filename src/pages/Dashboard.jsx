import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Notice, SelectControl, Spinner } from '@wordpress/components';
import { getGscStatus, getGscOverview, getGeoOverview, runAioCheck, syncGscNow } from '../api';

const pct = ( v ) => `${ ( Number( v || 0 ) * 100 ).toFixed( 2 ) }%`;
const num = ( v ) => Number( v || 0 ).toLocaleString();

/** Minimal inline SVG line chart (no chart lib). */
const LineChart = ( { data, field, color = '#2271b1', height = 120 } ) => {
	if ( ! data || data.length < 2 ) {
		return <p className="scp-key-hint">{ __( 'Not enough data yet.', 'seo-content-pilot' ) }</p>;
	}
	const width = 600;
	const values = data.map( ( d ) => Number( d[ field ] || 0 ) );
	const max = Math.max( ...values, 1 );
	const step = width / ( data.length - 1 );
	const points = values
		.map( ( v, i ) => `${ ( i * step ).toFixed( 1 ) },${ ( height - 8 - ( v / max ) * ( height - 20 ) ).toFixed( 1 ) }` )
		.join( ' ' );
	return (
		<svg viewBox={ `0 0 ${ width } ${ height }` } style={ { width: '100%', height } }>
			<polyline fill="none" stroke={ color } strokeWidth="2" points={ points } />
		</svg>
	);
};

const StatCard = ( { label, value, prev, invert = false } ) => {
	const delta =
		prev !== undefined && Number( prev ) !== 0
			? ( ( Number( value ) - Number( prev ) ) / Math.abs( Number( prev ) ) ) * 100
			: null;
	const good = delta !== null && ( invert ? delta < 0 : delta > 0 );
	return (
		<div className="scp-stat">
			<div className="scp-stat-label">{ label }</div>
			<div className="scp-stat-value">{ value }</div>
			{ delta !== null && (
				<div style={ { color: good ? '#00a32a' : '#b32d2e', fontSize: 12 } }>
					{ delta > 0 ? '▲' : '▼' } { Math.abs( delta ).toFixed( 1 ) }%
				</div>
			) }
		</div>
	);
};

const Dashboard = () => {
	const [ status, setStatus ] = useState( null );
	const [ range, setRange ] = useState( '28d' );
	const [ overview, setOverview ] = useState( null );
	const [ geo, setGeo ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( '' );

	const load = () => {
		getGscStatus().then( setStatus ).catch( () => {} );
		getGeoOverview().then( setGeo ).catch( () => {} );
	};

	useEffect( () => {
		load();
	}, [] );

	useEffect( () => {
		getGscOverview( range ).then( setOverview ).catch( ( err ) =>
			setNotice( { status: 'error', message: err.message } )
		);
	}, [ range ] );

	const onSync = () => {
		setBusy( 'sync' );
		syncGscNow()
			.then( () =>
				setNotice( {
					status: 'success',
					message: __( 'Sync scheduled — data appears within a minute.', 'seo-content-pilot' ),
				} )
			)
			.catch( ( err ) => setNotice( { status: 'error', message: err.message } ) )
			.finally( () => setBusy( '' ) );
	};

	const onAioRun = () => {
		setBusy( 'aio' );
		runAioCheck()
			.then( ( res ) => {
				setNotice( {
					status: 'success',
					message: `${ res.checked } ${ __( 'checked', 'seo-content-pilot' ) }, ${ res.with_aio } ${ __( 'had an AI Overview', 'seo-content-pilot' ) }, ${ res.cited } ${ __( 'cited you', 'seo-content-pilot' ) }`,
				} );
				load();
			} )
			.catch( ( err ) => setNotice( { status: 'error', message: err.message } ) )
			.finally( () => setBusy( '' ) );
	};

	if ( status && ! status.connected ) {
		return (
			<div className="scp-card">
				<h2>{ __( 'Connect Google Search Console', 'seo-content-pilot' ) }</h2>
				<p>
					{ __( 'The dashboard needs GSC data. Connect via Site Kit or your own Google OAuth app in', 'seo-content-pilot' ) }{ ' ' }
					<a href="#/settings">{ __( 'Settings', 'seo-content-pilot' ) }</a>.
				</p>
			</div>
		);
	}

	const t = overview?.totals;
	const p = overview?.prev_totals;

	return (
		<>
			{ notice && (
				<Notice { ...notice } onRemove={ () => setNotice( null ) } />
			) }

			<div className="scp-card">
				<h2>
					{ __( 'Site performance', 'seo-content-pilot' ) }
					<span style={ { float: 'right', display: 'flex', gap: 8, alignItems: 'center' } }>
						<SelectControl
							value={ range }
							options={ [
								{ value: '28d', label: __( 'Last 28 days', 'seo-content-pilot' ) },
								{ value: '3m', label: __( 'Last 3 months', 'seo-content-pilot' ) },
							] }
							onChange={ setRange }
						/>
						<Button variant="secondary" onClick={ onSync } isBusy={ busy === 'sync' }>
							{ __( 'Sync now', 'seo-content-pilot' ) }
						</Button>
					</span>
				</h2>
				{ status?.site && (
					<p className="scp-key-hint">
						{ status.site } · { status.via === 'sitekit' ? __( 'via Site Kit', 'seo-content-pilot' ) : __( 'own OAuth', 'seo-content-pilot' ) }
						{ status.last_sync && ` · ${ __( 'last sync', 'seo-content-pilot' ) }: ${ status.last_sync }` }
						{ ` · ${ num( status.rows ) } ${ __( 'rows cached', 'seo-content-pilot' ) }` }
					</p>
				) }
				{ ! overview ? (
					<Spinner />
				) : (
					<>
						<div className="scp-stats">
							<StatCard label={ __( 'Clicks', 'seo-content-pilot' ) } value={ num( t?.clicks ) } prev={ p?.clicks } />
							<StatCard label={ __( 'Impressions', 'seo-content-pilot' ) } value={ num( t?.impressions ) } prev={ p?.impressions } />
							<StatCard label="CTR" value={ pct( t?.ctr ) } prev={ p?.ctr } />
							<StatCard label={ __( 'Avg. position', 'seo-content-pilot' ) } value={ Number( t?.position || 0 ).toFixed( 1 ) } prev={ p?.position } invert />
						</div>
						<LineChart data={ overview.daily } field="clicks" />
						<LineChart data={ overview.daily } field="impressions" color="#94660a" height={ 90 } />
					</>
				) }
			</div>

			{ overview?.ai_pages?.length > 0 && (
				<div className="scp-card">
					<h2>{ __( 'AI-generated articles', 'seo-content-pilot' ) }</h2>
					<table className="widefat striped">
						<thead>
							<tr>
								<th>{ __( 'Article', 'seo-content-pilot' ) }</th>
								<th>{ __( 'Clicks', 'seo-content-pilot' ) }</th>
								<th>{ __( 'Impressions', 'seo-content-pilot' ) }</th>
								<th>CTR</th>
								<th>{ __( 'Position', 'seo-content-pilot' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ overview.ai_pages.map( ( row ) => (
								<tr key={ row.post_id }>
									<td>
										<a href={ `${ window.scpAdmin.editBase }?post=${ row.post_id }&action=edit` }>
											{ row.title }
										</a>
									</td>
									<td>{ num( row.clicks ) }</td>
									<td>{ num( row.impressions ) }</td>
									<td>{ pct( row.ctr ) }</td>
									<td>{ row.position }</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }

			{ geo?.aio && (
				<div className="scp-card">
					<h2>
						{ __( 'Google AI Overview citations', 'seo-content-pilot' ) }
						<Button variant="link" onClick={ onAioRun } isBusy={ busy === 'aio' } style={ { marginLeft: 12 } }>
							{ __( 'Run check now', 'seo-content-pilot' ) }
						</Button>
					</h2>
					{ geo.aio.keywords_checked === 0 ? (
						<p>{ __( 'No AIO checks yet. They run weekly for published keywords, or trigger one above.', 'seo-content-pilot' ) }</p>
					) : (
						<>
							<p>
								{ __( 'Cited', 'seo-content-pilot' ) }: <strong>{ geo.aio.cited }</strong> / { geo.aio.with_aio }{ ' ' }
								{ __( 'keywords with an AI Overview', 'seo-content-pilot' ) }
								{ geo.aio.citation_rate !== null && ` (${ pct( geo.aio.citation_rate ) })` }
								<span className="scp-key-hint">
									{ ' · ' }{ __( 'sample', 'seo-content-pilot' ) }: { geo.aio.keywords_checked } { __( 'keywords checked', 'seo-content-pilot' ) }
								</span>
							</p>
							{ geo.aio.recent?.length > 0 && (
								<table className="widefat striped">
									<thead>
										<tr>
											<th>{ __( 'Keyword', 'seo-content-pilot' ) }</th>
											<th>{ __( 'Checked', 'seo-content-pilot' ) }</th>
											<th>AIO</th>
											<th>{ __( 'Cited page', 'seo-content-pilot' ) }</th>
										</tr>
									</thead>
									<tbody>
										{ geo.aio.recent.map( ( r, i ) => (
											<tr key={ i }>
												<td>{ r.keyword }</td>
												<td>{ r.checked_at }</td>
												<td>{ Number( r.has_aio ) ? '✓' : '—' }</td>
												<td>
													{ Number( r.cited ) && r.cited_url ? (
														<a href={ r.cited_url } target="_blank" rel="noreferrer">
															{ r.cited_url }
														</a>
													) : (
														'—'
													) }
												</td>
											</tr>
										) ) }
									</tbody>
								</table>
							) }
						</>
					) }
				</div>
			) }

			{ overview?.brand_daily && overview.brand_daily.length > 0 && (
				<div className="scp-card">
					<h2>{ __( 'Brand search trend', 'seo-content-pilot' ) }</h2>
					<p className="scp-key-hint">
						{ __( 'Brand impressions are a lagging indicator of AI citations (people search your brand after seeing it in AI answers).', 'seo-content-pilot' ) }
					</p>
					<LineChart data={ overview.brand_daily } field="impressions" color="#7c3aed" height={ 90 } />
				</div>
			) }
		</>
	);
};

export default Dashboard;

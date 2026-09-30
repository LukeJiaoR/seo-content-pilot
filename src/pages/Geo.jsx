import { useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Notice, Spinner, SelectControl } from '@wordpress/components';
import {
	getGeoOverview,
	getGeoTracking,
	setGeoTracking,
	getGeoChecks,
	runGeoCheck,
} from '../api';

const pct = ( v ) =>
	v === null || v === undefined ? '—' : `${ ( Number( v ) * 100 ).toFixed( 1 ) }%`;

const ENGINE_LABELS = {
	chatgpt: 'ChatGPT',
	gemini: 'Gemini',
	perplexity: 'Perplexity',
};

const Geo = () => {
	const [ overview, setOverview ] = useState( null );
	const [ tracking, setTracking ] = useState( null );
	const [ checks, setChecks ] = useState( [] );
	const [ engineFilter, setEngineFilter ] = useState( '' );
	const [ selected, setSelected ] = useState( new Set() );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( '' );

	const refresh = useCallback( () => {
		getGeoOverview().then( setOverview ).catch( () => {} );
		getGeoTracking().then( setTracking ).catch( () => {} );
	}, [] );

	useEffect( () => {
		refresh();
	}, [ refresh ] );

	useEffect( () => {
		getGeoChecks( engineFilter )
			.then( ( res ) => setChecks( res.items || [] ) )
			.catch( () => {} );
	}, [ engineFilter ] );

	const toggle = ( id ) =>
		setSelected( ( prev ) => {
			const next = new Set( prev );
			if ( next.has( id ) ) {
				next.delete( id );
			} else {
				next.add( id );
			}
			return next;
		} );

	const applyTracking = ( track ) => {
		if ( selected.size === 0 || busy ) {
			return;
		}
		setBusy( 'tracking' );
		setNotice( null );
		setGeoTracking( [ ...selected ], track )
			.then( () => {
				setSelected( new Set() );
				refresh();
			} )
			.catch( ( err ) => setNotice( { status: 'error', message: err.message } ) )
			.finally( () => setBusy( '' ) );
	};

	const runNow = () => {
		setBusy( 'run' );
		setNotice( null );
		runGeoCheck( selected.size ? [ ...selected ] : [] )
			.then( ( res ) => {
				setNotice( {
					status: 'success',
					message: `${ res.checked } ${ __( 'checks done', 'seo-content-pilot' ) } · ${ res.mentioned } ${ __( 'mentioned', 'seo-content-pilot' ) } · ${ res.cited } ${ __( 'cited', 'seo-content-pilot' ) }${ res.skipped.length ? ` · ${ res.skipped.join( ', ' ) }` : '' }`,
				} );
				refresh();
				getGeoChecks( engineFilter ).then( ( res2 ) => setChecks( res2.items || [] ) );
			} )
			.catch( ( err ) => setNotice( { status: 'error', message: err.message } ) )
			.finally( () => setBusy( '' ) );
	};

	if ( ! overview || ! tracking ) {
		return (
			<div className="scp-card">
				<Spinner />
			</div>
		);
	}

	const pt = overview.prompt_tracking || {};
	const engineStats = pt.engines || {};

	return (
		<>
			{ notice && (
				<Notice { ...notice } onRemove={ () => setNotice( null ) } />
			) }

			<div className="scp-card">
				<h2>{ __( 'AI visibility by engine (last 28 days)', 'seo-content-pilot' ) }</h2>
				{ overview.engines.length === 0 ? (
					<p>
						{ __( 'Add at least one AI engine API key (OpenAI / Gemini / Perplexity) in', 'seo-content-pilot' ) }{ ' ' }
						<a href="#/settings">{ __( 'Settings', 'seo-content-pilot' ) }</a>.
					</p>
				) : Object.keys( engineStats ).length === 0 ? (
					<p>
						{ __( 'No checks yet. Track keywords below — the weekly task runs automatically, or use "Run check now".', 'seo-content-pilot' ) }
					</p>
				) : (
					<table className="widefat striped">
						<thead>
							<tr>
								<th>{ __( 'Engine', 'seo-content-pilot' ) }</th>
								<th>{ __( 'Checks', 'seo-content-pilot' ) }</th>
								<th>{ __( 'Mention rate', 'seo-content-pilot' ) }</th>
								<th>{ __( 'Citation rate', 'seo-content-pilot' ) }</th>
								<th>{ __( 'Avg. list position', 'seo-content-pilot' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ Object.entries( engineStats ).map( ( [ engine, s ] ) => (
								<tr key={ engine }>
									<td>{ ENGINE_LABELS[ engine ] || engine }</td>
									<td>{ s.checks }</td>
									<td>{ pct( s.mention_rate ) }</td>
									<td>{ pct( s.citation_rate ) }</td>
									<td>{ s.avg_position ?? '—' }</td>
								</tr>
							) ) }
						</tbody>
					</table>
				) }
				<p className="scp-key-hint">
					{ __( 'LLM answers are non-deterministic — read these as weekly trends, never single-run verdicts.', 'seo-content-pilot' ) }
				</p>
			</div>

			<div className="scp-card">
				<h2>
					{ __( 'Tracked keywords', 'seo-content-pilot' ) } ({ tracking.tracked_count }/{ tracking.track_cap })
				</h2>
				{ tracking.items.length === 0 ? (
					<p>
						{ __( 'Publish articles first, then pick keywords here. Commercial-intent keywords work best.', 'seo-content-pilot' ) }
					</p>
				) : (
					<>
						<table className="widefat striped">
							<thead>
								<tr>
									<th style={ { width: 30 } }></th>
									<th>{ __( 'Keyword', 'seo-content-pilot' ) }</th>
									<th>{ __( 'Intent', 'seo-content-pilot' ) }</th>
									<th>{ __( 'Status', 'seo-content-pilot' ) }</th>
									<th>{ __( 'Tracked', 'seo-content-pilot' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ tracking.items.map( ( k ) => (
									<tr key={ k.id }>
										<td>
											<input
												type="checkbox"
												checked={ selected.has( k.id ) }
												onChange={ () => toggle( k.id ) }
											/>
										</td>
										<td>{ k.keyword }</td>
										<td>{ k.intent || '—' }</td>
										<td>
											<code>{ k.status }</code>
										</td>
										<td>{ Number( k.track_geo ) ? '✓' : '—' }</td>
									</tr>
								) ) }
							</tbody>
						</table>
						<div className="scp-actions">
							<Button
								variant="primary"
								onClick={ () => applyTracking( true ) }
								isBusy={ busy === 'tracking' }
								disabled={ selected.size === 0 }
							>
								{ __( 'Track selected', 'seo-content-pilot' ) }
							</Button>
							<Button
								variant="secondary"
								onClick={ () => applyTracking( false ) }
								isBusy={ busy === 'tracking' }
								disabled={ selected.size === 0 }
							>
								{ __( 'Untrack', 'seo-content-pilot' ) }
							</Button>
							<Button
								variant="secondary"
								onClick={ runNow }
								isBusy={ busy === 'run' }
							>
								{ selected.size
									? __( 'Run check on selected', 'seo-content-pilot' )
									: __( 'Run check now (tracked)', 'seo-content-pilot' ) }
							</Button>
						</div>
					</>
				) }
			</div>

			<div className="scp-card">
				<h2>
					{ __( 'Recent checks', 'seo-content-pilot' ) }
					<span style={ { float: 'right' } }>
						<SelectControl
							value={ engineFilter }
							options={ [
								{ value: '', label: __( 'All engines', 'seo-content-pilot' ) },
								...overview.engines.map( ( e ) => ( {
									value: e,
									label: ENGINE_LABELS[ e ] || e,
								} ) ),
							] }
							onChange={ setEngineFilter }
						/>
					</span>
				</h2>
				{ checks.length === 0 ? (
					<p>{ __( 'Nothing yet.', 'seo-content-pilot' ) }</p>
				) : (
					<table className="widefat striped">
						<thead>
							<tr>
								<th>{ __( 'When', 'seo-content-pilot' ) }</th>
								<th>{ __( 'Engine', 'seo-content-pilot' ) }</th>
								<th>{ __( 'Keyword', 'seo-content-pilot' ) }</th>
								<th>{ __( 'Mentioned', 'seo-content-pilot' ) }</th>
								<th>{ __( 'Cited', 'seo-content-pilot' ) }</th>
								<th>{ __( 'Pos.', 'seo-content-pilot' ) }</th>
								<th>{ __( 'Sentiment', 'seo-content-pilot' ) }</th>
								<th>{ __( 'Competitors', 'seo-content-pilot' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ checks.map( ( c, i ) => (
								<tr key={ i }>
									<td>{ c.checked_at }</td>
									<td>{ ENGINE_LABELS[ c.engine ] || c.engine }</td>
									<td>{ c.keyword }</td>
									<td>{ Number( c.mentioned ) ? '✓' : '—' }</td>
									<td>{ Number( c.cited ) ? '✓' : '—' }</td>
									<td>{ c.mention_position ?? '—' }</td>
									<td>{ c.sentiment }</td>
									<td>
										{ c.competitors_mentioned
											? JSON.parse( c.competitors_mentioned ).join( ', ' )
											: '—' }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				) }
			</div>
		</>
	);
};

export default Geo;

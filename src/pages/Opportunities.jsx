import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';
import { getGscOpportunities, addKeywords } from '../api';

const pct = ( v ) => `${ ( Number( v || 0 ) * 100 ).toFixed( 2 ) }%`;

const OpportunityTable = ( { rows, actionLabel, onAction, busyId, extraColumns } ) => (
	<table className="widefat striped">
		<thead>
			<tr>
				<th>{ __( 'Query', 'seo-content-pilot' ) }</th>
				<th>{ __( 'Clicks', 'seo-content-pilot' ) }</th>
				<th>{ __( 'Impressions', 'seo-content-pilot' ) }</th>
				<th>CTR</th>
				<th>{ __( 'Position', 'seo-content-pilot' ) }</th>
				{ extraColumns }
				<th>{ __( 'Top page', 'seo-content-pilot' ) }</th>
				<th></th>
			</tr>
		</thead>
		<tbody>
			{ rows.map( ( r ) => (
				<tr key={ r.query }>
					<td>{ r.query }</td>
					<td>{ r.clicks }</td>
					<td>{ r.impressions }</td>
					<td>{ pct( r.ctr ) }</td>
					<td>{ r.position }</td>
					{ r.benchmark !== undefined && (
						<td>{ pct( r.benchmark ) }</td>
					) }
					<td>
						{ r.page ? (
							<a href={ r.page } target="_blank" rel="noreferrer">
								{ r.page.replace( /^https?:\/\/[^/]+/, '' ) }
							</a>
						) : (
							'—'
						) }
					</td>
					<td>
						{ r.in_pool ? (
							<code>{ __( 'in pool', 'seo-content-pilot' ) }</code>
						) : (
							<Button
								variant="secondary"
								size="small"
								isBusy={ busyId === r.query }
								onClick={ () => onAction( r.query ) }
							>
								{ actionLabel }
							</Button>
						) }
					</td>
				</tr>
			) ) }
		</tbody>
	</table>
);

const Opportunities = () => {
	const [ data, setData ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ busyId, setBusyId ] = useState( '' );

	const load = () => {
		getGscOpportunities()
			.then( setData )
			.catch( ( err ) => setError( err.message ) );
	};

	useEffect( () => {
		load();
	}, [] );

	const enqueue = ( query ) => {
		setBusyId( query );
		setNotice( null );
		addKeywords( [ query ], { source: 'gsc', enqueue: true } )
			.then( ( res ) => {
				setNotice( {
					status: 'success',
					message: `"${ query }" ${ __( 'queued for generation', 'seo-content-pilot' ) }`,
				} );
				load();
			} )
			.catch( ( err ) => setNotice( { status: 'error', message: err.message } ) )
			.finally( () => setBusyId( '' ) );
	};

	if ( error ) {
		return (
			<div className="scp-card">
				<p>{ error }</p>
			</div>
		);
	}
	if ( ! data ) {
		return (
			<div className="scp-card">
				<Spinner />
			</div>
		);
	}

	const empty = Object.values( data ).every( ( list ) => list.length === 0 );

	return (
		<>
			{ notice && (
				<Notice { ...notice } onRemove={ () => setNotice( null ) } />
			) }
			{ empty && (
				<div className="scp-card">
					<p>
						{ __( 'No opportunities yet — the miner needs GSC data. Connect GSC and let the daily sync (or 16-month backfill) populate the cache.', 'seo-content-pilot' ) }
					</p>
				</div>
			) }

			{ data.striking_distance.length > 0 && (
				<div className="scp-card">
					<h2>{ __( 'Striking distance (position 11–30)', 'seo-content-pilot' ) }</h2>
					<p className="scp-key-hint">
						{ __( 'Almost page 1 — a dedicated article or content refresh can push these over.', 'seo-content-pilot' ) }
					</p>
					<OpportunityTable
						rows={ data.striking_distance }
						actionLabel={ __( 'Generate article', 'seo-content-pilot' ) }
						onAction={ enqueue }
						busyId={ busyId }
					/>
				</div>
			) }

			{ data.low_ctr.length > 0 && (
				<div className="scp-card">
					<h2>{ __( 'High impressions, low CTR (position ≤ 10)', 'seo-content-pilot' ) }</h2>
					<p className="scp-key-hint">
						{ __( 'Ranking well but under-clicked vs. the position benchmark — rewrite the title/meta, or generate a sharper page.', 'seo-content-pilot' ) }
					</p>
					<OpportunityTable
						rows={ data.low_ctr }
						actionLabel={ __( 'Generate article', 'seo-content-pilot' ) }
						onAction={ enqueue }
						busyId={ busyId }
						extraColumns={ <th>{ __( 'Benchmark CTR', 'seo-content-pilot' ) }</th> }
					/>
				</div>
			) }

			{ data.unexpected.length > 0 && (
				<div className="scp-card">
					<h2>{ __( 'Unexpected traffic', 'seo-content-pilot' ) }</h2>
					<p className="scp-key-hint">
						{ __( 'Queries bringing clicks that you have no dedicated content for — best new-topic candidates.', 'seo-content-pilot' ) }
					</p>
					<OpportunityTable
						rows={ data.unexpected }
						actionLabel={ __( 'Generate article', 'seo-content-pilot' ) }
						onAction={ enqueue }
						busyId={ busyId }
					/>
				</div>
			) }
		</>
	);
};

export default Opportunities;

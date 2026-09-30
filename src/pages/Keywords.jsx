import { useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, TextareaControl, Notice, SelectControl } from '@wordpress/components';
import {
	getKeywords,
	addKeywords,
	deleteKeywords,
	expandSeeds,
	fetchMetrics,
	getProviders,
	enqueueKeywords,
} from '../api';

const fmt = ( v, digits = 0 ) =>
	v === null || v === undefined || v === ''
		? '—'
		: Number( v ).toLocaleString( undefined, {
				minimumFractionDigits: digits,
				maximumFractionDigits: digits,
			} );

const Keywords = () => {
	const [ seeds, setSeeds ] = useState( '' );
	const [ keywords, setKeywords ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ statusFilter, setStatusFilter ] = useState( '' );
	const [ selected, setSelected ] = useState( new Set() );
	const [ providers, setProviders ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( '' );

	const refresh = useCallback( () => {
		getKeywords( statusFilter )
			.then( ( res ) => {
				setKeywords( res.items || [] );
				setTotal( res.total || 0 );
			} )
			.catch( () => {} );
	}, [ statusFilter ] );

	useEffect( () => {
		refresh();
		getProviders().then( setProviders ).catch( () => {} );
	}, [ refresh ] );

	const run = ( label, fn, onOk ) => {
		if ( busy ) {
			return;
		}
		setBusy( label );
		setNotice( null );
		fn()
			.then( ( res ) => {
				onOk( res );
				refresh();
			} )
			.catch( ( err ) => setNotice( { status: 'error', message: err.message } ) )
			.finally( () => setBusy( '' ) );
	};

	const onAdd = () =>
		run(
			'add',
			() => addKeywords( seeds ),
			( res ) => {
				setSeeds( '' );
				setNotice( {
					status: 'success',
					message: `${ res.inserted } ${ __( 'added', 'seo-content-pilot' ) }, ${ res.duplicates } ${ __( 'duplicates', 'seo-content-pilot' ) }`,
				} );
			}
		);

	const onExpand = () =>
		run(
			'expand',
			() => expandSeeds( seeds ),
			( res ) => {
				setSeeds( '' );
				setNotice( {
					status: 'success',
					message: `${ res.scheduled } ${ __( 'seed expansion tasks scheduled — results appear in the pool within a few minutes.', 'seo-content-pilot' ) }`,
				} );
			}
		);

	const onMetrics = () =>
		run(
			'metrics',
			() => fetchMetrics( [ ...selected ] ),
			( res ) => {
				setSelected( new Set() );
				setNotice( {
					status: 'success',
					message: `${ __( 'Enriched', 'seo-content-pilot' ) }: ${ res.metrics } ${ __( 'metrics', 'seo-content-pilot' ) }, ${ res.clustered } ${ __( 'clustered', 'seo-content-pilot' ) }, ${ res.intents } ${ __( 'intents', 'seo-content-pilot' ) }, ${ res.scored } ${ __( 'scored', 'seo-content-pilot' ) }${ res.skipped.length ? ' · ' + __( 'skipped', 'seo-content-pilot' ) + ': ' + res.skipped.join( ', ' ) : '' }`,
				} );
			}
		);

	const onEnqueue = () =>
		run(
			'enqueue',
			() => enqueueKeywords( [ ...selected ] ),
			( res ) => {
				setSelected( new Set() );
				setNotice( {
					status: 'success',
					message: `${ res.enqueued } ${ __( 'jobs enqueued', 'seo-content-pilot' ) } (${ res.skipped.length } ${ __( 'skipped', 'seo-content-pilot' ) })`,
				} );
			}
		);

	const onDelete = () =>
		run(
			'delete',
			() => deleteKeywords( [ ...selected ] ),
			( res ) => {
				setSelected( new Set() );
				setNotice( {
					status: 'success',
					message: `${ res.deleted } ${ __( 'deleted', 'seo-content-pilot' ) }`,
				} );
			}
		);

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

	const selectable = keywords.filter( ( k ) => k.status === 'new' );
	const toggleAll = () =>
		setSelected( ( prev ) =>
			prev.size === selectable.length
				? new Set()
				: new Set( selectable.map( ( k ) => k.id ) )
		);

	return (
		<>
			{ notice && (
				<Notice { ...notice } onRemove={ () => setNotice( null ) } />
			) }

			<div className="scp-card">
				<h2>{ __( 'Seeds → expand', 'seo-content-pilot' ) }</h2>
				{ providers && (
					<p className="scp-key-hint">
						{ __( 'Sources', 'seo-content-pilot' ) }: Google Autocomplete ✓
						{ ' · ' }LLM { providers.llm ? '✓' : '✗' }
						{ ' · ' }DataForSEO{ ' ' }
						{ providers.dataforseo.configured
							? `✓ ($${ fmt( providers.dataforseo.balance, 2 ) })`
							: __( 'not configured', 'seo-content-pilot' ) }
					</p>
				) }
				<TextareaControl
					label={ __( 'Seed keywords, one per line (max 20)', 'seo-content-pilot' ) }
					value={ seeds }
					onChange={ setSeeds }
					rows={ 4 }
					placeholder={ "crm software\nemail marketing tools" }
				/>
				<div className="scp-actions">
					<Button variant="secondary" onClick={ onAdd } isBusy={ busy === 'add' }>
						{ __( 'Add to pool', 'seo-content-pilot' ) }
					</Button>
					<Button variant="primary" onClick={ onExpand } isBusy={ busy === 'expand' }>
						{ __( 'Add & expand', 'seo-content-pilot' ) }
					</Button>
				</div>
			</div>

			<div className="scp-card">
				<h2>
					{ __( 'Keyword pool', 'seo-content-pilot' ) } ({ total })
					{ selectable.length > 0 && (
						<Button variant="link" onClick={ toggleAll } style={ { marginLeft: 12 } }>
							{ selected.size === selectable.length
								? __( 'Deselect all', 'seo-content-pilot' )
								: __( 'Select all new', 'seo-content-pilot' ) }
						</Button>
					) }
				</h2>
				<SelectControl
					label={ __( 'Status filter', 'seo-content-pilot' ) }
					value={ statusFilter }
					options={ [
						{ value: '', label: __( 'All', 'seo-content-pilot' ) },
						{ value: 'new', label: 'new' },
						{ value: 'queued', label: 'queued' },
						{ value: 'generating', label: 'generating' },
						{ value: 'published', label: 'published' },
						{ value: 'skipped', label: 'skipped' },
					] }
					onChange={ setStatusFilter }
				/>
				{ keywords.length === 0 ? (
					<p>{ __( 'No keywords yet.', 'seo-content-pilot' ) }</p>
				) : (
					<table className="widefat striped">
						<thead>
							<tr>
								<th style={ { width: 30 } }></th>
								<th>{ __( 'Keyword', 'seo-content-pilot' ) }</th>
								<th>{ __( 'Source', 'seo-content-pilot' ) }</th>
								<th>{ __( 'Volume', 'seo-content-pilot' ) }</th>
								<th>{ __( 'KD', 'seo-content-pilot' ) }</th>
								<th>{ __( 'Intent', 'seo-content-pilot' ) }</th>
								<th>{ __( 'Score', 'seo-content-pilot' ) }</th>
								<th>{ __( 'Cluster', 'seo-content-pilot' ) }</th>
								<th>{ __( 'Status', 'seo-content-pilot' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ keywords.map( ( k ) => (
								<tr key={ k.id }>
									<td>
										{ k.status === 'new' && (
											<input
												type="checkbox"
												checked={ selected.has( k.id ) }
												onChange={ () => toggle( k.id ) }
											/>
										) }
									</td>
									<td>{ k.keyword }</td>
									<td>
										<code>{ k.source }</code>
									</td>
									<td>{ fmt( k.volume ) }</td>
									<td>{ fmt( k.kd ) }</td>
									<td>{ k.intent || '—' }</td>
									<td>{ fmt( k.score, 1 ) }</td>
									<td>
										{ k.cluster_id
											? Number( k.cluster_id ) === Number( k.id )
												? __( 'primary', 'seo-content-pilot' )
												: `#${ k.cluster_id }`
											: '—' }
									</td>
									<td>
										<code>{ k.status }</code>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				) }
				<div className="scp-actions">
					<Button
						variant="secondary"
						onClick={ onMetrics }
						isBusy={ busy === 'metrics' }
						disabled={ selected.size === 0 }
					>
						{ __( 'Fetch metrics & score', 'seo-content-pilot' ) }
					</Button>
					<Button
						variant="primary"
						onClick={ onEnqueue }
						isBusy={ busy === 'enqueue' }
						disabled={ selected.size === 0 }
					>
						{ __( 'Enqueue selected', 'seo-content-pilot' ) }
					</Button>
					<Button
						variant="tertiary"
						isDestructive
						onClick={ onDelete }
						isBusy={ busy === 'delete' }
						disabled={ selected.size === 0 }
					>
						{ __( 'Delete', 'seo-content-pilot' ) }
					</Button>
					{ selected.size > 0 && <span>{ selected.size } { __( 'selected', 'seo-content-pilot' ) }</span> }
				</div>
				<p className="scp-key-hint">
					{ __( 'Metrics run pulls search volume + KD (DataForSEO), computes embeddings, clusters similar keywords (cosine ≥ 0.82, same cluster = one article), classifies intent and scores: volume / max(KD,1) × 1.5 if already ranked in GSC.', 'seo-content-pilot' ) }
				</p>
			</div>
		</>
	);
};

export default Keywords;

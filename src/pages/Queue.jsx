import { useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';
import { getQueue, retryJob } from '../api';

const STATUS_LABELS = {
	pending: __( 'Pending', 'seo-content-pilot' ),
	running: __( 'Running', 'seo-content-pilot' ),
	done: __( 'Done', 'seo-content-pilot' ),
	failed: __( 'Failed', 'seo-content-pilot' ),
};

const Queue = () => {
	const [ jobs, setJobs ] = useState( [] );
	const [ usage, setUsage ] = useState( null );
	const [ notice, setNotice ] = useState( null );

	const refresh = useCallback( () => {
		getQueue()
			.then( ( res ) => {
				setJobs( res.jobs || [] );
				setUsage( res.usage || null );
			} )
			.catch( () => {} );
	}, [] );

	useEffect( () => {
		refresh();
		const timer = setInterval( refresh, 15000 );
		return () => clearInterval( timer );
	}, [ refresh ] );

	const onRetry = ( id ) => {
		retryJob( id )
			.then( refresh )
			.catch( ( err ) =>
				setNotice( { status: 'error', message: err.message } )
			);
	};

	return (
		<>
			{ notice && (
				<Notice { ...notice } onRemove={ () => setNotice( null ) } />
			) }

			<div className="scp-card">
				<h2>
					{ __( 'Generation jobs', 'seo-content-pilot' ) }
					<Button
						variant="link"
						onClick={ refresh }
						style={ { marginLeft: 12 } }
					>
						{ __( 'Refresh', 'seo-content-pilot' ) }
					</Button>
				</h2>
				{ usage && (
					<p className="scp-key-hint">
						{ __( 'Totals', 'seo-content-pilot' ) }:{ ' ' }
						{ Number( usage.tokens ).toLocaleString() } tokens (~$
						{ Number( usage.cost ).toFixed( 4 ) }) · { usage.pending }{ ' ' }
						{ __( 'pending', 'seo-content-pilot' ) } · { usage.running }{ ' ' }
						{ __( 'running', 'seo-content-pilot' ) } · { usage.done }{ ' ' }
						{ __( 'done', 'seo-content-pilot' ) } · { usage.failed }{ ' ' }
						{ __( 'failed', 'seo-content-pilot' ) }
					</p>
				) }
				{ jobs.length === 0 ? (
					<p>
						{ __( 'No jobs yet. Enqueue keywords from the ', 'seo-content-pilot' ) }
						<a href="#/keywords">{ __( 'Keywords', 'seo-content-pilot' ) }</a>
						{ __( ' page.', 'seo-content-pilot' ) }
					</p>
				) : (
					<table className="widefat striped">
						<thead>
							<tr>
								<th>#</th>
								<th>{ __( 'Keyword', 'seo-content-pilot' ) }</th>
								<th>{ __( 'Status', 'seo-content-pilot' ) }</th>
								<th>{ __( 'Step', 'seo-content-pilot' ) }</th>
								<th>{ __( 'Tokens', 'seo-content-pilot' ) }</th>
								<th>{ __( 'Post', 'seo-content-pilot' ) }</th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							{ jobs.map( ( j ) => (
								<tr key={ j.id }>
									<td>{ j.id }</td>
									<td>
										{ j.keyword }
										{ j.error && (
											<div
												style={ {
													color: '#b32d2e',
													fontSize: 12,
												} }
											>
												{ j.error }
											</div>
										) }
									</td>
									<td>
										<code>{ STATUS_LABELS[ j.status ] || j.status }</code>
										{ j.status === 'running' && <Spinner /> }
									</td>
									<td>{ j.status !== 'done' && j.step }</td>
									<td>{ Number( j.tokens_used ).toLocaleString() }</td>
									<td>
										{ j.post_id && (
											<a
												href={ `${ window.scpAdmin.editBase }?post=${ j.post_id }&action=edit` }
											>
												{ j.title || `#${ j.post_id }` }
											</a>
										) }
										{ j.scheduled_at && j.status === 'done' && (
											<div style={ { fontSize: 12, color: '#646970' } }>
												{ j.scheduled_at }
											</div>
										) }
									</td>
									<td>
										{ j.status === 'failed' && (
											<Button
												variant="secondary"
												size="small"
												onClick={ () => onRetry( j.id ) }
											>
												{ __( 'Retry', 'seo-content-pilot' ) }
											</Button>
										) }
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

export default Queue;

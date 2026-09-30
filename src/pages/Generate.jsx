import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, TextControl, Notice, Spinner } from '@wordpress/components';
import { generateArticle } from '../api';

const Generate = () => {
	const [ keyword, setKeyword ] = useState( '' );
	const [ running, setRunning ] = useState( false );
	const [ result, setResult ] = useState( null );
	const [ error, setError ] = useState( null );

	const onGenerate = () => {
		if ( keyword.trim().length < 2 || running ) {
			return;
		}
		setRunning( true );
		setResult( null );
		setError( null );
		generateArticle( keyword.trim() )
			.then( setResult )
			.catch( ( err ) =>
				setError(
					err.message || __( 'Generation failed.', 'seo-content-pilot' )
				)
			)
			.finally( () => setRunning( false ) );
	};

	return (
		<>
			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }
			<div className="scp-card">
				<h2>{ __( 'Generate a single article', 'seo-content-pilot' ) }</h2>
				<TextControl
					label={ __( 'Target keyword', 'seo-content-pilot' ) }
					value={ keyword }
					onChange={ setKeyword }
					placeholder="e.g. best crm for small business"
					onKeyDown={ ( e ) => e.key === 'Enter' && onGenerate() }
				/>
				<div className="scp-actions">
					<Button
						variant="primary"
						onClick={ onGenerate }
						isBusy={ running }
						disabled={ running || keyword.trim().length < 2 }
					>
						{ __( 'Generate draft', 'seo-content-pilot' ) }
					</Button>
					{ running && (
						<span>
							<Spinner />{ ' ' }
							{ __( 'Generating outline, sections and meta… this usually takes 1–3 minutes.', 'seo-content-pilot' ) }
						</span>
					) }
				</div>
			</div>

			{ result && (
				<div className="scp-card scp-result">
					<h2>{ __( 'Draft created', 'seo-content-pilot' ) }</h2>
					<dl>
						<dt>{ __( 'Title', 'seo-content-pilot' ) }</dt>
						<dd>{ result.title }</dd>
						<dt>{ __( 'Words', 'seo-content-pilot' ) }</dt>
						<dd>{ result.word_count }</dd>
						<dt>{ __( 'Tokens used', 'seo-content-pilot' ) }</dt>
						<dd>{ result.tokens }</dd>
						<dt>{ __( 'Job', 'seo-content-pilot' ) }</dt>
						<dd>#{ result.job_id }</dd>
					</dl>
					<Button variant="secondary" href={ result.edit_link }>
						{ __( 'Review the draft →', 'seo-content-pilot' ) }
					</Button>
				</div>
			) }
		</>
	);
};

export default Generate;

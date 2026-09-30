import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Button,
	TextControl,
	TextareaControl,
	SelectControl,
	ToggleControl,
	Notice,
	Spinner,
} from '@wordpress/components';
import {
	getSettings,
	saveSettings,
	getGscStatus,
	getGscConnectUrl,
	getGscSites,
	saveGscSite,
	disconnectGsc,
} from '../api';

const LENGTH_OPTIONS = [
	{ value: 'short', label: __( 'Short (800–1200 words)', 'seo-content-pilot' ) },
	{ value: 'medium', label: __( 'Medium (1500–2500 words)', 'seo-content-pilot' ) },
	{ value: 'long', label: __( 'Long (3000+ words)', 'seo-content-pilot' ) },
];

const TONE_OPTIONS = [
	{ value: 'professional', label: __( 'Professional', 'seo-content-pilot' ) },
	{ value: 'casual', label: __( 'Casual', 'seo-content-pilot' ) },
	{ value: 'technical', label: __( 'Technical', 'seo-content-pilot' ) },
];

const LANGUAGE_OPTIONS = [
	{ value: 'en-US', label: 'English (US)' },
	{ value: 'en-GB', label: 'English (UK)' },
	{ value: 'en-AU', label: 'English (AU)' },
];

const Settings = () => {
	const [ settings, setSettings ] = useState( null );
	const [ apiKey, setApiKey ] = useState( '' );
	const [ dfsLogin, setDfsLogin ] = useState( '' );
	const [ dfsPassword, setDfsPassword ] = useState( '' );
	const [ gscClientId, setGscClientId ] = useState( '' );
	const [ gscClientSecret, setGscClientSecret ] = useState( '' );
	const [ geminiKey, setGeminiKey ] = useState( '' );
	const [ perplexityKey, setPerplexityKey ] = useState( '' );
	const [ pexelsKey, setPexelsKey ] = useState( '' );
	const [ gsc, setGsc ] = useState( null );
	const [ gscSites, setGscSites ] = useState( [] );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	useEffect( () => {
		getSettings()
			.then( setSettings )
			.catch( ( err ) =>
				setNotice( {
					status: 'error',
					message: err.message || __( 'Failed to load settings.', 'seo-content-pilot' ),
				} )
			);
		loadGsc();
	}, [] );

	const loadGsc = () => {
		getGscStatus()
			.then( ( st ) => {
				setGsc( st );
				if ( st.connected ) {
					getGscSites()
						.then( ( res ) => setGscSites( res.sites || [] ) )
						.catch( () => {} );
				}
			} )
			.catch( () => {} );
	};

	const onConnectGsc = () => {
		getGscConnectUrl()
			.then( ( res ) => {
				window.location.href = res.url;
			} )
			.catch( ( err ) => setNotice( { status: 'error', message: err.message } ) );
	};

	const onDisconnectGsc = () => {
		disconnectGsc()
			.then( () => {
				setGscSites( [] );
				loadGsc();
			} )
			.catch( ( err ) => setNotice( { status: 'error', message: err.message } ) );
	};

	const onSelectSite = ( site ) => {
		saveGscSite( site )
			.then( ( st ) => {
				setGsc( st );
				setNotice( {
					status: 'success',
					message: __( 'Property saved. First-time selection schedules a 16-month history backfill.', 'seo-content-pilot' ),
				} );
			} )
			.catch( ( err ) => setNotice( { status: 'error', message: err.message } ) );
	};

	if ( ! settings ) {
		return (
			<div className="scp-card">
				<Spinner /> { notice && <Notice { ...notice } /> }
			</div>
		);
	}

	const update = ( section, key, value ) => {
		setSettings( ( prev ) => ( {
			...prev,
			[ section ]: { ...prev[ section ], [ key ]: value },
		} ) );
	};

	const onSave = () => {
		setSaving( true );
		setNotice( null );
		const payload = {
			llm: settings.llm,
			content: settings.content,
			publish: settings.publish,
			prompts: settings.prompts,
			brand: settings.brand,
			geo: settings.geo,
			gsc: { ...settings.gsc },
		};
		if ( gscClientId.trim() ) {
			payload.gsc.client_id = gscClientId.trim();
		}
		const keys = {};
		if ( apiKey.trim() ) {
			keys.openai = apiKey.trim();
		}
		if ( dfsLogin.trim() ) {
			keys.dataforseo_login = dfsLogin.trim();
		}
		if ( dfsPassword.trim() ) {
			keys.dataforseo_password = dfsPassword.trim();
		}
		if ( gscClientSecret.trim() ) {
			keys.gsc_client_secret = gscClientSecret.trim();
		}
		if ( geminiKey.trim() ) {
			keys.gemini = geminiKey.trim();
		}
		if ( perplexityKey.trim() ) {
			keys.perplexity = perplexityKey.trim();
		}
		if ( pexelsKey.trim() ) {
			keys.pexels = pexelsKey.trim();
		}
		if ( Object.keys( keys ).length ) {
			payload.keys = keys;
		}
		saveSettings( payload )
			.then( ( saved ) => {
				setSettings( saved );
				setApiKey( '' );
				setDfsLogin( '' );
				setDfsPassword( '' );
				setGscClientSecret( '' );
				setGeminiKey( '' );
				setPerplexityKey( '' );
				setPexelsKey( '' );
				loadGsc();
				setNotice( {
					status: 'success',
					message: __( 'Settings saved.', 'seo-content-pilot' ),
				} );
			} )
			.catch( ( err ) =>
				setNotice( {
					status: 'error',
					message: err.message || __( 'Save failed.', 'seo-content-pilot' ),
				} )
			)
			.finally( () => setSaving( false ) );
	};

	const authorOptions = [
		{ value: 0, label: __( '(Current user)', 'seo-content-pilot' ) },
		...( settings.users || [] ),
	];
	const categoryOptions = [
		{ value: 0, label: __( '(None)', 'seo-content-pilot' ) },
		...( settings.categories || [] ),
	];

	return (
		<>
			{ notice && (
				<Notice { ...notice } onRemove={ () => setNotice( null ) } />
			) }
			<div className="scp-card">
				<h2>{ __( 'LLM Provider', 'seo-content-pilot' ) }</h2>
				<SelectControl
					label={ __( 'Provider', 'seo-content-pilot' ) }
					value={ settings.llm.provider }
					options={ [
						{ value: 'openai', label: 'OpenAI' },
						{ value: 'claude', label: 'Claude (S2+)', disabled: true },
						{ value: 'gemini', label: 'Gemini (S2+)', disabled: true },
					] }
					onChange={ ( v ) => update( 'llm', 'provider', v ) }
				/>
				<TextControl
					label={ __( 'Model', 'seo-content-pilot' ) }
					value={ settings.llm.model }
					onChange={ ( v ) => update( 'llm', 'model', v ) }
					help={ __( 'e.g. gpt-4o-mini, gpt-4o, gpt-4.1-mini', 'seo-content-pilot' ) }
				/>
				<TextControl
					type="password"
					label={ __( 'OpenAI API Key', 'seo-content-pilot' ) }
					value={ apiKey }
					onChange={ setApiKey }
					placeholder={
						settings.keys?.openai
							? __( '•••••••• (saved — enter a new key to replace)', 'seo-content-pilot' )
							: 'sk-...'
					}
					autoComplete="off"
				/>
				<TextControl
					type="password"
					label={ __( 'Gemini API Key (GEO engine)', 'seo-content-pilot' ) }
					value={ geminiKey }
					onChange={ setGeminiKey }
					placeholder={ settings.keys?.gemini ? '••••••••' : '' }
					autoComplete="new-password"
				/>
				<TextControl
					type="password"
					label={ __( 'Perplexity API Key (GEO engine, optional)', 'seo-content-pilot' ) }
					value={ perplexityKey }
					onChange={ setPerplexityKey }
					placeholder={ settings.keys?.perplexity ? '••••••••' : '' }
					autoComplete="new-password"
				/>
				<p className="scp-key-hint">
					{ __( 'Keys are stored encrypted (sodium) in wp_options and never sent back to the browser.', 'seo-content-pilot' ) }
				</p>
			</div>

			<div className="scp-card">
				<h2>{ __( 'Featured images', 'seo-content-pilot' ) }</h2>
				<ToggleControl
					label={ __( 'Generate a featured image for each article', 'seo-content-pilot' ) }
					checked={ !! settings.content.featured_image }
					onChange={ ( v ) => update( 'content', 'featured_image', v ) }
				/>
				{ settings.content.featured_image && (
					<>
						<TextControl
							label={ __( 'Image model', 'seo-content-pilot' ) }
							value={ settings.llm.image_model }
							onChange={ ( v ) => update( 'llm', 'image_model', v ) }
							help={ __( 'e.g. gpt-image-1, dall-e-3 (uses the OpenAI key above)', 'seo-content-pilot' ) }
						/>
						<TextControl
							type="password"
							label={ __( 'Pexels API Key (fallback, optional)', 'seo-content-pilot' ) }
							value={ pexelsKey }
							onChange={ setPexelsKey }
							placeholder={ settings.keys?.pexels ? '••••••••' : '' }
							autoComplete="new-password"
							help={ __( 'If AI image generation fails, a stock photo is pulled from Pexels. If neither works, the article is published without an image.', 'seo-content-pilot' ) }
						/>
					</>
				) }
			</div>

			<div className="scp-card">
				<h2>{ __( 'GEO tracking & content rules', 'seo-content-pilot' ) }</h2>
				<TextControl
					type="number"
					min="1"
					max="200"
					label={ __( 'Tracked keywords cap', 'seo-content-pilot' ) }
					value={ settings.geo?.track_cap ?? 50 }
					onChange={ ( v ) => update( 'geo', 'track_cap', Math.max( 1, Number( v ) || 50 ) ) }
					help={ __( 'Each tracked keyword costs one call per engine per week.', 'seo-content-pilot' ) }
				/>
				<TextControl
					label={ __( 'Prompt template — commercial intent', 'seo-content-pilot' ) }
					value={ settings.geo?.prompt_commercial || '' }
					onChange={ ( v ) => update( 'geo', 'prompt_commercial', v ) }
					help={ __( 'Use {keyword}. WARNING: changing templates breaks week-over-week trend comparability.', 'seo-content-pilot' ) }
				/>
				<TextControl
					label={ __( 'Prompt template — informational intent', 'seo-content-pilot' ) }
					value={ settings.geo?.prompt_informational || '' }
					onChange={ ( v ) => update( 'geo', 'prompt_informational', v ) }
					help={ __( 'Use {keyword}. Same comparability warning.', 'seo-content-pilot' ) }
				/>
				<ToggleControl
					label={ __( 'Inject write-to-be-cited rules into article generation', 'seo-content-pilot' ) }
					checked={ !! settings.geo?.content_rules }
					onChange={ ( v ) => update( 'geo', 'content_rules', v ) }
					help={ __( 'Question H2s with direct answer blocks, data tables, crisp definitions — optimized for AI citations.', 'seo-content-pilot' ) }
				/>
				<ToggleControl
					label={ __( 'Append author box (E-E-A-T)', 'seo-content-pilot' ) }
					checked={ !! settings.geo?.author_box }
					onChange={ ( v ) => update( 'geo', 'author_box', v ) }
					help={ __( 'Shown when the configured author has a bio in their WP profile.', 'seo-content-pilot' ) }
				/>
				<ToggleControl
					label={ __( 'Serve /llms.txt', 'seo-content-pilot' ) }
					checked={ !! settings.geo?.llms_txt }
					onChange={ ( v ) => update( 'geo', 'llms_txt', v ) }
					help={ __( 'Site intro + article index for AI crawlers. Permalinks must be enabled.', 'seo-content-pilot' ) }
				/>
			</div>

			<div className="scp-card">
				<h2>{ __( 'Keyword Data (DataForSEO)', 'seo-content-pilot' ) }</h2>
				<TextControl
					label={ __( 'Login (email)', 'seo-content-pilot' ) }
					value={ dfsLogin }
					onChange={ setDfsLogin }
					placeholder={
						settings.keys?.dataforseo_login
							? __( '•••••••• (saved — enter to replace)', 'seo-content-pilot' )
							: 'you@example.com'
					}
					autoComplete="off"
				/>
				<TextControl
					type="password"
					label={ __( 'Password (API password)', 'seo-content-pilot' ) }
					value={ dfsPassword }
					onChange={ setDfsPassword }
					placeholder={
						settings.keys?.dataforseo_password
							? __( '•••••••• (saved — enter to replace)', 'seo-content-pilot' )
							: ''
					}
					autoComplete="new-password"
				/>
				<p className="scp-key-hint">
					{ __( 'Used for keyword suggestions, search volume, keyword difficulty and SERP/AIO data. Pay-as-you-go at dataforseo.com. Keyword expansion works without it (Autocomplete + LLM only), but volume/KD/score need it.', 'seo-content-pilot' ) }
				</p>
			</div>

			<div className="scp-card">
				<h2>{ __( 'Content Preferences', 'seo-content-pilot' ) }</h2>
				<SelectControl
					label={ __( 'Language', 'seo-content-pilot' ) }
					value={ settings.content.language }
					options={ LANGUAGE_OPTIONS }
					onChange={ ( v ) => update( 'content', 'language', v ) }
				/>
				<SelectControl
					label={ __( 'Article length', 'seo-content-pilot' ) }
					value={ settings.content.length }
					options={ LENGTH_OPTIONS }
					onChange={ ( v ) => update( 'content', 'length', v ) }
				/>
				<SelectControl
					label={ __( 'Tone', 'seo-content-pilot' ) }
					value={ settings.content.tone }
					options={ TONE_OPTIONS }
					onChange={ ( v ) => update( 'content', 'tone', v ) }
				/>
				<SelectControl
					label={ __( 'Author', 'seo-content-pilot' ) }
					value={ settings.content.author_id }
					options={ authorOptions }
					onChange={ ( v ) => update( 'content', 'author_id', Number( v ) ) }
				/>
				<SelectControl
					label={ __( 'Default category', 'seo-content-pilot' ) }
					value={ settings.content.default_category }
					options={ categoryOptions }
					onChange={ ( v ) => update( 'content', 'default_category', Number( v ) ) }
				/>
			</div>

			<div className="scp-card">
				<h2>{ __( 'Google Search Console', 'seo-content-pilot' ) }</h2>
				{ gsc?.connected ? (
					<>
						<p>
							<strong>{ __( 'Connected', 'seo-content-pilot' ) }</strong>
							{ gsc.via === 'sitekit'
								? ` ${ __( 'via Site Kit', 'seo-content-pilot' ) }`
								: ` ${ __( 'via own OAuth app', 'seo-content-pilot' ) }` }
							{ gsc.last_sync && ` · ${ __( 'last sync', 'seo-content-pilot' ) }: ${ gsc.last_sync }` }
						</p>
						<SelectControl
							label={ __( 'Property', 'seo-content-pilot' ) }
							value={ gsc.site || '' }
							options={ [
								{ value: '', label: __( '(Select a property)', 'seo-content-pilot' ) },
								...gscSites.map( ( s ) => ( { value: s, label: s } ) ),
							] }
							onChange={ onSelectSite }
						/>
						{ gsc.via !== 'sitekit' && (
							<Button variant="tertiary" isDestructive onClick={ onDisconnectGsc }>
								{ __( 'Disconnect', 'seo-content-pilot' ) }
							</Button>
						) }
					</>
				) : (
					<>
						{ gsc?.sitekit_detected ? (
							<Notice status="info" isDismissible={ false }>
								{ __( 'Site Kit is installed but has no authorized Google account yet — connect GSC inside Site Kit and this plugin will reuse its token (no OAuth app needed).', 'seo-content-pilot' ) }
							</Notice>
						) : (
							<p className="scp-key-hint">
								{ __( 'Easiest path: install the Site Kit by Google plugin and connect Search Console there — we reuse its token. Alternative: create your own Google Cloud OAuth client below.', 'seo-content-pilot' ) }
							</p>
						) }
						<TextControl
							label={ __( 'OAuth Client ID (optional, for own app)', 'seo-content-pilot' ) }
							value={ gscClientId }
							onChange={ setGscClientId }
							placeholder={ settings.gsc?.client_id || 'xxx.apps.googleusercontent.com' }
							autoComplete="off"
						/>
						<TextControl
							type="password"
							label={ __( 'OAuth Client Secret', 'seo-content-pilot' ) }
							value={ gscClientSecret }
							onChange={ setGscClientSecret }
							placeholder={ settings.keys?.gsc_client_secret ? '••••••••' : '' }
							autoComplete="new-password"
						/>
						<p className="scp-key-hint">
							{ __( 'Redirect URI to whitelist in Google Cloud Console:', 'seo-content-pilot' ) }{ ' ' }
							<code>{ gsc?.redirect_uri }</code>
						</p>
						<p className="scp-key-hint">
							{ __( 'Save first, then connect.', 'seo-content-pilot' ) }
						</p>
						<Button variant="primary" onClick={ onConnectGsc }>
							{ __( 'Connect Google', 'seo-content-pilot' ) }
						</Button>
					</>
				) }
			</div>

			<div className="scp-card">
				<h2>{ __( 'Brand & competitors (GEO)', 'seo-content-pilot' ) }</h2>
				<TextControl
					label={ __( 'Brand name', 'seo-content-pilot' ) }
					value={ settings.brand?.brand_name || '' }
					onChange={ ( v ) => update( 'brand', 'brand_name', v ) }
					help={ __( 'Used for brand-search trend tracking and (S5) AI mention detection.', 'seo-content-pilot' ) }
				/>
				<TextControl
					label={ __( 'Site domain', 'seo-content-pilot' ) }
					value={ settings.brand?.site_domain || '' }
					onChange={ ( v ) => update( 'brand', 'site_domain', v ) }
					help={ __( 'Defaults to this site’s domain. Used for AI Overview citation matching.', 'seo-content-pilot' ) }
				/>
				<TextareaControl
					label={ __( 'Competitor domains (one per line)', 'seo-content-pilot' ) }
					value={ ( settings.brand?.competitors || [] ).join( '\n' ) }
					onChange={ ( v ) =>
						update( 'brand', 'competitors', v.split( /[\r\n]+/ ).map( ( s ) => s.trim() ).filter( Boolean ) )
					}
					rows={ 3 }
					help={ __( 'AI Overview checks report when these domains get cited instead of you.', 'seo-content-pilot' ) }
				/>
			</div>

			<div className="scp-card">
				<h2>{ __( 'Publishing', 'seo-content-pilot' ) }</h2>
				<SelectControl
					label={ __( 'Publish mode', 'seo-content-pilot' ) }
					value={ settings.publish.mode }
					options={ [
						{ value: 'draft', label: __( 'Save as draft (recommended)', 'seo-content-pilot' ) },
						{ value: 'publish', label: __( 'Publish immediately', 'seo-content-pilot' ) },
						{ value: 'schedule', label: __( 'Scheduled (spread across days)', 'seo-content-pilot' ) },
					] }
					onChange={ ( v ) => update( 'publish', 'mode', v ) }
				/>
				{ settings.publish.mode === 'schedule' && (
					<>
						<TextControl
							type="number"
							min="1"
							max="20"
							label={ __( 'Max posts per day', 'seo-content-pilot' ) }
							value={ settings.publish.daily_cap }
							onChange={ ( v ) =>
								update( 'publish', 'daily_cap', Math.max( 1, Number( v ) || 1 ) )
							}
							help={ __( 'Keep this low (2–3). Publishing many AI posts on one day looks unnatural.', 'seo-content-pilot' ) }
						/>
						<TextControl
							type="time"
							label={ __( 'Window start', 'seo-content-pilot' ) }
							value={ settings.publish.window_start }
							onChange={ ( v ) => update( 'publish', 'window_start', v ) }
						/>
						<TextControl
							type="time"
							label={ __( 'Window end', 'seo-content-pilot' ) }
							value={ settings.publish.window_end }
							onChange={ ( v ) => update( 'publish', 'window_end', v ) }
						/>
						<p className="scp-key-hint">
							{ __( 'Posts go out at a random minute inside the window. WordPress cron publishes them — make sure WP-Cron runs (system cron recommended).', 'seo-content-pilot' ) }
						</p>
					</>
				) }
			</div>

			<div className="scp-actions">
				<Button variant="primary" onClick={ onSave } isBusy={ saving } disabled={ saving }>
					{ __( 'Save settings', 'seo-content-pilot' ) }
				</Button>
			</div>
		</>
	);
};

export default Settings;

/**
 * Triptych — in-canvas language UI for the Block Editor.
 *
 * The canvas IS the editor. Switching language replaces the title +
 * blocks with that language's stored content. Saving in a non-default
 * language writes to `_triptych_<field>_<lang>` postmeta and never
 * overwrites the default-language source columns.
 *
 * Layers:
 *   1. Header pill bar — CN | JP | EN, status dot per language, plus
 *      "Save translation" + "Translate post" action buttons. Mounted
 *      via React portal into the editor's header center slot.
 *   2. Per-block toolbar button — paragraph / heading / list / quote /
 *      table / code / button get a Translate icon. Click → DeepSeek
 *      translates just that block's text content.
 *
 * No JSX — pure wp.element.createElement. No build step required.
 */
( function ( wp ) {
	'use strict';

	const { registerPlugin }    = wp.plugins;
	const {
		Button, ButtonGroup, Notice,
		ToolbarButton, ToolbarGroup,
	} = wp.components;
	const {
		useState, useEffect, useCallback, useRef,
		createElement: el, Fragment, createPortal,
	} = wp.element;
	const { useSelect, useDispatch, select, dispatch } = wp.data;
	const { __, sprintf } = wp.i18n;
	const { addFilter }   = wp.hooks;
	const { createHigherOrderComponent } = wp.compose;
	const apiFetch        = wp.apiFetch;
	const { BlockControls } = wp.blockEditor;
	const { serialize, parse } = wp.blocks;

	const cfg = window.TriptychEditor || { languages: { en: 'English' }, default: 'en', i18n: {} };
	const langKeys = Object.keys( cfg.languages );
	const t = ( k, fb ) => cfg.i18n[ k ] || fb || k;

	const TRIPTYCH_SAVE_LOCK = 'triptych-non-default-lang';

	// ── Module-scope state shared between the bar, the BlockControls
	// extension, and the save subscriber.
	const triptychState = {
		activeLang: cfg.default,
		postId:     0,
		listeners:  new Set(),
	};
	function setActive( lang ) {
		triptychState.activeLang = lang;
		triptychState.listeners.forEach( ( fn ) => fn() );
	}
	function onChange( fn ) {
		triptychState.listeners.add( fn );
		return () => triptychState.listeners.delete( fn );
	}
	function useActiveLang() {
		const [ , force ] = useState( 0 );
		useEffect( () => onChange( () => force( ( n ) => n + 1 ) ), [] );
		return triptychState.activeLang;
	}

	// ── Block translatability table.
	const TRANSLATABLE_BLOCKS = {
		'core/paragraph':    { attrs: [ 'content' ] },
		'core/heading':      { attrs: [ 'content' ] },
		'core/list-item':    { attrs: [ 'content' ] },
		'core/list':         { attrs: [] },
		'core/quote':        { attrs: [ 'value', 'citation' ] },
		'core/pullquote':    { attrs: [ 'value', 'citation' ] },
		'core/code':         { attrs: [ 'content' ] },
		'core/preformatted': { attrs: [ 'content' ] },
		'core/verse':        { attrs: [ 'content' ] },
		'core/table':        { attrs: [ 'caption' ] },
		'core/button':       { attrs: [ 'text' ] },
		'core/buttons':      { attrs: [] },
		'core/image':        { attrs: [ 'alt', 'caption' ] },
	};

	// ── REST helpers
	async function fetchState( postId ) {
		return apiFetch( { path: `/triptych/v1/post/${ postId }` } );
	}
	async function saveValue( postId, field, lang, value ) {
		return apiFetch( {
			path: '/triptych/v1/save',
			method: 'POST',
			data: { post_id: postId, field, lang, value },
		} );
	}
	async function callTranslate( from, to, text, field ) {
		const r = await apiFetch( {
			path: '/triptych/v1/translate',
			method: 'POST',
			data: { from, to, text, field: field || '' },
		} );
		if ( ! r || typeof r.translated !== 'string' ) {
			throw new Error( 'Bad translate-endpoint response' );
		}
		return r.translated;
	}

	// ── Editor state helpers
	function getEditorTitle() {
		return select( 'core/editor' ).getEditedPostAttribute( 'title' ) || '';
	}
	function getEditorContent() {
		// Live block tree → canonical block markup. Reading the
		// 'content' attribute returns the on-load source only.
		const blocks = select( 'core/block-editor' ).getBlocks();
		return serialize( blocks );
	}
	function setEditorTitle( value ) {
		dispatch( 'core/editor' ).editPost( { title: value } );
	}
	function setEditorContent( value ) {
		const blocks = parse( value || '' );
		dispatch( 'core/block-editor' ).resetBlocks( blocks );
		// Mark the editor's content attribute so subsequent serializations
		// see the new state without flagging the post as dirty.
		dispatch( 'core/editor' ).editPost( { content: value } );
	}

	// ── Header bar: language pills + actions ───────────────────────
	function HeaderBar() {
		const activeLang = useActiveLang();
		const { postId, sourceTitle, sourceContent } = useSelect( ( s ) => {
			const ed = s( 'core/editor' );
			return {
				postId:        ed.getCurrentPostId(),
				sourceTitle:   ed.getEditedPostAttribute( 'title' ) || '',
				sourceContent: ed.getEditedPostAttribute( 'content' ) || '',
			};
		}, [] );

		const [ state, setState ]       = useState( null );
		const [ busyLang, setBusyLang ] = useState( null );
		const [ saveBusy, setSaveBusy ] = useState( false );
		const [ error, setError ]       = useState( null );
		const cacheRef = useRef( {} );  // { lang: { title, content } } unsaved swaps
		const noticesDispatch = useDispatch( 'core/notices' );

		const refresh = useCallback( async () => {
			if ( ! postId ) return;
			try {
				const data = await fetchState( postId );
				setState( data );
				triptychState.postId = postId;
			} catch ( err ) {
				setError( err && err.message ? err.message : String( err ) );
			}
		}, [ postId ] );

		useEffect( () => { refresh(); }, [ refresh ] );

		// Saving in a non-default language must NOT write to post_content.
		// We block Gutenberg's native save while non-default is active —
		// the dedicated "Save translation" button persists to postmeta.
		useEffect( () => {
			if ( activeLang === cfg.default ) {
				dispatch( 'core/editor' ).unlockPostSaving( TRIPTYCH_SAVE_LOCK );
			} else {
				dispatch( 'core/editor' ).lockPostSaving( TRIPTYCH_SAVE_LOCK );
			}
		}, [ activeLang ] );

		const switchLang = useCallback( ( target ) => {
			if ( target === activeLang ) return;

			// Stash current canvas state under the old lang in case the
			// user switches back without saving.
			cacheRef.current[ activeLang ] = {
				title:   getEditorTitle(),
				content: getEditorContent(),
			};

			// Pull next lang content from cache → state snapshot → empty.
			let nextTitle = '';
			let nextContent = '';
			const cached = cacheRef.current[ target ];
			if ( cached ) {
				nextTitle   = cached.title;
				nextContent = cached.content;
			} else if ( state ) {
				const tField = state.fields.post_title   || { values: {} };
				const cField = state.fields.post_content || { values: {} };
				nextTitle   = ( tField.values[ target ] || {} ).value || '';
				nextContent = ( cField.values[ target ] || {} ).value || '';
				if ( target === cfg.default ) {
					if ( ! nextTitle )   nextTitle   = sourceTitle;
					if ( ! nextContent ) nextContent = sourceContent;
				}
			}

			setEditorTitle( nextTitle );
			setEditorContent( nextContent );
			setActive( target );
		}, [ activeLang, state, sourceTitle, sourceContent ] );

		const saveTranslation = useCallback( async () => {
			if ( ! postId ) {
				noticesDispatch.createNotice( 'warning', t( 'savePostFirst', 'Save the post once before translating.' ) );
				return;
			}
			setSaveBusy( true );
			setError( null );
			try {
				const title   = getEditorTitle();
				const content = getEditorContent();
				if ( activeLang === cfg.default ) {
					dispatch( 'core/editor' ).unlockPostSaving( TRIPTYCH_SAVE_LOCK );
					await dispatch( 'core/editor' ).savePost();
					dispatch( 'core/editor' ).lockPostSaving( TRIPTYCH_SAVE_LOCK );
				} else {
					await Promise.all( [
						saveValue( postId, 'post_title',   activeLang, title ),
						saveValue( postId, 'post_content', activeLang, content ),
					] );
					noticesDispatch.createNotice(
						'success',
						t( 'savedTranslation', 'Translation saved' ),
						{ type: 'snackbar', isDismissible: true }
					);
				}
				await refresh();
			} catch ( err ) {
				const msg = err && err.message ? err.message : String( err );
				setError( msg );
			} finally {
				setSaveBusy( false );
			}
		}, [ postId, activeLang, refresh, noticesDispatch ] );

		const translateWholePost = useCallback( async ( target ) => {
			if ( ! postId ) {
				noticesDispatch.createNotice( 'warning', t( 'savePostFirst', 'Save the post once before translating.' ) );
				return;
			}
			setBusyLang( target );
			setError( null );
			try {
				let srcTitle, srcContent;
				if ( activeLang === cfg.default ) {
					srcTitle   = getEditorTitle();
					srcContent = getEditorContent();
				} else if ( state ) {
					srcTitle   = ( state.fields.post_title   || { values: {} } ).values[ cfg.default ]?.value || sourceTitle;
					srcContent = ( state.fields.post_content || { values: {} } ).values[ cfg.default ]?.value || sourceContent;
				} else {
					srcTitle   = sourceTitle;
					srcContent = sourceContent;
				}
				if ( ! ( srcTitle || '' ).trim() && ! ( srcContent || '' ).trim() ) {
					throw new Error( 'Source content is empty.' );
				}

				const [ tTitle, tContent ] = await Promise.all( [
					( srcTitle   || '' ).trim() ? callTranslate( cfg.default, target, srcTitle, 'post_title' )     : Promise.resolve( '' ),
					( srcContent || '' ).trim() ? callTranslate( cfg.default, target, srcContent, 'post_content' ) : Promise.resolve( '' ),
				] );

				await Promise.all( [
					tTitle   ? saveValue( postId, 'post_title',   target, tTitle )   : Promise.resolve(),
					tContent ? saveValue( postId, 'post_content', target, tContent ) : Promise.resolve(),
				] );

				cacheRef.current[ target ] = { title: tTitle, content: tContent };
				if ( activeLang === target ) {
					setEditorTitle( tTitle );
					setEditorContent( tContent );
				}
				await refresh();
				noticesDispatch.createNotice(
					'success',
					sprintf( t( 'translatedAgo', 'Translated %s' ), target.toUpperCase() ),
					{ type: 'snackbar' }
				);
			} catch ( err ) {
				const msg = err && err.message ? err.message : String( err );
				setError( sprintf( t( 'translateError', 'Translation failed: %s' ), msg ) );
			} finally {
				setBusyLang( null );
			}
		}, [ postId, state, activeLang, sourceTitle, sourceContent, refresh, noticesDispatch ] );

		const buildPill = ( lang ) => {
			const label    = cfg.languages[ lang ] || lang;
			const isActive = lang === activeLang;
			const fields   = state ? state.fields : {};
			const tStatus  = ( fields.post_title   || { values: {} } ).values[ lang ] || {};
			const cStatus  = ( fields.post_content || { values: {} } ).values[ lang ] || {};
			let dot = 'is-empty';
			if ( lang === cfg.default ) dot = 'is-source';
			else if ( tStatus.has_envelope || cStatus.has_envelope ) dot = 'is-translated';
			else if ( tStatus.has_value || cStatus.has_value ) dot = 'is-legacy';

			return el( 'button', {
				key:   lang,
				type:  'button',
				className: 'triptych-pill' + ( isActive ? ' is-active' : '' ),
				onClick: () => switchLang( lang ),
				'aria-pressed': isActive,
				title: label,
			},
				el( 'span', { className: 'triptych-pill-slug' }, lang.toUpperCase() ),
				el( 'span', { className: 'triptych-pill-dot ' + dot, 'aria-hidden': true } )
			);
		};

		const isNonDefault = activeLang !== cfg.default;

		return el( 'div', { className: 'triptych-bar' },
			el( 'div', { className: 'triptych-bar-pills' },
				el( 'span', { className: 'triptych-bar-label' }, t( 'switchLabel', 'Language' ) ),
				el( ButtonGroup, { className: 'triptych-pillgroup' }, langKeys.map( buildPill ) )
			),
			el( 'div', { className: 'triptych-bar-actions' },
				isNonDefault && el( Button, {
					variant:  'primary',
					isBusy:   saveBusy,
					disabled: saveBusy,
					onClick:  saveTranslation,
				}, t( 'saveTranslation', 'Save translation' ) ),
				isNonDefault && el( Button, {
					variant:  'secondary',
					isBusy:   busyLang === activeLang,
					disabled: busyLang === activeLang,
					onClick:  () => translateWholePost( activeLang ),
				}, t( 'translatePost', 'Translate post' ) )
			),
			error && el( Notice, {
				className: 'triptych-bar-error',
				status:    'error',
				isDismissible: true,
				onRemove:  () => setError( null ),
			}, error )
		);
	}

	// ── Mount the header bar via portal once the editor frame exists.
	function HeaderBarMount() {
		const [ anchor, setAnchor ] = useState( null );
		useEffect( () => {
			let cancelled = false;
			let rafId;
			const find = () => {
				if ( cancelled ) return;
				const target =
					document.querySelector( '.editor-header__center' ) ||
					document.querySelector( '.edit-post-header__center' ) ||
					document.querySelector( '.edit-post-header__settings' ) ||
					document.querySelector( '.edit-post-header' );
				if ( target ) {
					let host = target.querySelector( ':scope > .triptych-bar-host' );
					if ( ! host ) {
						host = document.createElement( 'div' );
						host.className = 'triptych-bar-host';
						target.appendChild( host );
					}
					setAnchor( host );
					return;
				}
				rafId = requestAnimationFrame( find );
			};
			find();
			return () => {
				cancelled = true;
				if ( rafId ) cancelAnimationFrame( rafId );
			};
		}, [] );
		if ( ! anchor ) return null;
		return createPortal( el( HeaderBar ), anchor );
	}

	// ── Per-block toolbar button ──────────────────────────────────
	const withTriptychToolbar = createHigherOrderComponent( ( BlockEdit ) => {
		return function TriptychBlockEdit( props ) {
			const { name, attributes, setAttributes, isSelected } = props;
			const def = TRANSLATABLE_BLOCKS[ name ];
			const activeLang = useActiveLang();
			const [ busy, setBusy ] = useState( false );

			if ( ! def || activeLang === cfg.default || ! isSelected ) {
				return el( BlockEdit, props );
			}

			const onTranslate = async () => {
				const sourceText = def.attrs
					.map( ( k ) => attributes[ k ] || '' )
					.filter( ( v ) => typeof v === 'string' && v.trim() !== '' )
					.join( '\n' );
				if ( ! sourceText ) {
					dispatch( 'core/notices' ).createNotice(
						'info',
						t( 'untranslatable', 'This block has no translatable text.' ),
						{ type: 'snackbar' }
					);
					return;
				}
				setBusy( true );
				try {
					if ( def.attrs.length === 1 ) {
						const k = def.attrs[ 0 ];
						const out = await callTranslate( cfg.default, activeLang, attributes[ k ] || '', 'block_' + name + '_' + k );
						setAttributes( { [ k ]: out } );
					} else {
						const updates = {};
						for ( const k of def.attrs ) {
							const v = attributes[ k ];
							if ( typeof v === 'string' && v.trim() !== '' ) {
								updates[ k ] = await callTranslate( cfg.default, activeLang, v, 'block_' + name + '_' + k );
							}
						}
						setAttributes( updates );
					}
				} catch ( err ) {
					const msg = err && err.message ? err.message : String( err );
					dispatch( 'core/notices' ).createNotice(
						'error',
						sprintf( t( 'translateError', 'Translation failed: %s' ), msg ),
						{ type: 'snackbar' }
					);
				} finally {
					setBusy( false );
				}
			};

			return el( Fragment, null,
				el( BlockEdit, props ),
				el( BlockControls, null,
					el( ToolbarGroup, null,
						el( ToolbarButton, {
							icon:     busy ? 'update' : 'translation',
							label:    t( 'translateBlock', 'Translate block' ),
							onClick:  onTranslate,
							disabled: busy,
						} )
					)
				)
			);
		};
	}, 'withTriptychToolbar' );

	addFilter(
		'editor.BlockEdit',
		'triptych/with-translate-toolbar',
		withTriptychToolbar
	);

	registerPlugin( 'triptych-language-bar', { render: HeaderBarMount, icon: 'translation' } );

} )( window.wp );
